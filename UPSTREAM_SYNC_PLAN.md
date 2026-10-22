# Upstream Sync Plan — merge coollabsio/coolify `v4.4.7` into the edesylabs fork

**Status:** Planned · **Created:** 2026-10-22 · **Target:** upstream stable tag **`v4.4.7`** (2026-10-21)
**Related:** [PHP_SECURITY_PATCH_PLAN.md](PHP_SECURITY_PATCH_PLAN.md) · [UPGRADE_UI_AND_RELEASE_PLAN.md](UPGRADE_UI_AND_RELEASE_PLAN.md)

> All numbers below come from a dry-run merge (`git merge-tree --write-tree HEAD v4.4.7`) against fork `main` at `9a4276f66` (`4.1.0-beta.5`). Nothing has been merged yet. The `upstream` remote was added locally: `git remote add upstream https://github.com/coollabsio/coolify.git`.

---

## 1. Summary

| | |
|---|---|
| Fork point | `5b850ba56`, **v4.0.0-beta.444**, 2025-11-14 (~11 months ago) |
| Upstream since fork | **~3,091 commits** to `v4.4.7` (stable, out of beta), **143 new migrations** |
| Fork since fork | **43 commits** (≈36 excluding version bumps) |
| Dry-run conflicts | **39 files**: 33 content conflicts, 6 modify/delete |
| Framework | Same majors on both sides: PHP 8.4, Laravel 12 (`^12.20` → `12.69.3`), Livewire 3 (`3.6` → `3.8.7`), Horizon 5 |
| DB schema collisions | **None.** `webhook_url`/`webhook_enabled` exist on both sides but in different tables (ours: `server_settings`, upstream: `webhook_notification_settings`). |
| API route collisions | **None.** Our `/applications/{uuid}/domains`, `/ssl-status`, `/scale`, `/status`, `/resources` don't exist upstream. |

**Recommendation:** merge **`v4.4.7`** (stable tag, not upstream `main`/`next`) on a branch `sync/upstream-v4.4.7`, resolve as described in §5, test on a staging server with a copy of the production DB, then merge to `main`. Wherever upstream now offers a config/env hook for something we patched in code, **use the hook instead of re-applying our patch** (§4). That shrinks the fork's diff and makes future syncs much cheaper.

---

## 2. Our fork's features (what must survive the merge)

| Feature | Key fork files | Conflicts with upstream? |
|---|---|---|
| **Custom release & update pipeline** (GHCR `edesylabs/*`, `release.yml`, `deploy.sh`, fixed `install.sh`/`upgrade.sh`) | `.github/workflows/release.yml`, `scripts/*`, `UpdateCoolify`, `CheckForUpdatesJob`, `config/constants.php`, `docker-compose.prod.yml` | Yes. Upstream rewrote the update system (§3.2) |
| **Teams & Google Chat notifications** | `app/Notifications/Channels/{Teams,GoogleChat}*`, `SendsTeams`, `SendsGoogleChat`, `app/Models/{Teams,GoogleChat}NotificationSettings`, `app/Livewire/Notifications/{Teams,GoogleChat}`, 2 migrations, `Team.php`, 5 notification classes | Partially. Upstream has **no** Teams/Google Chat (still Email, Discord, Telegram, Slack, Pushover, Webhook), but the notification classes and nav changed |
| **Kubernetes / Orchestrator** | `app/Services/Orchestrator/*`, `KubernetesController`, `Livewire/Server/Orchestrator`, `Livewire/Project/Application/Kubernetes`, `Models/Kubernetes*`, migration `2025_10_28_000003` | Yes, in `ApplicationDeploymentJob` (hooks), routes, views |
| **Wildcard SSL & dynamic domains** | `Livewire/Server/WildcardSsl`, `proxy.php` (`writeDynamicConfigurationForApplication`, `removeDynamicConfigurationForApplication`), `CheckCertificateStatusJob`, `Domain*` events, `SendDomainProvisioningWebhook`, migrations `2025_10_28_000001/2`, `2025_11_24_*` | Yes. Upstream added its own **managed DNS + Domains pages** (§3.4) |
| **DNS providers** (Cloudflare, DigitalOcean, Route53) | `app/Services/DnsProviders/*`, `app/Contracts/DnsProviderInterface.php` | Overlap. Upstream has `DnsProviderZone`, `ManagedDnsRecord`, `ConfigureDnsRecordJob`, `CheckDomainDnsJob` |
| **Domain management API** | `ApplicationsController::add_domains/remove_domain/ssl_status`, `routes/api.php` | Content conflict only |
| **`<x-forms.domain-input>`** | `resources/views/components/forms/domain-input.blade.php` | **Add/add**: upstream created a different component with the **same name** (§3.5) |
| **Security/build fixes** from today | Dockerfiles, `release.yml` | Upstream already has equivalents (v5.0.2/PHP 8.4.27, `quay.io/minio/aistor/mc`, PG client 18) |

