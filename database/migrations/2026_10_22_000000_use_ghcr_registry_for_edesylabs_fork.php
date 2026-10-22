<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The edesylabs fork publishes its coolify image only to ghcr.io (upstream's helper image is
     * on ghcr.io too). Upstream defaults docker_registry_url to docker.io, which would make the
     * in-app upgrade pull docker.io/edesylabs/coolify, an image that does not exist.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('docker_registry_url')->default('ghcr.io')->change();
        });

        DB::table('instance_settings')
            ->where('docker_registry_url', 'docker.io')
            ->update(['docker_registry_url' => 'ghcr.io']);
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('docker_registry_url')->default('docker.io')->change();
        });
    }
};
