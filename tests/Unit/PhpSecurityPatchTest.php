<?php

/**
 * Unit tests guarding the php2shell fix (CVE-2026-109618 / CVE-2026-109619) and the
 * fork's release/upgrade wiring that delivers patched images to servers.
 *
 * PHP < 8.4.27 is vulnerable to unauthenticated RCE. The production image builds on
 * upstream's hotfix image (PHP 8.4.27); development pins the patched serversideup v4.5.3.
 * serversideup v5 is avoided because it breaks Coolify's startup (upstream #12141).
 */
it('builds production on the patched upstream PHP hotfix image', function () {
    expect(file_get_contents(__DIR__.'/../../docker/production/Dockerfile'))
        ->toContain('ARG SERVERSIDEUP_PHP_IMAGE=ghcr.io/coollabsio/serversideup-php:8.4-fpm-nginx-alpine-v4.5.1-hotfix');
});

it('pins a patched serversideup base image for development', function () {
    expect(file_get_contents(__DIR__.'/../../docker/development/Dockerfile'))
        ->toContain('ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine-v4.5.3')
        ->not->toMatch('/^ARG SERVERSIDEUP_PHP_VERSION=8\.4-fpm-nginx-alpine-v4\.5\.[0-2]$/m');
});

it('pulls the MinIO client from a registry that still publishes it', function (string $dockerfile) {
    expect(file_get_contents(__DIR__.'/../../'.$dockerfile))->not->toContain('FROM minio/mc:');
})->with([
    'production' => 'docker/production/Dockerfile',
    'development' => 'docker/development/Dockerfile',
    'helper' => 'docker/coolify-helper/Dockerfile',
]);

it('installs a postgres client that exists on the Alpine 3.24 base image', function (string $dockerfile) {
    expect(file_get_contents(__DIR__.'/../../'.$dockerfile))
        ->toContain('ARG POSTGRES_VERSION=18')
        ->not->toContain('ARG POSTGRES_VERSION=15');
})->with([
    'production' => 'docker/production/Dockerfile',
    'development' => 'docker/development/Dockerfile',
]);

it('refreshes the base image when building in CI', function (string $workflow) {
    expect(file_get_contents(__DIR__.'/../../.github/workflows/'.$workflow))->toContain('pull: true');
})->with([
    'release' => 'release.yml',
    'manual build' => 'build-custom-images.yml',
]);

it('publishes a version tag for the coolify image on release', function () {
    expect(file_get_contents(__DIR__.'/../../.github/workflows/release.yml'))
        ->toContain('${{ matrix.image }}:${{ needs.bump-version.outputs.new_version }}');
});

it('downloads the fork scripts from the scripts directory', function (string $file, string $expected) {
    expect(file_get_contents(__DIR__.'/../../'.$file))->toContain($expected);
})->with([
    'install.sh upgrade.sh' => ['scripts/install.sh', 'curl -fsSL -L $CDN/scripts/upgrade.sh -o /data/coolify/source/upgrade.sh'],
    'install.sh upgrade-postgres.sh' => ['scripts/install.sh', 'curl -fsSL -L $CDN/scripts/upgrade-postgres.sh -o /data/coolify/source/upgrade-postgres.sh'],
    'upgrade.sh upgrade-postgres.sh' => ['scripts/upgrade.sh', 'curl -fsSL -L $CDN/scripts/upgrade-postgres.sh -o /data/coolify/source/upgrade-postgres.sh'],
    'default upgrade script url' => ['config/constants.php', "'upgrade_script_url' => env('UPGRADE_SCRIPT_URL', 'https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/scripts/upgrade.sh')"],
]);
