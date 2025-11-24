<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Server;
use Symfony\Component\Yaml\Yaml;

function collectProxyDockerNetworksByServer(Server $server)
{
    if (! $server->isFunctional()) {
        return collect();
    }
    $proxyType = $server->proxyType();
    if (is_null($proxyType) || $proxyType === 'NONE') {
        return collect();
    }
    $networks = instant_remote_process(['docker inspect --format="{{json .NetworkSettings.Networks }}" coolify-proxy'], $server, false);

    return collect($networks)->map(function ($network) {
        return collect(json_decode($network))->keys();
    })->flatten()->unique();
}
function collectDockerNetworksByServer(Server $server)
{
    $allNetworks = collect([]);
    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        });
    } else {
        // Standalone networks
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        });
    }
    $allNetworks = $allNetworks->merge($networks);
    // Service networks
    foreach ($server->services()->get() as $service) {
        if ($service->isRunning()) {
            $networks->push($service->networks());
        }
        $allNetworks->push($service->networks());
    }
    // Docker compose based apps
    $docker_compose_apps = $server->dockerComposeBasedApplications();
    foreach ($docker_compose_apps as $app) {
        if ($app->isRunning()) {
            $networks->push($app->uuid);
        }
        $allNetworks->push($app->uuid);
    }
    // Docker compose based preview deployments
    $docker_compose_previews = $server->dockerComposeBasedPreviewDeployments();
    foreach ($docker_compose_previews as $preview) {
        if (! $preview->isRunning()) {
            continue;
        }
        $pullRequestId = $preview->pull_request_id;
        $applicationId = $preview->application_id;
        $application = Application::find($applicationId);
        if (! $application) {
            continue;
        }
        $network = "{$application->uuid}-{$pullRequestId}";
        $networks->push($network);
        $allNetworks->push($network);
    }
    $networks = collect($networks)->flatten()->unique();
    $allNetworks = $allNetworks->flatten()->unique();
    if ($server->isSwarm()) {
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
            $allNetworks = collect(['coolify-overlay']);
        }
    } else {
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
            $allNetworks = collect(['coolify']);
        }
    }

    return [
        'networks' => $networks,
        'allNetworks' => $allNetworks,
    ];
}
function connectProxyToNetworks(Server $server)
{
    ['networks' => $networks] = collectDockerNetworksByServer($server);
    if ($server->isSwarm()) {
        $commands = $networks->map(function ($network) {
            return [
                "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null || docker network create --driver overlay --attachable $network >/dev/null",
                "docker network connect $network coolify-proxy >/dev/null 2>&1 || true",
                "echo 'Successfully connected coolify-proxy to $network network.'",
            ];
        });
    } else {
        $commands = $networks->map(function ($network) {
            return [
                "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null || docker network create --attachable $network >/dev/null",
                "docker network connect $network coolify-proxy >/dev/null 2>&1 || true",
                "echo 'Successfully connected coolify-proxy to $network network.'",
            ];
        });
    }

    return $commands->flatten();
}
function extractCustomProxyCommands(Server $server, string $existing_config): array
{
    $custom_commands = [];
    $proxy_type = $server->proxyType();

    if ($proxy_type !== ProxyTypes::TRAEFIK->value || empty($existing_config)) {
        return $custom_commands;
    }

    try {
        $yaml = Yaml::parse($existing_config);
        $existing_commands = data_get($yaml, 'services.traefik.command', []);

        if (empty($existing_commands)) {
            return $custom_commands;
        }

        // Define default commands that Coolify generates
        $default_command_prefixes = [
            '--ping=',
            '--api.',
            '--entrypoints.http.address=',
            '--entrypoints.https.address=',
            '--entrypoints.http.http.encodequerysemicolons=',
            '--entryPoints.http.http2.maxConcurrentStreams=',
            '--entrypoints.https.http.encodequerysemicolons=',
            '--entryPoints.https.http2.maxConcurrentStreams=',
            '--entrypoints.https.http3',
            '--providers.file.',
            '--certificatesresolvers.',
            '--providers.docker',
            '--providers.swarm',
            '--log.level=',
            '--accesslog.',
        ];

        // Extract commands that don't match default prefixes (these are custom)
        foreach ($existing_commands as $command) {
            $is_default = false;
            foreach ($default_command_prefixes as $prefix) {
                if (str_starts_with($command, $prefix)) {
                    $is_default = true;
                    break;
                }
            }
            if (! $is_default) {
                $custom_commands[] = $command;
            }
        }
    } catch (\Exception $e) {
        // If we can't parse the config, return empty array
        // Silently fail to avoid breaking the proxy regeneration
    }

    return $custom_commands;
}
function generateDefaultProxyConfiguration(Server $server, array $custom_commands = [])
{
    $proxy_path = $server->proxyPath();
    $proxy_type = $server->proxyType();

    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
        }
    } else {
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
    }

    $array_of_networks = collect([]);
    $filtered_networks = collect([]);
    $networks->map(function ($network) use ($array_of_networks, $filtered_networks) {
        if ($network === 'host') {
            return; // network-scoped alias is supported only for containers in user defined networks
        }

        $array_of_networks[$network] = [
            'external' => true,
        ];
        $filtered_networks->push($network);
    });
    if ($proxy_type === ProxyTypes::TRAEFIK->value) {
        $labels = [
            'traefik.enable=true',
            'traefik.http.routers.traefik.entrypoints=http',
            'traefik.http.routers.traefik.service=api@internal',
            'traefik.http.services.traefik.loadbalancer.server.port=8080',
            'coolify.managed=true',
            'coolify.proxy=true',
        ];
        $config = [
            'name' => 'coolify-proxy',
            'networks' => $array_of_networks->toArray(),
            'services' => [
                'traefik' => [
                    'container_name' => 'coolify-proxy',
                    'image' => 'traefik:v3.6',
                    'restart' => RESTART_MODE,
                    'extra_hosts' => [
                        'host.docker.internal:host-gateway',
                    ],
                    'networks' => $filtered_networks->toArray(),
                    'ports' => [
                        '80:80',
                        '443:443',
                        '443:443/udp',
                        '8080:8080',
                    ],
                    'healthcheck' => [
                        'test' => 'wget -qO- http://localhost:80/ping || exit 1',
                        'interval' => '4s',
                        'timeout' => '2s',
                        'retries' => 5,
                    ],
                    'volumes' => [
                        '/var/run/docker.sock:/var/run/docker.sock:ro',

                    ],
                    'command' => [
                        '--ping=true',
                        '--ping.entrypoint=http',
                        '--api.dashboard=true',
                        '--entrypoints.http.address=:80',
                        '--entrypoints.https.address=:443',
                        '--entrypoints.http.http.encodequerysemicolons=true',
                        '--entryPoints.http.http2.maxConcurrentStreams=250',
                        '--entrypoints.https.http.encodequerysemicolons=true',
                        '--entryPoints.https.http2.maxConcurrentStreams=250',
                        '--entrypoints.https.http3',
                        '--providers.file.directory=/traefik/dynamic/',
                        '--providers.file.watch=true',
                        '--certificatesresolvers.letsencrypt.acme.httpchallenge=true',
                        '--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=http',
                        '--certificatesresolvers.letsencrypt.acme.storage=/traefik/acme.json',
                        // DNS-01 challenge resolver for wildcard certificates
                        '--certificatesresolvers.letsencrypt-dns.acme.dnschallenge=true',
                        '--certificatesresolvers.letsencrypt-dns.acme.storage=/traefik/acme-dns.json',
                    ],
                    'labels' => $labels,
                ],
            ],
        ];
        if (isDev()) {
            $config['services']['traefik']['command'][] = '--api.insecure=true';
            $config['services']['traefik']['command'][] = '--log.level=debug';
            $config['services']['traefik']['command'][] = '--accesslog.filepath=/traefik/access.log';
            $config['services']['traefik']['command'][] = '--accesslog.bufferingsize=100';
            $config['services']['traefik']['volumes'][] = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy/:/traefik';
        } else {
            $config['services']['traefik']['command'][] = '--api.insecure=false';
            $config['services']['traefik']['volumes'][] = "{$proxy_path}:/traefik";
        }
        if ($server->isSwarm()) {
            data_forget($config, 'services.traefik.container_name');
            data_forget($config, 'services.traefik.restart');
            data_forget($config, 'services.traefik.labels');

            $config['services']['traefik']['command'][] = '--providers.swarm.endpoint=unix:///var/run/docker.sock';
            $config['services']['traefik']['command'][] = '--providers.swarm.exposedbydefault=false';
            $config['services']['traefik']['deploy'] = [
                'labels' => $labels,
                'placement' => [
                    'constraints' => [
                        'node.role==manager',
                    ],
                ],
            ];
        } else {
            $config['services']['traefik']['command'][] = '--providers.docker=true';
            $config['services']['traefik']['command'][] = '--providers.docker.exposedbydefault=false';
        }

        // Configure wildcard SSL if enabled
        if ($server->settings->is_wildcard_ssl_enabled && $server->settings->dns_provider) {
            $dnsProvider = $server->settings->dns_provider;
            $config['services']['traefik']['command'][] = "--certificatesresolvers.letsencrypt-dns.acme.dnschallenge.provider={$dnsProvider}";

            if ($server->settings->acme_email) {
                $config['services']['traefik']['command'][] = "--certificatesresolvers.letsencrypt-dns.acme.email={$server->settings->acme_email}";
                $config['services']['traefik']['command'][] = "--certificatesresolvers.letsencrypt.acme.email={$server->settings->acme_email}";
            }

            // Add staging server for testing
            if ($server->settings->use_staging_acme) {
                $config['services']['traefik']['command'][] = '--certificatesresolvers.letsencrypt-dns.acme.caserver=https://acme-staging-v02.api.letsencrypt.org/directory';
            }

            // Add DNS provider environment variables
            $credentials = $server->settings->dns_provider_credentials ?? [];
            if (!empty($credentials)) {
                $config['services']['traefik']['environment'] = $config['services']['traefik']['environment'] ?? [];

                // Provider-specific environment variables
                switch ($dnsProvider) {
                    case 'cloudflare':
                        if (isset($credentials['api_token'])) {
                            $config['services']['traefik']['environment'][] = "CF_API_TOKEN={$credentials['api_token']}";
                        } elseif (isset($credentials['email']) && isset($credentials['api_key'])) {
                            $config['services']['traefik']['environment'][] = "CF_API_EMAIL={$credentials['email']}";
                            $config['services']['traefik']['environment'][] = "CF_API_KEY={$credentials['api_key']}";
                        }
                        break;
                    case 'route53':
                        if (isset($credentials['access_key_id']) && isset($credentials['secret_access_key'])) {
                            $config['services']['traefik']['environment'][] = "AWS_ACCESS_KEY_ID={$credentials['access_key_id']}";
                            $config['services']['traefik']['environment'][] = "AWS_SECRET_ACCESS_KEY={$credentials['secret_access_key']}";
                            if (isset($credentials['region'])) {
                                $config['services']['traefik']['environment'][] = "AWS_REGION={$credentials['region']}";
                            }
                        }
                        break;
                    case 'digitalocean':
                        if (isset($credentials['auth_token'])) {
                            $config['services']['traefik']['environment'][] = "DO_AUTH_TOKEN={$credentials['auth_token']}";
                        }
                        break;
                }
            }
        }

        // Append custom commands (e.g., trustedIPs for Cloudflare)
        if (! empty($custom_commands)) {
            foreach ($custom_commands as $custom_command) {
                $config['services']['traefik']['command'][] = $custom_command;
            }
        }
    } elseif ($proxy_type === 'CADDY') {
        $config = [
            'networks' => $array_of_networks->toArray(),
            'services' => [
                'caddy' => [
                    'container_name' => 'coolify-proxy',
                    'image' => 'lucaslorentz/caddy-docker-proxy:2.8-alpine',
                    'restart' => RESTART_MODE,
                    'extra_hosts' => [
                        'host.docker.internal:host-gateway',
                    ],
                    'environment' => [
                        'CADDY_DOCKER_POLLING_INTERVAL=5s',
                        'CADDY_DOCKER_CADDYFILE_PATH=/dynamic/Caddyfile',
                    ],
                    'networks' => $filtered_networks->toArray(),
                    'ports' => [
                        '80:80',
                        '443:443',
                        '443:443/udp',
                    ],
                    'labels' => [
                        'coolify.managed=true',
                        'coolify.proxy=true',
                    ],
                    'volumes' => [
                        '/var/run/docker.sock:/var/run/docker.sock:ro',
                        "{$proxy_path}/dynamic:/dynamic",
                        "{$proxy_path}/config:/config",
                        "{$proxy_path}/data:/data",
                    ],
                ],
            ],
        ];
    } else {
        return null;
    }

    $config = Yaml::dump($config, 12, 2);
    SaveProxyConfiguration::run($server, $config);

    return $config;
}

