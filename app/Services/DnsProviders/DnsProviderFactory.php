<?php

namespace App\Services\DnsProviders;

use App\Contracts\DnsProviderInterface;

class DnsProviderFactory
{
    /**
     * Create a DNS provider instance
     *
     * @param  string  $provider  Provider name (cloudflare, route53, digitalocean)
     * @param  array  $credentials  Provider-specific credentials
     * @return DnsProviderInterface
     *
     * @throws \InvalidArgumentException If provider is not supported
     */
    public static function create(string $provider, array $credentials): DnsProviderInterface
    {
        return match (strtolower($provider)) {
            'cloudflare' => new CloudflareProvider($credentials),
            'route53' => new Route53Provider($credentials),
            'digitalocean' => new DigitalOceanProvider($credentials),
            default => throw new \InvalidArgumentException("Unsupported DNS provider: {$provider}"),
        };
    }

    /**
     * Get list of supported DNS providers
     *
     * @return array<string, string> Provider key => Display name
     */
    public static function getSupportedProviders(): array
    {
        return [
            'cloudflare' => 'Cloudflare',
            'route53' => 'AWS Route53',
            'digitalocean' => 'DigitalOcean',
        ];
    }

    /**
     * Get required credential fields for a provider
     *
     * @param  string  $provider  Provider name
     * @return array<string, array> Field configuration
     */
    public static function getRequiredFields(string $provider): array
    {
        return match (strtolower($provider)) {
            'cloudflare' => [
                'api_token' => [
                    'label' => 'API Token',
                    'type' => 'password',
                    'required' => false,
                    'helper' => 'Recommended: API Token with Zone:DNS:Edit permissions',
                ],
                'email' => [
                    'label' => 'Email',
                    'type' => 'email',
                    'required' => false,
                    'helper' => 'Alternative: Use with Global API Key',
                ],
                'api_key' => [
                    'label' => 'Global API Key',
                    'type' => 'password',
                    'required' => false,
                    'helper' => 'Alternative: Use with Email',
                ],
                'zone_id' => [
                    'label' => 'Zone ID',
                    'type' => 'text',
                    'required' => false,
                    'helper' => 'Optional: Will be auto-detected if not provided',
                ],
            ],
            'route53' => [
                'access_key_id' => [
                    'label' => 'Access Key ID',
                    'type' => 'password',
                    'required' => true,
                    'helper' => 'AWS IAM Access Key ID',
                ],
                'secret_access_key' => [
                    'label' => 'Secret Access Key',
                    'type' => 'password',
                    'required' => true,
                    'helper' => 'AWS IAM Secret Access Key',
                ],
                'region' => [
                    'label' => 'Region',
                    'type' => 'text',
                    'required' => false,
                    'helper' => 'AWS Region (default: us-east-1)',
                    'default' => 'us-east-1',
                ],
            ],
            'digitalocean' => [
                'auth_token' => [
                    'label' => 'API Token',
                    'type' => 'password',
                    'required' => true,
                    'helper' => 'DigitalOcean Personal Access Token',
                ],
            ],
            default => [],
        };
    }

    /**
     * Validate credentials for a provider
     *
     * @param  string  $provider  Provider name
     * @param  array  $credentials  Credentials to validate
     * @return array{valid: bool, message: string}
     */
    public static function validateProviderCredentials(string $provider, array $credentials): array
    {
        try {
            $providerInstance = self::create($provider, $credentials);

            if ($providerInstance->validateCredentials($credentials)) {
                return [
                    'valid' => true,
                    'message' => 'Credentials validated successfully',
                ];
            }

            return [
                'valid' => false,
                'message' => 'Failed to validate credentials. Please check your API keys.',
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'valid' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Validation error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check if a provider is supported
     *
     * @param  string  $provider  Provider name
     * @return bool
     */
    public static function isSupported(string $provider): bool
    {
        return array_key_exists(strtolower($provider), self::getSupportedProviders());
    }

    /**
     * Get documentation URL for a provider
     *
     * @param  string  $provider  Provider name
     * @return string|null
     */
    public static function getDocumentationUrl(string $provider): ?string
    {
        return match (strtolower($provider)) {
            'cloudflare' => 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/',
            'route53' => 'https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/Welcome.html',
            'digitalocean' => 'https://docs.digitalocean.com/reference/api/create-personal-access-token/',
            default => null,
        };
    }
}
