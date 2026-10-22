<?php

/**
 * Unit tests for scripts/inspect-user.sh, the read-only user activity report.
 */
function inspectUserScript(): string
{
    return file_get_contents(__DIR__.'/../../scripts/inspect-user.sh');
}

it('only reads the database', function () {
    $script = inspectUserScript();

    expect(substr_count($script, 'BEGIN READ ONLY;'))->toBe(2)
        ->and($script)->not->toMatch('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER)\s/');
});

it('flags root team access, host or private server addresses and dangerous compose settings', function () {
    expect(inspectUserScript())
        ->toContain('FROM team_user WHERE user_id = :uid AND user_id <> 0 AND team_id = 0')
        ->toContain("ip = ANY (:'hostips'::text[])")
        ->toContain('privileged:\s*true|docker\.sock|xmrig');
});

it('limits the per-user report to the teams the user belongs to', function () {
    expect(inspectUserScript())
        ->toContain("SELECT coalesce(string_agg(team_id::text, ','), '-1') AS tids FROM team_user WHERE user_id = :uid \\gset")
        ->toContain('FROM servers WHERE team_id IN (:tids);');
});
