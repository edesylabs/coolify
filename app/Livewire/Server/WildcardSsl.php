<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Services\DnsProviders\CloudflareProvider;
use App\Services\DnsProviders\DnsProviderFactory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class WildcardSsl extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isWildcardSslEnabled = false;

    public ?string $wildcardSslDomain = null;

    public ?string $dnsProvider = null;

    public ?string $acmeEmail = null;

    public bool $useStagingAcme = false;

    // Cloudflare credentials
    public ?string $cloudflareApiToken = null;

    public ?string $cloudflareEmail = null;

    public ?string $cloudflareApiKey = null;

    public ?string $cloudflareZoneId = null;

    // Route53 credentials
    public ?string $route53AccessKeyId = null;

    public ?string $route53SecretAccessKey = null;

    public ?string $route53Region = 'us-east-1';

    // DigitalOcean credentials
    public ?string $digitaloceanAuthToken = null;

    public bool $showCredentialFields = false;

    public ?string $testStatus = null;

    protected function rules(): array
    {
        $rules = [
            'isWildcardSslEnabled' => 'boolean',
            'wildcardSslDomain' => 'nullable|string',
            'dnsProvider' => 'nullable|in:cloudflare,route53,digitalocean',
            'acmeEmail' => 'nullable|email',
            'useStagingAcme' => 'boolean',
        ];

        if ($this->dnsProvider === 'cloudflare') {
            $rules['cloudflareApiToken'] = 'nullable|string';
            $rules['cloudflareEmail'] = 'nullable|email';
            $rules['cloudflareApiKey'] = 'nullable|string';
            $rules['cloudflareZoneId'] = 'nullable|string';
        } elseif ($this->dnsProvider === 'route53') {
            $rules['route53AccessKeyId'] = 'required_if:isWildcardSslEnabled,true|nullable|string';
            $rules['route53SecretAccessKey'] = 'required_if:isWildcardSslEnabled,true|nullable|string';
            $rules['route53Region'] = 'nullable|string';
        } elseif ($this->dnsProvider === 'digitalocean') {
            $rules['digitaloceanAuthToken'] = 'required_if:isWildcardSslEnabled,true|nullable|string';
        }

        return $rules;
    }

    public function mount()
    {
        $this->authorize('view', $this->server);
        $this->syncFromServer();
    }

    protected function syncFromServer()
    {
        $this->isWildcardSslEnabled = $this->server->settings->is_wildcard_ssl_enabled ?? false;
        $this->wildcardSslDomain = $this->server->settings->wildcard_ssl_domain;
        $this->dnsProvider = $this->server->settings->dns_provider;
        $this->acmeEmail = $this->server->settings->acme_email;
        $this->useStagingAcme = $this->server->settings->use_staging_acme ?? false;

        // Load DNS provider credentials
        $credentials = $this->server->settings->dns_provider_credentials ?? [];

        if ($this->dnsProvider === 'cloudflare') {
            $this->cloudflareApiToken = $credentials['api_token'] ?? null;
            $this->cloudflareEmail = $credentials['email'] ?? null;
            $this->cloudflareApiKey = $credentials['api_key'] ?? null;
            $this->cloudflareZoneId = $credentials['zone_id'] ?? null;
        } elseif ($this->dnsProvider === 'route53') {
            $this->route53AccessKeyId = $credentials['access_key_id'] ?? null;
            $this->route53SecretAccessKey = $credentials['secret_access_key'] ?? null;
            $this->route53Region = $credentials['region'] ?? 'us-east-1';
        } elseif ($this->dnsProvider === 'digitalocean') {
            $this->digitaloceanAuthToken = $credentials['auth_token'] ?? null;
        }
    }

    public function updatedDnsProvider($value)
    {
        $this->showCredentialFields = ! empty($value);
        $this->testStatus = null;
    }

    public function updatedIsWildcardSslEnabled($value)
    {
        if (! $value) {
            // Disable wildcard SSL
            $this->submit();
        }
    }

    public function testConnection()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate();

            $credentials = $this->getCredentialsArray();

            // Use factory to create and validate provider
            $result = DnsProviderFactory::validateProviderCredentials($this->dnsProvider, $credentials);

            if ($result['valid']) {
                $this->testStatus = 'success';
                $this->dispatch('success', $result['message']);
            } else {
                $this->testStatus = 'error';
                $this->dispatch('error', $result['message']);
            }
        } catch (\Throwable $e) {
            $this->testStatus = 'error';

            return handleError($e, $this);
        }
    }

    protected function getCredentialsArray(): array
    {
        $credentials = [];

        if ($this->dnsProvider === 'cloudflare') {
            if ($this->cloudflareApiToken) {
                $credentials['api_token'] = $this->cloudflareApiToken;
            } elseif ($this->cloudflareEmail && $this->cloudflareApiKey) {
                $credentials['email'] = $this->cloudflareEmail;
                $credentials['api_key'] = $this->cloudflareApiKey;
            }
            if ($this->cloudflareZoneId) {
                $credentials['zone_id'] = $this->cloudflareZoneId;
            }
            if ($this->wildcardSslDomain) {
                $credentials['domain'] = $this->wildcardSslDomain;
            }
        } elseif ($this->dnsProvider === 'route53') {
            $credentials['access_key_id'] = $this->route53AccessKeyId;
            $credentials['secret_access_key'] = $this->route53SecretAccessKey;
            $credentials['region'] = $this->route53Region;
        } elseif ($this->dnsProvider === 'digitalocean') {
            $credentials['auth_token'] = $this->digitaloceanAuthToken;
        }

        return $credentials;
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate();

            $this->server->settings->is_wildcard_ssl_enabled = $this->isWildcardSslEnabled;
            $this->server->settings->wildcard_ssl_domain = $this->wildcardSslDomain;
            $this->server->settings->dns_provider = $this->dnsProvider;
            $this->server->settings->acme_email = $this->acmeEmail;
            $this->server->settings->use_staging_acme = $this->useStagingAcme;

            // Save DNS provider credentials
            if ($this->isWildcardSslEnabled && $this->dnsProvider) {
                $this->server->settings->dns_provider_credentials = $this->getCredentialsArray();
            } else {
                $this->server->settings->dns_provider_credentials = null;
            }

            $this->server->settings->save();

            // Regenerate proxy configuration with new wildcard SSL settings
            generateDefaultProxyConfiguration($this->server);

            $this->dispatch('success', 'Wildcard SSL configuration saved. Please restart the proxy for changes to take effect.');
            $this->syncFromServer();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.wildcard-ssl');
    }
}
