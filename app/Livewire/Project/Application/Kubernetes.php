<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Kubernetes extends Component
{
    use AuthorizesRequests;

    public Application $application;

    #[Validate('required|integer|min:1')]
    public int $kubernetesReplicas;

    #[Validate(['nullable', 'json'])]
    public ?string $kubernetesNodeSelector = null;

    #[Validate(['nullable', 'json'])]
    public ?string $kubernetesTolerations = null;

    #[Validate(['nullable', 'json'])]
    public ?string $kubernetesAffinity = null;

    #[Validate(['nullable', 'json'])]
    public ?string $kubernetesPodLabels = null;

    #[Validate(['nullable', 'json'])]
    public ?string $kubernetesServiceAnnotations = null;

    #[Validate('required|in:ClusterIP,NodePort,LoadBalancer')]
    public string $kubernetesServiceType;

    public function mount()
    {
        try {
            $this->authorize('view', $this->application);
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->application->kubernetes_replicas = $this->kubernetesReplicas;
            $this->application->kubernetes_node_selector = $this->kubernetesNodeSelector;
            $this->application->kubernetes_tolerations = $this->kubernetesTolerations;
            $this->application->kubernetes_affinity = $this->kubernetesAffinity;
            $this->application->kubernetes_pod_labels = $this->kubernetesPodLabels;
            $this->application->kubernetes_service_annotations = $this->kubernetesServiceAnnotations;
            $this->application->kubernetes_service_type = $this->kubernetesServiceType;
            $this->application->save();
        } else {
            $this->kubernetesReplicas = $this->application->kubernetes_replicas ?? 1;
            $this->kubernetesNodeSelector = $this->application->kubernetes_node_selector;
            $this->kubernetesTolerations = $this->application->kubernetes_tolerations;
            $this->kubernetesAffinity = $this->application->kubernetes_affinity;
            $this->kubernetesPodLabels = $this->application->kubernetes_pod_labels;
            $this->kubernetesServiceAnnotations = $this->application->kubernetes_service_annotations;
            $this->kubernetesServiceType = $this->application->kubernetes_service_type ?? 'ClusterIP';
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->application);
            $this->syncData(true);
            $this->dispatch('success', 'Kubernetes settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->application);
            $this->syncData(true);
            $this->dispatch('success', 'Kubernetes settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.kubernetes');
    }
}
