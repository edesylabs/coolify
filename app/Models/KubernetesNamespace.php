<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KubernetesNamespace extends Model
{
    use HasFactory;

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

    /**
     * Get the cluster that owns this namespace
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KubernetesCluster::class);
    }

    /**
     * Check if namespace exists in the cluster
     */
    public function exists(): bool
    {
        try {
            $output = instant_remote_process([
                "kubectl get namespace {$this->name} -o json"
            ], $this->cluster->server, false);

            $namespaceData = json_decode($output, true);
            return isset($namespaceData['metadata']['name']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create namespace in the cluster
     */
    public function create(): bool
    {
        try {
            instant_remote_process([
                "kubectl create namespace {$this->name}"
            ], $this->cluster->server);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete namespace from the cluster
     */
    public function deleteFromCluster(): bool
    {
        try {
            instant_remote_process([
                "kubectl delete namespace {$this->name}"
            ], $this->cluster->server);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Apply resource quotas to the namespace
     */
    public function applyResourceQuotas(): bool
    {
        if (empty($this->resource_quotas)) {
            return true;
        }

        try {
            $yaml = yaml_emit([
                'apiVersion' => 'v1',
                'kind' => 'ResourceQuota',
                'metadata' => [
                    'name' => "{$this->name}-quota",
                    'namespace' => $this->name,
                ],
                'spec' => [
                    'hard' => $this->resource_quotas,
                ],
            ]);

            $tempFile = "/tmp/quota-{$this->name}.yaml";
            $base64Yaml = base64_encode($yaml);

            instant_remote_process([
                "echo '{$base64Yaml}' | base64 -d > {$tempFile}",
                "kubectl apply -f {$tempFile}",
                "rm {$tempFile}",
            ], $this->cluster->server);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get resource usage for this namespace
     */
    public function getResourceUsage(): array
    {
        try {
            $output = instant_remote_process([
                "kubectl top pods -n {$this->name} --no-headers"
            ], $this->cluster->server, false);

            $lines = explode("\n", trim($output));
            $totalCpu = 0;
            $totalMemory = 0;

            foreach ($lines as $line) {
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
                }
            }

            return [
                'cpu' => $totalCpu, // in millicores
                'memory' => $totalMemory, // in Mi
                'pods' => count($lines),
            ];
        } catch (\Throwable $e) {
            return [
                'cpu' => 0,
                'memory' => 0,
                'pods' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
}
