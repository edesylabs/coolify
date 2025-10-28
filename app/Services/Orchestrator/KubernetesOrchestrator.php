<?php

namespace App\Services\Orchestrator;

use App\Models\Application;

class KubernetesOrchestrator implements OrchestratorInterface
{
    public function deploy(Application $application, string $image): bool
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');

        try {
            // Generate Kubernetes manifests
            $deployment = $this->generateDeploymentManifest($application, $image);
            $service = $this->generateServiceManifest($application);
            $ingress = null;

            if (data_get($application->destination->server->settings, 'kubernetes_use_ingress', true) && !empty($application->fqdn)) {
                $ingress = $this->generateIngressManifest($application);
            }

            // Apply manifests
            $this->applyManifest($server, $namespace, $deployment, 'deployment');
            $this->applyManifest($server, $namespace, $service, 'service');

            if ($ingress) {
                $this->applyManifest($server, $namespace, $ingress, 'ingress');
            }

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to deploy application: " . $e->getMessage());
        }
    }

    public function scale(Application $application, int $replicas): bool
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            instant_remote_process([
                "kubectl scale deployment {$deploymentName} --replicas={$replicas} -n {$namespace}"
            ], $server);

            // Update application's kubernetes_replicas field
            $application->kubernetes_replicas = $replicas;
            $application->save();

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to scale deployment: " . $e->getMessage());
        }
    }

    public function stop(Application $application): bool
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            // Scale to 0 replicas to stop all pods
            instant_remote_process([
                "kubectl scale deployment {$deploymentName} --replicas=0 -n {$namespace}"
            ], $server);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to stop deployment: " . $e->getMessage());
        }
    }

    public function restart(Application $application): bool
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            // Rollout restart triggers recreation of all pods
            instant_remote_process([
                "kubectl rollout restart deployment/{$deploymentName} -n {$namespace}"
            ], $server);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Failed to restart deployment: " . $e->getMessage());
        }
    }

    public function getStatus(Application $application): array
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            $output = instant_remote_process([
                "kubectl get deployment {$deploymentName} -n {$namespace} -o json"
            ], $server, false);

            $deployment = json_decode($output, true);

            $readyReplicas = data_get($deployment, 'status.readyReplicas', 0);
            $replicas = data_get($deployment, 'status.replicas', 0);
            $desiredReplicas = data_get($deployment, 'spec.replicas', 1);
            $availableReplicas = data_get($deployment, 'status.availableReplicas', 0);

            $status = 'unknown';
            if ($readyReplicas === $desiredReplicas && $availableReplicas === $desiredReplicas) {
                $status = 'running';
            } elseif ($replicas > 0 && $readyReplicas < $desiredReplicas) {
                $status = 'updating';
            } elseif ($replicas === 0) {
                $status = 'stopped';
            }

            return [
                'running' => $readyReplicas,
                'desired' => $desiredReplicas,
                'status' => $status,
                'deployment_name' => $deploymentName,
                'namespace' => $namespace,
                'replicas' => $replicas,
                'available_replicas' => $availableReplicas,
                'conditions' => data_get($deployment, 'status.conditions', []),
            ];
        } catch (\Throwable $e) {
            return [
                'running' => 0,
                'desired' => data_get($application, 'kubernetes_replicas', 1),
                'status' => 'not_found',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getLogs(Application $application, int $lines = 100): string
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            // Get logs from all pods in the deployment
            $selector = "app={$deploymentName}";

            return instant_remote_process([
                "kubectl logs -n {$namespace} -l {$selector} --tail={$lines} --all-containers=true"
            ], $server, false);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to get logs: " . $e->getMessage());
        }
    }

    public function execute(Application $application, string $command): string
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            // Get first running pod
            $podName = instant_remote_process([
                "kubectl get pods -n {$namespace} -l app={$deploymentName} -o jsonpath='{.items[0].metadata.name}'"
            ], $server, false);

            $podName = trim($podName);

            if (empty($podName)) {
                throw new \Exception("No running pods found for deployment");
            }

            return instant_remote_process([
                "kubectl exec {$podName} -n {$namespace} -- {$command}"
            ], $server, false);
        } catch (\Throwable $e) {
            throw new \Exception("Failed to execute command: " . $e->getMessage());
        }
    }

    public function getResources(Application $application): array
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            $output = instant_remote_process([
                "kubectl top pods -n {$namespace} -l app={$deploymentName} --no-headers"
            ], $server, false);

            $lines = explode("\n", trim($output));
            $totalCpu = 0;
            $totalMemory = 0;
            $podCount = 0;

            foreach ($lines as $line) {
                if (empty($line)) {
                    continue;
                }

                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 3) {
                    // Parse CPU (e.g., "100m" to millicores)
                    $cpu = $parts[1];
                    if (str_ends_with($cpu, 'm')) {
                        $totalCpu += (int)rtrim($cpu, 'm');
                    } else {
                        $totalCpu += ((int)$cpu) * 1000;
                    }

                    // Parse Memory (e.g., "256Mi" to Mi)
                    $memory = $parts[2];
                    if (str_ends_with($memory, 'Mi')) {
                        $totalMemory += (int)rtrim($memory, 'Mi');
                    } elseif (str_ends_with($memory, 'Gi')) {
                        $totalMemory += ((int)rtrim($memory, 'Gi')) * 1024;
                    }

                    $podCount++;
                }
            }

            return [
                'cpu' => $totalCpu . 'm',
                'memory' => $totalMemory . 'Mi',
                'pods' => $podCount,
                'namespace' => $namespace,
            ];
        } catch (\Throwable $e) {
            return [
                'cpu' => '0m',
                'memory' => '0Mi',
                'pods' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getType(): string
    {
        return 'kubernetes';
    }

    /**
     * Get resource name for Kubernetes objects
     */
    private function getResourceName(Application $application): string
    {
        return $application->uuid;
    }

    /**
     * Generate Kubernetes Deployment manifest
     */
    private function generateDeploymentManifest(Application $application, string $image): array
    {
        $name = $this->getResourceName($application);
        $replicas = data_get($application, 'kubernetes_replicas', 1);

        // Parse environment variables
        $envVars = $this->parseEnvironmentVariables($application);

        // Parse node selector
        $nodeSelector = [];
        if ($application->kubernetes_node_selector) {
            $nodeSelector = json_decode($application->kubernetes_node_selector, true) ?: [];
        }

        // Parse tolerations
        $tolerations = [];
        if ($application->kubernetes_tolerations) {
            $tolerations = json_decode($application->kubernetes_tolerations, true) ?: [];
        }

        // Parse affinity
        $affinity = [];
        if ($application->kubernetes_affinity) {
            $affinity = json_decode($application->kubernetes_affinity, true) ?: [];
        }

        // Parse pod labels
        $podLabels = [
            'app' => $name,
            'coolify.applicationId' => (string)$application->id,
        ];
        if ($application->kubernetes_pod_labels) {
            $customLabels = json_decode($application->kubernetes_pod_labels, true) ?: [];
            $podLabels = array_merge($podLabels, $customLabels);
        }

        $deployment = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    'app' => $name,
                    'coolify.managed' => 'true',
                    'coolify.applicationId' => (string)$application->id,
                ],
            ],
            'spec' => [
                'replicas' => $replicas,
                'selector' => [
                    'matchLabels' => [
                        'app' => $name,
                    ],
                ],
                'template' => [
                    'metadata' => [
                        'labels' => $podLabels,
                    ],
                    'spec' => [
                        'containers' => [
                            [
                                'name' => $name,
                                'image' => $image,
                                'imagePullPolicy' => 'Always',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Add environment variables
        if (!empty($envVars)) {
            $deployment['spec']['template']['spec']['containers'][0]['env'] = $envVars;
        }

        // Add ports
        if (!empty($application->ports_exposes_array)) {
            $ports = [];
            foreach ($application->ports_exposes_array as $port) {
                $ports[] = [
                    'containerPort' => (int)$port,
                    'protocol' => 'TCP',
                ];
            }
            $deployment['spec']['template']['spec']['containers'][0]['ports'] = $ports;
        }

        // Add resource limits
        if ($application->limits_memory || $application->limits_cpus) {
            $resources = [];

            if ($application->limits_memory) {
                $resources['limits']['memory'] = $application->limits_memory;
                $resources['requests']['memory'] = $application->limits_memory;
            }

            if ($application->limits_cpus) {
                $resources['limits']['cpu'] = $application->limits_cpus;
                $resources['requests']['cpu'] = $application->limits_cpus;
            }

            if (!empty($resources)) {
                $deployment['spec']['template']['spec']['containers'][0]['resources'] = $resources;
            }
        }

        // Add node selector
        if (!empty($nodeSelector)) {
            $deployment['spec']['template']['spec']['nodeSelector'] = $nodeSelector;
        }

        // Add tolerations
        if (!empty($tolerations)) {
            $deployment['spec']['template']['spec']['tolerations'] = $tolerations;
        }

        // Add affinity
        if (!empty($affinity)) {
            $deployment['spec']['template']['spec']['affinity'] = $affinity;
        }

        return $deployment;
    }

    /**
     * Generate Kubernetes Service manifest
     */
    private function generateServiceManifest(Application $application): array
    {
        $name = $this->getResourceName($application);
        $serviceType = data_get($application, 'kubernetes_service_type', 'ClusterIP');

        $service = [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    'app' => $name,
                    'coolify.managed' => 'true',
                ],
            ],
            'spec' => [
                'type' => $serviceType,
                'selector' => [
                    'app' => $name,
                ],
                'ports' => [],
            ],
        ];

        // Add service annotations
        if ($application->kubernetes_service_annotations) {
            $annotations = json_decode($application->kubernetes_service_annotations, true) ?: [];
            if (!empty($annotations)) {
                $service['metadata']['annotations'] = $annotations;
            }
        }

        // Add ports
        if (!empty($application->ports_exposes_array)) {
            foreach ($application->ports_exposes_array as $port) {
                $service['spec']['ports'][] = [
                    'name' => "port-{$port}",
                    'port' => (int)$port,
                    'targetPort' => (int)$port,
                    'protocol' => 'TCP',
                ];
            }
        } else {
            // Default port if none specified
            $service['spec']['ports'][] = [
                'name' => 'http',
                'port' => 80,
                'targetPort' => 80,
                'protocol' => 'TCP',
            ];
        }

        return $service;
    }

    /**
     * Generate Kubernetes Ingress manifest
     */
    private function generateIngressManifest(Application $application): ?array
    {
        $fqdn = $application->fqdn;
        if (empty($fqdn)) {
            return null;
        }

        $name = $this->getResourceName($application);
        $ingressClass = data_get($application->destination->server->settings, 'kubernetes_ingress_class', 'nginx');

        // Parse domains (comma-separated)
        $domains = array_map('trim', explode(',', $fqdn));

        $ingress = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    'app' => $name,
                    'coolify.managed' => 'true',
                ],
                'annotations' => [
                    'kubernetes.io/ingress.class' => $ingressClass,
                ],
            ],
            'spec' => [
                'ingressClassName' => $ingressClass,
                'rules' => [],
            ],
        ];

        // Add TLS if cert-manager is enabled
        $tlsHosts = [];
        foreach ($domains as $domain) {
            $tlsHosts[] = $domain;
        }

        if (!empty($tlsHosts)) {
            $ingress['spec']['tls'] = [
                [
                    'hosts' => $tlsHosts,
                    'secretName' => "{$name}-tls",
                ],
            ];

            // Add cert-manager annotation for automatic SSL
            $ingress['metadata']['annotations']['cert-manager.io/cluster-issuer'] = 'letsencrypt-prod';
        }

        // Add rules for each domain
        foreach ($domains as $domain) {
            $port = 80;
            if (!empty($application->ports_exposes_array)) {
                $port = (int)$application->ports_exposes_array[0];
            }

            $ingress['spec']['rules'][] = [
                'host' => $domain,
                'http' => [
                    'paths' => [
                        [
                            'path' => '/',
                            'pathType' => 'Prefix',
                            'backend' => [
                                'service' => [
                                    'name' => $name,
                                    'port' => [
                                        'number' => $port,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $ingress;
    }

    /**
     * Apply Kubernetes manifest
     */
    private function applyManifest($server, string $namespace, array $manifest, string $type): void
    {
        $yaml = yaml_emit($manifest);
        $tempFile = "/tmp/coolify-k8s-{$type}-" . uniqid() . '.yaml';
        $base64Yaml = base64_encode($yaml);

        instant_remote_process([
            "echo '{$base64Yaml}' | base64 -d > {$tempFile}",
            "kubectl apply -f {$tempFile} -n {$namespace}",
            "rm {$tempFile}",
        ], $server);
    }

    /**
     * Parse environment variables from application
     */
    private function parseEnvironmentVariables(Application $application): array
    {
        $envVars = [];
        $environmentVariables = $application->environment_variables ?? collect();

        foreach ($environmentVariables as $env) {
            if ($env->is_build_time) {
                continue;
            }

            $envVars[] = [
                'name' => $env->key,
                'value' => $env->value,
            ];
        }

        return $envVars;
    }

    public function requiresRegistry(): bool
    {
        // Kubernetes can pull from registry or use local images with imagePullPolicy: IfNotPresent
        // For production deployments, registry is recommended but not strictly required
        return false;
    }

    public function supportsAdditionalDestinations(): bool
    {
        // Kubernetes deployments are namespace-bound and managed by the cluster
        // Additional destinations are not supported
        return false;
    }

    public function transformComposeFile(array $dockerCompose, Application $application): array
    {
        // Kubernetes doesn't use docker-compose files
        // Deployment is handled by generateDeploymentManifest() instead
        // This method is here for interface compliance
        return $dockerCompose;
    }

    public function performRollingUpdate(Application $application, string $composePath): bool
    {
        // Kubernetes handles rolling updates automatically when deployment is updated
        // We just need to apply the new deployment manifest
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            // Kubernetes automatically performs rolling updates when deployment spec changes
            // Check rollout status
            $output = instant_remote_process([
                "kubectl rollout status deployment/{$deploymentName} -n {$namespace} --timeout=300s"
            ], $server, false);

            return str_contains($output, 'successfully rolled out');
        } catch (\Throwable $e) {
            throw new \Exception("Failed to perform rolling update: " . $e->getMessage());
        }
    }

    public function performHealthCheck(Application $application): bool
    {
        $server = $application->destination->server;
        $namespace = data_get($application->destination->server->settings, 'kubernetes_namespace', 'default');
        $deploymentName = $this->getResourceName($application);

        try {
            $output = instant_remote_process([
                "kubectl get deployment {$deploymentName} -n {$namespace} -o jsonpath='{.status.conditions[?(@.type==\"Available\")].status}'"
            ], $server, false);

            $availableStatus = trim($output, "'");

            // Also check ready replicas
            $replicasOutput = instant_remote_process([
                "kubectl get deployment {$deploymentName} -n {$namespace} -o jsonpath='{.status.readyReplicas}/{.spec.replicas}'"
            ], $server, false);

            $replicasOutput = trim($replicasOutput, "'");
            [$ready, $desired] = explode('/', $replicasOutput);

            return $availableStatus === 'True' && (int)$ready === (int)$desired && (int)$ready > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
