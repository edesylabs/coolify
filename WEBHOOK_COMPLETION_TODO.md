# Webhook System - Completion Tasks

## Current Status

The webhook system for domain provisioning is **partially implemented**:

### ✅ Completed
1. **Events Created**:
   - `App\Events\DomainProvisioningStarted` - ✅ Created
   - `App\Events\DomainProvisioningCompleted` - ✅ Created
   - `App\Events\DomainProvisioningFailed` - ✅ Created

2. **Listener Created**:
   - `App\Listeners\SendDomainProvisioningWebhook` - ✅ Created
   - Auto-discovered via `EventServiceProvider::shouldDiscoverEvents()`

3. **Tests Written**:
   - `tests/Unit/WebhookSystemTest.php` - ✅ Created with comprehensive tests
   - Tests cover event broadcasting, listener logic, signatures, payload structure

4. **Started Event Integration**:
   - `DomainProvisioningStarted` event **IS** dispatched in `ApplicationsController::addDomain()` when domains are added (app/Http/Controllers/Api/ApplicationsController.php)

### ❌ Missing Implementation

The **Completed** and **Failed** events are **NOT being dispatched** anywhere because:

1. **Coolify doesn't actively provision SSL certificates** - it relies on Traefik's automatic ACME integration
2. **No mechanism to detect certificate provisioning status** - Traefik handles this internally
3. **No polling or checking system** - we need to query Traefik or check certificate status

---

## 🔴 Critical Missing Piece: Certificate Status Detection

### The Problem

When a domain is added to an application in Coolify:

```php
// In ApplicationsController::addDomain() (CURRENT)
event(new DomainProvisioningStarted($application, $domains, $certificateType));
// ✅ This works!

// Traefik then automatically provisions certificates via ACME...
// ...but we have NO code to detect when it completes or fails!

// ❌ These are NEVER dispatched:
event(new DomainProvisioningCompleted($application, $domains, $certificateType, $certificateDetails));
event(new DomainProvisioningFailed($application, $domains, $certificateType, $errorMessage, $errorDetails));
```

### Why This Happens

Traefik stores certificates in `acme.json` (HTTP-01) or `acme-dns.json` (DNS-01) files. Coolify doesn't monitor these files or poll Traefik's API to check certificate status.

---

## 🛠️ Solution: Implement Certificate Status Polling

### Approach 1: Background Job (Recommended)

Create a job that polls Traefik's API or checks certificate files after provisioning starts.

#### Step 1: Create CheckCertificateStatusJob

```bash
php artisan make:job CheckCertificateStatusJob
```

**File: `app/Jobs/CheckCertificateStatusJob.php`**

