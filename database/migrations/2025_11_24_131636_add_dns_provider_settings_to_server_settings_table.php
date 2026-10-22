<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The wildcard SSL migration (2025_10_28_000001) already adds these columns, so only
     * add whichever is missing. Without the guards this fails on every fresh database and
     * blocks all later migrations.
     */
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('server_settings', 'dns_provider')) {
                $table->string('dns_provider')->nullable()->after('wildcard_domain');
            }
            if (! Schema::hasColumn('server_settings', 'dns_provider_credentials')) {
                $table->text('dns_provider_credentials')->nullable()->after('dns_provider');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * The columns belong to 2025_10_28_000001, whose rollback drops them.
     */
    public function down(): void {}
};
