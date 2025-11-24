<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Services\DnsProviders\CloudflareProvider;

it('can enable wildcard SSL on server settings', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $settings = Mockery::mock(ServerSetting::class)->makePartial();

    $settings->shouldReceive('getAttribute')->with('is_wildcard_ssl_enabled')->andReturn(true);
    $settings->shouldReceive('getAttribute')->with('wildcard_ssl_domain')->andReturn('*.example.com');
    $settings->shouldReceive('getAttribute')->with('dns_provider')->andReturn('cloudflare');

    $server->settings = $settings;

    expect($settings->is_wildcard_ssl_enabled)->toBeTrue();
    expect($settings->wildcard_ssl_domain)->toBe('*.example.com');
    expect($settings->dns_provider)->toBe('cloudflare');
});

it('encrypts DNS provider credentials', function () {
    $settings = new ServerSetting();
    $credentials = [
        'api_token' => 'test-token-123',
        'email' => 'test@example.com',
    ];

    $settings->dns_provider_credentials = $credentials;

    expect($settings->dns_provider_credentials)->toBe($credentials);
});

it('validates Cloudflare provider name', function () {
    $credentials = [
        'api_token' => 'test-token',
    ];

    $provider = new CloudflareProvider($credentials);

    expect($provider->getProviderName())->toBe('cloudflare');
});

it('extracts root domain from subdomain', function () {
    $credentials = ['api_token' => 'test'];
    $provider = new CloudflareProvider($credentials);

    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('extractRootDomain');
    $method->setAccessible(true);

    $result = $method->invoke($provider, '_acme-challenge.app.example.com');

    expect($result)->toBe('example.com');
});

it('handles wildcard domain in application configuration', function () {
    // Mock server with wildcard SSL enabled
    $server = Mockery::mock(Server::class)->makePartial();
    $settings = Mockery::mock(ServerSetting::class)->makePartial();

    $settings->shouldReceive('getAttribute')->with('is_wildcard_ssl_enabled')->andReturn(true);
    $settings->shouldReceive('getAttribute')->with('wildcard_ssl_domain')->andReturn('*.course-app.edesy.in');
    $settings->shouldReceive('getAttribute')->with('dns_provider')->andReturn('cloudflare');
    $settings->shouldReceive('getAttribute')->with('dns_provider_credentials')->andReturn([
        'api_token' => 'test-token',
    ]);

    $server->settings = $settings;
    $server->shouldReceive('proxyType')->andReturn('TRAEFIK');

    expect($settings->is_wildcard_ssl_enabled)->toBeTrue();
    expect($settings->wildcard_ssl_domain)->toContain('*.');
});
