<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Orchestrator Configuration</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($orchestrator === 'kubernetes')
                <x-forms.button wire:click="testKubernetesConnection" type="button">
                    Test Connection
                </x-forms.button>
            @endif
        </div>

        <div class="flex flex-col gap-2 py-4">
            <x-forms.select id="orchestrator" label="Orchestrator Type"
                helper="Select the orchestration system for this server">
                <option value="none">None (Standalone Docker)</option>
                <option value="swarm">Docker Swarm</option>
                <option value="kubernetes">Kubernetes</option>
            </x-forms.select>

            @if ($orchestrator === 'swarm')
                <div class="pt-4">
                    <h3>Docker Swarm Settings</h3>
                    <p class="text-sm text-warning pt-2">
                        Make sure to initialize Docker Swarm on this server first: <code>docker swarm init</code>
                    </p>
                </div>
            @endif

            @if ($orchestrator === 'kubernetes')
                <div class="pt-4">
                    <h3>Kubernetes Settings</h3>

                    <div class="flex flex-col gap-2 pt-2">
                        <x-forms.checkbox id="isKubernetesMaster" label="Kubernetes Master Node"
                            helper="Is this server a Kubernetes master/control-plane node?" />

                        <x-forms.checkbox id="isKubernetesWorker" label="Kubernetes Worker Node"
                            helper="Is this server a Kubernetes worker node?" />
                    </div>

                    <div class="pt-4">
                        <x-forms.input id="kubernetesNamespace" label="Default Namespace"
                            helper="Namespace where applications will be deployed" required />

                        <x-forms.checkbox instantSave id="kubernetesUseIngress" label="Use Ingress Controller"
                            helper="Create Ingress resources for applications with domains" />

                        @if ($kubernetesUseIngress)
                            <x-forms.input id="kubernetesIngressClass" label="Ingress Class"
                                helper="Ingress controller class name (e.g., nginx, traefik)" />
                        @endif

                        <x-forms.input id="kubernetesStorageClass" label="Storage Class (Optional)"
                            helper="Default storage class for persistent volumes" />
                    </div>

                    <div class="pt-4">
                        <h4 class="text-sm font-semibold">Prerequisites:</h4>
                        <ul class="text-sm text-warning pl-4 pt-2 list-disc">
                            <li>kubectl must be installed on this server</li>
                            <li>Server must have access to the Kubernetes cluster</li>
                            <li>Kubeconfig must be configured (~/.kube/config)</li>
                            <li>If using Ingress, an Ingress controller must be installed (nginx, traefik, etc.)</li>
                            <li>For automatic SSL, cert-manager should be installed in the cluster</li>
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </form>
</div>
