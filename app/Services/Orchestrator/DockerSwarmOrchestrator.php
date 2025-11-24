<?php

namespace App\Services\Orchestrator;

use App\Models\Application;

class DockerSwarmOrchestrator implements OrchestratorInterface
{
    public function deploy(Application $application, string $image): bool
    {
        // Docker Swarm deployment is handled by ApplicationDeploymentJob
        // which generates docker-compose.yml with deploy section and uses:
        // docker stack deploy --detach=true --with-registry-auth -c docker-compose.yml {$application->uuid}
        // This orchestrator is mainly for interface compliance
        return true;
    }

    public function scale(Application $application, int $replicas): bool
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            instant_remote_process([
                "docker service scale {$serviceName}={$replicas}"
            ], $server);

            // Update application's swarm_replicas field
            $application->swarm_replicas = $replicas;
            $application->save();

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to scale service: " . $e->getMessage());
        }
    }

    public function stop(Application $application): bool
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            // Scale to 0 replicas to stop all tasks
            instant_remote_process([
                "docker service scale {$serviceName}=0"
            ], $server);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to stop service: " . $e->getMessage());
        }
    }

    public function restart(Application $application): bool
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            // Force update with no changes triggers restart
            instant_remote_process([
                "docker service update --force {$serviceName}"
            ], $server);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to restart service: " . $e->getMessage());
        }
    }

    public function getStatus(Application $application): array
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            // Get service information
            $output = instant_remote_process([
                "docker service ls --filter 'name={$serviceName}' --format '{{json .}}'"
            ], $server, false);

            if (empty(trim($output))) {
                return [
                    'running' => 0,
                    'desired' => data_get($application, 'swarm_replicas', 1),
                    'status' => 'not_found',
                ];
            }

            $serviceInfo = json_decode($output, true);
            $replicas = data_get($serviceInfo, 'Replicas', '0/0');
            [$running, $desired] = explode('/', $replicas);

            // Get detailed task status
            $tasksOutput = instant_remote_process([
                "docker service ps {$serviceName} --format '{{json .}}' --filter 'desired-state=running'"
            ], $server, false);

            $tasks = [];
            if (!empty(trim($tasksOutput))) {
                $lines = explode("\n", trim($tasksOutput));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $tasks[] = json_decode($line, true);
                    }
                }
            }

            $runningTasks = count(array_filter($tasks, function ($task) {
                return data_get($task, 'CurrentState', '') === 'Running';
            }));

            return [
                'running' => (int)$running,
                'desired' => (int)$desired,
                'status' => $runningTasks === (int)$desired ? 'running' : 'updating',
                'service_name' => $serviceName,
                'tasks' => $tasks,
                'mode' => data_get($serviceInfo, 'Mode', 'replicated'),
            ];
        } catch (\Throwable $e) {
            return [
                'running' => 0,
                'desired' => data_get($application, 'swarm_replicas', 1),
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getLogs(Application $application, int $lines = 100): string
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            return instant_remote_process([
                "docker service logs {$serviceName} --tail {$lines}"
            ], $server, false);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to get logs: " . $e->getMessage());
        }
    }

    public function execute(Application $application, string $command): string
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            // Get first running task/container ID
            $taskId = instant_remote_process([
                "docker service ps {$serviceName} -q --filter 'desired-state=running' | head -1"
            ], $server, false);

            $taskId = trim($taskId);

            if (empty($taskId)) {
                throw new \Exception("No running tasks found for service");
            }

            // Get container ID from task
            $containerId = instant_remote_process([
                "docker inspect --format '{{.Status.ContainerStatus.ContainerID}}' {$taskId}"
            ], $server, false);

            $containerId = trim($containerId);

            if (empty($containerId)) {
                throw new \Exception("Could not find container for task");
            }

            return instant_remote_process([
                "docker exec {$containerId} {$command}"
            ], $server, false);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to execute command: " . $e->getMessage());
        }
    }

    public function getResources(Application $application): array
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            // Get all running tasks
            $tasksOutput = instant_remote_process([
                "docker service ps {$serviceName} --format '{{.ID}}' --filter 'desired-state=running'"
            ], $server, false);

            if (empty(trim($tasksOutput))) {
                return [
                    'cpu' => '0%',
                    'memory' => '0B / 0B',
                    'tasks' => 0,
                ];
            }

            $taskIds = explode("\n", trim($tasksOutput));
            $totalCpu = 0.0;
            $totalMemoryUsed = 0;
            $totalMemoryLimit = 0;
            $validTasks = 0;

            foreach ($taskIds as $taskId) {
                $taskId = trim($taskId);
                if (empty($taskId)) {
                    continue;
                }

                try {
                    // Get container ID for task
                    $containerId = instant_remote_process([
                        "docker inspect --format '{{.Status.ContainerStatus.ContainerID}}' {$taskId}"
                    ], $server, false);

                    $containerId = trim($containerId);
                    if (empty($containerId)) {
                        continue;
                    }

                    // Get stats for container
                    $output = instant_remote_process([
                        "docker stats {$containerId} --no-stream --format '{{.CPUPerc}}|{{.MemUsage}}'"
                    ], $server, false);

                    $parts = explode('|', trim($output));

                    if (count($parts) >= 2) {
                        // Parse CPU (e.g., "1.23%")
                        $cpu = floatval(str_replace('%', '', $parts[0]));
                        $totalCpu += $cpu;

                        // Parse Memory (e.g., "100MiB / 2GiB")
                        $memory = $parts[1];
                        if (preg_match('/([0-9.]+)([a-zA-Z]+)\s*\/\s*([0-9.]+)([a-zA-Z]+)/', $memory, $matches)) {
                            $used = $this->convertToMB($matches[1], $matches[2]);
                            $limit = $this->convertToMB($matches[3], $matches[4]);

                            $totalMemoryUsed += $used;
                            $totalMemoryLimit += $limit;
                        }

                        $validTasks++;
                    }
                } catch (\Throwable $e) {
                    // Skip failed tasks
                    continue;
                }
            }

            if ($validTasks === 0) {
                return [
                    'cpu' => '0%',
                    'memory' => '0B / 0B',
                    'tasks' => 0,
                ];
            }

            $avgCpu = round($totalCpu / $validTasks, 2);
            $totalMemoryUsedFormatted = $this->formatMemory($totalMemoryUsed);
            $totalMemoryLimitFormatted = $this->formatMemory($totalMemoryLimit);

            return [
                'cpu' => "{$avgCpu}% (avg per task)",
                'memory' => "{$totalMemoryUsedFormatted} / {$totalMemoryLimitFormatted}",
                'tasks' => $validTasks,
                'total_cpu' => round($totalCpu, 2),
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
        return 'swarm';
    }

    /**
     * Convert memory value to MB
     */
    private function convertToMB(float $value, string $unit): float
    {
        $unit = strtoupper($unit);

        return match ($unit) {
            'B' => $value / 1024 / 1024,
            'KB', 'KIB' => $value / 1024,
            'MB', 'MIB' => $value,
            'GB', 'GIB' => $value * 1024,
            'TB', 'TIB' => $value * 1024 * 1024,
            default => $value,
        };
    }

    /**
     * Format memory value from MB to appropriate unit
     */
    private function formatMemory(float $mb): string
    {
        if ($mb < 1024) {
            return round($mb, 2) . 'MiB';
        } elseif ($mb < 1024 * 1024) {
            return round($mb / 1024, 2) . 'GiB';
        } else {
            return round($mb / 1024 / 1024, 2) . 'TiB';
        }
    }

    public function requiresRegistry(): bool
    {
        // Docker Swarm requires a registry for multi-node deployments
        // Images must be available on all nodes
        return true;
    }

    public function supportsAdditionalDestinations(): bool
    {
        // Docker Swarm doesn't support additional destinations
        // Services run on the swarm cluster
        return false;
    }

    public function transformComposeFile(array $dockerCompose, Application $application): array
    {
        $serviceName = $application->uuid;

        // Remove standalone Docker specific keys
        if (isset($dockerCompose['services'][$serviceName])) {
            unset($dockerCompose['services'][$serviceName]['container_name']);
            unset($dockerCompose['services'][$serviceName]['expose']);
            unset($dockerCompose['services'][$serviceName]['restart']);
            unset($dockerCompose['services'][$serviceName]['labels']);
        }

        // Add Swarm deploy section
        $replicas = data_get($application, 'swarm_replicas', 1);
        $placementConstraints = [];

        if ($swarmPlacementConstraints = data_get($application, 'swarm_placement_constraints')) {
            $placementConstraints = json_decode($swarmPlacementConstraints, true) ?? [];
        }

        $deployConfig = [
            'mode' => 'replicated',
            'replicas' => $replicas,
            'update_config' => [
                'parallelism' => 1,
                'delay' => '10s',
                'order' => 'start-first',
            ],
            'rollback_config' => [
                'parallelism' => 1,
                'delay' => '5s',
            ],
            'restart_policy' => [
                'condition' => 'any',
                'delay' => '5s',
                'max_attempts' => 3,
            ],
        ];

        if (!empty($placementConstraints)) {
            $deployConfig['placement'] = [
                'constraints' => $placementConstraints,
            ];
        }

        // Add resource limits if configured
        if ($cpuLimit = data_get($application, 'limits_cpus')) {
            $deployConfig['resources']['limits']['cpus'] = (string)$cpuLimit;
        }

        if ($memoryLimit = data_get($application, 'limits_memory')) {
            $deployConfig['resources']['limits']['memory'] = $memoryLimit;
        }

        if ($cpuReservation = data_get($application, 'limits_cpu_shares')) {
            $deployConfig['resources']['reservations']['cpus'] = (string)($cpuReservation / 1024);
        }

        if ($memoryReservation = data_get($application, 'limits_memory_reservation')) {
            $deployConfig['resources']['reservations']['memory'] = $memoryReservation;
        }

        $dockerCompose['services'][$serviceName]['deploy'] = $deployConfig;

        return $dockerCompose;
    }

    public function performRollingUpdate(Application $application, string $composePath): bool
    {
        $server = $application->destination->server;
        $stackName = $application->uuid;

        try {
            instant_remote_process([
                "docker stack deploy --detach=true --with-registry-auth -c {$composePath} {$stackName}"
            ], $server);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to perform rolling update: " . $e->getMessage());
        }
    }

    public function performHealthCheck(Application $application): bool
    {
        $server = $application->destination->server;
        $serviceName = $application->uuid;

        try {
            $output = instant_remote_process([
                "docker service ls --filter 'name={$serviceName}' --format '{{.Replicas}}'"
            ], $server, false);

            if (empty(trim($output))) {
                return false;
            }

            $replicas = trim($output);
            [$running, $desired] = explode('/', $replicas);

            // Service is healthy if all desired replicas are running
            return (int)$running === (int)$desired && (int)$running > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