```php
<?php

namespace App\Jobs;

use App\Events\DomainProvisioningCompleted;
use App\Events\DomainProvisioningFailed;
use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckCertificateStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 10; // Try 10 times over ~5 minutes
    public $backoff = 30; // Wait 30 seconds between retries

    public function __construct(
        public Application $application,
        public array $domains,
        public string $certificateType,
        public int $teamId
    ) {}

    public function handle()
    {
        $server = $this->application->destination->server;

        // Get Traefik proxy URL (usually localhost:8080 on the server)
        $traefikApiUrl = 'http://localhost:8080/api';

        try {
            // Check certificate status for each domain
            $allProvisioned = true;
            $certificateDetails = [];
            $errors = [];

            foreach ($this->domains as $domain) {
                $certStatus = $this->checkDomainCertificate($server, $domain, $traefikApiUrl);

                if ($certStatus['status'] === 'provisioned') {
                    $certificateDetails[$domain] = $certStatus['details'];
                } elseif ($certStatus['status'] === 'failed') {
                    $allProvisioned = false;
                    $errors[$domain] = $certStatus['error'];
                } else {
                    // Still pending, retry job
                    $allProvisioned = false;
                    if ($this->attempts() < $this->tries) {
                        $this->release($this->backoff);
                        return;
                    } else {
                        // Max retries reached
                        $errors[$domain] = 'Certificate provisioning timeout after ' . ($this->tries * $this->backoff) . ' seconds';
                    }
                }
            }

            // Dispatch appropriate event
            if ($allProvisioned) {
                event(new DomainProvisioningCompleted(
                    $this->application,
                    $this->domains,
                    $this->certificateType,
                    $certificateDetails,
                    $this->teamId
                ));
            } else {
                event(new DomainProvisioningFailed(
                    $this->application,
                    $this->domains,
                    $this->certificateType,
                    'One or more domains failed to provision SSL certificates',
                    $errors,
                    $this->teamId
                ));
            }
        } catch (\Exception $e) {
            Log::error('CheckCertificateStatusJob failed: ' . $e->getMessage());

            // If we haven't reached max retries, retry
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
            } else {
                // Dispatch failed event
                event(new DomainProvisioningFailed(
                    $this->application,
                    $this->domains,
                    $this->certificateType,
                    'Failed to check certificate status: ' . $e->getMessage(),
                    ['exception' => $e->getMessage()],
                    $this->teamId
                ));
            }
        }
    }

    protected function checkDomainCertificate($server, string $domain, string $traefikApiUrl): array
    {
        try {
            // Option 1: Check via Traefik API (if accessible)
            // This requires Traefik API to be enabled (it is in Coolify by default)

            // Execute command on server to check certificate
            $command = "docker exec coolify-proxy cat /traefik/acme.json 2>/dev/null || docker exec coolify-proxy cat /traefik/acme-dns.json 2>/dev/null";

            $acmeJson = instant_remote_process(
                [$command],
                $server,
                false
            );

            if (empty($acmeJson) || empty($acmeJson[0])) {
                return [
                    'status' => 'pending',
                    'error' => 'ACME file not found or empty'
                ];
            }

            $acmeData = json_decode($acmeJson[0], true);

            // Check if certificate exists for this domain
            $certificateFound = false;

            // Search through ACME data structure
            foreach ($acmeData as $resolver => $resolverData) {
                if (!isset($resolverData['Certificates'])) {
                    continue;
                }

                foreach ($resolverData['Certificates'] as $cert) {
                    $certDomain = $cert['domain']['main'] ?? null;
                    $altNames = $cert['domain']['sans'] ?? [];

                    if ($certDomain === $domain || in_array($domain, $altNames)) {
                        $certificateFound = true;

                        // Extract certificate details
                        return [
                            'status' => 'provisioned',
                            'details' => [
                                'issuer' => 'Let\'s Encrypt',
                                'domain' => $certDomain,
                                'alternative_names' => $altNames,
                                'provisioned_at' => now()->toIso8601String(),
                            ]
                        ];
                    }
                }
            }

            if (!$certificateFound) {
                // Check if there are any ACME errors
                // Traefik logs errors in Docker logs
                $logsCommand = "docker logs coolify-proxy --tail 50 2>&1 | grep -i 'error\\|fail\\|$domain'";
                $logs = instant_remote_process(
                    [$logsCommand],
                    $server,
                    false
                );

                if (!empty($logs[0]) && (stripos($logs[0], 'error') !== false || stripos($logs[0], 'fail') !== false)) {
                    return [
                        'status' => 'failed',
                        'error' => 'Certificate provisioning failed: ' . trim($logs[0])
                    ];
                }

                // Still pending
                return [
                    'status' => 'pending',
                    'error' => 'Certificate not yet provisioned'
                ];
            }

        } catch (\Exception $e) {
            Log::error("Error checking certificate for domain {$domain}: " . $e->getMessage());
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        return [
            'status' => 'pending',
            'error' => 'Unknown status'
        ];
    }
}
```

#### Step 2: Dispatch Job After DomainProvisioningStarted

**Modify: `app/Http/Controllers/Api/ApplicationsController.php`**

Find the section where `DomainProvisioningStarted` is dispatched and add:

```php
// Dispatch provisioning started event
event(new \App\Events\DomainProvisioningStarted(
    $application,
    $newDomains->toArray(),
    $certificateType,
    $application->team->id
));

// NEW: Dispatch job to check certificate status
\App\Jobs\CheckCertificateStatusJob::dispatch(
    $application,
    $newDomains->toArray(),
    $certificateType,
    $application->team->id
)->delay(now()->addSeconds(30)); // Wait 30 seconds before first check
```

---

### Approach 2: Traefik API Integration (More Reliable)

Use Traefik's REST API to check certificate status directly.

#### Traefik API Endpoints

Traefik exposes its API on port 8080 (configured in Coolify):

```bash
# List all routers
GET http://localhost:8080/api/http/routers

# Get specific router
GET http://localhost:8080/api/http/routers/{routerName}

# List all certificates
GET http://localhost:8080/api/http/routers/{routerName}/tls
```

**Implementation:**

```php
protected function checkCertificateViaTraefikApi(Server $server, string $domain): array
{
    try {
        // Execute API call on server
        $command = "curl -s http://localhost:8080/api/http/routers | jq '.[] | select(.rule | contains(\"$domain\"))'";

        $result = instant_remote_process(
            [$command],
            $server,
            false
        );

        if (empty($result) || empty($result[0])) {
            return ['status' => 'pending', 'error' => 'Router not found'];
        }

        $router = json_decode($result[0], true);

        // Check if TLS is configured and certificate is present
        if (isset($router['tls']) && !empty($router['tls'])) {
            return [
                'status' => 'provisioned',
                'details' => [
                    'router' => $router['name'],
                    'tls_enabled' => true,
                    'certificate_resolver' => $router['tls']['certResolver'] ?? 'letsencrypt',
                    'provisioned_at' => now()->toIso8601String(),
                ]
            ];
        }

        // Check for errors in router status
        if (isset($router['status']) && $router['status'] !== 'enabled') {
            return [
                'status' => 'failed',
                'error' => 'Router status: ' . $router['status']
            ];
        }

        return ['status' => 'pending', 'error' => 'TLS not yet configured'];

    } catch (\Exception $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}
```

