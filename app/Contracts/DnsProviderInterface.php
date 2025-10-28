<?php

namespace App\Contracts;

interface DnsProviderInterface
{
    /**
     * Create a TXT record for DNS-01 challenge
     *
     * @param  string  $domain  The domain (e.g., "_acme-challenge.example.com")
     * @param  string  $value  The TXT record value
     * @return bool Success status
     */
    public function createTxtRecord(string $domain, string $value): bool;

    /**
     * Delete a TXT record after verification
     *
     * @param  string  $domain  The domain
     * @param  string  $value  The TXT record value
     * @return bool Success status
     */
    public function deleteTxtRecord(string $domain, string $value): bool;

    /**
     * Verify DNS propagation
     *
     * @param  string  $domain  The domain
     * @param  string  $expectedValue  Expected TXT record value
     * @return bool Whether record has propagated
     */
    public function verifyDnsPropagation(string $domain, string $expectedValue): bool;

    /**
     * Get provider name
     *
     * @return string Provider identifier (cloudflare, route53, etc.)
     */
    public function getProviderName(): string;

    /**
     * Validate credentials
     *
     * @param  array  $credentials  Provider-specific credentials
     * @return bool Whether credentials are valid
     */
    public function validateCredentials(array $credentials): bool;
}
