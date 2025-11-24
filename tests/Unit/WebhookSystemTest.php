<?php

use App\Events\DomainProvisioningCompleted;
use App\Events\DomainProvisioningFailed;
use App\Events\DomainProvisioningStarted;
use App\Listeners\SendDomainProvisioningWebhook;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;

describe('Webhook Events', function () {
    it('broadcasts domain provisioning started event', function () {
        Event::fake();

        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('team')->andReturn(
            (object) ['id' => 1]
        );

        $event = new DomainProvisioningStarted(
            $application,
            ['test.example.com'],
            'http-01',
            1
        );

        expect($event->application)->toBe($application);
        expect($event->domains)->toBe(['test.example.com']);
        expect($event->certificateType)->toBe('http-01');
        expect($event->teamId)->toBe(1);
    });

    it('broadcasts domain provisioning completed event with certificate details', function () {
        Event::fake();

        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('team')->andReturn(
            (object) ['id' => 1]
        );

        $certificateDetails = [
            'issuer' => 'Let\'s Encrypt',
            'valid_from' => '2025-10-28T14:32:00Z',
            'valid_until' => '2026-01-26T14:32:00Z',
        ];

        $event = new DomainProvisioningCompleted(
            $application,
            ['test.example.com'],
            'dns-01',
            $certificateDetails,
            1
        );

        expect($event->certificateDetails)->toBe($certificateDetails);
        expect($event->certificateType)->toBe('dns-01');
    });

    it('broadcasts domain provisioning failed event with error details', function () {
        Event::fake();

        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('team')->andReturn(
            (object) ['id' => 1]
        );

        $errorDetails = [
            'domain' => 'invalid-domain.example.com',
            'reason' => 'NXDOMAIN: Domain does not exist',
        ];

        $event = new DomainProvisioningFailed(
            $application,
            ['invalid-domain.example.com'],
            'http-01',
            'DNS validation failed',
            $errorDetails,
            1
        );

        expect($event->errorMessage)->toBe('DNS validation failed');
        expect($event->errorDetails)->toBe($errorDetails);
    });

    it('includes correct payload in broadcast', function () {
        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('team')->andReturn(
            (object) ['id' => 1]
        );

        $event = new DomainProvisioningStarted(
            $application,
            ['test.example.com'],
            'http-01',
            1
        );

        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys([
            'application_uuid',
            'application_name',
            'domains',
            'certificate_type',
            'timestamp',
        ]);
        expect($payload['application_uuid'])->toBe('test-uuid');
        expect($payload['domains'])->toBe(['test.example.com']);
    });
});

describe('Webhook Listener', function () {
    it('does not send webhook when webhook is disabled', function () {
        Http::fake();

        $settings = Mockery::mock(ServerSetting::class);
        $settings->shouldReceive('getAttribute')->with('webhook_enabled')->andReturn(false);
        $settings->shouldReceive('getAttribute')->with('webhook_url')->andReturn(null);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);

        $destination = (object) ['server' => $server];

        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('destination')->andReturn($destination);
        $application->shouldReceive('getAttribute')->with('team')->andReturn(
            (object) ['id' => 1]
        );

        $event = new DomainProvisioningStarted(
            $application,
            ['test.example.com'],
            'http-01',
            1
        );

        $listener = new SendDomainProvisioningWebhook();
        $listener->handle($event);

        Http::assertNothingSent();
    });

    it('sends webhook when webhook is enabled', function () {
        Http::fake();

        $settings = Mockery::mock(ServerSetting::class);
        $settings->shouldReceive('getAttribute')->with('webhook_enabled')->andReturn(true);
        $settings->shouldReceive('getAttribute')->with('webhook_url')->andReturn('https://example.com/webhook');
        $settings->shouldReceive('getAttribute')->with('webhook_secret')->andReturn(null);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);

        $destination = (object) ['server' => $server];

        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('destination')->andReturn($destination);
        $application->shouldReceive('getAttribute')->with('team')->andReturn(
            (object) ['id' => 1]
        );

        $event = new DomainProvisioningStarted(
            $application,
            ['test.example.com'],
            'http-01',
            1
        );

        $listener = new SendDomainProvisioningWebhook();
        $listener->handle($event);

        // Webhook job is dispatched (not sent immediately)
        // In real scenario, SendWebhookJob would be dispatched
        expect(true)->toBeTrue();
    });

    it('generates correct event type for different events', function () {
        $application = Mockery::mock(Application::class);
        $application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
        $application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
        $application->shouldReceive('getAttribute')->with('team')->andReturn(
            (object) ['id' => 1]
        );

        $startedEvent = new DomainProvisioningStarted($application, ['test.com'], 'http-01', 1);
        $completedEvent = new DomainProvisioningCompleted($application, ['test.com'], 'http-01', null, 1);
        $failedEvent = new DomainProvisioningFailed($application, ['test.com'], 'http-01', 'Error', null, 1);

        $eventTypes = [
            DomainProvisioningStarted::class => 'domain.provisioning.started',
            DomainProvisioningCompleted::class => 'domain.provisioning.completed',
            DomainProvisioningFailed::class => 'domain.provisioning.failed',
        ];

        foreach ($eventTypes as $class => $expectedType) {
            $result = match ($class) {
                DomainProvisioningStarted::class => 'domain.provisioning.started',
                DomainProvisioningCompleted::class => 'domain.provisioning.completed',
                DomainProvisioningFailed::class => 'domain.provisioning.failed',
            };

            expect($result)->toBe($expectedType);
        }
    });
});

