<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Orchestrator\OrchestratorFactory;
use Illuminate\Http\Request;

class KubernetesController extends Controller
{
    /**
     * Scale an application
     */
    public function scale_application(Request $request)
    {
        $teamId = currentTeam()->id;
        $uuid = $request->route('uuid');
        $replicas = $request->input('replicas');

        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }

        if (! $replicas || ! is_numeric($replicas) || $replicas < 0) {
            return response()->json(['message' => 'Valid replicas count is required.'], 400);
        }

        $application = Application::ownedByCurrentTeam(['uuid' => $uuid])->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $server = $application->destination->server;
        $orchestratorType = $server->settings->orchestrator ?? 'none';

        if ($orchestratorType === 'none') {
            return response()->json([
                'message' => 'This application does not support scaling. The server is using standalone Docker orchestration.'
            ], 400);
        }

        try {
            $orchestrator = OrchestratorFactory::make($application);
            $result = $orchestrator->scale($application, (int)$replicas);

            if ($result) {
                return response()->json([
                    'message' => 'Application scaled successfully.',
                    'orchestrator' => $orchestratorType,
                    'replicas' => (int)$replicas
                ], 200);
            } else {
                return response()->json(['message' => 'Failed to scale application.'], 500);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get application status
     */
    public function get_status(Request $request)
    {
        $teamId = currentTeam()->id;
        $uuid = $request->route('uuid');

        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }

        $application = Application::ownedByCurrentTeam(['uuid' => $uuid])->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $server = $application->destination->server;
        $orchestratorType = $server->settings->orchestrator ?? 'none';

        try {
            $orchestrator = OrchestratorFactory::make($application);
            $status = $orchestrator->getStatus($application);

            return response()->json([
                'orchestrator' => $orchestratorType,
                'status' => $status
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get application resources (CPU/memory usage)
     */
    public function get_resources(Request $request)
    {
        $teamId = currentTeam()->id;
        $uuid = $request->route('uuid');

        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }

        $application = Application::ownedByCurrentTeam(['uuid' => $uuid])->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $server = $application->destination->server;
        $orchestratorType = $server->settings->orchestrator ?? 'none';

        try {
            $orchestrator = OrchestratorFactory::make($application);
            $resources = $orchestrator->getResources($application);

            return response()->json([
                'orchestrator' => $orchestratorType,
                'resources' => $resources
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
