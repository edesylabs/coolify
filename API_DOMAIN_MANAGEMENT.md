# Domain Management API Guide

Complete guide for managing domains dynamically via Coolify's REST API - perfect for multi-tenant SaaS applications.

---

## 📋 **Table of Contents**

1. [Overview](#overview)
2. [Authentication](#authentication)
3. [API Endpoints](#api-endpoints)
4. [Code Examples](#code-examples)
5. [Error Handling](#error-handling)
6. [Best Practices](#best-practices)

---

## Overview

The Domain Management API allows you to programmatically manage application domains, which is essential for multi-tenant SaaS applications where users can:

- Add custom domains to their tenant instances
- Create subdomain-based tenant sites
- Remove domains when tenants cancel
- Check SSL certificate status

### **Use Cases**

**Multi-tenant Course Platform**:
```
Main App: course-app.edesy.in
Tenant 1: site1.course-app.edesy.in (subdomain)
Tenant 1: learncooking.com (custom domain)
Tenant 2: site2.course-app.edesy.in (subdomain)
Tenant 2: mathcourses.org (custom domain)
```

**Multi-tenant Blog Platform**:
```
Main App: blog-app.edesy.in
Tenant 1: site1.blog-app.edesy.in
Tenant 1: suvojit-blog.in (custom domain)
Tenant 2: site2.blog-app.edesy.in
```

---

## Authentication

All API requests require authentication via **Bearer Token** (Laravel Sanctum).

### **1. Generate API Token**

#### **Via UI**:
1. Navigate to **Team Settings** → **API Tokens**
2. Click **Create New Token**
3. Name: `Domain Management`
4. Scopes: Select `write` (for adding/removing domains) and `read` (for checking status)
5. Copy the token (shown only once)

#### **Via API** (if you already have a token with admin access):
```bash
curl -X POST https://coolify.example.com/api/v1/tokens \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Domain Management",
    "abilities": ["read", "write"]
  }'
```

### **2. Using the Token**

Include the token in the `Authorization` header:

```bash
Authorization: Bearer YOUR_API_TOKEN
```

---

## API Endpoints

### **Base URL**

```
https://your-coolify-instance.com/api/v1
```

All endpoints are prefixed with `/api/v1`.

---

### **1. Add Domains**

Add one or more domains to an application.

#### **Endpoint**
```
POST /api/v1/applications/{uuid}/domains
```

#### **Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `uuid` | string | Yes | Application UUID (path parameter) |
| `domains` | array | Yes | Array of domain strings to add |
| `force_domain_override` | boolean | No | Force addition even if domain conflicts exist (default: false) |

#### **Request Example**

```bash
curl -X POST https://coolify.example.com/api/v1/applications/8a7b6c5d-4e3f-2g1h-0i9j-8k7l6m5n4o3p/domains \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domains": [
      "site1.course-app.edesy.in",
      "learncooking.com"
    ]
  }'
```

#### **Response (Success - 200)**

```json
{
  "message": "Domains added successfully",
  "domains": [
    "course-app.edesy.in",
    "site1.course-app.edesy.in",
    "learncooking.com"
  ],
  "fqdn": "course-app.edesy.in,site1.course-app.edesy.in,learncooking.com",
  "certificate_type": "dns-01"
}
```

#### **Response (Conflict - 409)**

```json
{
  "message": "Domain conflicts detected. Use force_domain_override=true to proceed.",
  "conflicts": [
    {
      "domain": "learncooking.com",
      "used_by": {
        "uuid": "another-app-uuid",
        "name": "Other Application",
        "type": "application"
      }
    }
  ],
  "warning": "Using the same domain for multiple resources can cause routing conflicts."
}
```

#### **Force Override Example**

```bash
curl -X POST https://coolify.example.com/api/v1/applications/{uuid}/domains \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "domains": ["learncooking.com"],
    "force_domain_override": true
  }'
```

---

### **2. Remove Domain**

Remove a specific domain from an application.

#### **Endpoint**
```
DELETE /api/v1/applications/{uuid}/domains/{domain}
```

#### **Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `uuid` | string | Yes | Application UUID (path parameter) |
| `domain` | string | Yes | Domain to remove (path parameter, URL-encoded) |

#### **Request Example**

```bash
curl -X DELETE "https://coolify.example.com/api/v1/applications/8a7b6c5d-4e3f-2g1h-0i9j-8k7l6m5n4o3p/domains/learncooking.com" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

**Note**: If domain contains special characters, URL-encode it:
```bash
# Domain: site1.course-app.edesy.in
# Encoded: site1.course-app.edesy.in (no encoding needed for standard domains)

# Domain with spaces or special chars (hypothetical):
# Original: my domain.com
# Encoded: my%20domain.com
```

#### **Response (Success - 200)**

```json
{
  "message": "Domain removed successfully",
  "domains": [
    "course-app.edesy.in",
    "site1.course-app.edesy.in"
  ],
  "fqdn": "course-app.edesy.in,site1.course-app.edesy.in"
}
```

#### **Response (Not Found - 404)**

```json
{
  "message": "Domain not found on this application"
}
```

---

### **3. Check SSL Status**

Get SSL certificate status for all domains on an application.

#### **Endpoint**
```
GET /api/v1/applications/{uuid}/ssl-status
```

#### **Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `uuid` | string | Yes | Application UUID (path parameter) |

#### **Request Example**

```bash
curl -X GET https://coolify.example.com/api/v1/applications/8a7b6c5d-4e3f-2g1h-0i9j-8k7l6m5n4o3p/ssl-status \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

#### **Response (Success - 200)**

```json
{
  "application": {
    "uuid": "8a7b6c5d-4e3f-2g1h-0i9j-8k7l6m5n4o3p",
    "name": "course-platform"
  },
  "server": {
    "wildcard_ssl_enabled": true,
    "wildcard_domain": "*.course-app.edesy.in"
  },
  "domains": [
    {
      "domain": "course-app.edesy.in",
      "ssl_enabled": true,
      "wildcard_supported": false,
      "certificate_type": "http-01"
    },
    {
      "domain": "site1.course-app.edesy.in",
      "ssl_enabled": true,
      "wildcard_supported": true,
      "certificate_type": "dns-01"
    },
    {
      "domain": "learncooking.com",
      "ssl_enabled": true,
      "wildcard_supported": false,
      "certificate_type": "http-01"
    }
  ]
}
```

**Field Descriptions**:
- `ssl_enabled`: Whether SSL is enabled (always true for Coolify applications)
- `wildcard_supported`: Whether this domain is covered by wildcard certificate
- `certificate_type`:
  - `dns-01`: Uses DNS challenge (for wildcard support)
  - `http-01`: Uses HTTP challenge (standard SSL)

---

## Code Examples

### **PHP (Laravel)**

```php
use Illuminate\Support\Facades\Http;

class CoolifyDomainManager
{
    protected string $baseUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->baseUrl = config('services.coolify.url');
        $this->apiToken = config('services.coolify.api_token');
    }

    /**
     * Add domains to an application
     */
    public function addDomains(string $applicationUuid, array $domains, bool $forceOverride = false): array
    {
        $response = Http::withToken($this->apiToken)
            ->post("{$this->baseUrl}/api/v1/applications/{$applicationUuid}/domains", [
                'domains' => $domains,
                'force_domain_override' => $forceOverride,
            ]);

        if ($response->status() === 409) {
            // Handle conflicts
            $conflicts = $response->json('conflicts');
            throw new DomainConflictException($conflicts);
        }

        return $response->throw()->json();
    }

    /**
     * Remove domain from an application
     */
    public function removeDomain(string $applicationUuid, string $domain): array
    {
        $encodedDomain = urlencode($domain);

        $response = Http::withToken($this->apiToken)
            ->delete("{$this->baseUrl}/api/v1/applications/{$applicationUuid}/domains/{$encodedDomain}");

        return $response->throw()->json();
    }

    /**
     * Get SSL status for application domains
     */
    public function getSslStatus(string $applicationUuid): array
    {
        $response = Http::withToken($this->apiToken)
            ->get("{$this->baseUrl}/api/v1/applications/{$applicationUuid}/ssl-status");

        return $response->throw()->json();
    }

    /**
     * Check if domain is ready (SSL provisioned)
     */
    public function isDomainReady(string $applicationUuid, string $domain): bool
    {
        $status = $this->getSslStatus($applicationUuid);

        $domainStatus = collect($status['domains'])
            ->firstWhere('domain', $domain);

        return $domainStatus && $domainStatus['ssl_enabled'];
    }
}

// Usage Example
$manager = new CoolifyDomainManager();

// Add tenant domain
try {
    $result = $manager->addDomains(
        'app-uuid',
        ['site1.course-app.edesy.in', 'learncooking.com']
    );

    echo "Domains added: " . implode(', ', $result['domains']);
} catch (DomainConflictException $e) {
    echo "Domain conflicts detected!";
    // Handle conflicts...
}

// Check if ready
if ($manager->isDomainReady('app-uuid', 'learncooking.com')) {
    echo "Domain is live with SSL!";
}

// Remove domain
$manager->removeDomain('app-uuid', 'learncooking.com');
```

---

### **JavaScript (Node.js)**

```javascript
const axios = require('axios');

class CoolifyDomainManager {
    constructor(baseUrl, apiToken) {
        this.client = axios.create({
            baseURL: `${baseUrl}/api/v1`,
            headers: {
                'Authorization': `Bearer ${apiToken}`,
                'Content-Type': 'application/json'
            }
        });
    }

    /**
     * Add domains to an application
     */
    async addDomains(applicationUuid, domains, forceOverride = false) {
        try {
            const response = await this.client.post(
                `/applications/${applicationUuid}/domains`,
                {
                    domains,
                    force_domain_override: forceOverride
                }
            );
            return response.data;
        } catch (error) {
            if (error.response?.status === 409) {
                // Handle conflicts
                throw new Error(`Domain conflicts: ${JSON.stringify(error.response.data.conflicts)}`);
            }
            throw error;
        }
    }

    /**
     * Remove domain from an application
     */
    async removeDomain(applicationUuid, domain) {
        const encodedDomain = encodeURIComponent(domain);
        const response = await this.client.delete(
            `/applications/${applicationUuid}/domains/${encodedDomain}`
        );
        return response.data;
    }

    /**
     * Get SSL status for application domains
     */
    async getSslStatus(applicationUuid) {
        const response = await this.client.get(
            `/applications/${applicationUuid}/ssl-status`
        );
        return response.data;
    }

    /**
     * Check if domain is ready (SSL provisioned)
     */
    async isDomainReady(applicationUuid, domain) {
        const status = await this.getSslStatus(applicationUuid);
        const domainStatus = status.domains.find(d => d.domain === domain);
        return domainStatus?.ssl_enabled || false;
    }

    /**
     * Wait for domain to be ready (with timeout)
     */
    async waitForDomain(applicationUuid, domain, timeoutMs = 300000) {
        const startTime = Date.now();
        const checkInterval = 5000; // Check every 5 seconds

        while (Date.now() - startTime < timeoutMs) {
            if (await this.isDomainReady(applicationUuid, domain)) {
                return true;
            }
            await new Promise(resolve => setTimeout(resolve, checkInterval));
        }

        throw new Error(`Timeout waiting for domain: ${domain}`);
    }
}

// Usage Example
const manager = new CoolifyDomainManager(
    'https://coolify.example.com',
    process.env.COOLIFY_API_TOKEN
);

// Add tenant domain
(async () => {
    try {
        const result = await manager.addDomains(
            'app-uuid',
            ['site1.course-app.edesy.in', 'learncooking.com']
        );
        console.log('Domains added:', result.domains);

        // Wait for SSL provisioning (with webhook notification)
        await manager.waitForDomain('app-uuid', 'learncooking.com');
        console.log('Domain is live with SSL!');

    } catch (error) {
        console.error('Error:', error.message);
    }
})();
```

---

### **Python**

```python
import requests
import urllib.parse
import time

class CoolifyDomainManager:
    def __init__(self, base_url: str, api_token: str):
        self.base_url = f"{base_url}/api/v1"
        self.headers = {
            'Authorization': f'Bearer {api_token}',
            'Content-Type': 'application/json'
        }

    def add_domains(self, application_uuid: str, domains: list, force_override: bool = False) -> dict:
        """Add domains to an application"""
        url = f"{self.base_url}/applications/{application_uuid}/domains"
        payload = {
            'domains': domains,
            'force_domain_override': force_override
        }

        response = requests.post(url, json=payload, headers=self.headers)

        if response.status_code == 409:
            conflicts = response.json().get('conflicts', [])
            raise Exception(f"Domain conflicts: {conflicts}")

        response.raise_for_status()
        return response.json()

    def remove_domain(self, application_uuid: str, domain: str) -> dict:
        """Remove domain from an application"""
        encoded_domain = urllib.parse.quote(domain)
        url = f"{self.base_url}/applications/{application_uuid}/domains/{encoded_domain}"

        response = requests.delete(url, headers=self.headers)
        response.raise_for_status()
        return response.json()

    def get_ssl_status(self, application_uuid: str) -> dict:
        """Get SSL status for application domains"""
        url = f"{self.base_url}/applications/{application_uuid}/ssl-status"

        response = requests.get(url, headers=self.headers)
        response.raise_for_status()
        return response.json()

    def is_domain_ready(self, application_uuid: str, domain: str) -> bool:
        """Check if domain is ready (SSL provisioned)"""
        status = self.get_ssl_status(application_uuid)

        for d in status['domains']:
            if d['domain'] == domain:
                return d.get('ssl_enabled', False)

        return False

    def wait_for_domain(self, application_uuid: str, domain: str, timeout: int = 300):
        """Wait for domain to be ready (with timeout in seconds)"""
        start_time = time.time()
        check_interval = 5  # Check every 5 seconds

        while time.time() - start_time < timeout:
            if self.is_domain_ready(application_uuid, domain):
                return True
            time.sleep(check_interval)

        raise TimeoutError(f"Timeout waiting for domain: {domain}")

# Usage Example
manager = CoolifyDomainManager(
    'https://coolify.example.com',
    'your-api-token'
)

# Add tenant domain
try:
    result = manager.add_domains(
        'app-uuid',
        ['site1.course-app.edesy.in', 'learncooking.com']
    )
    print(f"Domains added: {result['domains']}")

    # Wait for SSL provisioning
    manager.wait_for_domain('app-uuid', 'learncooking.com')
    print("Domain is live with SSL!")

except Exception as e:
    print(f"Error: {e}")
```

---

## Error Handling

### **Common HTTP Status Codes**

| Status | Meaning | Action |
|--------|---------|--------|
| 200 | Success | Process response data |
| 401 | Unauthorized | Check API token is valid |
| 404 | Not Found | Application or domain doesn't exist |
| 409 | Conflict | Domain already in use (see conflicts in response) |
| 422 | Validation Error | Check request parameters |
| 500 | Server Error | Contact Coolify administrators |

### **Error Response Format**

```json
{
  "message": "Human-readable error message",
  "errors": {
    "domains.0": ["The domain format is invalid."]
  }
}
```

### **Retry Logic**

Implement exponential backoff for transient errors:

```php
use Illuminate\Support\Facades\Http;

function addDomainsWithRetry($applicationUuid, $domains, $maxRetries = 3): array
{
    $attempt = 0;
    $backoff = 1; // Start with 1 second

    while ($attempt < $maxRetries) {
        try {
            $response = Http::withToken(config('services.coolify.api_token'))
                ->post("https://coolify.example.com/api/v1/applications/{$applicationUuid}/domains", [
                    'domains' => $domains,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            // Don't retry on client errors (4xx except 429)
            if ($response->clientError() && $response->status() !== 429) {
                throw new Exception($response->json('message'));
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Network error - retry
        }

        $attempt++;
        if ($attempt < $maxRetries) {
            sleep($backoff);
            $backoff *= 2; // Exponential backoff
        }
    }

    throw new Exception("Failed after {$maxRetries} attempts");
}
```

---

## Best Practices

### **1. Webhook Integration**

Don't poll for SSL status - use webhooks:

```php
// ❌ Bad: Polling for status
while (!$manager->isDomainReady($appUuid, $domain)) {
    sleep(5);
}

// ✅ Good: Use webhooks
$manager->addDomains($appUuid, [$domain]);
// Webhook will notify when ready (see WEBHOOK_API_GUIDE.md)
```

### **2. Domain Validation**

Validate domains before adding:

```php
function isValidDomain(string $domain): bool
{
    // Check format
    if (!filter_var("http://{$domain}", FILTER_VALIDATE_URL)) {
        return false;
    }

    // Check DNS exists
    $dns = dns_get_record($domain, DNS_A + DNS_AAAA + DNS_CNAME);
    return !empty($dns);
}

// Use it
if (!isValidDomain($customDomain)) {
    throw new InvalidDomainException("Domain {$customDomain} is invalid or does not exist");
}

$manager->addDomains($appUuid, [$customDomain]);
```

### **3. Idempotency**

Make operations idempotent:

```php
// Check if domain already exists before adding
$status = $manager->getSslStatus($appUuid);
$existingDomains = collect($status['domains'])->pluck('domain');

$domainsToAdd = collect($newDomains)
    ->reject(fn($d) => $existingDomains->contains($d))
    ->toArray();

if (!empty($domainsToAdd)) {
    $manager->addDomains($appUuid, $domainsToAdd);
}
```

### **4. Rate Limiting**

Respect API rate limits:

```php
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::attempt(
    'coolify-api',
    $perMinute = 30,
    function() use ($manager, $appUuid, $domains) {
        return $manager->addDomains($appUuid, $domains);
    }
);
```

### **5. Logging**

Log all API operations:

```php
Log::info('Adding domains to Coolify', [
    'application_uuid' => $appUuid,
    'domains' => $domains,
    'tenant_id' => $tenant->id,
]);

try {
    $result = $manager->addDomains($appUuid, $domains);
    Log::info('Domains added successfully', ['result' => $result]);
} catch (\Exception $e) {
    Log::error('Failed to add domains', [
        'error' => $e->getMessage(),
        'application_uuid' => $appUuid,
        'domains' => $domains,
    ]);
    throw $e;
}
```

### **6. Security**

- ✅ **Always use HTTPS** in production
- ✅ **Store API tokens securely** (environment variables, secrets manager)
- ✅ **Rotate API tokens** every 90 days
- ✅ **Use minimal permissions** (read-only tokens for status checks)
- ❌ **Never commit API tokens** to git
- ❌ **Never expose tokens** in client-side code

---

## Complete Multi-Tenant Example

```php
// app/Services/TenantDomainService.php
class TenantDomainService
{
    public function __construct(
        protected CoolifyDomainManager $coolify,
        protected TenantRepository $tenants
    ) {}

    public function provisionTenant(Tenant $tenant, string $subdomain, ?string $customDomain = null): void
    {
        $domains = ["{$subdomain}.course-app.edesy.in"];

        if ($customDomain) {
            // Validate custom domain
            if (!$this->isValidDomain($customDomain)) {
                throw new InvalidDomainException("Invalid domain: {$customDomain}");
            }
            $domains[] = $customDomain;
        }

        // Add domains via Coolify API
        try {
            $result = $this->coolify->addDomains($tenant->coolify_app_uuid, $domains);

            // Update tenant
            $tenant->update([
                'subdomain' => $subdomain,
                'custom_domain' => $customDomain,
                'domain_status' => 'provisioning',
                'provisioning_started_at' => now(),
            ]);

            Log::info('Tenant domains provisioned', [
                'tenant_id' => $tenant->id,
                'domains' => $result['domains'],
            ]);

        } catch (DomainConflictException $e) {
            throw new TenantProvisioningException(
                "Domain conflict: {$e->getMessage()}"
            );
        }
    }

    public function deprovisionTenant(Tenant $tenant): void
    {
        $domainsToRemove = [];

        if ($tenant->subdomain) {
            $domainsToRemove[] = "{$tenant->subdomain}.course-app.edesy.in";
        }

        if ($tenant->custom_domain) {
            $domainsToRemove[] = $tenant->custom_domain;
        }

        foreach ($domainsToRemove as $domain) {
            try {
                $this->coolify->removeDomain($tenant->coolify_app_uuid, $domain);
            } catch (\Exception $e) {
                Log::error('Failed to remove domain', [
                    'tenant_id' => $tenant->id,
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $tenant->update([
            'domain_status' => 'removed',
            'deprovisioned_at' => now(),
        ]);
    }

    protected function isValidDomain(string $domain): bool
    {
        return filter_var("http://{$domain}", FILTER_VALIDATE_URL) !== false &&
               !empty(dns_get_record($domain, DNS_A + DNS_AAAA + DNS_CNAME));
    }
}

// Webhook handler updates tenant status
class WebhookController extends Controller
{
    public function __construct(protected TenantRepository $tenants) {}

    protected function handleProvisioningCompleted(array $data): void
    {
        $applicationUuid = $data['application']['uuid'];
        $tenant = $this->tenants->findByApplicationUuid($applicationUuid);

        if ($tenant) {
            $tenant->update([
                'domain_status' => 'active',
                'provisioned_at' => now(),
            ]);

            // Notify tenant
            $tenant->user->notify(new DomainProvisioningCompleted($data['domains']));
        }
    }
}
```

---

## 🆘 **Getting Help**

If you encounter issues:

1. **Check API token permissions**: Ensure token has `write` scope
2. **Verify application UUID**: Use `GET /api/v1/applications` to list applications
3. **Check domain format**: Must be valid FQDN (e.g., `example.com`, not `http://example.com`)
4. **Review Coolify logs**: `docker logs coolify`
5. **Create GitHub issue**: https://github.com/coollabsio/coolify/issues

---

**Happy domain managing!** 🎉🌐
