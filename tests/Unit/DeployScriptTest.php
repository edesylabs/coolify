<?php

/**
 * Unit tests for scripts/deploy.sh, the single install-or-update entrypoint.
 *
 * The script is exercised in DRY_RUN mode with stubbed docker/curl binaries so
 * scenario detection can be verified without root, Docker, or network access.
 */
function runDeployDryRun(bool $hasEnvFile, bool $hasDbVolume, string $version = ''): string
{
    $sandbox = sys_get_temp_dir().'/deploy-test-'.uniqid();
    mkdir("$sandbox/bin", 0777, true);
    mkdir("$sandbox/data/source", 0777, true);

    if ($hasEnvFile) {
        touch("$sandbox/data/source/.env");
    }

    file_put_contents("$sandbox/bin/docker", "#!/bin/bash\n".
        'case "$1 $2" in "volume ls") '.($hasDbVolume ? 'echo coolify-db' : 'true').';; "inspect coolify") exit 1;; esac'."\n");
    file_put_contents("$sandbox/bin/curl", "#!/bin/bash\necho '{ \"coolify\": { \"v4\": { \"version\": \"4.1.0-beta.4\" } } }'\n");
    chmod("$sandbox/bin/docker", 0755);
    chmod("$sandbox/bin/curl", 0755);

    $script = escapeshellarg(realpath(__DIR__.'/../../scripts/deploy.sh'));
    $env = 'PATH='.escapeshellarg("$sandbox/bin:".getenv('PATH')).' DRY_RUN=true COOLIFY_DATA_DIR='.escapeshellarg("$sandbox/data");

    $output = shell_exec("env $env bash $script ".escapeshellarg($version).' 2>&1');

    exec('rm -rf '.escapeshellarg($sandbox));

    return (string) $output;
}

it('chooses a fresh install on a new server', function () {
    expect(runDeployDryRun(hasEnvFile: false, hasDbVolume: false))
        ->toContain('Action         : install')
        ->toContain('Target version : 4.1.0-beta.4');
});

it('chooses a fresh install when a previous install stopped before creating the database', function () {
    expect(runDeployDryRun(hasEnvFile: true, hasDbVolume: false))->toContain('Action         : install');
});

it('chooses an update when Coolify is already installed', function () {
    expect(runDeployDryRun(hasEnvFile: true, hasDbVolume: true))->toContain('Action         : update');
});

it('normalises an explicitly requested version', function () {
    expect(runDeployDryRun(hasEnvFile: true, hasDbVolume: true, version: 'V4.1.0-BETA.2'))
        ->toContain('Target version : 4.1.0-beta.2');
});

it('snapshots the database and configuration before updating', function () {
    expect(file_get_contents(__DIR__.'/../../scripts/deploy.sh'))
        ->toContain('docker exec coolify-db pg_dump -U "$(db_user)" "$(db_name)" | gzip >"$SNAPSHOT_DIR/coolify-db.sql.gz"')
        ->toContain('gzip -t "$SNAPSHOT_DIR/coolify-db.sql.gz"')
        ->toContain('for file in .env docker-compose.yml docker-compose.prod.yml docker-compose.custom.yml; do');
});

it('refuses to update while deployments are queued or running', function () {
    expect(file_get_contents(__DIR__.'/../../scripts/deploy.sh'))
        ->toContain("SELECT count(*) FROM application_deployment_queues WHERE status IN ('queued', 'in_progress');")
        ->toContain('re-run with FORCE=true');
});

it('pulls the target image before changing anything', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy.sh');

    expect(strpos($script, 'docker pull -q "$target"'))->toBeLessThan(strpos($script, "\nsnapshot() {"))
        ->and($script)->toContain("    recover_unfinished_deploy\n    preflight_update\n    snapshot\n");
});

it('verifies migrations explicitly because the startup migration always exits 0', function () {
    expect(file_get_contents(__DIR__.'/../../scripts/deploy.sh'))
        ->toContain('docker exec coolify php artisan migrate --force --isolated --no-interaction')
        ->toContain('docker exec coolify php artisan migrate:status --pending=1 --no-interaction')
        ->toContain("verify_deployment\nverify_migrations\n");
});

it('rolls back configuration, database and image when an update fails', function () {
    expect(file_get_contents(__DIR__.'/../../scripts/deploy.sh'))
        ->toContain('if [ "$ROLLBACK_ARMED" = "true" ] && [ "$ROLLING_BACK" = "false" ]; then')
        ->toContain('DROP DATABASE IF EXISTS \\"$database\\" WITH (FORCE);')
        ->toContain('gunzip -c "$dump" | docker exec -i coolify-db psql -U "$user" -d "$database" -v ON_ERROR_STOP=1 -q')
        ->toContain('compose_up "${PREVIOUS_IMAGE##*:}"');
});

it('runs upgrade.sh with version, registry and namespace and checks the PHP version', function () {
    expect(file_get_contents(__DIR__.'/../../scripts/deploy.sh'))
        ->toContain('bash "$SOURCE_DIR/upgrade.sh" "$VERSION" "$REGISTRY_URL" "$IMAGE_NAMESPACE"')
        ->toContain('MIN_PHP_VERSION="${MIN_PHP_VERSION:-8.4.27}"');
});

it('waits for the background container restart of upgrade.sh before verifying or rolling back', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy.sh');

    expect($script)
        ->toContain("grep -q 'Container restart sequence started in background' \"\$UPGRADE_LOG\"")
        ->toContain("! grep -q 'Step 6/6: Upgrade complete' \"\$UPGRADE_LOG\"")
        ->toContain('Not rolling back while it runs')
        ->and(strpos($script, 'wait_for_restart || fail "upgrade.sh did not finish restarting the containers'))->toBeGreaterThan(strpos($script, 'bash "$SOURCE_DIR/upgrade.sh"'))
        ->and(strpos($script, 'if restart_in_progress; then', strpos($script, "\nrollback() {")))->toBeLessThan(strpos($script, 'if restore_snapshot; then'));
});

it('repairs an unfinished earlier run before updating, restoring only an incomplete database', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy.sh');

    expect($script)
        ->toContain("run_update() {\n    recover_unfinished_deploy\n    preflight_update\n")
        ->toContain('done | rolled-back | recovered) ;;')
        ->toContain("SELECT count(*) FROM pg_constraint WHERE conname = '\$last';")
        ->toContain("mark_snapshot done\nok \"Coolify \$VERSION deployed successfully\"")
        ->and(strpos($script, 'if database_complete "$unfinished"; then'))->toBeLessThan(strpos($script, 'restore_snapshot || fail "Automatic repair failed.'));
});

it('reports a pending repair in a dry run', function () {
    expect(file_get_contents(__DIR__.'/../../scripts/deploy.sh'))
        ->toContain('echo "  Repair first   : $(unfinished_snapshot) did not finish"');
});
