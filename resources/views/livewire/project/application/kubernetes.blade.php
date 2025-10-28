<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Kubernetes Configuration</h2>
            @can('update', $application)
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
            @else
                <x-forms.button type="submit" disabled
                    title="You don't have permission to update this application. Contact your team administrator for access.">
                    Save
                </x-forms.button>
            @endcan
        </div>
        <div class="flex flex-col gap-2 py-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input id="kubernetesReplicas" label="Replicas" required helper="Number of pod replicas to run" canGate="update" :canResource="$application" />
                <x-forms.select id="kubernetesServiceType" label="Service Type" helper="How the service is exposed" canGate="update" :canResource="$application">
                    <option value="ClusterIP">ClusterIP (Internal)</option>
                    <option value="NodePort">NodePort</option>
                    <option value="LoadBalancer">LoadBalancer</option>
                </x-forms.select>
            </div>

            <h3 class="pt-4">Advanced Configuration</h3>

            <x-forms.textarea id="kubernetesNodeSelector" rows="5" label="Node Selector (JSON)"
                helper="Schedule pods on specific nodes. Example: {&quot;disktype&quot;: &quot;ssd&quot;}"
                placeholder='{"disktype": "ssd", "size": "large"}' canGate="update" :canResource="$application" />

            <x-forms.textarea id="kubernetesTolerations" rows="7" label="Tolerations (JSON)"
                helper="Allow pods to schedule on nodes with matching taints"
                placeholder='[{"key": "key1", "operator": "Equal", "value": "value1", "effect": "NoSchedule"}]' canGate="update" :canResource="$application" />

            <x-forms.textarea id="kubernetesAffinity" rows="10" label="Affinity Rules (JSON)"
                helper="Pod affinity and anti-affinity configuration"
                placeholder='{"nodeAffinity": {"requiredDuringSchedulingIgnoredDuringExecution": {...}}}' canGate="update" :canResource="$application" />

            <x-forms.textarea id="kubernetesPodLabels" rows="5" label="Custom Pod Labels (JSON)"
                helper="Additional labels to add to pods"
                placeholder='{"environment": "production", "team": "backend"}' canGate="update" :canResource="$application" />

            <x-forms.textarea id="kubernetesServiceAnnotations" rows="5" label="Service Annotations (JSON)"
                helper="Annotations for the Kubernetes service"
                placeholder='{"service.beta.kubernetes.io/aws-load-balancer-type": "nlb"}' canGate="update" :canResource="$application" />
        </div>
    </form>
</div>
