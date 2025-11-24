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
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_wildcard_domain_enabled')->default(false)->after('is_dynamic_domain_enabled');
            $table->string('wildcard_domain')->nullable()->after('is_wildcard_domain_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn(['is_wildcard_domain_enabled', 'wildcard_domain']);
        });
    }
};