---

### Approach 3: File Watching (Alternative)

Monitor the ACME JSON files for changes.

**File: `app/Jobs/WatchAcmeFileJob.php`**

```php
<?php

namespace App\Jobs;

use App\Events\DomainProvisioningCompleted;
use App\Events\DomainProvisioningFailed;
use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WatchAcmeFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 20;
    public $backoff = 15;

    public function __construct(
        public Application $application,
        public array $domains,
        public string $certificateType,
        public int $teamId,
        public ?string $previousHash = null
    ) {}

    public function handle()
    {
        $server = $this->application->destination->server;
        $proxyPath = $server->proxyPath();

        // Get current ACME file hash
        $acmeFile = $this->certificateType === 'dns-01'
            ? "{$proxyPath}/acme-dns.json"
            : "{$proxyPath}/acme.json";

        $command = "docker exec coolify-proxy md5sum /traefik/" . basename($acmeFile) . " 2>/dev/null | awk '{print $1}'";

        $currentHash = instant_remote_process([$command], $server, false)[0] ?? null;

        if (!$currentHash) {
            // File doesn't exist yet or can't access it
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
                return;
            }

            // Timeout
            event(new DomainProvisioningFailed(
                $this->application,
                $this->domains,
                $this->certificateType,
                'ACME file not accessible after timeout',
                ['file' => $acmeFile],
                $this->teamId
            ));
            return;
        }

        // File changed, check if our domain is there
        if ($this->previousHash !== null && $currentHash !== $this->previousHash) {
            // File was updated, check certificate status
            $certStatus = $this->checkCertificateInFile($server, $acmeFile);

            if ($certStatus['found']) {
                event(new DomainProvisioningCompleted(
                    $this->application,
                    $this->domains,
                    $this->certificateType,
                    $certStatus['details'],
                    $this->teamId
                ));
                return;
            }
        }

        // File hasn't changed or certificate not found yet
        if ($this->attempts() < $this->tries) {
            // Dispatch with updated hash
            self::dispatch(
                $this->application,
                $this->domains,
                $this->certificateType,
                $this->teamId,
                $currentHash
            )->delay(now()->addSeconds($this->backoff));
            return;
        }

        // Max retries reached
        event(new DomainProvisioningFailed(
            $this->application,
            $this->domains,
            $this->certificateType,
            'Certificate not found in ACME file after ' . ($this->tries * $this->backoff) . ' seconds',
            ['last_hash' => $currentHash],
            $this->teamId
        ));
    }

    protected function checkCertificateInFile($server, string $acmeFile): array
    {
        $command = "docker exec coolify-proxy cat /traefik/" . basename($acmeFile);
        $acmeJson = instant_remote_process([$command], $server, false)[0] ?? null;

        if (!$acmeJson) {
            return ['found' => false];
        }

        $acmeData = json_decode($acmeJson, true);

        foreach ($acmeData as $resolver => $resolverData) {
            if (!isset($resolverData['Certificates'])) {
                continue;
            }

            foreach ($resolverData['Certificates'] as $cert) {
                $certDomain = $cert['domain']['main'] ?? null;
                $altNames = $cert['domain']['sans'] ?? [];

                foreach ($this->domains as $domain) {
                    if ($certDomain === $domain || in_array($domain, $altNames)) {
                        return [
                            'found' => true,
                            'details' => [
                                'issuer' => 'Let\'s Encrypt',
                                'domain' => $certDomain,
                                'alternative_names' => $altNames,
                                'resolver' => $resolver,
                                'provisioned_at' => now()->toIso8601String(),
                            ]
                        ];
                    }
                }
            }
        }

        return ['found' => false];
    }
}
```

---

## 📋 Implementation Checklist

To complete the webhook system, follow these steps:

### Phase 1: Choose Approach
- [ ] **Recommended**: Implement Approach 1 (Background Job with ACME file checking)
- [ ] Alternative: Implement Approach 2 (Traefik API)
- [ ] Alternative: Implement Approach 3 (File watching)

### Phase 2: Implement Job
- [ ] Create `CheckCertificateStatusJob` (or chosen alternative)
- [ ] Test locally with Docker containers running
- [ ] Add error handling and logging
- [ ] Configure retry logic (tries, backoff)

