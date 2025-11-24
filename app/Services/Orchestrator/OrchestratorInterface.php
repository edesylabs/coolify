<?php

namespace App\Services\Orchestrator;

use App\Models\Application;

interface OrchestratorInterface
{
    /**
     * Deploy an application with the given image
     *
     * @param Application $application The application to deploy
     * @param string $image The Docker image to deploy
     * @return bool True if deployment was successful
     * @throws \Exception If deployment fails
     */
    public function deploy(Application $application, string $image): bool;

    /**
     * Scale application to specified number of replicas
     *
     * @param Application $application The application to scale
     * @param int $replicas Number of replicas to scale to
     * @return bool True if scaling was successful
     * @throws \Exception If scaling fails
     */
    public function scale(Application $application, int $replicas): bool;

    /**
     * Stop the application
     *
     * @param Application $application The application to stop
     * @return bool True if stop was successful
     * @throws \Exception If stop fails
     */
    public function stop(Application $application): bool;

    /**
     * Restart the application
     *
     * @param Application $application The application to restart
     * @return bool True if restart was successful
     * @throws \Exception If restart fails
     */
    public function restart(Application $application): bool;

    /**
     * Get current status of the application
     *
     * @param Application $application The application to check
     * @return array Status information including running/desired replicas
     */
    public function getStatus(Application $application): array;

    /**
     * Get logs from the application
     *
     * @param Application $application The application to get logs from
     * @param int $lines Number of log lines to retrieve
     * @return string The log output
     */
    public function getLogs(Application $application, int $lines = 100): string;

    /**
     * Execute a command in the application container
     *
     * @param Application $application The application to execute command in
     * @param string $command The command to execute
     * @return string The command output
     * @throws \Exception If execution fails
     */
    public function execute(Application $application, string $command): string;

    /**
     * Get resource usage metrics for the application
     *
     * @param Application $application The application to get metrics for
     * @return array Resource usage information (CPU, memory, etc.)
     */
    public function getResources(Application $application): array;

    /**
     * Get the orchestrator type name
     *
     * @return string The orchestrator type (standalone, swarm, kubernetes)
     */
    public function getType(): string;

    /**
     * Check if this orchestrator requires a registry for deployments
     *
     * @return bool True if registry is required
     */
    public function requiresRegistry(): bool;

    /**
     * Check if this orchestrator supports additional destinations
     *
     * @return bool True if additional destinations are supported
     */
    public function supportsAdditionalDestinations(): bool;

    /**
     * Transform docker compose array for orchestrator-specific requirements
     *
     * @param array $dockerCompose The docker compose configuration
     * @param Application $application The application being deployed
     * @return array The transformed docker compose configuration
     */
    public function transformComposeFile(array $dockerCompose, Application $application): array;

    /**
     * Perform a rolling update for the application
     *
     * @param Application $application The application to update
     * @param string $composePath Path to the docker compose file
     * @return bool True if rolling update was successful
     * @throws \Exception If rolling update fails
     */
    public function performRollingUpdate(Application $application, string $composePath): bool;

    /**
     * Perform health check on the application
     *
     * @param Application $application The application to health check
     * @return bool True if application is healthy
     */
    public function performHealthCheck(Application $application): bool;
}
