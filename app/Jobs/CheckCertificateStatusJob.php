<?php

namespace App\Jobs;

use App\Events\DomainProvisioningCompleted;
use App\Events\DomainProvisioningFailed;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckCertificateStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10; // Try 10 times over ~5 minutes

    public int $backoff = 30; // Wait 30 seconds between retries

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Application $application,
        public array $domains,
        public string $certificateType,
        public int $teamId
    ) {
        $this->onQueue('low');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $server = $this->application->destination->server;

            // Check certificate status for each domain
            $allProvisioned = true;
            $certificateDetails = [];
            $errors = [];

            foreach ($this->domains as $domain) {
                $certStatus = $this->checkDomainCertificate($server, $domain);

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
                        $errors[$domain] = 'Certificate provisioning timeout after '.($this->tries * $this->backoff).' seconds';
                    }
                }
            }

            // Dispatch appropriate event
            if ($allProvisioned && empty($errors)) {
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
                    implode('; ', $errors),
                    $errors,
                    $this->teamId
                ));
            }
        } catch (\Throwable $e) {
            Log::error('CheckCertificateStatusJob failed', [
                'application_id' => $this->application->id,
                'domains' => $this->domains,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Dispatch failed event on exception
            event(new DomainProvisioningFailed(
                $this->application,
                $this->domains,
                $this->certificateType,
                $e->getMessage(),
                ['exception' => $e->getMessage()],
                $this->teamId
            ));
        }
    }

    /**
     * Check certificate status for a specific domain
     *
     * @return array{status: string, details?: array, error?: string}
     */
    protected function checkDomainCertificate(Server $server, string $domain): array
    {
        try {
            // Check if certificate exists in acme.json or acme-dns.json
            $acmeFile = $this->certificateType === 'dns-01' ? 'acme-dns.json' : 'acme.json';
            $command = "cat /data/coolify/proxy/{$acmeFile} 2>/dev/null || echo '{}'";

            $result = instant_remote_process([$command], $server, false);

            if (empty($result) || $result === '{}') {
                return ['status' => 'pending'];
            }

            $acmeData = json_decode($result, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['status' => 'pending'];
            }

            // Search for certificate in ACME data
            // Traefik ACME JSON structure varies by provider
            $found = $this->searchCertificateInAcmeData($acmeData, $domain);

            if ($found) {
                return [
                    'status' => 'provisioned',
                    'details' => [
                        'domain' => $domain,
                        'certificate_type' => $this->certificateType,
                        'issued_at' => now()->toIso8601String(),
                    ],
                ];
            }

            return ['status' => 'pending'];
        } catch (\Throwable $e) {
            Log::warning('Failed to check certificate status', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'error' => 'Failed to check certificate: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Search for certificate in ACME data structure
     */
    protected function searchCertificateInAcmeData(array $acmeData, string $domain): bool
    {
        // Check different ACME JSON structures
        // Traefik stores certificates in different formats depending on the resolver

        // Check in the main certificates array
        foreach ($acmeData as $resolver => $resolverData) {
            if (! is_array($resolverData)) {
                continue;
            }

            // Check Certificates array
            if (isset($resolverData['Certificates']) && is_array($resolverData['Certificates'])) {
                foreach ($resolverData['Certificates'] as $cert) {
                    if (! is_array($cert)) {
                        continue;
                    }

                    // Check domain in certificate
                    if (isset($cert['domain'])) {
                        $certDomains = is_array($cert['domain']) ? $cert['domain'] : [$cert['domain']];

                        // Check main domain
                        if (isset($cert['domain']['main']) && $this->domainMatches($cert['domain']['main'], $domain)) {
                            return true;
                        }

                        // Check SANs (Subject Alternative Names)
                        if (isset($cert['domain']['sans']) && is_array($cert['domain']['sans'])) {
                            foreach ($cert['domain']['sans'] as $san) {
                                if ($this->domainMatches($san, $domain)) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if domain matches certificate domain (including wildcards)
     */
    protected function domainMatches(string $certDomain, string $checkDomain): bool
    {
        // Exact match
        if ($certDomain === $checkDomain) {
            return true;
        }

        // Wildcard match
        if (str_starts_with($certDomain, '*.')) {
            $wildcardBase = substr($certDomain, 2); // Remove *.
            $checkBase = substr($checkDomain, strpos($checkDomain, '.') + 1); // Get base domain

            return $wildcardBase === $checkBase;
        }

        return false;
    }
}
