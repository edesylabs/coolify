<?php

namespace App\Listeners;

use App\Events\DomainProvisioningCompleted;
use App\Events\DomainProvisioningFailed;
use App\Events\DomainProvisioningStarted;
use App\Jobs\SendWebhookJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SendDomainProvisioningWebhook
{
    /**
     * Handle domain provisioning events.
     */
    public function handle(DomainProvisioningStarted|DomainProvisioningCompleted|DomainProvisioningFailed $event): void
    {
        $server = $event->application->destination->server;
        $settings = $server->settings;

        // Check if webhooks are enabled
        if (! $settings->webhook_enabled || empty($settings->webhook_url)) {
            return;
        }

        // Determine event type
        $eventType = match ($event::class) {
            DomainProvisioningStarted::class => 'domain.provisioning.started',
            DomainProvisioningCompleted::class => 'domain.provisioning.completed',
            DomainProvisioningFailed::class => 'domain.provisioning.failed',
        };

        // Build webhook payload
        $payload = [
            'event' => $eventType,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'application' => [
                    'uuid' => $event->application->uuid,
                    'name' => $event->application->name,
                ],
                'domains' => $event->domains,
                'certificate_type' => $event->certificateType,
            ],
        ];

        // Add event-specific data
        if ($event instanceof DomainProvisioningCompleted && $event->certificateDetails) {
            $payload['data']['certificate_details'] = $event->certificateDetails;
        }

        if ($event instanceof DomainProvisioningFailed) {
            $payload['data']['error'] = [
                'message' => $event->errorMessage,
                'details' => $event->errorDetails,
            ];
        }

        // Add signature if webhook secret is configured
        if (! empty($settings->webhook_secret)) {
            $payload['signature'] = $this->generateSignature($payload, $settings->webhook_secret);
        }

        // Dispatch webhook job
        SendWebhookJob::dispatch($payload, $settings->webhook_url);
    }

    /**
     * Generate HMAC signature for webhook payload.
     */
    protected function generateSignature(array $payload, string $secret): string
    {
        $json = json_encode($payload);

        return hash_hmac('sha256', $json, $secret);
    }
}
