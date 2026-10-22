<?php

/**
 * Unit tests for scripts/restore.sh, which restores the Coolify database from a deploy.sh snapshot.
 */
function restoreScript(): string
{
    return file_get_contents(__DIR__.'/../../scripts/restore.sh');
}

it('skips snapshots whose dump has no users when choosing one', function () {
    expect(restoreScript())
        ->toContain('$1 == "COPY" && $2 == "public.users"')
        ->toContain('[ "${users:-0}" -gt 0 ] && echo "$dir" && return');
});

it('refuses to restore a snapshot made with another APP_KEY', function () {
    expect(restoreScript())->toContain('APP_KEY in $ENV_FILE differs from the snapshot');
});

it('asks for confirmation and saves the current database before replacing it', function () {
    $script = restoreScript();

    expect(strpos($script, "\nconfirm\n"))->toBeLessThan(strpos($script, 'before-restore-'))
        ->and(strpos($script, 'before-restore-'))->toBeLessThan(strpos($script, 'DROP DATABASE IF EXISTS'));
});

it('verifies the restored users and applies migrations explicitly', function () {
    expect(restoreScript())
        ->toContain('[ "$RESTORED_USERS" = "$SNAPSHOT_USERS" ]')
        ->toContain('docker exec coolify php artisan migrate --force --isolated --no-interaction');
});
