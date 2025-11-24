<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            // Wildcard SSL certificate support
            $table->boolean('is_wildcard_ssl_enabled')->default(false)->after('wildcard_domain');
            $table->string('wildcard_ssl_domain')->nullable()->after('is_wildcard_ssl_enabled');

            // DNS Provider for DNS-01 challenge
            $table->string('dns_provider')->nullable()->after('wildcard_ssl_domain'); // cloudflare, route53, digitalocean, etc.
            $table->text('dns_provider_credentials')->nullable()->after('dns_provider'); // encrypted JSON

            // ACME configuration
            $table->string('acme_email')->nullable()->after('dns_provider_credentials');
            $table->boolean('use_staging_acme')->default(false)->after('acme_email'); // for testing
        });

        Schema::table('ssl_certificates', function (Blueprint $table) {
            // Mark wildcard certificates
            $table->boolean('is_wildcard')->default(false)->after('is_ca_certificate');
            $table->string('dns_provider')->nullable()->after('is_wildcard');
        });
    }

    public function down()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_wildcard_ssl_enabled',
                'wildcard_ssl_domain',
                'dns_provider',
                'dns_provider_credentials',
                'acme_email',
                'use_staging_acme',
            ]);
        });

        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->dropColumn(['is_wildcard', 'dns_provider']);
        });
    }
};
