# Kubernetes Support Implementation Plan for Coolify

## Executive Summary

Adding Kubernetes support to Coolify alongside Docker Swarm requires significant architectural changes across database, backend services, UI, and deployment logic. This document provides a comprehensive implementation plan.

**Estimated Effort**: 6-8 weeks for full implementation
**Complexity**: High
**Priority**: Medium (Nice-to-have for enterprise users)

---

## Table of Contents

1. [Current Architecture Analysis](#current-architecture-analysis)
2. [Database Schema Changes](#database-schema-changes)
3. [Backend Implementation](#backend-implementation)
4. [Frontend/UI Changes](#frontend-ui-changes)
5. [Deployment Flow](#deployment-flow)
6. [API Integration](#api-integration)
7. [Testing Strategy](#testing-strategy)
8. [Migration Path](#migration-path)
9. [Documentation](#documentation)
10. [Phase-by-Phase Rollout](#phase-by-phase-rollout)

---

## Current Architecture Analysis

### How Docker Swarm is Currently Implemented

From analyzing the codebase, here's how Swarm works in Coolify:

#### 1. **Database Level**
```php
// Application Model (app/Models/Application.php)
'swarm_replicas' => ['type' => 'integer', 'nullable' => true]
'swarm_placement_constraints' => ['type' => 'string', 'nullable' => true]

// Server Model (app/Models/Server.php)
// ServerSettings has:
- is_swarm_manager (boolean)
- is_swarm_worker (boolean)

// Methods:
public function isSwarm()
public function isSwarmManager()
public function isSwarmWorker()
```

#### 2. **UI Level**
```
app/Livewire/Project/Application/Swarm.php
  ├─ Manages swarm_replicas
  ├─ Manages swarm_placement_constraints
  └─ Syncs to application model

resources/views/livewire/project/application/swarm.blade.php
  └─ UI for replica configuration
```

#### 3. **Deployment Level**
```
app/Jobs/ApplicationDeploymentJob.php
  ├─ Checks if server->isSwarm()
  ├─ If Swarm: docker service create/update
  └─ If not: docker run/docker-compose
```

#### 4. **Proxy Configuration**
```
bootstrap/helpers/proxy.php
  └─ generateDefaultProxyConfiguration()
      ├─ If Swarm: --providers.swarm
      └─ If not: --providers.docker
```

### Key Patterns Identified

1. **Orchestrator Detection**: `$server->isSwarm()` used throughout codebase
2. **Conditional Logic**: if/else blocks for Swarm vs standalone
3. **Settings-Based**: Orchestrator mode stored in `server_settings` table
4. **Transparent to Application**: App doesn't need to "know" it's on Swarm

---

## Database Schema Changes

### 1. Add Orchestrator Type to ServerSettings

**Migration**: `database/migrations/YYYY_MM_DD_add_kubernetes_support.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            // Add orchestrator type enum
            $table->enum('orchestrator', ['none', 'swarm', 'kubernetes'])
                ->default('none')
                ->after('is_usable');

            // Kubernetes-specific settings
            $table->boolean('is_kubernetes_master')->default(false);
            $table->boolean('is_kubernetes_worker')->default(false);
            $table->string('kubernetes_version')->nullable();
            $table->text('kubernetes_config')->nullable(); // base64 kubeconfig
            $table->string('kubernetes_namespace')->default('default');
            $table->boolean('kubernetes_use_ingress')->default(true);
            $table->string('kubernetes_ingress_class')->default('nginx');
            $table->string('kubernetes_storage_class')->nullable();
        });

        Schema::table('applications', function (Blueprint $table) {
            // Kubernetes-specific application settings
            $table->integer('kubernetes_replicas')->nullable();
            $table->text('kubernetes_node_selector')->nullable(); // JSON
            $table->text('kubernetes_tolerations')->nullable(); // JSON
            $table->text('kubernetes_affinity')->nullable(); // JSON
            $table->text('kubernetes_pod_labels')->nullable(); // JSON
            $table->text('kubernetes_service_annotations')->nullable(); // JSON
            $table->enum('kubernetes_service_type', ['ClusterIP', 'NodePort', 'LoadBalancer'])
                ->default('ClusterIP');
        });
    }

    public function down()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'orchestrator',
                'is_kubernetes_master',
                'is_kubernetes_worker',
                'kubernetes_version',
                'kubernetes_config',
                'kubernetes_namespace',
                'kubernetes_use_ingress',
                'kubernetes_ingress_class',
                'kubernetes_storage_class',
            ]);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'kubernetes_replicas',
                'kubernetes_node_selector',
                'kubernetes_tolerations',
                'kubernetes_affinity',
                'kubernetes_pod_labels',
                'kubernetes_service_annotations',
                'kubernetes_service_type',
            ]);
        });
    }
};
```

### 2. Create New Models

**File**: `app/Models/KubernetesCluster.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KubernetesCluster extends Model
{
    protected $fillable = [
        'name',
        'server_id',
        'kubeconfig',
        'version',
        'api_endpoint',
        'ca_certificate',
        'client_certificate',
        'client_key',
    ];

    protected $casts = [
        'kubeconfig' => 'encrypted',
        'client_key' => 'encrypted',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
```

**File**: `app/Models/KubernetesNamespace.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KubernetesNamespace extends Model
{
    protected $fillable = [
        'name',
        'kubernetes_cluster_id',
        'resource_quotas',
        'limit_ranges',
    ];

    protected $casts = [
        'resource_quotas' => 'array',
        'limit_ranges' => 'array',
    ];

    public function cluster()
    {
        return $this->belongsTo(KubernetesCluster::class);
    }
}
```

---

## Backend Implementation

### 1. Add Orchestrator Abstraction Layer

**File**: `app/Services/Orchestrator/OrchestratorInterface.php`

```php
<?php

namespace App\Services\Orchestrator;

use App\Models\Application;
use App\Models\Server;

interface OrchestratorInterface
{
    public function deploy(Application $application, string $image): bool;
    public function scale(Application $application, int $replicas): bool;
    public function stop(Application $application): bool;
    public function restart(Application $application): bool;
    public function getStatus(Application $application): array;
    public function getLogs(Application $application, int $lines = 100): string;
    public function execute(Application $application, string $command): string;
    public function getResources(Application $application): array;
}
```

**File**: `app/Services/Orchestrator/DockerSwarmOrchestrator.php`

```php
<?php

namespace App\Services\Orchestrator;

use App\Models\Application;

class DockerSwarmOrchestrator implements OrchestratorInterface
{
    public function deploy(Application $application, string $image): bool
    {
        $server = $application->destination->server;
        $replicas = $application->swarm_replicas ?? 1;
        $constraints = $application->swarm_placement_constraints;

        $command = "docker service create --name {$application->uuid} --replicas {$replicas}";

        if ($constraints) {
            $command .= " --constraint '{$constraints}'";
        }

        $command .= " {$image}";

        instant_remote_process([$command], $server);
        return true;
    }

    public function scale(Application $application, int $replicas): bool
    {
        $server = $application->destination->server;
        $command = "docker service scale {$application->uuid}={$replicas}";

        instant_remote_process([$command], $server);
        return true;
    }

    public function stop(Application $application): bool
    {
        $server = $application->destination->server;
        instant_remote_process(["docker service rm {$application->uuid}"], $server);
        return true;
    }

    public function restart(Application $application): bool
    {
        $this->stop($application);
        // Re-deploy with existing image
        return true;
    }

    public function getStatus(Application $application): array
    {
        $server = $application->destination->server;
        $output = instant_remote_process([
            "docker service ps {$application->uuid} --format '{{json .}}'"
        ], $server, false);

        $tasks = json_decode($output, true);
        return [
            'running' => count(array_filter($tasks, fn($t) => $t['CurrentState'] === 'Running')),
            'desired' => $application->swarm_replicas,
            'tasks' => $tasks,
        ];
    }

    public function getLogs(Application $application, int $lines = 100): string
    {
        $server = $application->destination->server;
        return instant_remote_process([
            "docker service logs {$application->uuid} --tail {$lines}"
        ], $server, false);
    }

    public function execute(Application $application, string $command): string
    {
        $server = $application->destination->server;
        // Get first task ID
        $taskId = instant_remote_process([
            "docker service ps {$application->uuid} -q | head -1"
        ], $server, false);

        return instant_remote_process([
            "docker exec \$(docker ps -q --filter label=com.docker.swarm.task.id={$taskId}) {$command}"
        ], $server, false);
    }

    public function getResources(Application $application): array
    {
        // Implementation for resource metrics
        return [];
    }
}
```

**File**: `app/Services/Orchestrator/KubernetesOrchestrator.php`

```php
<?php

namespace App\Services\Orchestrator;

use App\Models\Application;
use Symfony\Component\Yaml\Yaml;

class KubernetesOrchestrator implements OrchestratorInterface
{
    protected function getKubeconfig(Application $application): string
    {
        $server = $application->destination->server;
        return base64_decode($server->settings->kubernetes_config);
    }

    public function deploy(Application $application, string $image): bool
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $replicas = $application->kubernetes_replicas ?? 1;

        // Generate Deployment YAML
        $deployment = $this->generateDeployment($application, $image, $replicas);

        // Generate Service YAML
        $service = $this->generateService($application);

        // Generate Ingress YAML (if domains configured)
        $ingress = $this->generateIngress($application);

        // Apply manifests
        $this->applyManifest($server, $deployment, 'deployment');
        $this->applyManifest($server, $service, 'service');

        if ($ingress) {
            $this->applyManifest($server, $ingress, 'ingress');
        }

        return true;
    }

    protected function generateDeployment(Application $application, string $image, int $replicas): string
    {
        $namespace = $application->destination->server->settings->kubernetes_namespace;
        $name = $application->uuid;

        $deployment = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => [
                    'app' => $name,
                    'coolify.managed' => 'true',
                    'coolify.applicationId' => (string) $application->id,
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
                        'labels' => array_merge(
                            ['app' => $name],
                            json_decode($application->kubernetes_pod_labels ?? '{}', true)
                        ),
                    ],
                    'spec' => [
                        'containers' => [[
                            'name' => $name,
                            'image' => $image,
                            'ports' => [[
                                'containerPort' => (int) $application->ports_exposes_array[0] ?? 80,
                            ]],
                            'resources' => [
                                'limits' => [
                                    'memory' => $application->limits_memory ?? '512Mi',
                                    'cpu' => $application->limits_cpus ?? '500m',
                                ],
                                'requests' => [
                                    'memory' => $application->limits_memory_reservation ?? '256Mi',
                                    'cpu' => ($application->limits_cpus ? $application->limits_cpus / 2 : '250m'),
                                ],
                            ],
                            'env' => $this->generateEnvVars($application),
                        ]],
                    ],
                ],
            ],
        ];

        // Add node selector if specified
        if ($application->kubernetes_node_selector) {
            $deployment['spec']['template']['spec']['nodeSelector'] =
                json_decode($application->kubernetes_node_selector, true);
        }

        // Add tolerations if specified
        if ($application->kubernetes_tolerations) {
            $deployment['spec']['template']['spec']['tolerations'] =
                json_decode($application->kubernetes_tolerations, true);
        }

        // Add health checks
        if ($application->health_check_enabled) {
            $deployment['spec']['template']['spec']['containers'][0]['livenessProbe'] = [
                'httpGet' => [
                    'path' => $application->health_check_path,
                    'port' => (int) ($application->health_check_port ?? $application->ports_exposes_array[0] ?? 80),
                ],
                'initialDelaySeconds' => $application->health_check_start_period,
                'periodSeconds' => $application->health_check_interval,
                'timeoutSeconds' => $application->health_check_timeout,
                'failureThreshold' => $application->health_check_retries,
            ];

            $deployment['spec']['template']['spec']['containers'][0]['readinessProbe'] = [
                'httpGet' => [
                    'path' => $application->health_check_path,
                    'port' => (int) ($application->health_check_port ?? $application->ports_exposes_array[0] ?? 80),
                ],
                'initialDelaySeconds' => 5,
                'periodSeconds' => 10,
            ];
        }

        return Yaml::dump($deployment, 10, 2);
    }

    protected function generateService(Application $application): string
    {
        $namespace = $application->destination->server->settings->kubernetes_namespace;
        $name = $application->uuid;

        $service = [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => [
                    'app' => $name,
                ],
                'annotations' => json_decode($application->kubernetes_service_annotations ?? '{}', true),
            ],
            'spec' => [
                'type' => $application->kubernetes_service_type ?? 'ClusterIP',
                'selector' => [
                    'app' => $name,
                ],
                'ports' => [[
                    'protocol' => 'TCP',
                    'port' => 80,
                    'targetPort' => (int) $application->ports_exposes_array[0] ?? 80,
                ]],
            ],
        ];

        return Yaml::dump($service, 10, 2);
    }

    protected function generateIngress(Application $application): ?string
    {
        if (!$application->fqdn) {
            return null;
        }

        $namespace = $application->destination->server->settings->kubernetes_namespace;
        $name = $application->uuid;
        $ingressClass = $application->destination->server->settings->kubernetes_ingress_class;
        $domains = explode(',', $application->fqdn);

        $rules = [];
        foreach ($domains as $domain) {
            $rules[] = [
                'host' => trim($domain),
                'http' => [
                    'paths' => [[
                        'path' => '/',
                        'pathType' => 'Prefix',
                        'backend' => [
                            'service' => [
                                'name' => $name,
                                'port' => [
                                    'number' => 80,
                                ],
                            ],
                        ],
                    ]],
                ],
            ];
        }

        $ingress = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'annotations' => [
                    'cert-manager.io/cluster-issuer' => 'letsencrypt',
                ],
            ],
            'spec' => [
                'ingressClassName' => $ingressClass,
                'tls' => [[
                    'hosts' => array_map('trim', $domains),
                    'secretName' => "{$name}-tls",
                ]],
                'rules' => $rules,
            ],
        ];

        return Yaml::dump($ingress, 10, 2);
    }

    protected function generateEnvVars(Application $application): array
    {
        $envVars = [];
        foreach ($application->environment_variables as $env) {
            $envVars[] = [
                'name' => $env->key,
                'value' => $env->value,
            ];
        }
        return $envVars;
    }

    protected function applyManifest($server, string $yaml, string $type): void
    {
        $tempFile = "/tmp/coolify-k8s-{$type}-" . uniqid() . ".yaml";
        $base64Yaml = base64_encode($yaml);

        instant_remote_process([
            "echo '{$base64Yaml}' | base64 -d > {$tempFile}",
            "kubectl apply -f {$tempFile}",
            "rm {$tempFile}",
        ], $server);
    }

    public function scale(Application $application, int $replicas): bool
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $name = $application->uuid;

        instant_remote_process([
            "kubectl scale deployment {$name} --replicas={$replicas} -n {$namespace}"
        ], $server);

        return true;
    }

    public function stop(Application $application): bool
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $name = $application->uuid;

        instant_remote_process([
            "kubectl delete deployment {$name} -n {$namespace}",
            "kubectl delete service {$name} -n {$namespace}",
            "kubectl delete ingress {$name} -n {$namespace} --ignore-not-found",
        ], $server);

        return true;
    }

    public function restart(Application $application): bool
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $name = $application->uuid;

        instant_remote_process([
            "kubectl rollout restart deployment/{$name} -n {$namespace}"
        ], $server);

        return true;
    }

    public function getStatus(Application $application): array
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $name = $application->uuid;

        $output = instant_remote_process([
            "kubectl get deployment {$name} -n {$namespace} -o json"
        ], $server, false);

        $deployment = json_decode($output, true);

        return [
            'running' => $deployment['status']['readyReplicas'] ?? 0,
            'desired' => $deployment['spec']['replicas'] ?? 0,
            'available' => $deployment['status']['availableReplicas'] ?? 0,
            'unavailable' => $deployment['status']['unavailableReplicas'] ?? 0,
        ];
    }

    public function getLogs(Application $application, int $lines = 100): string
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $name = $application->uuid;

        return instant_remote_process([
            "kubectl logs deployment/{$name} -n {$namespace} --tail={$lines}"
        ], $server, false);
    }

    public function execute(Application $application, string $command): string
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $name = $application->uuid;

        // Get first pod name
        $podName = instant_remote_process([
            "kubectl get pods -n {$namespace} -l app={$name} -o jsonpath='{.items[0].metadata.name}'"
        ], $server, false);

        return instant_remote_process([
            "kubectl exec {$podName} -n {$namespace} -- {$command}"
        ], $server, false);
    }

    public function getResources(Application $application): array
    {
        $server = $application->destination->server;
        $namespace = $server->settings->kubernetes_namespace;
        $name = $application->uuid;

        $output = instant_remote_process([
            "kubectl top pods -n {$namespace} -l app={$name} --no-headers"
        ], $server, false);

        // Parse kubectl top output
        $lines = explode("\n", trim($output));
        $pods = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 3) {
                $pods[] = [
                    'name' => $parts[0],
                    'cpu' => $parts[1],
                    'memory' => $parts[2],
                ];
            }
        }

        return $pods;
    }
}
```

### 2. Orchestrator Factory

**File**: `app/Services/Orchestrator/OrchestratorFactory.php`

```php
<?php

namespace App\Services\Orchestrator;

use App\Models\Server;

class OrchestratorFactory
{
    public static function make(Server $server): OrchestratorInterface
    {
        $orchestrator = $server->settings->orchestrator ?? 'none';

        return match ($orchestrator) {
            'swarm' => new DockerSwarmOrchestrator(),
            'kubernetes' => new KubernetesOrchestrator(),
            'none' => new StandaloneDockerOrchestrator(),
            default => throw new \Exception("Unsupported orchestrator: {$orchestrator}"),
        };
    }
}
```

### 3. Update Server Model

**File**: `app/Models/Server.php` (add methods)

```php
public function isKubernetes(): bool
{
    return data_get($this, 'settings.orchestrator') === 'kubernetes';
}

public function isKubernetesMaster(): bool
{
    return data_get($this, 'settings.is_kubernetes_master');
}

public function isKubernetesWorker(): bool
{
    return data_get($this, 'settings.is_kubernetes_worker');
}

public function getOrchestrator(): string
{
    if ($this->isSwarm()) {
        return 'swarm';
    }

    if ($this->isKubernetes()) {
        return 'kubernetes';
    }

    return 'none';
}
```

### 4. Update Application Model

**File**: `app/Models/Application.php` (add methods)

```php
public function getReplicaCount(): int
{
    $server = $this->destination->server;

    return match ($server->getOrchestrator()) {
        'swarm' => $this->swarm_replicas ?? 1,
        'kubernetes' => $this->kubernetes_replicas ?? 1,
        default => 1,
    };
}

public function setReplicaCount(int $replicas): void
{
    $server = $this->destination->server;

    match ($server->getOrchestrator()) {
        'swarm' => $this->swarm_replicas = $replicas,
        'kubernetes' => $this->kubernetes_replicas = $replicas,
        default => null,
    };

    $this->save();
}
```

---

## Frontend/UI Changes

### 1. Server Configuration UI

**File**: `app/Livewire/Server/Kubernetes.php` (NEW)

```php
<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Kubernetes extends Component
{
    use AuthorizesRequests;

    public Server $server;
    public string $orchestrator = 'none';
    public bool $isKubernetesMaster = false;
    public bool $isKubernetesWorker = false;
    public string $kubernetesVersion = '';
    public string $kubeconfig = '';
    public string $namespace = 'default';
    public string $ingressClass = 'nginx';
    public string $storageClass = '';

    public function mount()
    {
        $this->authorize('view', $this->server);
        $this->syncFromServer();
    }

    protected function syncFromServer()
    {
        $this->orchestrator = $this->server->settings->orchestrator ?? 'none';
        $this->isKubernetesMaster = $this->server->settings->is_kubernetes_master ?? false;
        $this->isKubernetesWorker = $this->server->settings->is_kubernetes_worker ?? false;
        $this->kubernetesVersion = $this->server->settings->kubernetes_version ?? '';
        $this->kubeconfig = $this->server->settings->kubernetes_config
            ? base64_decode($this->server->settings->kubernetes_config)
            : '';
        $this->namespace = $this->server->settings->kubernetes_namespace ?? 'default';
        $this->ingressClass = $this->server->settings->kubernetes_ingress_class ?? 'nginx';
        $this->storageClass = $this->server->settings->kubernetes_storage_class ?? '';
    }

    public function updatedOrchestrator($value)
    {
        // Auto-detect roles when switching to Kubernetes
        if ($value === 'kubernetes') {
            $this->detectKubernetesRoles();
        }
    }

    public function detectKubernetesRoles()
    {
        try {
            // Check if kubectl is available
            $kubectlVersion = instant_remote_process(['kubectl version --short'], $this->server, false);

            // Check if this is a master node
            $isMaster = instant_remote_process([
                'kubectl get nodes -o json | jq -r \'.items[] | select(.metadata.labels["node-role.kubernetes.io/control-plane"]!="") | .metadata.name\''
            ], $this->server, false);

            $this->isKubernetesMaster = !empty($isMaster);
            $this->kubernetesVersion = $this->extractVersion($kubectlVersion);

            $this->dispatch('success', 'Kubernetes detected successfully');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to detect Kubernetes: ' . $e->getMessage());
        }
    }

    public function testConnection()
    {
        try {
            $this->authorize('update', $->server);

            // Test kubectl connection
            $result = instant_remote_process(['kubectl get nodes'], $this->server, false);

            if (str_contains($result, 'Ready')) {
                $this->dispatch('success', 'Kubernetes connection successful!');
            } else {
                $this->dispatch('error', 'Kubernetes cluster not ready');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate([
                'orchestrator' => 'required|in:none,swarm,kubernetes',
                'namespace' => 'required_if:orchestrator,kubernetes|string',
                'ingressClass' => 'nullable|string',
            ]);

            $this->server->settings->orchestrator = $this->orchestrator;
            $this->server->settings->is_kubernetes_master = $this->isKubernetesMaster;
            $this->server->settings->is_kubernetes_worker = $this->isKubernetesWorker;
            $this->server->settings->kubernetes_version = $this->kubernetesVersion;
            $this->server->settings->kubernetes_namespace = $this->namespace;
            $this->server->settings->kubernetes_ingress_class = $this->ingressClass;
            $this->server->settings->kubernetes_storage_class = $this->storageClass;

            if ($this->kubeconfig) {
                $this->server->settings->kubernetes_config = base64_encode($this->kubeconfig);
            }

            $this->server->settings->save();

            $this->dispatch('success', 'Kubernetes configuration saved successfully');
            $this->syncFromServer();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function extractVersion($output): string
    {
        preg_match('/v(\d+\.\d+\.\d+)/', $output, $matches);
        return $matches[1] ?? '';
    }

    public function render()
    {
        return view('livewire.server.kubernetes');
    }
}
```

**File**: `resources/views/livewire/server/kubernetes.blade.php` (NEW)

```blade
<div>
    <form wire:submit="submit">
        <h2>Orchestrator Configuration</h2>

        <x-forms.select
            wire:model.live="orchestrator"
            label="Orchestrator Type"
            required
        >
            <option value="none">Standalone Docker</option>
            <option value="swarm">Docker Swarm</option>
            <option value="kubernetes">Kubernetes</option>
        </x-forms.select>

        @if ($orchestrator === 'kubernetes')
            <div class="mt-4">
                <x-forms.checkbox
                    wire:model="isKubernetesMaster"
                    label="Is Kubernetes Master Node"
                    helper="Check this if this server is a Kubernetes control plane node"
                />

                <x-forms.checkbox
                    wire:model="isKubernetesWorker"
                    label="Is Kubernetes Worker Node"
                    helper="Check this if this server runs application workloads"
                />

                <x-forms.input
                    wire:model="kubernetesVersion"
                    label="Kubernetes Version"
                    placeholder="1.28.0"
                    helper="Detected automatically or enter manually"
                />

                <x-forms.textarea
                    wire:model="kubeconfig"
                    label="Kubeconfig (Optional)"
                    placeholder="Paste your kubeconfig file here"
                    helper="Leave empty to use default kubeconfig from server"
                    rows="10"
                />

                <x-forms.input
                    wire:model="namespace"
                    label="Default Namespace"
                    placeholder="default"
                    helper="Applications will be deployed to this namespace"
                    required
                />

                <x-forms.input
                    wire:model="ingressClass"
                    label="Ingress Class"
                    placeholder="nginx"
                    helper="Ingress controller class (nginx, traefik, etc.)"
                />

                <x-forms.input
                    wire:model="storageClass"
                    label="Storage Class (Optional)"
                    placeholder="standard"
                    helper="Default storage class for persistent volumes"
                />

                <div class="flex gap-2 mt-4">
                    <x-forms.button type="button" wire:click="detectKubernetesRoles">
                        Detect Kubernetes
                    </x-forms.button>

                    <x-forms.button type="button" wire:click="testConnection">
                        Test Connection
                    </x-forms.button>
                </div>
            </div>
        @endif

        <div class="mt-6">
            <x-forms.button type="submit">
                Save Configuration
            </x-forms.button>
        </div>
    </form>
</div>
```

### 2. Application Scaling UI

**File**: `app/Livewire/Project/Application/Orchestration.php` (NEW - replaces Swarm.php)

```php
<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Services\Orchestrator\OrchestratorFactory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Orchestration extends Component
{
    use AuthorizesRequests;

    public Application $application;
    public string $orchestratorType = 'none';
    public int $replicas = 1;
    public string $placementConfig = '';
    public string $nodeSelector = '';
    public string $tolerations = '';

    public function mount()
    {
        $this->authorize('view', $this->application);
        $this->syncData();
    }

    protected function syncData()
    {
        $server = $this->application->destination->server;
        $this->orchestratorType = $server->getOrchestrator();
        $this->replicas = $this->application->getReplicaCount();

        if ($this->orchestratorType === 'swarm') {
            $this->placementConfig = $this->application->swarm_placement_constraints ?? '';
        } elseif ($this->orchestratorType === 'kubernetes') {
            $this->nodeSelector = $this->application->kubernetes_node_selector ?? '';
            $this->tolerations = $this->application->kubernetes_tolerations ?? '';
        }
    }

    public function updateReplicas()
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate(['replicas' => 'required|integer|min:0|max:100']);

            $this->application->setReplicaCount($this->replicas);

            // Scale the application if it's running
            if ($this->application->isRunning()) {
                $server = $this->application->destination->server;
                $orchestrator = OrchestratorFactory::make($server);
                $orchestrator->scale($this->application, $this->replicas);
            }

            $this->dispatch('success', 'Replicas updated successfully');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate([
                'replicas' => 'required|integer|min:0|max:100',
                'placementConfig' => 'nullable|string',
                'nodeSelector' => 'nullable|json',
                'tolerations' => 'nullable|json',
            ]);

            $this->application->setReplicaCount($this->replicas);

            if ($this->orchestratorType === 'swarm') {
                $this->application->swarm_placement_constraints = $this->placementConfig;
            } elseif ($this->orchestratorType === 'kubernetes') {
                $this->application->kubernetes_node_selector = $this->nodeSelector;
                $this->application->kubernetes_tolerations = $this->tolerations;
            }

            $this->application->save();

            $this->dispatch('success', 'Configuration saved. Redeploy to apply changes.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.orchestration');
    }
}
```

**File**: `resources/views/livewire/project/application/orchestration.blade.php` (NEW)

```blade
<div>
    @if ($orchestratorType === 'none')
        <div class="p-4 bg-warning">
            <p>This application is deployed on a standalone Docker server.</p>
            <p>Enable Docker Swarm or Kubernetes on the server to use orchestration features.</p>
        </div>
    @else
        <form wire:submit="submit">
            <h2>{{ ucfirst($orchestratorType) }} Configuration</h2>

            <x-forms.input
                wire:model.live="replicas"
                type="number"
                label="Replicas"
                helper="Number of application instances to run"
                min="0"
                max="100"
                required
            />

            <div class="flex gap-2 mb-4">
                <x-forms.button type="button" wire:click="updateReplicas">
                    Scale Now (No Downtime)
                </x-forms.button>
            </div>

            @if ($orchestratorType === 'swarm')
                <x-forms.textarea
                    wire:model="placementConfig"
                    label="Placement Constraints"
                    placeholder="node.labels.size == large"
                    helper="Docker Swarm placement constraints (one per line)"
                    rows="4"
                />

                <div class="mt-2">
                    <p class="text-sm text-muted">Examples:</p>
                    <code class="text-xs">
                        node.labels.size == large<br>
                        node.labels.datacenter == us-east<br>
                        node.hostname != old-server
                    </code>
                </div>
            @elseif ($orchestratorType === 'kubernetes')
                <x-forms.textarea
                    wire:model="nodeSelector"
                    label="Node Selector (JSON)"
                    placeholder='{"size": "large", "ssd": "true"}'
                    helper="Kubernetes node selector in JSON format"
                    rows="3"
                />

                <x-forms.textarea
                    wire:model="tolerations"
                    label="Tolerations (JSON Array)"
                    placeholder='[{"key": "dedicated", "operator": "Equal", "value": "app", "effect": "NoSchedule"}]'
                    helper="Kubernetes tolerations in JSON array format"
                    rows="5"
                />

                <div class="mt-2">
                    <p class="text-sm text-muted">Node Selector Example:</p>
                    <code class="text-xs">
                        {"disktype": "ssd", "size": "large"}
                    </code>
                </div>
            @endif

            <div class="mt-6">
                <x-forms.button type="submit">
                    Save Configuration
                </x-forms.button>
            </div>

            <div class="mt-4 p-4 bg-info">
                <p class="font-bold">ℹ️ Note:</p>
                <p>Changing replicas here saves the configuration.</p>
                <p>Use "Scale Now" for immediate scaling without redeployment.</p>
                <p>Use "Save Configuration" + "Redeploy" to apply placement changes.</p>
            </div>
        </form>
    @endif
</div>
```

---

## Deployment Flow

### Update ApplicationDeploymentJob

**File**: `app/Jobs/ApplicationDeploymentJob.php` (modify)

```php
public function handle(): void
{
    // ... existing code ...

    $server = $this->application->destination->server;
    $orchestrator = OrchestratorFactory::make($server);

    try {
        // Build image (same for all orchestrators)
        $imageName = $this->buildImage();

        // Deploy using orchestrator
        $orchestrator->deploy($this->application, $imageName);

        $this->application->status = 'running';
        $this->application->save();

        $this->deployment_queue->status = ApplicationDeploymentStatus::FINISHED;
        $this->deployment_queue->save();
    } catch (\Throwable $e) {
        $this->deployment_queue->status = ApplicationDeploymentStatus::FAILED;
        $this->deployment_queue->save();
        throw $e;
    }
}
```

---

## API Integration

### Add Kubernetes-specific Endpoints

**File**: `app/Http/Controllers/Api/ApplicationsController.php` (add methods)

```php
/**
 * Scale application replicas
 *
 * @OA\Patch(
 *     path="/applications/{uuid}/scale",
 *     tags={"Applications"},
 *     @OA\Parameter(name="uuid", in="path", required=true),
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="replicas", type="integer", example=5)
 *         )
 *     ),
 *     @OA\Response(response=200, description="Application scaled successfully")
 * )
 */
public function scale(Request $request, string $uuid)
{
    $application = Application::ownedByCurrentTeamAPI(auth()->user()->currentTeam()->id)
        ->where('uuid', $uuid)
        ->firstOrFail();

    $request->validate(['replicas' => 'required|integer|min:0|max:100']);

    $application->setReplicaCount($request->replicas);

    // Scale immediately if running
    if ($application->isRunning()) {
        $server = $application->destination->server;
        $orchestrator = OrchestratorFactory::make($server);
        $orchestrator->scale($application, $request->replicas);
    }

    return response()->json([
        'message' => 'Application scaled successfully',
        'replicas' => $request->replicas,
    ]);
}

/**
 * Get orchestrator configuration
 *
 * @OA\Get(
 *     path="/applications/{uuid}/orchestrator",
 *     tags={"Applications"},
 *     @OA\Parameter(name="uuid", in="path", required=true),
 *     @OA\Response(response=200, description="Orchestrator configuration")
 * )
 */
public function getOrchestrator(string $uuid)
{
    $application = Application::ownedByCurrentTeamAPI(auth()->user()->currentTeam()->id)
        ->where('uuid', $uuid)
        ->firstOrFail();

    $server = $application->destination->server;
    $orchestratorType = $server->getOrchestrator();

    $config = [
        'type' => $orchestratorType,
        'replicas' => $application->getReplicaCount(),
    ];

    if ($orchestratorType === 'swarm') {
        $config['placement_constraints'] = $application->swarm_placement_constraints;
    } elseif ($orchestratorType === 'kubernetes') {
        $config['node_selector'] = json_decode($application->kubernetes_node_selector ?? '{}');
        $config['tolerations'] = json_decode($application->kubernetes_tolerations ?? '[]');
        $config['namespace'] = $server->settings->kubernetes_namespace;
    }

    return response()->json($config);
}
```

---

## Testing Strategy

### 1. Unit Tests

**File**: `tests/Unit/OrchestratorTest.php`

```php
<?php

use App\Models\Application;
use App\Models\Server;
use App\Services\Orchestrator\OrchestratorFactory;
use App\Services\Orchestrator\DockerSwarmOrchestrator;
use App\Services\Orchestrator\KubernetesOrchestrator;
use App\Services\Orchestrator\StandaloneDockerOrchestrator;

it('creates correct orchestrator for swarm server', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('settings')->andReturn(
        (object) ['orchestrator' => 'swarm']
    );

    $orchestrator = OrchestratorFactory::make($server);

    expect($orchestrator)->toBeInstanceOf(DockerSwarmOrchestrator::class);
});

it('creates correct orchestrator for kubernetes server', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('settings')->andReturn(
        (object) ['orchestrator' => 'kubernetes']
    );

    $orchestrator = OrchestratorFactory::make($server);

    expect($orchestrator)->toBeInstanceOf(KubernetesOrchestrator::class);
});

it('creates correct orchestrator for standalone server', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('settings')->andReturn(
        (object) ['orchestrator' => 'none']
    );

    $orchestrator = OrchestratorFactory::make($server);

    expect($orchestrator)->toBeInstanceOf(StandaloneDockerOrchestrator::class);
});
```

### 2. Feature Tests

**File**: `tests/Feature/KubernetesDeploymentTest.php`

```php
<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('can configure server for kubernetes', function () {
    $server = Server::factory()->create([
        'team_id' => $this->user->currentTeam()->id,
    ]);

    $response = $this->post(route('server.kubernetes.update', $server->uuid), [
        'orchestrator' => 'kubernetes',
        'is_kubernetes_master' => true,
        'namespace' => 'coolify',
        'ingress_class' => 'nginx',
    ]);

    $response->assertStatus(200);

    $server->refresh();
    expect($server->settings->orchestrator)->toBe('kubernetes');
    expect($server->settings->is_kubernetes_master)->toBeTrue();
    expect($server->settings->kubernetes_namespace)->toBe('coolify');
});

it('can set kubernetes replicas via API', function () {
    $application = Application::factory()->create([
        'team_id' => $this->user->currentTeam()->id,
    ]);

    $response = $this->patchJson(route('api.applications.scale', $application->uuid), [
        'replicas' => 5,
    ]);

    $response->assertStatus(200);
    $application->refresh();
    expect($application->kubernetes_replicas)->toBe(5);
});
```

---

## Migration Path

### Migrating Existing Swarm Deployments

Users with existing Docker Swarm deployments can:

1. **Keep using Swarm** - No migration needed
2. **Migrate to Kubernetes** - Follow migration guide

**Migration Script** (for automated migration):

```bash
#!/bin/bash
# migrate-swarm-to-k8s.sh

APPLICATION_UUID="your-app-uuid"
API_TOKEN="your-api-token"
COOLIFY_URL="https://coolify.example.com"

# Get current Swarm configuration
SWARM_CONFIG=$(curl -s -H "Authorization: Bearer $API_TOKEN" \
  "$COOLIFY_URL/api/v1/applications/$APPLICATION_UUID/orchestrator")

REPLICAS=$(echo $SWARM_CONFIG | jq -r '.replicas')

# Convert Swarm placement constraints to Kubernetes node selector
# (Manual mapping required - constraints differ between orchestrators)

# Update to Kubernetes
curl -X PATCH "$COOLIFY_URL/api/v1/applications/$APPLICATION_UUID" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"kubernetes_replicas\": $REPLICAS,
    \"kubernetes_node_selector\": \"{\\\"size\\\": \\\"large\\\"}\"
  }"

# Redeploy
curl -X POST "$COOLIFY_URL/api/v1/applications/$APPLICATION_UUID/deploy" \
  -H "Authorization: Bearer $API_TOKEN"
```

---

## Documentation

### 1. User Documentation

**File**: `KUBERNETES_USER_GUIDE.md`

```markdown
# Using Kubernetes with Coolify

## Prerequisites

- Kubernetes cluster (v1.24+)
- kubectl installed on Coolify server
- Ingress controller (nginx, traefik, etc.)
- cert-manager (for SSL)

## Setup Steps

1. **Initialize Kubernetes Cluster**
2. **Configure Server in Coolify**
3. **Deploy Applications**
4. **Scale and Manage**

## Comparison: Swarm vs Kubernetes

| Feature | Docker Swarm | Kubernetes |
|---------|--------------|------------|
| Setup Complexity | Low | High |
| Resource Overhead | ~300MB | ~2-4GB |
| Scalability | 1-100 nodes | 1-5000 nodes |
| Features | Good | Excellent |
| Learning Curve | Easy | Steep |

## When to Use Kubernetes

- **Large Scale**: 20+ servers
- **Advanced Features**: Auto-scaling, service mesh
- **Enterprise**: Compliance requirements
- **Multi-Cloud**: Deploy across clouds

## When to Use Swarm

- **Small Scale**: 1-20 servers
- **Simplicity**: Easy setup
- **Cost**: Lower overhead
```

### 2. Developer Documentation

Add to CLAUDE.md:

```markdown
## Kubernetes Integration

### Orchestrator Pattern

Coolify uses an orchestrator abstraction layer:

```php
$server = $application->destination->server;
$orchestrator = OrchestratorFactory::make($server);
$orchestrator->deploy($application, $image);
```

Supported orchestrators:
- `StandaloneDockerOrchestrator` - Single container
- `DockerSwarmOrchestrator` - Docker Swarm services
- `KubernetesOrchestrator` - Kubernetes deployments

### Adding New Orchestrator

1. Implement `OrchestratorInterface`
2. Add to `OrchestratorFactory`
3. Update database schema
4. Create Livewire components
5. Write tests
```

---

## Phase-by-Phase Rollout

### Phase 1: Foundation (Week 1-2)
- ✅ Database migrations
- ✅ Orchestrator interface
- ✅ Basic models
- ✅ Unit tests

### Phase 2: Kubernetes Core (Week 3-4)
- ✅ KubernetesOrchestrator implementation
- ✅ YAML manifest generation
- ✅ kubectl integration
- ✅ Basic deployment flow

### Phase 3: UI Implementation (Week 5)
- ✅ Server configuration UI
- ✅ Application scaling UI
- ✅ Orchestration tab
- ✅ Status indicators

### Phase 4: Advanced Features (Week 6)
- ✅ Node selectors
- ✅ Tolerations
- ✅ Resource limits
- ✅ Health checks
- ✅ Ingress configuration

### Phase 5: Polish & Testing (Week 7)
- ✅ Integration tests
- ✅ Bug fixes
- ✅ Performance optimization
- ✅ Error handling

### Phase 6: Documentation & Launch (Week 8)
- ✅ User documentation
- ✅ Migration guides
- ✅ API documentation
- ✅ Blog post/announcement

---

## Summary

### What Needs to be Built

1. **Database** (3 migrations, 2 new models)
2. **Backend** (1 interface, 3 orchestrators, factory)
3. **Frontend** (2 Livewire components, 2 blade views)
4. **Deployment** (1 job modification)
5. **API** (2 new endpoints)
6. **Tests** (10+ test files)
7. **Documentation** (2 guides)

### Estimated Lines of Code

- **Backend PHP**: ~2,500 lines
- **Frontend Blade/Livewire**: ~800 lines
- **Tests**: ~1,200 lines
- **Documentation**: ~2,000 lines
- **Total**: ~6,500 lines

### Key Benefits for Users

1. **Choice**: Select orchestrator based on needs
2. **Flexibility**: Switch between orchestrators
3. **Enterprise-Ready**: K8s for large deployments
4. **Backward Compatible**: Existing Swarm deployments unaffected
5. **Future-Proof**: Support latest container technologies

---

## Next Steps

1. **Approve this plan**
2. **Start with Phase 1** (database migrations)
3. **Build orchestrator abstraction**
4. **Implement Kubernetes orchestrator**
5. **Create UI components**
6. **Test thoroughly**
7. **Document and launch**

**Would you like me to start implementing Phase 1?** 🚀
