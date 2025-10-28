<div>
    <form wire:submit='submit' class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">Wildcard SSL Certificates</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Enable wildcard SSL certificates for automatic SSL provisioning on all subdomains (e.g., *.course-app.edesy.in).
                <br>Requires DNS-01 challenge with a supported DNS provider.
            </p>
        </div>

        {{-- Enable Wildcard SSL --}}
        <x-forms.checkbox
            wire:model.live="isWildcardSslEnabled"
            canGate="update"
            :canResource="$server"
            id="isWildcardSslEnabled"
            label="Enable Wildcard SSL"
            helper="Automatically provision SSL certificates for wildcard domains using DNS-01 challenge"
        />

        @if ($isWildcardSslEnabled)
            {{-- Wildcard Domain --}}
            <x-forms.input
                wire:model="wildcardSslDomain"
                canGate="update"
                :canResource="$server"
                id="wildcardSslDomain"
                label="Wildcard Domain"
                helper="Example: *.course-app.edesy.in or *.example.com"
                placeholder="*.course-app.edesy.in"
            />

            {{-- ACME Email --}}
            <x-forms.input
                wire:model="acmeEmail"
                canGate="update"
                :canResource="$server"
                id="acmeEmail"
                type="email"
                label="ACME Email"
                helper="Email address for Let's Encrypt certificate notifications"
                placeholder="admin@example.com"
            />

            {{-- DNS Provider Selection --}}
            <x-forms.select
                wire:model.live="dnsProvider"
                canGate="update"
                :canResource="$server"
                id="dnsProvider"
                label="DNS Provider"
                helper="Select your DNS provider for DNS-01 challenge"
            >
                <option value="">Select DNS Provider</option>
                <option value="cloudflare">Cloudflare</option>
                <option value="route53">AWS Route53</option>
                <option value="digitalocean">DigitalOcean</option>
            </x-forms.select>

            {{-- Cloudflare Credentials --}}
            @if ($dnsProvider === 'cloudflare')
                <div class="flex flex-col gap-2 p-4 border rounded-lg dark:border-gray-700">
                    <h3 class="font-semibold">Cloudflare Credentials</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Use either API Token (recommended) or Global API Key
                    </p>

                    <x-forms.input
                        wire:model="cloudflareApiToken"
                        canGate="update"
                        :canResource="$server"
                        id="cloudflareApiToken"
                        label="API Token (Recommended)"
                        helper="Create an API Token with Zone:DNS:Edit permissions"
                        placeholder="Your Cloudflare API Token"
                        type="password"
                    />

                    <div class="text-center text-sm text-gray-500">OR</div>

                    <x-forms.input
                        wire:model="cloudflareEmail"
                        canGate="update"
                        :canResource="$server"
                        id="cloudflareEmail"
                        type="email"
                        label="Cloudflare Email"
                        helper="Your Cloudflare account email"
                        placeholder="your-email@example.com"
                    />

                    <x-forms.input
                        wire:model="cloudflareApiKey"
                        canGate="update"
                        :canResource="$server"
                        id="cloudflareApiKey"
                        label="Global API Key"
                        helper="Your Cloudflare Global API Key"
                        placeholder="Your Global API Key"
                        type="password"
                    />

                    <x-forms.input
                        wire:model="cloudflareZoneId"
                        canGate="update"
                        :canResource="$server"
                        id="cloudflareZoneId"
                        label="Zone ID (Optional)"
                        helper="Cloudflare Zone ID - will be auto-detected if not provided"
                        placeholder="Zone ID"
                    />
                </div>
            @endif

            {{-- Route53 Credentials --}}
            @if ($dnsProvider === 'route53')
                <div class="flex flex-col gap-2 p-4 border rounded-lg dark:border-gray-700">
                    <h3 class="font-semibold">AWS Route53 Credentials</h3>

                    <x-forms.input
                        wire:model="route53AccessKeyId"
                        canGate="update"
                        :canResource="$server"
                        id="route53AccessKeyId"
                        label="Access Key ID"
                        helper="AWS IAM Access Key ID with Route53 permissions"
                        placeholder="AKIAIOSFODNN7EXAMPLE"
                        type="password"
                    />

                    <x-forms.input
                        wire:model="route53SecretAccessKey"
                        canGate="update"
                        :canResource="$server"
                        id="route53SecretAccessKey"
                        label="Secret Access Key"
                        helper="AWS IAM Secret Access Key"
                        placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
                        type="password"
                    />

                    <x-forms.input
                        wire:model="route53Region"
                        canGate="update"
                        :canResource="$server"
                        id="route53Region"
                        label="AWS Region"
                        helper="AWS region (default: us-east-1)"
                        placeholder="us-east-1"
                    />
                </div>
            @endif

            {{-- DigitalOcean Credentials --}}
            @if ($dnsProvider === 'digitalocean')
                <div class="flex flex-col gap-2 p-4 border rounded-lg dark:border-gray-700">
                    <h3 class="font-semibold">DigitalOcean Credentials</h3>

                    <x-forms.input
                        wire:model="digitaloceanAuthToken"
                        canGate="update"
                        :canResource="$server"
                        id="digitaloceanAuthToken"
                        label="API Token"
                        helper="DigitalOcean Personal Access Token with read/write permissions"
                        placeholder="Your DigitalOcean API Token"
                        type="password"
                    />
                </div>
            @endif

            {{-- Testing Options --}}
            <x-forms.checkbox
                wire:model="useStagingAcme"
                canGate="update"
                :canResource="$server"
                id="useStagingAcme"
                label="Use Let's Encrypt Staging Server"
                helper="Enable for testing to avoid rate limits (certificates will not be trusted)"
            />

            {{-- Test Connection Button --}}
            @if ($dnsProvider)
                <div class="flex gap-2">
                    <x-forms.button
                        type="button"
                        wire:click="testConnection"
                        canGate="update"
                        :canResource="$server"
                    >
                        Test DNS Provider Connection
                    </x-forms.button>

                    @if ($testStatus === 'success')
                        <span class="text-green-600 dark:text-green-400 flex items-center">
                            ✓ Connection successful
                        </span>
                    @elseif ($testStatus === 'error')
                        <span class="text-red-600 dark:text-red-400 flex items-center">
                            ✗ Connection failed
                        </span>
                    @endif
                </div>
            @endif
        @endif

        {{-- Submit Button --}}
        <x-forms.button
            type="submit"
            canGate="update"
            :canResource="$server"
        >
            Save Configuration
        </x-forms.button>
    </form>

    {{-- Information Box --}}
    <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
        <h3 class="font-semibold text-blue-900 dark:text-blue-100">How Wildcard SSL Works</h3>
        <ul class="mt-2 text-sm text-blue-800 dark:text-blue-200 list-disc list-inside space-y-1">
            <li>Wildcard certificates cover all subdomains (e.g., site1.app.example.com, site2.app.example.com)</li>
            <li>Uses DNS-01 challenge which requires API access to your DNS provider</li>
            <li>Certificates are automatically renewed before expiration</li>
            <li>After saving, restart your proxy for changes to take effect</li>
        </ul>
    </div>
</div>