---

## 3. Upstream changes that affect the fork

### 3.1 `coolify-realtime` container is gone
Since 4.4, realtime runs **inside** the `coolify` container (`docker-compose.yml` keeps `coolify-realtime` only as a network alias), and **`docker/coolify-realtime/` is deleted upstream** (upstream `docker/` now has `coolify-helper`, `coolify-terminal`, `development`, `lima`, `production`, `testing-host`). Consequences:
- ⚠️ **Required in the same merge:** remove the `coolify-realtime` entry from `release.yml`'s matrix **and** the `build-realtime` job from `build-custom-images.yml`. Otherwise the first release after the merge fails (Dockerfile not found).
- Check whether `docker/coolify-terminal` is a separately deployed image upstream (it's not in `docker-compose.prod.yml`; likely dev/terminal tooling). Don't add it to the release matrix unless needed.
- `scripts/upgrade.sh`, `scripts/deploy.sh`, `.env` (`REALTIME_IMAGE`) references to the realtime image become dead. Remove them.

### 3.2 Update mechanism rewritten and now env-configurable
Upstream `config/constants.php` (`coolify` block):

```php
'version' => env('COOLIFY_VERSION') ?: '4.4.7',
'helper_version' => '1.0.17',
'registry_url' => env('REGISTRY_URL', 'docker.io'),
'helper_image' => env('HELPER_IMAGE', env('REGISTRY_URL', 'docker.io').'/coollabsio/coolify-helper'),
'cdn_url' => env('CDN_URL', 'https://cdn.coollabs.io'),
'versions_url' => env('VERSIONS_URL', env('CDN_URL', ...).'/coolify/versions.json'),
'upgrade_script_url' => env('UPGRADE_SCRIPT_URL', env('CDN_URL', ...).'/coolify/upgrade.sh'),
'releases_url' => env('RELEASES_URL', 'https://cdn.coollabs.io/coolify/releases.json'),
```

- `UpdateCoolify` now downloads `config('constants.coolify.upgrade_script_url')` and calls
  `upgrade.sh <version> <helper_version> <registry>`, plus an upgrade-status file and a lock (`ensureNoUpgradeIsRunning`).
- Upstream `upgrade.sh` args: **`$1` image, `$2` helper version, `$3` registry, `$4` skip_backup**. Default registry is now **`docker.io`**.
- **The `coollabsio` namespace is hard-coded** in `docker-compose.prod.yml` (`${REGISTRY_URL:-docker.io}/coollabsio/coolify:...`) and in `upgrade.sh` (helper image).

⚠️ **Fork argument contract differs:** our `upgrade.sh` is `$1` image, `$2` registry, `$3` namespace, `$4` skip_backup, and every fork server currently running `4.1.0-beta.x` calls it that way (old `UpdateCoolify`, `install.sh`, `deploy.sh`). See §6.2 for how to stay compatible.

### 3.3 `versions.json` schema expanded
Upstream reads `coolify.v4.version`, `coolify.helper.version`, `coolify.sentinel.version`, `coolify.nightly.version`, `coolify.sentinel-host.{version,digest}` and a `traefik` map (proxy upgrade hints). Ours only has `coolify.v4.version`, and `release.yml` only updates that key. We must keep the full upstream structure (§6.4).

### 3.4 Upstream managed DNS & Domains pages
New upstream tables `dns_provider_zones`, `managed_dns_records`, `managed_dns_record_references`, plus `domain_dns_statuses` and `domain_port_overrides` columns, and dedicated **Domains** pages (`project.application.domains`, `project.service.domains`). This **overlaps** our DNS-provider and dynamic-domain features. Decision **D3** (§4).

### 3.5 Upstream `<x-forms.domain-input>` (same name, different API)

| | Upstream | Ours |
|---|---|---|
| Props | `id`, `errorId`, `hostLabel`, `hostPlaceholder` | `id`, `label`, `helper`, `placeholder`, `required`, `disabled`, `readonly`, `canGate`, `canResource` |
| Shape | Protocol listbox + host + port grid | Single FQDN input with domain helpers |
| Used by | `application/domains`, `application/preview-domains`, `service/domains`, `storage/create`, `storage/form` | `application/general`, `service/service-application-view` (deleted upstream) |

Both cannot exist under the same name. See §5.4.

### 3.6 Files deleted/moved upstream that we modified (modify/delete)

| Our modified file | Upstream fate | Where our change goes |
|---|---|---|
| `.github/workflows/coolify-production-build.yml`, `coolify-staging-build.yml`, `coolify-realtime.yml`, `coolify-realtime-next.yml` | Deleted (replaced by `coolify-main-build.yml`, `coolify-next-build.yml`, `coolify-release.yml`, `coolify-rc-release.yml`, …) | **Accept deletion.** We only use `release.yml` |
| `app/Notifications/Container/ContainerStopped.php` | Deleted in `67b33c27a` (stopped-container handling moved into `GetContainersStatus` / restart-limit logic) | Re-add `toTeams()`/`toGoogleChat()` to whichever notification upstream now sends for that event (check `GetContainersStatus.php`). If none exists, drop |
| `resources/views/components/notification/navbar.blade.php` | Deleted in `d666a0a95`; replaced by `components/notification/settings-layout.blade.php` (array of `['label','route','icon']`) | Add two entries there: `Teams` → `notifications.teams`, `Google Chat` → `notifications.google-chat` |
| `resources/views/livewire/project/service/service-application-view.blade.php` | Deleted in `796bb3a19` (service UI refactored into `Project/Service/Index` + `service/domains`) | Our change was only switching to our `domain-input`. **Accept deletion**; upstream's `service/domains` page replaces it |

### 3.7 Also new upstream (no action, be aware)
`app/Mcp` (MCP server), `.agents/skills`, `.claude/skills`, `.cursor/skills`, `docs/v5`, ~980 new tests, 83 new service templates, new GitHub workflows (`claude.yml`, `pr-quality.yaml`, `sync-main-to-release.yml`, …). After the merge, **disable** any new upstream workflow we don't want (`gh workflow disable <file>`), the same way we disabled the 9 old ones.

---

## 4. Decisions to make before starting

| # | Decision | Options | Recommendation |
|---|---|---|---|
| **D1** | Upstream ref to merge | `v4.4.7` tag · upstream `main` · upstream `next` | **`v4.4.7`**. `main`/`next` are moving targets |
| **D2** | Fork version scheme after merge | Keep `4.1.0-beta.N` · adopt `4.4.7` then bump patch · **`4.4.7-edesy.N`** | **`4.4.7-edesy.1`**: shows the upstream base and keeps ordering (`version_compare('4.4.7-edesy.1','4.1.0-beta.5')` = newer; `4.4.8-edesy.1` > `4.4.7-edesy.9`). Verify with `php -r` before release. Requires a `release.yml` bump-regex change (§6.5) |
| **D3** | DNS / dynamic-domain overlap | Keep ours alongside upstream · migrate to upstream managed DNS and retire ours · keep only ours | **Keep ours for this sync** (data and API clients depend on it), and plan a separate evaluation of upstream managed DNS. Ensure both don't write Traefik dynamic config for the same app (§5.3) |
| **D4** | Helper image | Keep building `edesylabs/coolify-helper` · use upstream `coollabsio/coolify-helper:<versions.json helper>` | **Use upstream's helper.** Our helper diff was only the MinIO source fix, which upstream already has. One less image to build and publish |
| **D5** | Our `domain-input` component | Rename ours · drop ours and use upstream | **Rename ours to `<x-forms.fqdn-input>`**. Keeps `application/general` working with zero behavior change |
| **D6** | Kubernetes / Orchestrator | Keep · drop | Keep if in use. Confirm whether any production app uses `server->isKubernetes()`. If not, consider dropping to cut ~6 conflict hunks in `ApplicationDeploymentJob` |

---

## 5. Merge procedure & per-file resolution

### 5.1 Setup

```bash
git fetch upstream --tags
git switch main && git pull --ff-only
git switch -c sync/upstream-v4.4.7
git merge --no-ff v4.4.7 -m "chore: merge upstream coollabsio/coolify v4.4.7"
# resolve conflicts per §5.2–5.4, then:
git add -A && git commit
```

Tips: enable `git config rerere.enabled true` so future syncs reuse these resolutions. Work file by file with `git checkout --theirs <file>` (upstream) or `--ours <file>` (fork) as the starting point, then re-apply the other side's small change by hand.

### 5.2 Release, update & infra (16 files): start from **upstream**, re-apply fork hooks

| File | Hunks | Resolution |
|---|---|---|
| `.github/workflows/coolify-helper.yml`, `generate-changelog.yml` | 1, 1 | Take upstream (`--theirs`), keep disabled on GitHub (`gh workflow disable`) |
| `.github/workflows/coolify-production-build.yml`, `coolify-staging-build.yml`, `coolify-realtime.yml`, `coolify-realtime-next.yml` | m/d | `git rm`, accept upstream deletion |
| `docker/production/Dockerfile`, `docker/development/Dockerfile`, `docker/coolify-helper/Dockerfile` | 3, 2, 1 | **Take upstream.** It already has `serversideup/php:8.4-fpm-nginx-alpine-v5.0.2` (PHP 8.4.27), `quay.io/minio/aistor/mc`, `POSTGRES_VERSION=18`. Our fixes become redundant |
| `docker-compose.prod.yml` | 2 | Take upstream, then re-add the namespace variable: `"${REGISTRY_URL:-docker.io}/${COOLIFY_IMAGE_NAMESPACE:-coollabsio}/coolify:${LATEST_IMAGE:-latest}"` |
| `config/constants.php` | 1 | Take upstream. Fork values come from **env** (§6.1), not code. Only code change: keep `'github_repository'` if the UPGRADE_UI plan is implemented. Version per D2 |
| `versions.json` | 1 | Take upstream's **structure**, set `coolify.v4.version` to the fork version (§6.4) |
| `app/Actions/Server/UpdateCoolify.php` | 2 | Take upstream (keeps the lock + status file), then change only the remote call to the fork contract (§6.2) |
| `app/Jobs/CheckForUpdatesJob.php` | 1 | Take upstream. It reads `versions_url`, which we set via `VERSIONS_URL` env |
| `scripts/upgrade.sh` | 3 | Take upstream as base, re-apply: namespace support, `--pull always`, and the **fork arg contract** (§6.2) |
| `scripts/install.sh` | 3 | Take upstream as base, re-apply: `CDN` → `.../edesylabs/coolify/refs/heads/main`, download `$CDN/scripts/upgrade.sh`, call `upgrade.sh` with fork args, write fork env vars (§6.1) |

### 5.3 Fork features (17 files): start from **upstream**, re-insert fork code

| File | Hunks | Resolution |
|---|---|---|
| `app/Jobs/ApplicationDeploymentJob.php` | 6 | Take upstream (+3025/−880). Re-insert: (a) `if ($this->server->isKubernetes()) { $this->deploy_to_kubernetes(); return; }` at the deploy entry point, plus the `deploy_to_kubernetes()` method; (b) the `OrchestratorFactory::make(...)` calls (4×) at the equivalent start/stop/health points; (c) the `is_dynamic_domain_enabled` branch that filters Traefik labels and logs "Using dynamic domain management". **Also check upstream's new managed-DNS label/config path doesn't double-route the same app (D3).** Highest-risk file: review line by line |
| `bootstrap/helpers/proxy.php` | 1 | Keep upstream's +647 lines and **append** our two functions `writeDynamicConfigurationForApplication()` / `removeDynamicConfigurationForApplication()` (they're self-contained) |
| `bootstrap/helpers/shared.php` | 1 | Take upstream. Our change replaced the hard-coded `https://cdn.coollabs.io/coolify/versions.json` fetch with `config('constants.coolify.versions_url')`. Verify upstream's version of that function also uses `versions_url` (set via `VERSIONS_URL` env, §6.1); if not, re-apply that one line |
| `app/Http/Controllers/Api/ApplicationsController.php` | 6 | Take upstream, append our 3 methods `add_domains`, `remove_domain`, `ssl_status` (+349). Re-check helpers they call still exist (upstream refactored this controller heavily: +3427/−482). Consider moving them to a new `ApplicationDomainsController` to avoid future conflicts |
| `routes/api.php` | 1 | Take upstream, re-add `use App\Http\Controllers\Api\KubernetesController;` and our 6 routes (`POST/DELETE /applications/{uuid}/domains[/{domain}]`, `GET .../ssl-status`, `POST .../scale`, `GET .../status`, `GET .../resources`). Exact block: `git diff 5b850ba56 9a4276f66 -- routes/api.php` |
| `routes/web.php` | 2 | Take upstream, re-add `use` lines and routes: `notifications.teams`, `notifications.google-chat`, `project.application.kubernetes`, `server.orchestrator`, `server.wildcard-ssl` |
| `app/Models/ServerSetting.php` | 1 | Take upstream, re-add our 5 `$casts` entries: `is_wildcard_ssl_enabled` (boolean), `dns_provider_credentials` (`encrypted:array`), `use_staging_acme` (boolean), `webhook_secret` (`encrypted`), `webhook_enabled` (boolean). Also confirm the matching columns are in `$fillable` if upstream switched from `$guarded` |
| `app/Models/Team.php` | 2 | Take upstream, re-add: `implements ... SendsTeams, SendsGoogleChat` (+ `use` lines), `teamsNotificationSettings()->create()` / `googleChatNotificationSettings()->create()` in the `created` hook, the two relations, and `routeNotificationForTeams()` / `routeNotificationForGoogleChat()`. Check upstream's notification-enabled aggregation helpers include the two new channels |
| `app/Providers/AuthServiceProvider.php` | 1 | Take upstream, re-add `TeamsNotificationSettings::class` and `GoogleChatNotificationSettings::class` → `NotificationPolicy::class` |
| `app/Livewire/Project/Application/Advanced.php` + `advanced.blade.php` | 1, 1 | Take upstream, re-add `is_dynamic_domain_enabled` property/checkbox (+37 / +5) |
| `resources/views/livewire/project/application/configuration.blade.php` | 1 | Take upstream, re-add the **Kubernetes Configuration** menu link and the `@elseif ($currentRoute === 'project.application.kubernetes' ...)` branch rendering `<livewire:project.application.kubernetes>` (both guarded by `isKubernetes()`, +7) |
| `resources/views/livewire/project/application/general.blade.php` | 2 | Take upstream. Our only change swapped `x-forms.input` → our `domain-input` for the app `fqdn` field and the compose `parsedServiceDomains.*.domain` fields. Upstream moved domains to its **Domains page**: if those fields are gone from `general`, drop our change; if still present, use `<x-forms.fqdn-input>` (D5) |
| `resources/views/components/server/sidebar.blade.php` | 1 | Take upstream, re-add the **Wildcard SSL** link (`route('server.wildcard-ssl')`, +3). Note: `server.orchestrator` has a route but no sidebar link today. Add one if D6 keeps Kubernetes |
| `app/Notifications/Container/ContainerRestarted.php`, `Internal/GeneralNotification.php`, `Server/ForceDisabled.php`, `Server/ForceEnabled.php`, `Server/HighDiskUsage.php` | 1 each | Take upstream, re-add our `toTeams()` and `toGoogleChat()` methods and the channel entries in `via()`. Mechanical: copy from our version |

