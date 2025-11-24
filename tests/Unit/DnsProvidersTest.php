<?php

use App\Services\DnsProviders\CloudflareProvider;
use App\Services\DnsProviders\DigitalOceanProvider;
use App\Services\DnsProviders\DnsProviderFactory;
use App\Services\DnsProviders\Route53Provider;

describe('CloudflareProvider', function () {
    it('implements DnsProviderInterface', function () {
        $provider = new CloudflareProvider(['api_token' => 'test']);

        expect($provider)->toBeInstanceOf(\App\Contracts\DnsProviderInterface::class);
    });

    it('returns correct provider name', function () {
        $provider = new CloudflareProvider(['api_token' => 'test']);

        expect($provider->getProviderName())->toBe('cloudflare');
    });

    it('extracts root domain correctly', function () {
        $provider = new CloudflareProvider(['api_token' => 'test']);
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('extractRootDomain');
        $method->setAccessible(true);

        expect($method->invoke($provider, '_acme-challenge.app.example.com'))->toBe('example.com');
        expect($method->invoke($provider, 'subdomain.example.com'))->toBe('example.com');
        expect($method->invoke($provider, 'example.com'))->toBe('example.com');
    });
});

describe('Route53Provider', function () {
    it('implements DnsProviderInterface', function () {
        $provider = new Route53Provider([
            'access_key_id' => 'test',
            'secret_access_key' => 'test',
        ]);

        expect($provider)->toBeInstanceOf(\App\Contracts\DnsProviderInterface::class);
    });

    it('returns correct provider name', function () {
        $provider = new Route53Provider([
            'access_key_id' => 'test',
            'secret_access_key' => 'test',
        ]);

        expect($provider->getProviderName())->toBe('route53');
    });

    it('extracts root domain correctly', function () {
        $provider = new Route53Provider([
            'access_key_id' => 'test',
            'secret_access_key' => 'test',
        ]);
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('extractRootDomain');
        $method->setAccessible(true);

        expect($method->invoke($provider, '_acme-challenge.app.example.com'))->toBe('example.com');
        expect($method->invoke($provider, 'example.com'))->toBe('example.com');
    });

    it('generates correct XML for change batch', function () {
        $provider = new Route53Provider([
            'access_key_id' => 'test',
            'secret_access_key' => 'test',
        ]);
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('buildChangeResourceRecordSetsXml');
        $method->setAccessible(true);

        $changeBatch = [
            'Changes' => [
                [
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'Name' => '_acme-challenge.example.com',
                        'Type' => 'TXT',
                        'TTL' => 120,
                        'ResourceRecords' => [
                            ['Value' => '"test-value"'],
                        ],
                    ],
                ],
            ],
        ];

        $xml = $method->invoke($provider, $changeBatch);

        expect($xml)->toContain('UPSERT');
        expect($xml)->toContain('_acme-challenge.example.com');
        expect($xml)->toContain('TXT');
        expect($xml)->toContain('"test-value"');
    });
});

describe('DigitalOceanProvider', function () {
    it('implements DnsProviderInterface', function () {
        $provider = new DigitalOceanProvider(['auth_token' => 'test']);

        expect($provider)->toBeInstanceOf(\App\Contracts\DnsProviderInterface::class);
    });

    it('returns correct provider name', function () {
        $provider = new DigitalOceanProvider(['auth_token' => 'test']);

        expect($provider->getProviderName())->toBe('digitalocean');
    });

    it('extracts base domain correctly', function () {
        $provider = new DigitalOceanProvider(['auth_token' => 'test']);
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('extractBaseDomain');
        $method->setAccessible(true);

        expect($method->invoke($provider, '_acme-challenge.app.example.com'))->toBe('example.com');
        expect($method->invoke($provider, 'app.example.com'))->toBe('example.com');
        expect($method->invoke($provider, 'example.com'))->toBe('example.com');
    });

    it('extracts subdomain correctly', function () {
        $provider = new DigitalOceanProvider(['auth_token' => 'test']);
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('extractSubdomain');
        $method->setAccessible(true);

        expect($method->invoke($provider, '_acme-challenge.app.example.com', 'example.com'))
            ->toBe('_acme-challenge.app');
        expect($method->invoke($provider, 'app.example.com', 'example.com'))
            ->toBe('app');
        expect($method->invoke($provider, 'example.com', 'example.com'))
            ->toBe('@');
    });
});

