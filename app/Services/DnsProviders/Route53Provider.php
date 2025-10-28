<?php

namespace App\Services\DnsProviders;

use App\Contracts\DnsProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Route53Provider implements DnsProviderInterface
{
    protected string $accessKeyId;

    protected string $secretAccessKey;

    protected string $region;

    protected ?string $hostedZoneId = null;

    public function __construct(array $credentials)
    {
        $this->accessKeyId = $credentials['access_key_id'] ?? '';
        $this->secretAccessKey = $credentials['secret_access_key'] ?? '';
        $this->region = $credentials['region'] ?? 'us-east-1';
        $this->hostedZoneId = $credentials['hosted_zone_id'] ?? null;

        // If hosted zone ID not provided, try to get it from domain
        if (empty($this->hostedZoneId) && ! empty($credentials['domain'])) {
            $this->hostedZoneId = $this->getHostedZoneId($credentials['domain']);
        }
    }

    public function createTxtRecord(string $domain, string $value): bool
    {
        try {
            if (! $this->hostedZoneId) {
                Log::error('Route53: Hosted zone ID not found');

                return false;
            }

            $changeBatch = [
                'Changes' => [
                    [
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'Name' => $domain,
                            'Type' => 'TXT',
                            'TTL' => 120,
                            'ResourceRecords' => [
                                ['Value' => '"'.$value.'"'], // TXT records must be quoted
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->makeRequest('POST', "/2013-04-01/hostedzone/{$this->hostedZoneId}/rrset/", [
                'ChangeBatch' => $changeBatch,
            ]);

            if ($response['status'] === 200) {
                Log::info("Route53: Created TXT record for {$domain}");

                return true;
            }

            Log::error('Route53 API error: '.json_encode($response));

            return false;
        } catch (\Exception $e) {
            Log::error('Route53 createTxtRecord failed: '.$e->getMessage());

            return false;
        }
    }

    public function deleteTxtRecord(string $domain, string $value): bool
    {
        try {
            if (! $this->hostedZoneId) {
                Log::error('Route53: Hosted zone ID not found');

                return false;
            }

            $changeBatch = [
                'Changes' => [
                    [
                        'Action' => 'DELETE',
                        'ResourceRecordSet' => [
                            'Name' => $domain,
                            'Type' => 'TXT',
                            'TTL' => 120,
                            'ResourceRecords' => [
                                ['Value' => '"'.$value.'"'],
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->makeRequest('POST', "/2013-04-01/hostedzone/{$this->hostedZoneId}/rrset/", [
                'ChangeBatch' => $changeBatch,
            ]);

            if ($response['status'] === 200) {
                Log::info("Route53: Deleted TXT record for {$domain}");

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Route53 deleteTxtRecord failed: '.$e->getMessage());

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
        return 'route53';
    }

    public function validateCredentials(array $credentials): bool
    {
        try {
            // Test by listing hosted zones
            $response = $this->makeRequest('GET', '/2013-04-01/hostedzone');

            return $response['status'] === 200;
        } catch (\Exception $e) {
            Log::error('Route53 credential validation failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get hosted zone ID from domain name
     */
    protected function getHostedZoneId(string $domain): ?string
    {
        try {
            $rootDomain = $this->extractRootDomain($domain);

            $response = $this->makeRequest('GET', '/2013-04-01/hostedzone');

            if ($response['status'] === 200 && isset($response['body']['HostedZones'])) {
                foreach ($response['body']['HostedZones'] as $zone) {
                    // Remove trailing dot from zone name
                    $zoneName = rtrim($zone['Name'], '.');
                    if ($zoneName === $rootDomain || $zoneName === $rootDomain.'.') {
                        // Extract zone ID from format: /hostedzone/Z1234567890ABC
                        return basename($zone['Id']);
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get Route53 hosted zone ID: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Make authenticated request to Route53 API
     */
    protected function makeRequest(string $method, string $path, array $data = []): array
    {
        $host = "route53.{$this->region}.amazonaws.com";
        $url = "https://{$host}{$path}";

        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        $body = '';
        $contentType = 'application/json';

        if (! empty($data)) {
            // Wrap in ChangeResourceRecordSetsRequest for Route53 API
            if (isset($data['ChangeBatch'])) {
                $xmlBody = $this->buildChangeResourceRecordSetsXml($data['ChangeBatch']);
                $body = $xmlBody;
                $contentType = 'text/xml';
            } else {
                $body = json_encode($data);
            }
        }

        $headers = [
            'Host' => $host,
            'Content-Type' => $contentType,
            'X-Amz-Date' => $timestamp,
        ];

        // Generate AWS Signature Version 4
        $signature = $this->generateAwsSignature($method, $path, $body, $headers, $timestamp, $date);
        $headers['Authorization'] = $signature;

        try {
            if ($method === 'GET') {
                $response = Http::withHeaders($headers)->get($url);
            } elseif ($method === 'POST') {
                $response = Http::withHeaders($headers)->withBody($body, $contentType)->post($url);
            } else {
                throw new \Exception('Unsupported HTTP method');
            }

            return [
                'status' => $response->status(),
                'body' => $this->parseXmlResponse($response->body()),
            ];
        } catch (\Exception $e) {
            Log::error('Route53 API request failed: '.$e->getMessage());

            return [
                'status' => 500,
                'body' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Generate AWS Signature Version 4
     */
    protected function generateAwsSignature(string $method, string $path, string $body, array $headers, string $timestamp, string $date): string
    {
        $service = 'route53';
        $algorithm = 'AWS4-HMAC-SHA256';

        // Canonical headers
        $canonicalHeaders = "content-type:{$headers['Content-Type']}\nhost:{$headers['Host']}\nx-amz-date:{$timestamp}\n";
        $signedHeaders = 'content-type;host;x-amz-date';

        // Canonical request
        $payloadHash = hash('sha256', $body);
        $canonicalRequest = "{$method}\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        // String to sign
        $credentialScope = "{$date}/{$this->region}/{$service}/aws4_request";
        $stringToSign = "{$algorithm}\n{$timestamp}\n{$credentialScope}\n".hash('sha256', $canonicalRequest);

        // Signing key
        $kDate = hash_hmac('sha256', $date, 'AWS4'.$this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        // Signature
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return "{$algorithm} Credential={$this->accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    }

    /**
     * Build XML for ChangeResourceRecordSets request
     */
    protected function buildChangeResourceRecordSetsXml(array $changeBatch): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/">';
        $xml .= '<ChangeBatch>';

        foreach ($changeBatch['Changes'] as $change) {
            $xml .= '<Changes>';
            $xml .= '<Action>'.$change['Action'].'</Action>';
            $xml .= '<ResourceRecordSet>';
            $xml .= '<Name>'.$change['ResourceRecordSet']['Name'].'</Name>';
            $xml .= '<Type>'.$change['ResourceRecordSet']['Type'].'</Type>';
            $xml .= '<TTL>'.$change['ResourceRecordSet']['TTL'].'</TTL>';
            $xml .= '<ResourceRecords>';

            foreach ($change['ResourceRecordSet']['ResourceRecords'] as $record) {
                $xml .= '<ResourceRecord>';
                $xml .= '<Value>'.$record['Value'].'</Value>';
                $xml .= '</ResourceRecord>';
            }

            $xml .= '</ResourceRecords>';
            $xml .= '</ResourceRecordSet>';
            $xml .= '</Changes>';
        }

        $xml .= '</ChangeBatch>';
        $xml .= '</ChangeResourceRecordSetsRequest>';

        return $xml;
    }

    /**
     * Parse XML response to array
     */
    protected function parseXmlResponse(string $xml): array
    {
        try {
            if (empty($xml)) {
                return [];
            }

            libxml_use_internal_errors(true);
            $parsed = simplexml_load_string($xml);

            if ($parsed === false) {
                return ['error' => 'Failed to parse XML'];
            }

            return json_decode(json_encode($parsed), true);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Extract root domain from subdomain
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
