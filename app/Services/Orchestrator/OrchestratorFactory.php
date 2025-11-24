<?php

namespace App\Services\Orchestrator;

use App\Models\Application;
use App\Models\Server;

class OrchestratorFactory
{
    /**
     * Create an orchestrator instance based on application's server settings
     */
    public static function make(Application $application): OrchestratorInterface
    {
        $server = $application->destination->server;

        return self::makeFromServer($server);
    }

    /**
     * Create an orchestrator instance based on server settings
     */
    public static function makeFromServer(Server $server): OrchestratorInterface
    {
        $orchestrator = data_get($server->settings, 'orchestrator', 'none');

        return match ($orchestrator) {
            'swarm' => new DockerSwarmOrchestrator(),
            'kubernetes' => new KubernetesOrchestrator(),
            default => new StandaloneDockerOrchestrator(),
        };
    }

    /**
     * Get orchestrator type from application
     */
    public static function getType(Application $application): string
    {
        $orchestrator = self::make($application);

        return $orchestrator->getType();
    }

    /**
     * Check if application uses orchestration (Swarm or Kubernetes)
     */
    public static function usesOrchestration(Application $application): bool
    {
        $type = self::getType($application);

        return in_array($type, ['swarm', 'kubernetes']);
    }

    /**
     * Check if application can scale
     */
    public static function canScale(Application $application): bool
    {
        return self::usesOrchestration($application);
    }
}
