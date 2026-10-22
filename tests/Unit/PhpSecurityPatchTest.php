<?php

/**
 * Unit tests guarding the php2shell fix (CVE-2026-109618 / CVE-2026-109619).
 *
 * PHP < 8.4.27 is vulnerable to unauthenticated RCE. These tests ensure the
 * Docker base image stays pinned to a patched serversideup release and that
 * curl-installed servers actually download and pull the patched image.
 */
it('pins a patched serversideup base image in the Dockerfiles', function (string $dockerfile) {
    $contents = file_get_contents(__DIR__.'/../../'.$dockerfile);

    expect($contents)
        ->toContain('ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine-v4.5.3')
        ->not->toMatch('/^ARG SERVERSIDEUP_PHP_VERSION=8\.4-fpm-nginx-alpine$/m');
})->with([
    'production' => 'docker/production/Dockerfile',
    'development' => 'docker/development/Dockerfile',
]);

it('pulls the MinIO client from quay.io instead of the removed Docker Hub image', function (string $dockerfile) {
    $contents = file_get_contents(__DIR__.'/../../'.$dockerfile);

    expect($contents)
        ->toContain('FROM quay.io/minio/aistor/mc:${MINIO_VERSION} AS minio-client')
        ->not->toContain('FROM minio/mc:');
})->with([
    'production' => 'docker/production/Dockerfile',
    'development' => 'docker/development/Dockerfile',
    'helper' => 'docker/coolify-helper/Dockerfile',
]);

it('refreshes the base image when building in CI', function (string $workflow) {
    expect(file_get_contents(__DIR__.'/../../.github/workflows/'.$workflow))->toContain('pull: true');
})->with([
    'release' => 'release.yml',
    'manual build' => 'build-custom-images.yml',
]);

it('publishes a version tag for the coolify image on release', function () {
    $workflow = file_get_contents(__DIR__.'/../../.github/workflows/release.yml');

    expect($workflow)->toContain('${{ matrix.image }}:${{ needs.bump-version.outputs.new_version }}');
});

it('downloads upgrade.sh from the scripts directory', function (string $file, string $expected) {
    expect(file_get_contents(__DIR__.'/../../'.$file))->toContain($expected);
})->with([
    'install.sh' => ['scripts/install.sh', 'curl -fsSL $CDN/scripts/upgrade.sh -o /data/coolify/source/upgrade.sh'],
    'UpdateCoolify' => ['app/Actions/Server/UpdateCoolify.php', 'curl -fsSL $cdn/scripts/upgrade.sh -o /data/coolify/source/upgrade.sh'],
]);

it('passes registry and namespace to upgrade.sh in the expected order', function () {
    $install = file_get_contents(__DIR__.'/../../scripts/install.sh');

    expect($install)
        ->toContain('upgrade.sh "${LATEST_VERSION:-latest}" "${REGISTRY_URL:-ghcr.io}" "${COOLIFY_IMAGE_NAMESPACE:-edesylabs}" "true"')
        ->not->toContain('"${LATEST_HELPER_VERSION:-latest}" "${REGISTRY_URL:-ghcr.io}" "true"');
});

it('always pulls the coolify image when upgrading', function () {
    $upgrade = file_get_contents(__DIR__.'/../../scripts/upgrade.sh');

    expect(substr_count($upgrade, 'up -d --pull always'))->toBe(2);
});