### 5.4 Add/add and modify/delete

| File | Resolution |
|---|---|
| `resources/views/components/forms/domain-input.blade.php` | **Take upstream** (used by 5 upstream views). `git mv` our version to `resources/views/components/forms/fqdn-input.blade.php` and replace `<x-forms.domain-input` → `<x-forms.fqdn-input` in **our** call sites only (D5) |
| `app/Notifications/Container/ContainerStopped.php` | Accept deletion (§3.6) |
| `resources/views/components/notification/navbar.blade.php` | Accept deletion; add Teams + Google Chat to `components/notification/settings-layout.blade.php` (§3.6) |
| `resources/views/livewire/project/service/service-application-view.blade.php` | Accept deletion (§3.6) |

---

## 6. Fork integration changes (needed even where git reports no conflict)

### 6.1 Configure the fork through env instead of code
Set these in `.env.production` (merged into every server's `.env` by `install.sh`/`upgrade.sh`) **and** in `deploy.sh`'s `set_env_var` list:

| Env var | Value | Replaces fork code change in |
|---|---|---|
| `REGISTRY_URL` | `ghcr.io` | constants default |
| `COOLIFY_IMAGE_NAMESPACE` | `edesylabs` | compose / upgrade.sh |
| `VERSIONS_URL` | `https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/versions.json` | our `COOLIFY_VERSIONS_URL` |
| `UPGRADE_SCRIPT_URL` | `https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/upgrade.sh` | our `UpdateCoolify` CDN path fix |
| `HELPER_IMAGE` | `docker.io/coollabsio/coolify-helper` (D4) or `ghcr.io/edesylabs/coolify-helper` | our `HELPER_IMAGE` |
| `RELEASES_URL` | leave upstream (changelog feed) or point to fork | — |

Legacy fork vars to **keep reading for one release** (transition), then remove: `COOLIFY_CDN`, `COOLIFY_VERSIONS_URL`, `REALTIME_IMAGE`.

### 6.2 `upgrade.sh` argument compatibility (critical for already-deployed servers)
Servers on `4.1.0-beta.x` run the **old** `UpdateCoolify`, which downloads `$COOLIFY_CDN/scripts/upgrade.sh` from our `main` and calls:
`upgrade.sh <version> <registry> <namespace>`
The merged `upgrade.sh` **must accept this order**. Keep the fork contract:

```
upgrade.sh <image-version> [registry] [namespace] [skip_backup]
```

- Read the helper version **inside** the script (from `versions.json` `coolify.helper.version`, falling back to `latest`) instead of taking it as `$2`.
- In the merged `UpdateCoolify::update()`, call it the fork way:
  `'bash /data/coolify/source/upgrade.sh '.escapeshellarg($this->latestVersion).' '.escapeshellarg($registryUrl).' '.escapeshellarg(config('constants.coolify.image_namespace'))`
  and add `'image_namespace' => env('COOLIFY_IMAGE_NAMESPACE', 'coollabsio')` to `config/constants.php`.
- Keep upstream's status-file/lock logic in `upgrade.sh`. It's what `ensureNoUpgradeIsRunning()` reads.

### 6.3 Helper image references
Upstream hard-codes `coollabsio/coolify-helper` in `upgrade.sh` (lines ~205, ~301). Replace with `${HELPER_IMAGE}` (from `.env`) so D4 is a pure config choice.

### 6.4 `versions.json`
Adopt upstream's full file (helper, sentinel, sentinel-host, traefik, nightly). The fork owns only `coolify.v4.version`. Update `release.yml`'s jq step to touch **only** that key (it already does: `.coolify.v4.version = $ver`), and never overwrite the file wholesale. On future syncs, merge upstream's `traefik`/`helper`/`sentinel` values in.

### 6.5 `release.yml`
- **Required:** remove the `coolify-realtime` matrix entry (§3.1); `docker/coolify-realtime/Dockerfile` no longer exists. Remove `coolify-helper` too if D4 = upstream helper.
- Same for `build-custom-images.yml` (`build-realtime` job).
- Bump logic for D2 (`X.Y.Z-edesy.N`): add a branch to the version regex:
  ```bash
  elif [[ "$CURRENT" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)-edesy\.([0-9]+)$ ]]; then
    # patch bump -> edesy.N+1 ; upstream sync sets X.Y.Z manually and resets N=1
  ```
- Keep `pull: true` and `fail-fast: false`.
- Add `scripts/**`, `**.md`, `docs/**`, `.agents/**`, `.claude/**`, `.cursor/**` to `paths-ignore` (see UPGRADE_UI plan §2.4).

### 6.6 `scripts/deploy.sh`
- Remove realtime handling (`REALTIME_IMAGE`). Add `VERSIONS_URL`, `UPGRADE_SCRIPT_URL` to `set_env_var`.
- The image check `ghcr.io/edesylabs/coolify:$VERSION` stays valid.
- Health check: upstream still uses `/api/health` on 8080. Re-verify after merge.

### 6.7 Fork tests to update
- `tests/Unit/PhpSecurityPatchTest.php`: Dockerfile pin assertion expects `-v4.5.3`. Change to assert a **patched** tag (`v5.0.2` or `v4.5.3`), or assert `MIN_PHP_VERSION` via the image at deploy time instead. Remove the realtime/helper assumptions if D4 changes the matrix.
- `tests/Unit/DeployScriptTest.php`: still valid. Add a case for the new env vars.
- Add tests for: `upgrade.sh` accepting `<version> <registry> <namespace>` (§6.2), `docker-compose.prod.yml` containing `${COOLIFY_IMAGE_NAMESPACE`, `settings-layout` containing Teams/Google Chat routes.

---

## 7. Database
- **143** upstream migrations will run on first boot of the merged image, plus our 8 (already applied on production).
- Migrations are ordered by filename. Upstream's `2025_11_16_*` sorts before our `2025_11_24_*`, but since ours already ran, Laravel just runs the missing upstream ones. Safe.
- **Not reversible in practice:** once the 4.4.7 schema is applied, rolling back to `4.1.0-beta.5` requires restoring the DB backup. `deploy.sh` takes one automatically (`/data/coolify/backups/deploy/`). Keep it until the merged version is confirmed stable.

---

## 8. Testing

1. **Locally** (Docker running):
   - `docker build -f docker/production/Dockerfile .` builds.
   - `./vendor/bin/pest tests/Unit`, `docker exec coolify php artisan test` (feature suite, ~980 new upstream tests) pass.
   - `./vendor/bin/pint --dirty`, `./vendor/bin/phpstan`.
2. **Push the branch only** (no release: `release.yml` triggers on `main` only). Build an image manually: `gh workflow run build-custom-images.yml -f tag=sync-test --ref sync/upstream-v4.4.7` (after removing its `build-realtime` job, §6.5).
3. **Staging server** with a **copy of the production DB**:
   - Install `4.1.0-beta.5` via `deploy.sh 4.1.0-beta.5`, restore the prod DB dump.
   - Upgrade with `deploy.sh sync-test` → migrations run, container healthy, PHP ≥ 8.4.27.
   - Also test the **in-app Upgrade button** path from `4.1.0-beta.5` (old `UpdateCoolify` → new `upgrade.sh`, §6.2).
4. **Smoke-test fork features:** deploy an app, Teams + Google Chat test notification, wildcard SSL page, dynamic-domain app, domain API (`POST/DELETE /api/v1/applications/{uuid}/domains`, `GET /ssl-status`), Kubernetes page (D6), server sidebar links.
5. **Smoke-test upstream features we now get:** Domains page, managed DNS (with D3 caution), notifications settings layout.

---

## 9. Rollout & rollback
1. Merge `sync/upstream-v4.4.7` → `main` (squash **not** recommended; keep the merge commit so future syncs have the right merge-base).
2. `release.yml` publishes `4.4.7-edesy.1` (D2).
3. Production: `curl -fsSL .../scripts/deploy.sh | bash` (backs up DB, upgrades, verifies).
4. **Rollback:** `deploy.sh 4.1.0-beta.5` **and** restore the DB from `/data/coolify/backups/deploy/coolify-db-<ts>.sql.gz`:
   ```bash
   gunzip -c /data/coolify/backups/deploy/coolify-db-<ts>.sql.gz | docker exec -i coolify-db psql -U coolify coolify
   ```

---

## 10. Keeping in sync afterwards
- Sync **every upstream stable tag** (roughly monthly): `git fetch upstream --tags && git switch -c sync/upstream-vX.Y.Z && git merge vX.Y.Z`. With `rerere` enabled and the env-based config from §6.1, most syncs should only conflict in `versions.json`/`constants.php`.
- Optional automation: a scheduled workflow that detects a new upstream tag and opens a PR `sync/upstream-<tag>` (with conflict markers listed in the PR body if the merge isn't clean).
- Reduce the fork surface over time: upstream the self-contained features (Teams/Google Chat channels are good candidates); move fork-only code into fork-only files (e.g. `ApplicationDomainsController`, `fqdn-input`) rather than editing shared ones.

---

## 11. Checklist

- [ ] Decisions D1–D6 recorded
- [ ] Branch `sync/upstream-v4.4.7` created, `rerere` enabled
- [ ] §5.2 release/infra files resolved
- [ ] §5.3 fork feature files resolved (`ApplicationDeploymentJob` reviewed line by line)
- [ ] §5.4 add/add + modify/delete resolved (`fqdn-input` rename, settings-layout entries)
- [ ] §6.1 env vars in `.env.production` + `deploy.sh`
- [ ] §6.2 `upgrade.sh` fork arg contract + `UpdateCoolify` call + `image_namespace` config
- [ ] §6.3 helper image via `${HELPER_IMAGE}`
- [ ] §6.4 `versions.json` full upstream structure
- [ ] §6.5 `release.yml` matrix (**realtime removed**), version regex, `paths-ignore`; `build-custom-images.yml` realtime job removed
- [ ] §6.6 `deploy.sh` realtime removal / env additions
- [ ] §6.7 tests updated + new tests
- [ ] New upstream workflows reviewed and disabled where unwanted
- [ ] Local build + unit + feature tests green
- [ ] Staging: fresh install, `deploy.sh` upgrade, **and** in-app Upgrade from `4.1.0-beta.5`
- [ ] Fork features smoke-tested
- [ ] Merged to `main`, released, production upgraded, PHP ≥ 8.4.27 confirmed
