<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KubernetesCluster extends Model
{
    use HasFactory;

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
        'ca_certificate' => 'encrypted',
        'client_certificate' => 'encrypted',
        'client_key' => 'encrypted',
    ];

    /**
     * Get the server that owns this Kubernetes cluster
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the namespaces for this cluster
     */
    public function namespaces(): HasMany
    {
        return $this->hasMany(KubernetesNamespace::class);
    }

    /**
     * Check if kubectl is available on the server
     */
    public function isKubectlAvailable(): bool
    {
        try {
            $result = instant_remote_process(['which kubectl'], $this->server, false);
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get cluster info
     */
    public function getClusterInfo(): array
    {
        try {
            $output = instant_remote_process([
                'kubectl cluster-info --request-timeout=5s'
            ], $this->server, false);

            return [
                'accessible' => true,
                'info' => $output,
            ];
        } catch (\Throwable $e) {
            return [
                'accessible' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all nodes in the cluster
     */
    public function getNodes(): array
    {
        try {
            $output = instant_remote_process([
                "kubectl get nodes -o json"
            ], $this->server, false);

            $nodesData = json_decode($output, true);
            $nodes = [];

            foreach ($nodesData['items'] ?? [] as $node) {
                $nodes[] = [
                    'name' => $node['metadata']['name'],
                    'status' => $this->getNodeStatus($node),
                    'roles' => $this->getNodeRoles($node),
                    'version' => $node['status']['nodeInfo']['kubeletVersion'] ?? 'unknown',
                    'cpu' => $node['status']['capacity']['cpu'] ?? 'unknown',
                    'memory' => $node['status']['capacity']['memory'] ?? 'unknown',
                ];
            }

            return $nodes;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Extract node status from node data
     */
    protected function getNodeStatus(array $nodeData): string
    {
        $conditions = $nodeData['status']['conditions'] ?? [];
        foreach ($conditions as $condition) {
            if ($condition['type'] === 'Ready') {
                return $condition['status'] === 'True' ? 'Ready' : 'NotReady';
            }
        }
        return 'Unknown';
    }

    /**
     * Extract node roles from node data
     */
    protected function getNodeRoles(array $nodeData): array
    {
        $labels = $nodeData['metadata']['labels'] ?? [];
        $roles = [];

        foreach ($labels as $key => $value) {
            if (str_starts_with($key, 'node-role.kubernetes.io/')) {
                $role = str_replace('node-role.kubernetes.io/', '', $key);
                $roles[] = $role;
            }
        }

        return empty($roles) ? ['<none>'] : $roles;
    }

    /**
     * Get Kubernetes version
     */
    public function getVersion(): ?string
    {
        try {
            $output = instant_remote_process([
                "kubectl version --short --output=json"
            ], $this->server, false);

            $versionData = json_decode($output, true);
            return $versionData['serverVersion']['gitVersion'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