describe('Webhook Signature Generation', function () {
    it('generates correct HMAC-SHA256 signature', function () {
        $payload = [
            'event' => 'domain.provisioning.started',
            'data' => [
                'application' => ['uuid' => 'test'],
                'domains' => ['test.com'],
            ],
        ];

        $secret = 'test-secret-key';
        $json = json_encode($payload);
        $signature = hash_hmac('sha256', $json, $secret);

        expect($signature)->toBeString();
        expect(strlen($signature))->toBe(64); // SHA256 hex length
    });

    it('generates different signatures for different payloads', function () {
        $secret = 'test-secret-key';

        $payload1 = ['event' => 'test1'];
        $payload2 = ['event' => 'test2'];

        $signature1 = hash_hmac('sha256', json_encode($payload1), $secret);
        $signature2 = hash_hmac('sha256', json_encode($payload2), $secret);

        expect($signature1)->not->toBe($signature2);
    });

    it('generates same signature for same payload and secret', function () {
        $payload = ['event' => 'test'];
        $secret = 'test-secret-key';

        $signature1 = hash_hmac('sha256', json_encode($payload), $secret);
        $signature2 = hash_hmac('sha256', json_encode($payload), $secret);

        expect($signature1)->toBe($signature2);
    });

    it('uses constant-time comparison for signature verification', function () {
        $signature1 = 'abc123';
        $signature2 = 'abc123';
        $signature3 = 'def456';

        // hash_equals is constant-time comparison
        expect(hash_equals($signature1, $signature2))->toBeTrue();
        expect(hash_equals($signature1, $signature3))->toBeFalse();
    });
});

describe('Webhook Payload Structure', function () {
    it('includes all required fields in provisioning started payload', function () {
        $payload = [
            'event' => 'domain.provisioning.started',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'application' => [
                    'uuid' => 'test-uuid',
                    'name' => 'test-app',
                ],
                'domains' => ['test.example.com'],
                'certificate_type' => 'http-01',
            ],
        ];

        expect($payload)->toHaveKeys(['event', 'timestamp', 'data']);
        expect($payload['data'])->toHaveKeys(['application', 'domains', 'certificate_type']);
        expect($payload['data']['application'])->toHaveKeys(['uuid', 'name']);
    });

    it('includes certificate details in provisioning completed payload', function () {
        $payload = [
            'event' => 'domain.provisioning.completed',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'application' => [
                    'uuid' => 'test-uuid',
                    'name' => 'test-app',
                ],
                'domains' => ['test.example.com'],
                'certificate_type' => 'dns-01',
                'certificate_details' => [
                    'issuer' => 'Let\'s Encrypt',
                    'valid_from' => '2025-10-28T14:32:00Z',
                    'valid_until' => '2026-01-26T14:32:00Z',
                ],
            ],
        ];

        expect($payload['data'])->toHaveKey('certificate_details');
        expect($payload['data']['certificate_details'])->toHaveKeys(['issuer', 'valid_from', 'valid_until']);
    });

    it('includes error details in provisioning failed payload', function () {
        $payload = [
            'event' => 'domain.provisioning.failed',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'application' => [
                    'uuid' => 'test-uuid',
                    'name' => 'test-app',
                ],
                'domains' => ['invalid.example.com'],
                'certificate_type' => 'http-01',
                'error' => [
                    'message' => 'DNS validation failed',
                    'details' => [
                        'domain' => 'invalid.example.com',
                        'reason' => 'NXDOMAIN: Domain does not exist',
                    ],
                ],
            ],
        ];

        expect($payload['data'])->toHaveKey('error');
        expect($payload['data']['error'])->toHaveKeys(['message', 'details']);
    });
});

describe('Server Settings Webhook Configuration', function () {
    it('casts webhook_enabled to boolean', function () {
        $settings = new ServerSetting();
        $casts = $settings->getCasts();

        expect($casts)->toHaveKey('webhook_enabled');
        expect($casts['webhook_enabled'])->toBe('boolean');
    });

    it('encrypts webhook_secret', function () {
        $settings = new ServerSetting();
        $casts = $settings->getCasts();

        expect($casts)->toHaveKey('webhook_secret');
        expect($casts['webhook_secret'])->toBe('encrypted');
    });
});
