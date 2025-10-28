<?php

namespace App\Services\DnsProviders;

use App\Contracts\DnsProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareProvider implements DnsProviderInterface
{
    protected string $apiToken;

    protected string $zoneId;

    protected ?string $email = null;

    protected ?string $apiKey = null;

    public function __construct(array $credentials)
    {
        // Support both API Token and Global API Key methods
        $this->apiToken = $credentials['api_token'] ?? '';
        $this->email = $credentials['email'] ?? null;
        $this->apiKey = $credentials['api_key'] ?? null;
        $this->zoneId = $credentials['zone_id'] ?? '';

        // If zone_id not provided, try to get it from domain
        if (empty($this->zoneId) && ! empty($credentials['domain'])) {
            $this->zoneId = $this->getZoneId($credentials['domain']);
        }
    }

    public function createTxtRecord(string $domain, string $value): bool
    {
        try {
            $response = $this->makeRequest('POST', "/zones/{$this->zoneId}/dns_records", [
                'type' => 'TXT',
                'name' => $domain,
                'content' => $value,
                'ttl' => 120, // 2 minutes for fast propagation
            ]);

            if ($response->successful()) {
                Log::info("Cloudflare: Created TXT record for {$domain}");

                return true;
            }

            Log::error('Cloudflare API error: '.$response->body());

            return false;
        } catch (\Exception $e) {
            Log::error('Cloudflare createTxtRecord failed: '.$e->getMessage());

            return false;
        }
    }

    public function deleteTxtRecord(string $domain, string $value): bool
    {
        try {
            // First, find the record ID
            $recordId = $this->findRecordId($domain, $value);

            if (! $recordId) {
                Log::warning("Cloudflare: Record not found for {$domain}");

                return true; // Already deleted
            }

            $response = $this->makeRequest('DELETE', "/zones/{$this->zoneId}/dns_records/{$recordId}");

            if ($response->successful()) {
                Log::info("Cloudflare: Deleted TXT record for {$domain}");

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Cloudflare deleteTxtRecord failed: '.$e->getMessage());

            return false;
        }
    }

    public function verifyDnsPropagation(string $domain, string $expectedValue): bool
    {
        try {
            $records = dns_get_record($domain, DNS_TXT);

            foreach ($records as $record) {
                if (isset($record['txt']) && $record['txt'] === $expectedValue) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::error('DNS propagation check failed: '.$e->getMessage());

            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'cloudflare';
    }

    public function validateCredentials(array $credentials): bool
    {
        try {
            $response = $this->makeRequest('GET', '/user/tokens/verify');

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Cloudflare credential validation failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get zone ID from domain name
     */
    protected function getZoneId(string $domain): ?string
    {
        try {
            $response = $this->makeRequest('GET', '/zones', [
                'name' => $this->extractRootDomain($domain),
            ]);

            if ($response->successful()) {
                $zones = $response->json('result');
                if (! empty($zones)) {
                    return $zones[0]['id'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get Cloudflare zone ID: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Find DNS record ID
     */
    protected function findRecordId(string $domain, string $value): ?string
    {
        try {
            $response = $this->makeRequest('GET', "/zones/{$this->zoneId}/dns_records", [
                'type' => 'TXT',
                'name' => $domain,
            ]);

            if ($response->successful()) {
                $records = $response->json('result');
                foreach ($records as $record) {
                    if ($record['content'] === $value) {
                        return $record['id'];
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to find Cloudflare record ID: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Make authenticated request to Cloudflare API
     */
    protected function makeRequest(string $method, string $endpoint, array $data = [])
    {
        $url = 'https://api.cloudflare.com/client/v4'.$endpoint;

        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Use API Token if available (preferred method)
        if (! empty($this->apiToken)) {
            $headers['Authorization'] = 'Bearer '.$this->apiToken;
        } elseif (! empty($this->email) && ! empty($this->apiKey)) {
            // Fallback to Global API Key
            $headers['X-Auth-Email'] = $this->email;
            $headers['X-Auth-Key'] = $this->apiKey;
        } else {
            throw new \Exception('Cloudflare credentials not configured');
        }

        if ($method === 'GET') {
            return Http::withHeaders($headers)->get($url, $data);
        } elseif ($method === 'POST') {
            return Http::withHeaders($headers)->post($url, $data);
        } elseif ($method === 'DELETE') {
            return Http::withHeaders($headers)->delete($url);
        }

        throw new \Exception('Unsupported HTTP method');
    }

    /**
     * Extract root domain from subdomain
     * Example: _acme-challenge.app.example.com -> example.com
     */
    protected function extractRootDomain(string $domain): string
    {
        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
        }

        return $domain;
    }
}