/**
 * Write Traefik dynamic configuration file for an application's domains
 * This enables zero-downtime domain additions for multi-tenant applications
 *
 * @param  Application  $application  The application instance
 */
function writeDynamicConfigurationForApplication(Application $application): void
{
    $server = $application->destination->server;
    $proxy_path = $server->proxyPath();
    $proxy_type = $server->proxyType();

    // Only works with Traefik
    if ($proxy_type !== ProxyTypes::TRAEFIK->value) {
        return;
    }

    // Get domains from application
    $domains = str($application->fqdn)->explode(',')->filter(fn ($domain) => ! empty(trim($domain)));

    if ($domains->isEmpty()) {
        // No domains, remove the config file if it exists
        removeDynamicConfigurationForApplication($application);

        return;
    }

    // Generate container name (use consistent name if enabled, otherwise use UUID)
    $containerName = $application->settings->is_consistent_container_name_enabled
        ? $application->uuid
        : $application->uuid;

    // Get port
    $ports = $application->settings->is_static ? [80] : $application->ports_exposes_array;
    $port = count($ports) > 0 ? $ports[0] : 80;

    // Build Traefik configuration
    $config = [
        'http' => [
            'routers' => [],
            'services' => [
                "app-{$application->uuid}" => [
                    'loadBalancer' => [
                        'servers' => [
                            ['url' => "http://{$containerName}:{$port}"],
                        ],
                    ],
                ],
            ],
            'middlewares' => [],
        ],
    ];

    // Add gzip middleware if enabled
    if ($application->isGzipEnabled()) {
        $config['http']['middlewares']['gzip-'.$application->uuid] = [
            'compress' => true,
        ];
    }

    // Process each domain
    foreach ($domains as $index => $domain) {
        $domain = trim($domain);
        $url = \Spatie\Url\Url::fromString($domain);
        $host = $url->getHost();
        $path = $url->getPath() ?: '/';
        $schema = $url->getScheme();

        $routerName = "http-{$index}-{$application->uuid}";
        $httpsRouterName = "https-{$index}-{$application->uuid}";

        // HTTP router
        $router = [
            'rule' => "Host(`{$host}`) && PathPrefix(`{$path}`)",
            'service' => "app-{$application->uuid}",
            'entryPoints' => ['http'],
        ];

        // Add middlewares
        $middlewares = [];
        if ($application->isGzipEnabled()) {
            $middlewares[] = 'gzip-'.$application->uuid;
        }

        if (! empty($middlewares)) {
            $router['middlewares'] = $middlewares;
        }

        $config['http']['routers'][$routerName] = $router;

        // HTTPS router if schema is https
        if ($schema === 'https') {
            $httpsRouter = $router;
            $httpsRouter['entryPoints'] = ['https'];
            $httpsRouter['tls'] = [
                'certResolver' => 'letsencrypt',
            ];

            $config['http']['routers'][$httpsRouterName] = $httpsRouter;

            // Add redirect middleware for http->https if force https is enabled
            if ($application->isForceHttpsEnabled()) {
                $config['http']['routers'][$routerName]['middlewares'][] = 'redirect-to-https';
            }
        }
    }

    // Convert to YAML
    $yaml = Yaml::dump($config, 10, 2);

    // Add header comment
    $yaml = "# This file is generated by Coolify for application: {$application->name}\n# UUID: {$application->uuid}\n# Last updated: ".now()->toDateTimeString()."\n\n".$yaml;

    // Write to file on server
    $filename = "app-{$application->uuid}.yaml";
    $encoded = base64_encode($yaml);

    instant_remote_process([
        "mkdir -p {$proxy_path}/dynamic",
        "echo '{$encoded}' | base64 -d > {$proxy_path}/dynamic/{$filename}",
        "chmod 644 {$proxy_path}/dynamic/{$filename}",
    ], $server);
}

/**
 * Remove dynamic configuration file for an application
 *
 * @param  Application  $application  The application instance
 */
function removeDynamicConfigurationForApplication(Application $application): void
{
    $server = $application->destination->server;
    $proxy_path = $server->proxyPath();
    $filename = "app-{$application->uuid}.yaml";

    instant_remote_process([
        "rm -f {$proxy_path}/dynamic/{$filename}",
    ], $server, throwError: false);
}
