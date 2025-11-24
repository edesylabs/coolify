<?php

namespace App\Services\DnsProviders;

use App\Contracts\DnsProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DigitalOceanProvider implements DnsProviderInterface
{
    protected string $authToken;

    protected ?string $domainName = null;

    public function __construct(array $credentials)
    {
        $this->authToken = $credentials['auth_token'] ?? '';

        // Extract base domain from provided domain
        if (! empty($credentials['domain'])) {
            $this->domainName = $this->extractBaseDomain($credentials['domain']);
        }
    }

    public function createTxtRecord(string $domain, string $value): bool
    {
        try {
            if (! $this->domainName) {
                $this->domainName = $this->extractBaseDomain($domain);
            }

            // Extract subdomain from full domain
            $subdomain = $this->extractSubdomain($domain, $this->domainName);

            $response = $this->makeRequest('POST', "/v2/domains/{$this->domainName}/records", [
                'type' => 'TXT',
                'name' => $subdomain,
                'data' => $value,
                'ttl' => 120,
            ]);

            if ($response->successful()) {
                Log::info("DigitalOcean: Created TXT record for {$domain}");

                return true;
            }

            $error = $response->json('message', 'Unknown error');
            Log::error("DigitalOcean API error: {$error}");

            return false;
        } catch (\Exception $e) {
            Log::error('DigitalOcean createTxtRecord failed: '.$e->getMessage());

            return false;
        }
    }

    public function deleteTxtRecord(string $domain, string $value): bool
    {
        try {
            if (! $this->domainName) {
                $this->domainName = $this->extractBaseDomain($domain);
            }

            // Find the record ID first
            $recordId = $this->findRecordId($domain, $value);

            if (! $recordId) {
                Log::warning("DigitalOcean: Record not found for {$domain}");

                return true; // Already deleted
            }

            $response = $this->makeRequest('DELETE', "/v2/domains/{$this->domainName}/records/{$recordId}");

            if ($response->successful() || $response->status() === 404) {
                Log::info("DigitalOcean: Deleted TXT record for {$domain}");

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('DigitalOcean deleteTxtRecord failed: '.$e->getMessage());

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
        return 'digitalocean';
    }

    public function validateCredentials(array $credentials): bool
    {
        try {
            // Test by fetching account info
            $response = $this->makeRequest('GET', '/v2/account');

            if ($response->successful()) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('DigitalOcean credential validation failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Find DNS record ID by domain and value
     */
    protected function findRecordId(string $domain, string $value): ?int
    {
        try {
            $subdomain = $this->extractSubdomain($domain, $this->domainName);

            $response = $this->makeRequest('GET', "/v2/domains/{$this->domainName}/records", [
                'type' => 'TXT',
                'name' => $subdomain,
            ]);

            if ($response->successful()) {
                $records = $response->json('domain_records', []);

                foreach ($records as $record) {
                    if ($record['data'] === $value && $record['name'] === $subdomain) {
                        return $record['id'];
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to find DigitalOcean record ID: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Make authenticated request to DigitalOcean API
     */
    protected function makeRequest(string $method, string $endpoint, array $data = [])
    {
        $url = 'https://api.digitalocean.com'.$endpoint;

        $headers = [
            'Authorization' => 'Bearer '.$this->authToken,
            'Content-Type' => 'application/json',
        ];

        try {
            if ($method === 'GET') {
                return Http::withHeaders($headers)->get($url, $data);
            } elseif ($method === 'POST') {
                return Http::withHeaders($headers)->post($url, $data);
            } elseif ($method === 'DELETE') {
                return Http::withHeaders($headers)->delete($url);
            }

            throw new \Exception('Unsupported HTTP method');
        } catch (\Exception $e) {
            Log::error('DigitalOcean API request failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract base domain from full domain
     * Example: _acme-challenge.app.example.com -> example.com
     */
    protected function extractBaseDomain(string $domain): string
    {
        // Remove _acme-challenge prefix if present
        $domain = str_replace('_acme-challenge.', '', $domain);

        // Get last two parts (assuming standard TLD)
        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
        }

        return $domain;
    }

    /**
     * Extract subdomain from full domain
     * Example: _acme-challenge.app.example.com with base example.com -> _acme-challenge.app
     */
    protected function extractSubdomain(string $fullDomain, string $baseDomain): string
    {
        $subdomain = str_replace('.'.$baseDomain, '', $fullDomain);

        // If subdomain equals base domain, return @ for root
        if ($subdomain === $baseDomain) {
            return '@';
        }

        return $subdomain;
    }
}
