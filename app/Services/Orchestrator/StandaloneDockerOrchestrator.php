<?php

namespace App\Services\Orchestrator;

use App\Models\Application;

class StandaloneDockerOrchestrator implements OrchestratorInterface
{
    public function deploy(Application $application, string $image): bool
    {
        // Standalone Docker deployment is handled by existing deployment logic
        // This orchestrator is mainly for interface compliance
        // Actual deployment happens in ApplicationDeploymentJob
        return true;
    }

    public function scale(Application $application, int $replicas): bool
    {
        // Standalone Docker doesn't support scaling
        // Always 1 replica
        throw new \Exception('Scaling is not supported for standalone Docker deployments. Enable Docker Swarm or Kubernetes for scaling support.');
    }

    public function stop(Application $application): bool
    {
        $server = $application->destination->server;
        $containerName = $application->uuid;

        try {
            instant_remote_process([
                "docker stop {$containerName}"
            ], $server);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to stop application: " . $e->getMessage());
        }
    }

    public function restart(Application $application): bool
    {
        $server = $application->destination->server;
        $containerName = $application->uuid;

        try {
            instant_remote_process([
                "docker restart {$containerName}"
            ], $server);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to restart application: " . $e->getMessage());
        }
    }

    public function getStatus(Application $application): array
    {
        $server = $application->destination->server;
        $containerName = $application->uuid;

        try {
            $output = instant_remote_process([
                "docker inspect --format='{{.State.Status}}' {$containerName}"
            ], $server, false);

            $status = trim($output);
            $isRunning = $status === 'running';

            return [
                'running' => $isRunning ? 1 : 0,
                'desired' => 1,
                'status' => $status,
                'container_name' => $containerName,
            ];
        } catch (\Throwable $e) {
            return [
                'running' => 0,
                'desired' => 1,
                'status' => 'not_found',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getLogs(Application $application, int $lines = 100): string
    {
        $server = $application->destination->server;
        $containerName = $application->uuid;

        try {
            return instant_remote_process([
                "docker logs {$containerName} --tail {$lines}"
            ], $server, false);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to get logs: " . $e->getMessage());
        }
    }

    public function execute(Application $application, string $command): string
    {
        $server = $application->destination->server;
        $containerName = $application->uuid;

        try {
            return instant_remote_process([
                "docker exec {$containerName} {$command}"
            ], $server, false);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to execute command: " . $e->getMessage());
        }
    }

    public function getResources(Application $application): array
    {
        $server = $application->destination->server;
        $containerName = $application->uuid;

        try {
            $output = instant_remote_process([
                "docker stats {$containerName} --no-stream --format '{{.CPUPerc}}|{{.MemUsage}}'"
            ], $server, false);

            $parts = explode('|', trim($output));

            if (count($parts) >= 2) {
                return [
                    'cpu' => $parts[0],
                    'memory' => $parts[1],
                    'container' => $containerName,
                ];
            }

            return [
                'cpu' => '0%',
                'memory' => '0B / 0B',
                'container' => $containerName,
            ];
        } catch (\Throwable $e) {
            return [
                'cpu' => '0%',
                'memory' => '0B / 0B',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getType(): string
    {
        return 'standalone';
    }

    public function requiresRegistry(): bool
    {
        // Standalone Docker doesn't require a registry
        // Images can be built locally
        return false;
    }

    public function supportsAdditionalDestinations(): bool
    {
        // Standalone Docker supports additional destinations
        return true;
    }

    public function transformComposeFile(array $dockerCompose, Application $application): array
    {
        // Standalone Docker doesn't require any special transformations
        // The compose file is used as-is
        return $dockerCompose;
    }

    public function performRollingUpdate(Application $application, string $composePath): bool
    {
        // Standalone Docker uses docker-compose up with health checks
        // This is handled by the existing deployment logic
        return true;
    }

    public function performHealthCheck(Application $application): bool
    {
        $server = $application->destination->server;
        $containerName = $application->uuid;

        try {
            $output = instant_remote_process([
                "docker inspect --format='{{.State.Health.Status}}' {$containerName}"
            ], $server, false);

            $healthStatus = trim($output);

            // If no health check defined, check if container is running
            if ($healthStatus === '<no value>' || empty($healthStatus)) {
                $output = instant_remote_process([
                    "docker inspect --format='{{.State.Status}}' {$containerName}"
                ], $server, false);

                return trim($output) === 'running';
            }

            return $healthStatus === 'healthy';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
