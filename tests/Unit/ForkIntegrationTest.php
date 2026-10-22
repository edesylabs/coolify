<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\ServerSetting;

/**
 * Unit tests for the edesylabs fork's integration on top of upstream Coolify.
 *
 * Servers running fork releases before the upstream sync call
 * `upgrade.sh <version> <registry> <namespace>`, so the merged upgrade path must keep
 * that contract, use the fork's registry/namespace, and never fall back to upstream URLs.
 */
function forkFile(string $path): string
{
    return file_get_contents(__DIR__.'/../../'.$path);
}

it('keeps the fork upgrade.sh argument contract', function () {
    expect(forkFile('scripts/upgrade.sh'))
        ->toContain('LATEST_IMAGE=${1:-latest}')
        ->toContain('REGISTRY_URL="$2"')
        ->toContain('IMAGE_NAMESPACE=${3:-edesylabs}')
        ->toContain('SKIP_BACKUP=${4:-false}');
});

it('calls upgrade.sh with version, registry and namespace', function (string $file, string $expected) {
    expect(forkFile($file))->toContain($expected);
})->with([
    'install.sh' => ['scripts/install.sh', 'upgrade.sh "${LATEST_VERSION:-latest}" "${REGISTRY_URL:-ghcr.io}" "${IMAGE_NAMESPACE}" "true"'],
    'UpdateCoolify' => ['app/Actions/Server/UpdateCoolify.php', "escapeshellarg(config('constants.coolify.image_namespace'))"],
    'deploy.sh' => ['scripts/deploy.sh', 'bash "$SOURCE_DIR/upgrade.sh" "$VERSION" "$REGISTRY_URL" "$IMAGE_NAMESPACE"'],
]);

it('points upgraded servers at the fork and the upstream helper image', function () {
    expect(forkFile('scripts/upgrade.sh'))
        ->toContain('set_env_var "COOLIFY_IMAGE_NAMESPACE" "$IMAGE_NAMESPACE"')
        ->toContain('set_env_var "VERSIONS_URL" "$CDN/versions.json"')
        ->toContain('set_env_var "UPGRADE_SCRIPT_URL" "$CDN/scripts/upgrade.sh"')
        ->toContain('set_env_var "HELPER_IMAGE" "${REGISTRY_URL}/coollabsio/coolify-helper"');
});

it('resolves the coolify image from the configured namespace', function () {
    expect(forkFile('docker-compose.prod.yml'))
        ->toContain('"${REGISTRY_URL:-ghcr.io}/${COOLIFY_IMAGE_NAMESPACE:-edesylabs}/coolify:${LATEST_IMAGE:-latest}"');
});

it('defaults update checks and registry to the fork', function () {
    expect(forkFile('config/constants.php'))
        ->toContain("'registry_url' => env('REGISTRY_URL', 'ghcr.io')")
        ->toContain("'image_namespace' => env('COOLIFY_IMAGE_NAMESPACE', 'edesylabs')")
        ->toContain("'versions_url' => env('VERSIONS_URL', 'https://raw.githubusercontent.com/edesylabs/coolify/refs/heads/main/versions.json')")
        ->not->toContain('cdn.coollabs.io/coolify/versions.json');
});

it('switches instances to the ghcr.io registry where the fork image is published', function () {
    expect(forkFile('database/migrations/2026_10_22_000000_use_ghcr_registry_for_edesylabs_fork.php'))
        ->toContain("->default('ghcr.io')->change()")
        ->toContain("->where('docker_registry_url', 'docker.io')")
        ->toContain("->update(['docker_registry_url' => 'ghcr.io'])");
});

it('bumps the fork release number without touching other version keys', function () {
    expect(forkFile('.github/workflows/release.yml'))
        ->toContain('NEW_VERSION="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]}.$((BASH_REMATCH[4] + 1))"')
        ->toContain("0,/^[[:space:]]*'version' =>/")
        ->not->toContain('coolify-realtime');
});

it('does not release for script or markdown only changes', function () {
    expect(forkFile('.github/workflows/release.yml'))
        ->toContain('- "scripts/**"')
        ->toContain('- "**.md"');
});

it('lists Teams and Google Chat in the notification settings menu', function () {
    expect(forkFile('resources/views/components/notification/settings-layout.blade.php'))
        ->toContain("'route' => 'notifications.teams'")
        ->toContain("'route' => 'notifications.google-chat'");
});

it('allows mass assignment of fork columns', function (string $model, array $columns) {
    expect((new $model)->getFillable())->toContain(...$columns);
})->with([
    'server settings' => [ServerSetting::class, ['is_wildcard_ssl_enabled', 'wildcard_ssl_domain', 'dns_provider', 'dns_provider_credentials', 'acme_email', 'use_staging_acme', 'webhook_url', 'webhook_secret', 'webhook_enabled', 'orchestrator', 'kubernetes_config', 'kubernetes_namespace']],
    'application' => [Application::class, ['kubernetes_replicas', 'kubernetes_service_type']],
    'application settings' => [ApplicationSetting::class, ['is_dynamic_domain_enabled', 'is_wildcard_domain_enabled', 'wildcard_domain']],
]);

it('hides fork secrets from serialized server settings', function () {
    expect((new ServerSetting)->getHidden())
        ->toContain('dns_provider_credentials', 'webhook_secret', 'kubernetes_config');
});
