<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->text('webhook_url')->nullable()->after('use_staging_acme');
            $table->text('webhook_secret')->nullable()->after('webhook_url');
            $table->boolean('webhook_enabled')->default(false)->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret', 'webhook_enabled']);
        });
    }
};
