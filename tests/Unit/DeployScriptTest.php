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

it('backs up the database and verifies the PHP version on update', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy.sh');

    expect($script)
        ->toContain('docker exec coolify-db pg_dump -U coolify coolify')
        ->toContain('MIN_PHP_VERSION="${MIN_PHP_VERSION:-8.4.27}"')
        ->toContain('bash "$DATA_DIR/source/upgrade.sh" "$VERSION" "$REGISTRY_URL" "$IMAGE_NAMESPACE"');
});
