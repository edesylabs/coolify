<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Orchestrator extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $orchestrator = 'none';

    public bool $isKubernetesMaster = false;

    public bool $isKubernetesWorker = false;

    public string $kubernetesNamespace = 'default';

    public bool $kubernetesUseIngress = true;

    public string $kubernetesIngressClass = 'nginx';

    public ?string $kubernetesStorageClass = null;

    public function mount()
    {
        try {
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->server->settings->orchestrator = $this->orchestrator;
            $this->server->settings->is_kubernetes_master = $this->isKubernetesMaster;
            $this->server->settings->is_kubernetes_worker = $this->isKubernetesWorker;
            $this->server->settings->kubernetes_namespace = $this->kubernetesNamespace;
            $this->server->settings->kubernetes_use_ingress = $this->kubernetesUseIngress;
            $this->server->settings->kubernetes_ingress_class = $this->kubernetesIngressClass;
            $this->server->settings->kubernetes_storage_class = $this->kubernetesStorageClass;
            $this->server->settings->save();
        } else {
            $this->orchestrator = data_get($this->server->settings, 'orchestrator', 'none');
            $this->isKubernetesMaster = data_get($this->server->settings, 'is_kubernetes_master', false);
            $this->isKubernetesWorker = data_get($this->server->settings, 'is_kubernetes_worker', false);
            $this->kubernetesNamespace = data_get($this->server->settings, 'kubernetes_namespace', 'default');
            $this->kubernetesUseIngress = data_get($this->server->settings, 'kubernetes_use_ingress', true);
            $this->kubernetesIngressClass = data_get($this->server->settings, 'kubernetes_ingress_class', 'nginx');
            $this->kubernetesStorageClass = data_get($this->server->settings, 'kubernetes_storage_class');
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Orchestrator settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Orchestrator settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function testKubernetesConnection()
    {
        try {
            if ($this->orchestrator !== 'kubernetes') {
                $this->dispatch('error', 'Server is not configured for Kubernetes.');

                return;
            }

            $output = instant_remote_process(['kubectl version --short'], $this->server, false);

            if ($output) {
                $this->dispatch('success', 'Kubernetes connection successful!');
            } else {
                $this->dispatch('error', 'Failed to connect to Kubernetes cluster.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.orchestrator');
    }
}