### Phase 3: Integrate with API
- [ ] Modify `ApplicationsController::addDomain()` to dispatch job
- [ ] Test with actual domain additions
- [ ] Verify events are dispatched correctly

### Phase 4: Test Webhooks
- [ ] Set up test webhook endpoint (e.g., webhook.site)
- [ ] Configure server webhook settings in Coolify
- [ ] Add domain to application
- [ ] Verify all three webhook events are received:
  1. `domain.provisioning.started`
  2. `domain.provisioning.completed` (or `failed`)

### Phase 5: Update Tests
- [ ] Update `WebhookSystemTest.php` to test job integration
- [ ] Add integration tests for certificate checking logic
- [ ] Test failure scenarios (invalid domains, DNS issues)

### Phase 6: Documentation
- [ ] Update `WEBHOOK_API_GUIDE.md` with implementation details
- [ ] Document how to troubleshoot webhook issues
- [ ] Add examples of webhook payloads from all three events

---

## 🧪 Testing the Complete Flow

Once implemented, test with these scenarios:

### Test 1: Successful Provisioning
```bash
curl -X POST "http://localhost/api/v1/applications/{uuid}/domains" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domains": ["test.example.com"],
    "certificate_type": "http-01"
  }'

# Expected webhooks (in order):
# 1. domain.provisioning.started (immediate)
# 2. domain.provisioning.completed (after ~30-60 seconds)
```

### Test 2: Failed Provisioning (Invalid Domain)
```bash
curl -X POST "http://localhost/api/v1/applications/{uuid}/domains" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domains": ["invalid-domain-that-does-not-exist.com"],
    "certificate_type": "http-01"
  }'

# Expected webhooks:
# 1. domain.provisioning.started
# 2. domain.provisioning.failed (after timeout or ACME error detected)
```

### Test 3: Multiple Domains
```bash
curl -X POST "http://localhost/api/v1/applications/{uuid}/domains" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domains": ["app1.example.com", "app2.example.com"],
    "certificate_type": "http-01"
  }'

# Expected webhooks:
# 1. domain.provisioning.started (both domains)
# 2. domain.provisioning.completed (if all succeed)
#    OR domain.provisioning.failed (if any fail)
```

---

## 🚨 Important Considerations

### 1. Queue Worker Must Be Running
The background job requires Laravel Horizon or `php artisan queue:work` to be running:

```bash
# In production
docker exec coolify php artisan horizon

# In development
php artisan queue:work --tries=3
```

### 2. Server SSH Access
The job needs to execute commands on remote servers via SSH. Ensure:
- SSH keys are properly configured
- `instant_remote_process()` helper works correctly
- Network connectivity between Coolify and target servers

### 3. Traefik API Access
If using Traefik API approach:
- Verify port 8080 is accessible on the server
- Check Traefik API is enabled (it should be by default in Coolify)
- Test with: `docker exec coolify-proxy wget -qO- http://localhost:8080/api/http/routers`

### 4. Performance Impact
- Each domain addition triggers a background job
- Jobs poll every 30 seconds for up to 10 attempts (5 minutes)
- Monitor queue performance with Horizon dashboard

### 5. Error Scenarios
Handle these edge cases:
- Domain DNS not configured correctly
- ACME rate limits reached
- Traefik container not running
- Network connectivity issues
- Certificate already exists

---

## 🎯 Recommended Next Steps

1. **Start with Approach 1** (CheckCertificateStatusJob with ACME file checking)
   - It's the most straightforward
   - Doesn't require additional API configuration
   - Works with existing Coolify setup

2. **Test locally first**:
   - Spin up Coolify dev environment
   - Add a test domain
   - Watch logs: `docker logs coolify --follow`
   - Check job execution in Horizon

3. **Iterate based on results**:
   - If ACME file parsing is unreliable, switch to Traefik API
   - If polling is too slow, reduce backoff time
   - If too many false failures, increase max tries

4. **Document edge cases**:
   - What happens if Traefik is restarted during provisioning?
   - How to handle certificate renewals?
   - What if multiple domains are added simultaneously?

---

## 📚 Related Documentation

- **Traefik ACME Documentation**: https://doc.traefik.io/traefik/https/acme/
- **Traefik API Reference**: https://doc.traefik.io/traefik/operations/api/
- **Laravel Queue Documentation**: https://laravel.com/docs/12.x/queues
- **Laravel Horizon**: https://laravel.com/docs/12.x/horizon

---

**Status**: This document outlines what needs to be done. Implementation is **NOT YET COMPLETE**.

**Priority**: Medium-High (webhooks work for `started` event, but not `completed`/`failed`)

**Estimated Effort**: 4-6 hours for implementation + testing
