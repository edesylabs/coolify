<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add orchestrator type and Kubernetes settings to server_settings
        Schema::table('server_settings', function (Blueprint $table) {
            // Add orchestrator type enum (none, swarm, kubernetes)
            $table->enum('orchestrator', ['none', 'swarm', 'kubernetes'])
                ->default('none')
                ->after('is_usable');

            // Kubernetes-specific settings
            $table->boolean('is_kubernetes_master')->default(false)->after('orchestrator');
            $table->boolean('is_kubernetes_worker')->default(false)->after('is_kubernetes_master');
            $table->string('kubernetes_version')->nullable()->after('is_kubernetes_worker');
            $table->text('kubernetes_config')->nullable()->after('kubernetes_version'); // base64 kubeconfig
            $table->string('kubernetes_namespace')->default('default')->after('kubernetes_config');
            $table->boolean('kubernetes_use_ingress')->default(true)->after('kubernetes_namespace');
            $table->string('kubernetes_ingress_class')->default('nginx')->after('kubernetes_use_ingress');
            $table->string('kubernetes_storage_class')->nullable()->after('kubernetes_ingress_class');
        });

        // Add Kubernetes-specific application settings
        Schema::table('applications', function (Blueprint $table) {
            // Kubernetes replica count (separate from swarm_replicas)
            $table->integer('kubernetes_replicas')->nullable()->after('swarm_placement_constraints');

            // Kubernetes node selector (JSON)
            $table->text('kubernetes_node_selector')->nullable()->after('kubernetes_replicas');

            // Kubernetes tolerations (JSON array)
            $table->text('kubernetes_tolerations')->nullable()->after('kubernetes_node_selector');

            // Kubernetes affinity rules (JSON)
            $table->text('kubernetes_affinity')->nullable()->after('kubernetes_tolerations');

            // Kubernetes pod labels (JSON)
            $table->text('kubernetes_pod_labels')->nullable()->after('kubernetes_affinity');

            // Kubernetes service annotations (JSON)
            $table->text('kubernetes_service_annotations')->nullable()->after('kubernetes_pod_labels');

            // Kubernetes service type
            $table->enum('kubernetes_service_type', ['ClusterIP', 'NodePort', 'LoadBalancer'])
                ->default('ClusterIP')
                ->after('kubernetes_service_annotations');
        });

        // Migrate existing Swarm servers to use orchestrator field
        DB::table('server_settings')
            ->where('is_swarm_manager', true)
            ->orWhere('is_swarm_worker', true)
            ->update(['orchestrator' => 'swarm']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
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
