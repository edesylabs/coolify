#!/bin/bash
## Coolify (edesylabs fork) — inspect user accounts and what they do on this instance. Read-only.
##
## Usage (as root on the Coolify server):
##   curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/inspect-user.sh | bash
##       lists every user with their teams, last activity and resource counts
##   curl -fsSL .../scripts/inspect-user.sh | bash -s -- someone@example.com
##       full report for one user, followed by automatic red-flag findings
##
## Everything runs inside a READ ONLY database transaction; nothing is changed.
##
## Environment variables:
##   COOLIFY_DATA_DIR         Coolify data directory (default: /data/coolify)

set -e
set -o pipefail

DATA_DIR="${COOLIFY_DATA_DIR:-/data/coolify}"
ENV_FILE="$DATA_DIR/source/.env"

RED='\033[0;31m'
NC='\033[0m'

fail() {
    echo -e "${RED}✗${NC} $*" >&2
    exit 1
}

env_value() {
    local key="$1" default="$2" value=""
    [ -f "$ENV_FILE" ] && value=$(grep -m1 "^${key}=" "$ENV_FILE" | cut -d '=' -f2- || true)
    echo "${value:-$default}"
}

[ "$EUID" -eq 0 ] || fail "Please run as root (or with sudo)."
[ "$(docker inspect coolify-db --format '{{.State.Running}}' 2>/dev/null)" = "true" ] || fail "coolify-db is not running."

DB_USER=$(env_value DB_USERNAME coolify)
DB_NAME=$(env_value DB_DATABASE coolify)
EMAIL="${1:-}"

# This host's own addresses: a user adding one of them as "their" server is probing the Coolify host
HOST_IPS="{$( (hostname -I 2>/dev/null || true; curl -fsS --max-time 3 https://ifconfig.me 2>/dev/null || true) | tr -s ' \n' ',' | sed 's/^,//; s/,$//')}"

psql_ro() {
    docker exec -i coolify-db psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 "$@"
}

if [ -z "$EMAIL" ]; then
    psql_ro <<'SQL'
BEGIN READ ONLY;
\pset pager off
\set QUIET on
\echo '\n=== Users (team 0 is the root/instance team)'
SELECT u.id, u.email, u.name, u.created_at::timestamp(0) AS registered,
       (SELECT string_agg(tu.team_id || ':' || tu.role, ', ' ORDER BY tu.team_id) FROM team_user tu WHERE tu.user_id = u.id) AS "teams (id:role)",
       (SELECT to_timestamp(max(s.last_activity))::timestamp(0) FROM sessions s WHERE s.user_id = u.id) AS last_seen,
       (SELECT count(*) FROM personal_access_tokens p WHERE p.tokenable_id = u.id) AS api_tokens,
       (SELECT count(*) FROM servers sv WHERE sv.team_id IN (SELECT team_id FROM team_user WHERE user_id = u.id) AND sv.team_id <> 0) AS own_servers,
       (SELECT count(*) FROM applications a JOIN environments e ON e.id = a.environment_id JOIN projects p ON p.id = e.project_id
         WHERE p.team_id IN (SELECT team_id FROM team_user WHERE user_id = u.id) AND p.team_id <> 0) AS own_apps
FROM users u ORDER BY u.id;
\echo '\nRegistration open to anyone:'
SELECT is_registration_enabled FROM instance_settings WHERE id = 0;
ROLLBACK;
SQL
    echo ""
    echo "Full report for one user: curl -fsSL https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/inspect-user.sh | bash -s -- <email>"
    exit 0
fi

psql_ro -v email="$EMAIL" -v hostips="$HOST_IPS" <<'SQL'
BEGIN READ ONLY;
\pset pager off
\set QUIET on
SELECT coalesce(max(id), -1) AS uid FROM users WHERE email = :'email' \gset
SELECT :uid = -1 AS missing \gset
\if :missing
\echo 'No user with that email.'
ROLLBACK;
\quit
\endif
SELECT coalesce(string_agg(team_id::text, ','), '-1') AS tids FROM team_user WHERE user_id = :uid \gset

\echo '\n=== 1. Account'
SELECT id, name, email, created_at, updated_at, email_verified_at, two_factor_confirmed_at IS NOT NULL AS has_2fa
FROM users WHERE id = :uid;

\echo '\n=== 2. Teams (team 0 = root/instance team)'
SELECT tu.team_id, t.name AS team, tu.role, tu.created_at AS joined
FROM team_user tu JOIN teams t ON t.id = tu.team_id WHERE tu.user_id = :uid;

\echo '\n=== 3. Other members of those teams'
SELECT tu.team_id, u.email, tu.role FROM team_user tu JOIN users u ON u.id = tu.user_id
WHERE tu.team_id IN (:tids) AND tu.user_id <> :uid;

\echo '\n=== 4. Logins / sessions'
SELECT ip_address, left(user_agent, 90) AS user_agent, to_timestamp(last_activity) AS last_activity
FROM sessions WHERE user_id = :uid ORDER BY last_activity DESC;

\echo '\n=== 5. API tokens'
SELECT id, name, abilities, team_id, created_at, last_used_at, expires_at
FROM personal_access_tokens WHERE tokenable_id = :uid;

\echo '\n=== 6. Servers in their team(s)'
SELECT id, name, ip, "user", port, created_at FROM servers WHERE team_id IN (:tids);

\echo '\n=== 7. SSH keys in their team(s)'
SELECT id, name, created_at FROM private_keys WHERE team_id IN (:tids);

\echo '\n=== 8. Projects'
SELECT id, name, created_at FROM projects WHERE team_id IN (:tids);

