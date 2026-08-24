<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Forge;

class ForgeManifestBuilder
{
    public function __construct(private readonly ForgeApiClient $client) {}

    public function organizations(): array
    {
        return array_map([ForgeApiClient::class, 'resource'], $this->client->paginate('/orgs'));
    }

    public function servers(string $organization): array
    {
        return array_map([ForgeApiClient::class, 'resource'], $this->client->paginate("/orgs/{$organization}/servers"));
    }

    public function sites(string $organization, string $server): array
    {
        return array_map([ForgeApiClient::class, 'resource'], $this->client->paginate("/orgs/{$organization}/servers/{$server}/sites"));
    }

    public function build(string $organization, string $server, array $siteIds, array $resources): array
    {
        $serverProcesses = in_array('workers', $resources, true)
            ? $this->resources("/orgs/{$organization}/servers/{$server}/background-processes")
            : [];

        $manifests = [];
        foreach ($siteIds as $siteId) {
            $sitePayload = $this->client->get("/orgs/{$organization}/sites/{$siteId}");
            $site = ForgeApiClient::resource($sitePayload['data'] ?? $sitePayload);

            $manifest = [
                'site' => $site,
                'domains' => in_array('domains', $resources, true)
                    ? $this->siteDomains($site)
                    : [],
                'environment' => in_array('environment', $resources, true)
                    ? $this->content("/orgs/{$organization}/servers/{$server}/sites/{$siteId}/environment", ['environment', 'content'])
                    : null,
                'deployment_script' => in_array('deployment_script', $resources, true)
                    ? $this->content("/orgs/{$organization}/servers/{$server}/sites/{$siteId}/deployments/script", ['script', 'content'])
                    : null,
                'cron_jobs' => in_array('cron_jobs', $resources, true)
                    ? $this->resources("/orgs/{$organization}/servers/{$server}/sites/{$siteId}/scheduled-jobs")
                    : [],
                'workers' => $this->siteProcesses($serverProcesses, (string) $siteId),
            ];

            $manifests[] = $manifest;
        }

        return $manifests;
    }

    public function redact(array $manifest): array
    {
        $redacted = $manifest;
        $environment = $redacted['environment'];
        $redacted['environment'] = [
            'available' => is_string($environment),
            'keys' => $this->environmentKeys($environment),
        ];
        $redacted['deployment_script'] = [
            'available' => is_string($redacted['deployment_script']),
            'lines' => is_string($redacted['deployment_script']) ? substr_count($redacted['deployment_script'], "\n") + 1 : 0,
        ];

        return $redacted;
    }

    private function resources(string $path): array
    {
        return array_map([ForgeApiClient::class, 'resource'], $this->client->optionalPaginate($path));
    }

    private function content(string $path, array $keys): ?string
    {
        $payload = $this->client->optional($path);
        if ($payload === null) {
            return null;
        }

        foreach ($keys as $key) {
            foreach (["data.attributes.{$key}", "data.{$key}", $key] as $candidate) {
                $value = data_get($payload, $candidate);
                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function siteProcesses(array $processes, string $siteId): array
    {
        return array_values(array_filter($processes, function (array $process) use ($siteId): bool {
            $relatedId = data_get($process, '_relationships.site.data.id');
            $processSiteId = $process['site_id'] ?? $relatedId;

            return (string) $processSiteId === $siteId;
        }));
    }

    private function siteDomains(array $site): array
    {
        $domains = [];
        $primary = $site['name'] ?? $site['domain'] ?? null;

        if (is_string($primary) && $primary !== '') {
            $domains[] = ['name' => $primary, 'primary' => true];
        }

        foreach (($site['aliases'] ?? []) as $alias) {
            if (is_string($alias) && $alias !== '') {
                $domains[] = ['name' => $alias, 'primary' => false];
            }
        }

        return $domains;
    }

    private function environmentKeys(?string $environment): array
    {
        if (! is_string($environment)) {
            return [];
        }

        preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $environment, $matches);

        return array_values(array_unique($matches[1]));
    }
}
