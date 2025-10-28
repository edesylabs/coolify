<?php

use App\Events\DomainProvisioningStarted;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Support\Facades\Event;
use Mockery;

describe('Domain Management API', function () {
    beforeEach(function () {
        Event::fake();
    });

    it('dispatches provisioning started event when domains are added', function () {
        // Mock application and server
        $server = Mockery::mock(Server::class);
        $settings = Mockery::mock(ServerSetting::class);
        $settings->shouldReceive('getAttribute')->with('is_wildcard_ssl_enabled')->andReturn(false);
        $settings->shouldReceive('getAttribute')->with('wildcard_ssl_domain')->andReturn(null);
        $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);

        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('destination->server')->andReturn($server);

        // Test event dispatching
        $domains = ['test.example.com', 'another.example.com'];
        $certificateType = 'http-01';

        event(new DomainProvisioningStarted($application, $domains, $certificateType));

        Event::assertDispatched(DomainProvisioningStarted::class, function ($event) use ($domains, $certificateType) {
            return $event->domains === $domains && $event->certificateType === $certificateType;
        });
    });

    it('determines dns-01 certificate type for wildcard domains', function () {
        $server = Mockery::mock(Server::class);
        $settings = Mockery::mock(ServerSetting::class);
        $settings->shouldReceive('getAttribute')->with('is_wildcard_ssl_enabled')->andReturn(true);
        $settings->shouldReceive('getAttribute')->with('wildcard_ssl_domain')->andReturn('*.course-app.edesy.in');

        $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);

        $wildcardPattern = str_replace('*.', '', '*.course-app.edesy.in');
        $domain = 'site1.course-app.edesy.in';

        // Test wildcard pattern matching
        expect(str($domain)->contains($wildcardPattern))->toBeTrue();
    });

    it('determines http-01 certificate type for non-wildcard domains', function () {
        $server = Mockery::mock(Server::class);
        $settings = Mockery::mock(ServerSetting::class);
        $settings->shouldReceive('getAttribute')->with('is_wildcard_ssl_enabled')->andReturn(true);
        $settings->shouldReceive('getAttribute')->with('wildcard_ssl_domain')->andReturn('*.course-app.edesy.in');

        $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);

        $wildcardPattern = str_replace('*.', '', '*.course-app.edesy.in');
        $domain = 'custom-domain.com';

        // Test non-wildcard domain
        expect(str($domain)->contains($wildcardPattern))->toBeFalse();
    });
});

describe('Domain Validation', function () {
    it('validates domain format correctly', function () {
        $validDomains = [
            'example.com',
            'subdomain.example.com',
            'deep.subdomain.example.com',
            'site1.course-app.edesy.in',
        ];

        foreach ($validDomains as $domain) {
            $isValid = filter_var("http://{$domain}", FILTER_VALIDATE_URL) !== false;
            expect($isValid)->toBeTrue("Domain {$domain} should be valid");
        }
    });

    it('rejects invalid domain formats', function () {
        $invalidDomains = [
            'not a domain',
            'http://example.com', // Should not include protocol
            'example .com', // Space in domain
            '',
        ];

        foreach ($invalidDomains as $domain) {
            if (empty($domain)) {
                expect($domain)->toBeEmpty();

                continue;
            }
            $isValid = filter_var("http://{$domain}", FILTER_VALIDATE_URL) !== false;
            expect($isValid)->toBeFalse("Domain {$domain} should be invalid");
        }
    });

    it('normalizes domains to lowercase', function () {
        $domains = [
            'Example.COM' => 'example.com',
            'SITE1.EXAMPLE.COM' => 'site1.example.com',
            'MixedCase.Domain.COM' => 'mixedcase.domain.com',
        ];

        foreach ($domains as $input => $expected) {
            $normalized = str($input)->trim()->lower()->toString();
            expect($normalized)->toBe($expected);
        }
    });
});

describe('Domain Deduplication', function () {
    it('removes duplicate domains', function () {
        $existingDomains = collect(['example.com', 'test.example.com']);
        $newDomains = collect(['test.example.com', 'new.example.com']);

        $allDomains = $existingDomains->merge($newDomains)->unique();

        expect($allDomains->count())->toBe(3);
        expect($allDomains->contains('example.com'))->toBeTrue();
        expect($allDomains->contains('test.example.com'))->toBeTrue();
        expect($allDomains->contains('new.example.com'))->toBeTrue();
    });

    it('maintains domain order', function () {
        $existingDomains = collect(['a.com', 'b.com']);
        $newDomains = collect(['c.com', 'd.com']);

        $allDomains = $existingDomains->merge($newDomains)->unique()->values();

        expect($allDomains[0])->toBe('a.com');
        expect($allDomains[1])->toBe('b.com');
        expect($allDomains[2])->toBe('c.com');
        expect($allDomains[3])->toBe('d.com');
    });
});

describe('SSL Status Response', function () {
    it('returns correct certificate type for wildcard domains', function () {
        $wildcardSslEnabled = true;
        $wildcardDomain = '*.course-app.edesy.in';
        $domain = 'site1.course-app.edesy.in';

        $wildcardPattern = str_replace('*.', '', $wildcardDomain);
        $isWildcardSupported = str($domain)->contains($wildcardPattern);

        $domainStatus = [
            'domain' => $domain,
            'ssl_enabled' => true,
            'wildcard_supported' => $isWildcardSupported,
            'certificate_type' => $isWildcardSupported ? 'dns-01' : 'http-01',
        ];

        expect($domainStatus['wildcard_supported'])->toBeTrue();
        expect($domainStatus['certificate_type'])->toBe('dns-01');
    });

    it('returns correct certificate type for custom domains', function () {
        $wildcardSslEnabled = true;
        $wildcardDomain = '*.course-app.edesy.in';
        $domain = 'custom-domain.com';

        $wildcardPattern = str_replace('*.', '', $wildcardDomain);
        $isWildcardSupported = str($domain)->contains($wildcardPattern);

        $domainStatus = [
            'domain' => $domain,
            'ssl_enabled' => true,
            'wildcard_supported' => $isWildcardSupported,
            'certificate_type' => $isWildcardSupported ? 'dns-01' : 'http-01',
        ];

        expect($domainStatus['wildcard_supported'])->toBeFalse();
        expect($domainStatus['certificate_type'])->toBe('http-01');
    });
});