describe('DnsProviderFactory', function () {
    it('creates Cloudflare provider', function () {
        $provider = DnsProviderFactory::create('cloudflare', ['api_token' => 'test']);

        expect($provider)->toBeInstanceOf(CloudflareProvider::class);
    });

    it('creates Route53 provider', function () {
        $provider = DnsProviderFactory::create('route53', [
            'access_key_id' => 'test',
            'secret_access_key' => 'test',
        ]);

        expect($provider)->toBeInstanceOf(Route53Provider::class);
    });

    it('creates DigitalOcean provider', function () {
        $provider = DnsProviderFactory::create('digitalocean', ['auth_token' => 'test']);

        expect($provider)->toBeInstanceOf(DigitalOceanProvider::class);
    });

    it('throws exception for unsupported provider', function () {
        DnsProviderFactory::create('unsupported', []);
    })->throws(\InvalidArgumentException::class, 'Unsupported DNS provider: unsupported');

    it('returns list of supported providers', function () {
        $providers = DnsProviderFactory::getSupportedProviders();

        expect($providers)->toHaveKeys(['cloudflare', 'route53', 'digitalocean']);
        expect($providers['cloudflare'])->toBe('Cloudflare');
        expect($providers['route53'])->toBe('AWS Route53');
        expect($providers['digitalocean'])->toBe('DigitalOcean');
    });

    it('checks if provider is supported', function () {
        expect(DnsProviderFactory::isSupported('cloudflare'))->toBeTrue();
        expect(DnsProviderFactory::isSupported('route53'))->toBeTrue();
        expect(DnsProviderFactory::isSupported('digitalocean'))->toBeTrue();
        expect(DnsProviderFactory::isSupported('invalid'))->toBeFalse();
    });

    it('returns required fields for Cloudflare', function () {
        $fields = DnsProviderFactory::getRequiredFields('cloudflare');

        expect($fields)->toHaveKeys(['api_token', 'email', 'api_key', 'zone_id']);
        expect($fields['api_token']['label'])->toBe('API Token');
        expect($fields['api_token']['type'])->toBe('password');
    });

    it('returns required fields for Route53', function () {
        $fields = DnsProviderFactory::getRequiredFields('route53');

        expect($fields)->toHaveKeys(['access_key_id', 'secret_access_key', 'region']);
        expect($fields['access_key_id']['required'])->toBeTrue();
        expect($fields['region']['default'])->toBe('us-east-1');
    });

    it('returns required fields for DigitalOcean', function () {
        $fields = DnsProviderFactory::getRequiredFields('digitalocean');

        expect($fields)->toHaveKey('auth_token');
        expect($fields['auth_token']['required'])->toBeTrue();
    });

    it('returns documentation URLs', function () {
        expect(DnsProviderFactory::getDocumentationUrl('cloudflare'))
            ->toContain('cloudflare.com');
        expect(DnsProviderFactory::getDocumentationUrl('route53'))
            ->toContain('aws.amazon.com');
        expect(DnsProviderFactory::getDocumentationUrl('digitalocean'))
            ->toContain('digitalocean.com');
        expect(DnsProviderFactory::getDocumentationUrl('invalid'))
            ->toBeNull();
    });
});

describe('DNS Provider Integration', function () {
    it('validates DNS propagation check format', function () {
        $provider = new CloudflareProvider(['api_token' => 'test']);

        // Mock dns_get_record behavior
        $result = $provider->verifyDnsPropagation('_acme-challenge.example.com', 'test-value');

        expect($result)->toBeBool();
    });

    it('handles provider instantiation with minimal credentials', function () {
        // Cloudflare with API token only
        $cf = new CloudflareProvider(['api_token' => 'test-token']);
        expect($cf->getProviderName())->toBe('cloudflare');

        // Route53 with required fields
        $r53 = new Route53Provider([
            'access_key_id' => 'AKIATEST',
            'secret_access_key' => 'test-secret',
        ]);
        expect($r53->getProviderName())->toBe('route53');

        // DigitalOcean with token
        $do = new DigitalOceanProvider(['auth_token' => 'test-do-token']);
        expect($do->getProviderName())->toBe('digitalocean');
    });
});