\echo '\n=== 9. Applications'
SELECT a.id, a.name, a.build_pack, a.git_repository, a.docker_registry_image_name AS image, a.fqdn, a.created_at
FROM applications a JOIN environments e ON e.id = a.environment_id JOIN projects p ON p.id = e.project_id
WHERE p.team_id IN (:tids);

\echo '\n=== 10. Services (docker compose stacks; first 400 chars)'
SELECT s.id, s.name, s.created_at, left(s.docker_compose_raw, 400) AS compose
FROM services s JOIN environments e ON e.id = s.environment_id JOIN projects p ON p.id = e.project_id
WHERE p.team_id IN (:tids);

\echo '\n=== 11. Deployments (latest 50)'
SELECT q.created_at, q.status, a.name AS application, q.server_name, q.commit, q.is_api
FROM application_deployment_queues q JOIN applications a ON q.application_id = a.id::text
JOIN environments e ON e.id = a.environment_id JOIN projects p ON p.id = e.project_id
WHERE p.team_id IN (:tids) ORDER BY q.created_at DESC LIMIT 50;

\echo '\n=== 12. Scheduled tasks'
SELECT id, name, command, frequency, container, enabled, created_at FROM scheduled_tasks WHERE team_id IN (:tids);

\echo '\n=== 13. Cloud tokens, GitHub apps, S3 storages'
SELECT 'cloud token' AS kind, provider AS detail, name, created_at FROM cloud_provider_tokens WHERE team_id IN (:tids)
UNION ALL SELECT 'github app', html_url, name, created_at FROM github_apps WHERE team_id IN (:tids)
UNION ALL SELECT 's3 storage', endpoint, name, created_at FROM s3_storages WHERE team_id IN (:tids);

\echo '\n=== 14. Audit log (recorded since the 4.4.7 upgrade)'
SELECT created_at, action, resource_type, resource_name, ip_address, left(description, 100) AS description
FROM audit_events WHERE actor_id = :uid ORDER BY created_at DESC LIMIT 50;

\echo '\n=== FINDINGS (empty = nothing suspicious detected automatically)'
SELECT finding FROM (SELECT DISTINCT o, finding FROM (
    SELECT 1 AS o, 'HIGH: member of the root team 0 (' || role || ') - can see and control the whole instance; expected only for people you invited' AS finding
      FROM team_user WHERE user_id = :uid AND user_id <> 0 AND team_id = 0
    UNION ALL SELECT 2, 'HIGH: server "' || name || '" points at this Coolify host (' || ip || ')'
      FROM servers WHERE team_id IN (:tids) AND team_id <> 0 AND ip = ANY (:'hostips'::text[])
    UNION ALL SELECT 3, 'HIGH: server "' || name || '" uses a local/private address (' || ip || ') - internal network probing?'
      FROM servers WHERE team_id IN (:tids) AND team_id <> 0
       AND ip ~* '^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|169\.254\.|0\.0\.0\.0|localhost|host\.docker\.internal|::1|f[cd])'
    UNION ALL SELECT 4, 'HIGH: service "' || s.name || '" compose contains ' || m[1]
      FROM services s JOIN environments e ON e.id = s.environment_id JOIN projects p ON p.id = e.project_id,
           regexp_matches(s.docker_compose_raw, '(privileged:\s*true|docker\.sock|xmrig|minerd|cryptonight|stratum\+tcp|network_mode:\s*"?host|pid:\s*"?host|cap_add|- /:/|- /root|- /etc)', 'gi') AS m
      WHERE p.team_id IN (:tids) AND p.team_id <> 0
    UNION ALL SELECT 5, 'MEDIUM: service "' || s.name || '" runs a tunnel/proxy tool (' || m[1] || ')'
      FROM services s JOIN environments e ON e.id = s.environment_id JOIN projects p ON p.id = e.project_id,
           regexp_matches(s.docker_compose_raw, '(ngrok|cloudflared|frpc|frps|chisel|v2ray|xray|shadowsocks|tor:|socks5)', 'gi') AS m
      WHERE p.team_id IN (:tids) AND p.team_id <> 0
    UNION ALL SELECT 6, 'HIGH: application "' || a.name || '" uses a suspicious image/repository (' || coalesce(a.docker_registry_image_name, a.git_repository) || ')'
      FROM applications a JOIN environments e ON e.id = a.environment_id JOIN projects p ON p.id = e.project_id
      WHERE p.team_id IN (:tids) AND p.team_id <> 0
        AND coalesce(a.docker_registry_image_name, '') || ' ' || coalesce(a.git_repository, '') ~* '(xmrig|miner|monero|ngrok|cloudflared|frp|chisel|v2ray|xray|shadowsocks|proxy|tunnel)'
    UNION ALL SELECT 7, 'MEDIUM: ' || count(*) || ' scheduled task(s) - review the commands in section 12'
      FROM scheduled_tasks WHERE team_id IN (:tids) AND team_id <> 0 HAVING count(*) > 0
    UNION ALL SELECT 8, 'MEDIUM: API token "' || name || '" with abilities ' || abilities || coalesce(', last used ' || last_used_at::timestamp(0), ', never used')
      FROM personal_access_tokens WHERE tokenable_id = :uid
    UNION ALL SELECT 9, 'MEDIUM: session from an automated client (' || left(user_agent, 60) || ')'
      FROM sessions WHERE user_id = :uid AND user_agent ~* '(curl|wget|python|go-http|httpie|postman|scrapy|headless|bot)'
    UNION ALL SELECT 10, 'INFO: email address not verified'
      FROM users WHERE id = :uid AND email_verified_at IS NULL
    UNION ALL SELECT 11, 'INFO: public registration is open - anyone can create an account'
      FROM instance_settings WHERE id = 0 AND is_registration_enabled
) all_findings) findings ORDER BY o, finding;
ROLLBACK;
SQL
