<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

const TEST_HELPER_IMAGE = 'ghcr.io/coollabsio/coolify-helper:1.0.17';

class EnsureHelperImageDeploymentJob extends ApplicationDeploymentJob
{
    public string $remoteOutput = '';

    public array $remoteCommands = [];

    public function __construct() {}

    public function execute_remote_command(...$commands)
    {
        $this->remoteCommands = $commands;
        $savedOutputs = new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs');
        $savedOutputs->getValue($this)->put($commands[0]['save'], str($this->remoteOutput));
    }
}

/**
 * Runs the generated command with a fake `docker` on PATH. The fake records each call as
 * "<DOCKER_CONFIG>|<args>" and behaves according to $mode.
 *
 * @param  'present'|'pull'|'stale-credentials'|'denied'  $mode
 * @return array{exit: int, output: string, calls: list<string>}
 */
function runEnsureHelperImageCommand(string $mode): array
{
    $binDirectory = sys_get_temp_dir().'/fake-docker-'.uniqid();
    mkdir($binDirectory);
    $log = "{$binDirectory}/calls.log";
    file_put_contents("{$binDirectory}/docker", <<<'SH'
#!/bin/sh
echo "${DOCKER_CONFIG-}|$*" >> "$FAKE_DOCKER_LOG"
if [ "$1" = image ]; then
    [ "$FAKE_DOCKER_MODE" = present ]
    exit $?
fi
anonymous=false
if [ -n "${DOCKER_CONFIG-}" ] && [ -d "$DOCKER_CONFIG" ] && [ -z "$(ls -A "$DOCKER_CONFIG")" ]; then
    anonymous=true
fi
case "$FAKE_DOCKER_MODE" in
    pull) exit 0 ;;
    stale-credentials) [ "$anonymous" = true ] && exit 0 ;;
esac
echo "denied: denied (anonymous=$anonymous)" >&2
exit 1
SH);
    chmod("{$binDirectory}/docker", 0755);

    $process = Process::fromShellCommandline(ensureHelperImageCommand(TEST_HELPER_IMAGE), env: [
        'PATH' => $binDirectory.':'.getenv('PATH'),
        'FAKE_DOCKER_LOG' => $log,
        'FAKE_DOCKER_MODE' => $mode,
    ]);
    $process->run();
    $calls = file_exists($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    array_map('unlink', glob("{$binDirectory}/*"));
    rmdir($binDirectory);

    return ['exit' => $process->getExitCode(), 'output' => trim($process->getOutput()), 'calls' => $calls];
}

/**
 * @return array{0: EnsureHelperImageDeploymentJob, 1: MockInterface}
 */
function makeEnsureHelperImageJob(string $remoteOutput): array
{
    $job = new EnsureHelperImageDeploymentJob;
    $job->remoteOutput = $remoteOutput;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->setValue($job, new Collection);
    (new ReflectionProperty(ApplicationDeploymentJob::class, 'application_deployment_queue'))->setValue($job, $queue);

    return [$job, $queue];
}

function invokeEnsureHelperImage(EnsureHelperImageDeploymentJob $job): void
{
    (new ReflectionMethod(ApplicationDeploymentJob::class, 'ensure_helper_image'))->invoke($job, TEST_HELPER_IMAGE);
}

afterEach(function () {
    Mockery::close();
});

test('does not pull when the helper image is already on the server', function () {
    $result = runEnsureHelperImageCommand('present');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe(HELPER_IMAGE_PRESENT)
        ->and($result['calls'])->toBe(['|image inspect '.TEST_HELPER_IMAGE]);
});

test('pulls with the stored credentials when they work', function () {
    $result = runEnsureHelperImageCommand('pull');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe(HELPER_IMAGE_PULLED)
        ->and($result['calls'])->toHaveCount(2);
});

test('pulls without credentials when the stored login is denied', function () {
    $result = runEnsureHelperImageCommand('stale-credentials');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toEndWith(HELPER_IMAGE_PULLED_ANONYMOUSLY)
        ->and($result['output'])->toContain('denied: denied (anonymous=false)')
        ->and($result['calls'])->toHaveCount(3);

    $anonymousConfig = explode('|', $result['calls'][2])[0];
    expect($anonymousConfig)->not->toBeEmpty()
        ->and(is_dir($anonymousConfig))->toBeFalse();
});

test('fails with both pull errors when the image cannot be pulled at all', function () {
    $result = runEnsureHelperImageCommand('denied');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('Pull with stored credentials: denied: denied (anonymous=false)')
        ->and($result['output'])->toContain('Pull without credentials: denied: denied (anonymous=true)')
        ->and($result['output'])->not->toContain(HELPER_IMAGE_PULLED_ANONYMOUSLY);
});

test('runs as one root shell script for non-root SSH users', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->user = 'ubuntu';
    $command = ensureHelperImageCommand(TEST_HELPER_IMAGE);

    expect(parseLineForSudo($command, $server))->toBe("sudo {$command}")
        ->and(parseCommandsByLineForSudo(collect([$command]), $server))->toBe(["sudo {$command}"]);
});

test('deployment continues silently when the helper image is present or pulled', function (string $marker) {
    [$job, $queue] = makeEnsureHelperImageJob($marker);
    $queue->shouldNotReceive('addLogEntry');

    invokeEnsureHelperImage($job);

    expect($job->remoteCommands[0]['command'])->toBe(ensureHelperImageCommand(TEST_HELPER_IMAGE))
        ->and($job->remoteCommands[0]['ignore_errors'])->toBeTrue();
})->with([HELPER_IMAGE_PRESENT, HELPER_IMAGE_PULLED]);

test('deployment warns about stale registry credentials after an anonymous pull', function () {
    [$job, $queue] = makeEnsureHelperImageJob("denied: denied\n".HELPER_IMAGE_PULLED_ANONYMOUSLY);
    $queue->shouldReceive('addLogEntry')->once()->withArgs(fn (string $message, string $type) => str_contains($message, 'pulled without credentials') && $type === 'stderr');

    invokeEnsureHelperImage($job);
});

test('deployment fails with the pull errors when the helper image cannot be pulled', function () {
    [$job] = makeEnsureHelperImageJob("Pull with stored credentials: denied: denied\nPull without credentials: denied: denied");

    expect(fn () => invokeEnsureHelperImage($job))
        ->toThrow(DeploymentException::class, 'Pull without credentials: denied: denied');
});
