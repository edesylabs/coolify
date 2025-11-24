# Webhook API Guide

Complete guide for using Coolify's webhook system to receive real-time notifications about domain provisioning and SSL certificate events.

---

## 📋 **Table of Contents**

1. [Overview](#overview)
2. [Webhook Configuration](#webhook-configuration)
3. [Webhook Events](#webhook-events)
4. [Security & Signature Verification](#security--signature-verification)
5. [Payload Examples](#payload-examples)
6. [Testing Webhooks](#testing-webhooks)
7. [Troubleshooting](#troubleshooting)

---

## Overview

Coolify webhooks allow your application to receive real-time notifications when domains are added to applications and SSL certificates are provisioned. This is particularly useful for multi-tenant SaaS applications where you need to:

- Track SSL certificate provisioning status
- Notify users when their custom domains are ready
- Monitor certificate issuance failures
- Automate post-provisioning tasks

### **Key Features**
- ✅ Real-time event notifications
- ✅ HMAC-SHA256 signature verification
- ✅ Automatic retries with exponential backoff
- ✅ Support for multiple event types
- ✅ Detailed error information

---

## Webhook Configuration

### **1. Configure Webhook in Coolify**

Webhooks are configured per server in the Wildcard SSL settings.

#### **Via UI**:
1. Navigate to **Server Settings** → **Wildcard SSL**
2. Enable **Webhook Notifications**
3. Enter your **Webhook URL** (must be HTTPS in production)
4. (Optional) Generate a **Webhook Secret** for signature verification
5. Click **Save**

#### **Via Database** (for programmatic setup):
```php
$server->settings->webhook_enabled = true;
$server->settings->webhook_url = 'https://your-app.com/webhooks/coolify';
$server->settings->webhook_secret = Str::random(32); // Generate secure secret
$server->settings->save();
```

### **2. Webhook URL Requirements**

- **Must be publicly accessible** (Coolify needs to reach it)
- **HTTPS required** in production (HTTP allowed for local testing)
- **Fast response time** (< 5 seconds recommended)
- **Return 200 OK** to acknowledge receipt

#### **Example Webhook Endpoint (Laravel)**:
```php
// routes/web.php
Route::post('/webhooks/coolify', [WebhookController::class, 'handle']);

// app/Http/Controllers/WebhookController.php
class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify signature (see Security section)
        if (!$this->verifySignature($request)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        // Process event
        match ($event) {
            'domain.provisioning.started' => $this->handleProvisioningStarted($data),
            'domain.provisioning.completed' => $this->handleProvisioningCompleted($data),
            'domain.provisioning.failed' => $this->handleProvisioningFailed($data),
            default => Log::warning("Unknown webhook event: {$event}"),
        };

        return response()->json(['message' => 'Webhook received'], 200);
    }

    protected function verifySignature(Request $request): bool
    {
        $signature = $request->input('signature');
        $secret = config('services.coolify.webhook_secret');

        if (!$signature || !$secret) {
            return false;
        }

        $payload = $request->except('signature');
        $expectedSignature = hash_hmac('sha256', json_encode($payload), $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
```

---

## Webhook Events

Coolify sends webhooks for three domain provisioning lifecycle events:

### **1. `domain.provisioning.started`**

Sent immediately when domains are added via API and SSL provisioning begins.

**Use Cases**:
- Show "Provisioning..." status to users
- Log domain addition
- Start monitoring certificate issuance

### **2. `domain.provisioning.completed`**

Sent when SSL certificates are successfully issued for the domains.

**Use Cases**:
- Notify users that their domain is live
- Update domain status to "Active"
- Send confirmation emails
- Enable features that require HTTPS

### **3. `domain.provisioning.failed`**

Sent when SSL certificate provisioning fails.

**Use Cases**:
- Alert administrators
- Notify users of issues
- Trigger retry logic
- Log error details for debugging

---

## Security & Signature Verification

### **Why Signature Verification?**

Webhook signatures ensure that requests are genuinely from Coolify and haven't been tampered with.

### **How It Works**

1. Coolify generates HMAC-SHA256 signature using your webhook secret
2. Signature is included in webhook payload
3. Your endpoint verifies the signature matches

### **Verification Implementation**

#### **PHP (Laravel)**:
```php
protected function verifySignature(Request $request): bool
{
    $receivedSignature = $request->input('signature');
    $secret = config('services.coolify.webhook_secret');

    if (!$receivedSignature || !$secret) {
        return false;
    }

    // Reconstruct payload without signature
    $payload = $request->except('signature');

    // Generate expected signature
    $expectedSignature = hash_hmac('sha256', json_encode($payload), $secret);

    // Constant-time comparison to prevent timing attacks
    return hash_equals($expectedSignature, $receivedSignature);
}
```

#### **Node.js (Express)**:
```javascript
const crypto = require('crypto');

function verifySignature(req) {
    const receivedSignature = req.body.signature;
    const secret = process.env.COOLIFY_WEBHOOK_SECRET;

    if (!receivedSignature || !secret) {
        return false;
    }

    // Remove signature from payload
    const { signature, ...payload } = req.body;

    // Generate expected signature
    const expectedSignature = crypto
        .createHmac('sha256', secret)
        .update(JSON.stringify(payload))
        .digest('hex');

    // Constant-time comparison
    return crypto.timingSafeEqual(
        Buffer.from(receivedSignature),
        Buffer.from(expectedSignature)
    );
}

app.post('/webhooks/coolify', (req, res) => {
    if (!verifySignature(req)) {
        return res.status(401).json({ message: 'Invalid signature' });
    }

    // Process webhook...
    res.json({ message: 'Webhook received' });
});
```

#### **Python (Flask)**:
```python
import hmac
import hashlib
import json
from flask import request

def verify_signature():
    received_signature = request.json.get('signature')
    secret = os.getenv('COOLIFY_WEBHOOK_SECRET')

    if not received_signature or not secret:
        return False

    # Remove signature from payload
    payload = {k: v for k, v in request.json.items() if k != 'signature'}

    # Generate expected signature
    expected_signature = hmac.new(
        secret.encode(),
        json.dumps(payload, separators=(',', ':')).encode(),
        hashlib.sha256
    ).hexdigest()

    # Constant-time comparison
    return hmac.compare_digest(expected_signature, received_signature)

@app.route('/webhooks/coolify', methods=['POST'])
def handle_webhook():
    if not verify_signature():
        return {'message': 'Invalid signature'}, 401

    # Process webhook...
    return {'message': 'Webhook received'}, 200
```

### **Best Practices**

- ✅ **Always verify signatures** in production
- ✅ **Use constant-time comparison** (`hash_equals`, `timingSafeEqual`, `compare_digest`)
- ✅ **Store webhook secrets securely** (environment variables, secrets manager)
- ✅ **Rotate webhook secrets** every 90 days
- ❌ **Never commit webhook secrets** to git
- ❌ **Never log webhook secrets** in plain text

---

## Payload Examples

### **1. Domain Provisioning Started**

```json
{
  "event": "domain.provisioning.started",
  "timestamp": "2025-10-28T14:30:00Z",
  "data": {
    "application": {
      "uuid": "8a7b6c5d-4e3f-2g1h-0i9j-8k7l6m5n4o3p",
      "name": "course-platform"
    },
    "domains": [
      "site1.course-app.edesy.in",
      "learncooking.com"
    ],
    "certificate_type": "dns-01"
  },
  "signature": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6"
}
```

### **2. Domain Provisioning Completed**

```json
{
  "event": "domain.provisioning.completed",
  "timestamp": "2025-10-28T14:32:15Z",
  "data": {
    "application": {
      "uuid": "8a7b6c5d-4e3f-2g1h-0i9j-8k7l6m5n4o3p",
      "name": "course-platform"
    },
    "domains": [
      "site1.course-app.edesy.in",
      "learncooking.com"
    ],
    "certificate_type": "dns-01",
    "certificate_details": {
      "issuer": "Let's Encrypt",
      "valid_from": "2025-10-28T14:32:00Z",
      "valid_until": "2026-01-26T14:32:00Z",
      "san": [
        "site1.course-app.edesy.in",
        "learncooking.com"
      ]
    }
  },
  "signature": "b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a1"
}
```

### **3. Domain Provisioning Failed**

```json
{
  "event": "domain.provisioning.failed",
  "timestamp": "2025-10-28T14:35:00Z",
  "data": {
    "application": {
      "uuid": "8a7b6c5d-4e3f-2g1h-0i9j-8k7l6m5n4o3p",
      "name": "course-platform"
    },
    "domains": [
      "invalid-domain.example.com"
    ],
    "certificate_type": "http-01",
    "error": {
      "message": "DNS validation failed",
      "details": {
        "domain": "invalid-domain.example.com",
        "reason": "NXDOMAIN: Domain does not exist",
        "dns_records_checked": ["A", "AAAA", "CNAME"]
      }
    }
  },
  "signature": "c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a1b2"
}
```

---

## Testing Webhooks

### **1. Local Testing with ngrok**

Since Coolify needs to reach your webhook endpoint, use ngrok for local development:

```bash
# Install ngrok
npm install -g ngrok
# or
brew install ngrok

# Start your local server
php artisan serve
# or
npm run dev

# Expose local server
ngrok http 8000

# Copy the HTTPS URL (e.g., https://abc123.ngrok.io)
# Configure in Coolify: https://abc123.ngrok.io/webhooks/coolify
```

### **2. Testing with curl**

```bash
# Test webhook endpoint locally
curl -X POST http://localhost:8000/webhooks/coolify \
  -H "Content-Type: application/json" \
  -d '{
    "event": "domain.provisioning.started",
    "timestamp": "2025-10-28T14:30:00Z",
    "data": {
      "application": {
        "uuid": "test-uuid",
        "name": "test-app"
      },
      "domains": ["test.example.com"],
      "certificate_type": "http-01"
    },
    "signature": "test-signature"
  }'
```

### **3. Webhook Testing Service**

Use **webhook.site** for quick testing without coding:

1. Visit https://webhook.site
2. Copy your unique URL
3. Configure in Coolify
4. Add domains via API
5. View webhook payloads in real-time

### **4. Automated Testing**

```php
// tests/Feature/WebhookTest.php
use App\Events\DomainProvisioningStarted;
use App\Models\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('sends webhook when domain provisioning starts', function () {
    Http::fake();

    $server = Server::factory()->create();
    $server->settings->webhook_enabled = true;
    $server->settings->webhook_url = 'https://example.com/webhook';
    $server->settings->save();

    $application = Application::factory()->create([
        'destination_id' => $server->id,
    ]);

    event(new DomainProvisioningStarted(
        $application,
        ['test.example.com'],
        'http-01'
    ));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/webhook' &&
               $request['event'] === 'domain.provisioning.started';
    });
});
```

---

## Troubleshooting

### **Webhooks Not Being Received**

**Issue**: Webhook endpoint not receiving requests

**Solutions**:
1. **Check webhook URL is accessible**:
   ```bash
   curl -I https://your-app.com/webhooks/coolify
   # Should return 200 OK or 405 Method Not Allowed (POST required)
   ```

2. **Check Coolify logs**:
   ```bash
   docker logs coolify | grep webhook
   docker logs coolify-horizon | grep SendWebhookJob
   ```

3. **Verify webhook is enabled**:
   ```bash
   # Check in database
   docker exec -it coolify-db psql -U coolify -d coolify \
     -c "SELECT id, webhook_enabled, webhook_url FROM server_settings WHERE webhook_enabled = true;"
   ```

4. **Check firewall rules**: Ensure Coolify server can reach your webhook endpoint

### **Signature Verification Failing**

**Issue**: `Invalid signature` errors

**Solutions**:
1. **Verify secret matches**:
   ```php
   // In Coolify database
   $secret = $server->settings->webhook_secret;

   // In your application
   $configuredSecret = config('services.coolify.webhook_secret');

   // Should be identical
   ```

2. **Check JSON serialization**:
   ```php
   // Must match Coolify's serialization (no spaces)
   $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
   ```

3. **Debug signature generation**:
   ```php
   Log::info('Received signature: ' . $request->input('signature'));
   $payload = $request->except('signature');
   $expected = hash_hmac('sha256', json_encode($payload), $secret);
   Log::info('Expected signature: ' . $expected);
   ```

### **Webhook Timeouts**

**Issue**: Webhooks timing out

**Solutions**:
1. **Return 200 immediately**, process async:
   ```php
   public function handle(Request $request)
   {
       // Verify and queue for processing
       if ($this->verifySignature($request)) {
           ProcessWebhookJob::dispatch($request->all());
           return response()->json(['message' => 'Queued'], 200);
       }
       return response()->json(['message' => 'Invalid'], 401);
   }
   ```

2. **Optimize webhook handler**: Avoid slow operations (external API calls, complex queries)

3. **Increase timeout** (if self-hosting):
   ```php
   // In SendWebhookJob.php
   Http::timeout(10)->post($this->webhookUrl, $this->payload);
   ```

### **Duplicate Webhooks**

**Issue**: Receiving same webhook multiple times

**Cause**: Webhook retries due to slow response or errors

**Solutions**:
1. **Implement idempotency**:
   ```php
   public function handle(Request $request)
   {
       $eventId = $request->input('data.application.uuid') . '-' .
                  $request->input('timestamp');

       // Check if already processed
       if (Cache::has("webhook:{$eventId}")) {
           return response()->json(['message' => 'Already processed'], 200);
       }

       // Process webhook
       // ...

       // Mark as processed (TTL: 24 hours)
       Cache::put("webhook:{$eventId}", true, 86400);

       return response()->json(['message' => 'Processed'], 200);
   }
   ```

2. **Return 200 quickly**: Don't wait for processing to complete

### **Webhooks to Development Environment**

**Issue**: Cannot receive webhooks in local development

**Solutions**:
1. **Use ngrok** (recommended):
   ```bash
   ngrok http 8000
   # Use HTTPS URL in Coolify
   ```

2. **Use LocalTunnel**:
   ```bash
   npm install -g localtunnel
   lt --port 8000 --subdomain my-coolify-webhooks
   ```

3. **Expose via Cloudflare Tunnel**:
   ```bash
   cloudflared tunnel --url localhost:8000
   ```

---

## Integration Examples

### **Multi-tenant SaaS Domain Provisioning**

```php
// app/Actions/Tenant/ProvisionTenantDomain.php
class ProvisionTenantDomain
{
    public function execute(Tenant $tenant, string $customDomain): void
    {
        // Add domain via Coolify API
        $response = Http::withToken(config('services.coolify.api_token'))
            ->post("https://coolify.example.com/api/v1/applications/{$tenant->coolify_app_uuid}/domains", [
                'domains' => [$customDomain],
            ]);

        if ($response->successful()) {
            // Update tenant status
            $tenant->update([
                'custom_domain' => $customDomain,
                'domain_status' => 'provisioning', // Will be updated by webhook
            ]);

            // Notify tenant
            $tenant->user->notify(new DomainProvisioningStarted($customDomain));
        }
    }
}

// app/Http/Controllers/WebhookController.php
class WebhookController extends Controller
{
    protected function handleProvisioningCompleted(array $data): void
    {
        $applicationUuid = $data['application']['uuid'];
        $domains = $data['domains'];

        // Find tenant by application UUID
        $tenant = Tenant::where('coolify_app_uuid', $applicationUuid)->first();

        if ($tenant) {
            // Update domain status
            $tenant->update(['domain_status' => 'active']);

            // Send confirmation email
            $tenant->user->notify(new DomainProvisioningCompleted($domains));

            // Log event
            Log::info("Domain provisioning completed for tenant {$tenant->id}", [
                'domains' => $domains,
                'certificate_type' => $data['certificate_type'],
            ]);
        }
    }

    protected function handleProvisioningFailed(array $data): void
    {
        $applicationUuid = $data['application']['uuid'];
        $tenant = Tenant::where('coolify_app_uuid', $applicationUuid)->first();

        if ($tenant) {
            $tenant->update(['domain_status' => 'failed']);

            // Alert administrators
            Notification::route('slack', config('services.slack.alerts_webhook'))
                ->notify(new DomainProvisioningFailed($tenant, $data['error']));
        }
    }
}
```

---

## 🆘 **Getting Help**

If you're still having issues:

1. **Check Coolify logs**:
   ```bash
   docker logs coolify
   docker logs coolify-horizon
   ```

2. **Check webhook job status** (Laravel Horizon):
   ```
   https://your-coolify-instance.com/horizon
   ```

3. **Test webhook endpoint**:
   ```bash
   curl -X POST https://your-app.com/webhooks/coolify \
     -H "Content-Type: application/json" \
     -d '{"event":"test"}'
   ```

4. **Create GitHub issue**:
   - https://github.com/coollabsio/coolify/issues

---

**Happy webhook integrating!** 🎉🔔
