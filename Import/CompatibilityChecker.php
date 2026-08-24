<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import;

use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControl;

class CompatibilityChecker
{
    public function check(array $manifest, Server $target): array
    {
        $site = $manifest['site'];
        $domain = $this->domain($site);
        $php = $this->phpVersion($site['php_version'] ?? null);
        $mappedType = $this->siteTypeForManifest($manifest);
        $repositoryData = $this->repository($site['repository'] ?? null);
        $repository = $repositoryData['name'];
        $sourceProvider = $this->stringValue(
            $site['source_control_provider'] ?? $site['repository_provider'] ?? $repositoryData['provider'],
        );
        $branch = $this->stringValue(
            $site['branch'] ?? $site['repository_branch'] ?? $repositoryData['branch'],
        ) ?: 'main';

        $checks = [
            $this->makeCheck('domain', 'Domain is available', $domain !== '' && ! Site::query()->where('server_id', $target->id)->where('domain', $domain)->exists(), $domain ?: 'Missing domain'),
            $this->makeCheck('site_type', 'Vito site type is available', isset(config('site.types')[$mappedType]), $mappedType),
            $this->makeCheck('webserver', 'Web server service is installed', $target->services()->where('type', 'webserver')->exists(), 'webserver'),
        ];

        if ($php !== null && in_array($mappedType, ['laravel', 'php', 'php-blank'], true)) {
            $checks[] = $this->makeCheck('php', 'PHP version is installed', in_array($php, $target->installedPHPVersions(), true), $php);
        }

        if (in_array($mappedType, ['node', 'blank'], true)) {
            $checks[] = $this->makeCheck(
                'process_manager',
                'Process manager service is installed',
                $target->services()->where('type', 'process_manager')->exists(),
                'process manager',
            );
        }

        if ($repository !== '' && in_array($mappedType, ['laravel', 'php', 'node'], true)) {
            $sourceControl = SourceControl::query()
                ->where('user_id', $target->user_id)
                ->where(function ($query) use ($target): void {
                    $query->where('project_id', $target->project_id)->orWhereNull('project_id');
                })
                ->when($sourceProvider !== '', fn ($query) => $query->where('provider', 'like', $sourceProvider.'%'))
                ->first();

            $checks[] = $this->makeCheck('source_control', 'Matching source control is connected', $sourceControl !== null, $sourceProvider ?: 'source control');
        }

        if ($repository === '' && in_array($mappedType, ['laravel', 'php', 'node'], true)) {
            $checks[] = $this->makeCheck('repository', 'Repository is present', false, 'No repository returned by Forge');
        }

        return [
            'can_import' => ! collect($checks)->contains(fn (array $check) => $check['status'] === 'blocked'),
            'checks' => $checks,
            'defaults' => [
                'forge_site_id' => (string) $site['id'],
                'domain' => $domain,
                'aliases' => $this->aliases($manifest, $domain),
                'type' => $mappedType,
                'user' => $this->username($domain, $target),
                'forge_user' => $this->stringValue($site['username'] ?? $site['user'] ?? ''),
                'php_version' => $php ?? ($target->installedPHPVersions()[0] ?? '8.4'),
                'repository' => $repository,
                'branch' => $branch,
                'web_directory' => $this->defaultWebDirectory($mappedType),
                'forge_web_directory' => $this->stringValue($site['web_directory'] ?? ''),
                'source_control_provider' => $sourceProvider,
                'port' => (int) ($site['port'] ?? 3000),
                'node_version' => '22',
                'package_manager' => 'node',
                'start_command' => (string) ($site['start_command'] ?? 'npm start'),
            ],
        ];
    }

    public function siteType(string $type): string
    {
        return match ($type) {
            'laravel' => 'laravel',
            'nextjs', 'nuxtjs' => 'node',
            'other', 'custom' => 'blank',
            'wordpress', 'phpmyadmin' => 'php-blank',
            default => 'php',
        };
    }

    private function siteTypeForManifest(array $manifest): string
    {
        $site = $manifest['site'];
        $type = $this->siteType($this->stringValue($site['app_type'] ?? $site['type'] ?? $site['project_type'] ?? 'php'));

        if ($type !== 'php') {
            return $type;
        }

        $scripts = array_filter([
            $this->stringValue($site['deployment_script'] ?? ''),
            $this->stringValue($manifest['deployment_script'] ?? ''),
        ]);
        $environment = $this->stringValue($manifest['environment'] ?? '');
        $usesArtisan = str_contains(strtolower(implode("\n", $scripts)), 'artisan');
        $hasLaravelEnvironment = preg_match('/^\s*APP_KEY\s*=/m', $environment) === 1;

        return $usesArtisan || $hasLaravelEnvironment ? 'laravel' : 'php';
    }

    private function defaultWebDirectory(string $type): string
    {
        return $type === 'laravel' ? 'public' : '';
    }

    private function makeCheck(string $key, string $label, bool $matches, string $value): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $matches ? 'matched' : 'blocked',
            'value' => $value,
        ];
    }

    private function domain(array $site): string
    {
        return strtolower((string) ($site['domain'] ?? $site['name'] ?? ''));
    }

    private function phpVersion(mixed $version): ?string
    {
        if (! is_string($version) || $version === '') {
            return null;
        }

        if (preg_match('/php(\d)(\d)/', $version, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        return preg_match('/^\d+\.\d+$/', $version) ? $version : null;
    }

    private function aliases(array $manifest, string $primary): array
    {
        $aliases = [];
        foreach ($manifest['domains'] as $domain) {
            $name = strtolower((string) ($domain['name'] ?? $domain['domain'] ?? ''));
            if ($name !== '' && $name !== $primary) {
                $aliases[] = $name;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @return array{name: string, provider: string, branch: string}
     */
    private function repository(mixed $repository): array
    {
        if (is_string($repository)) {
            return ['name' => $repository, 'provider' => '', 'branch' => ''];
        }

        if (! is_array($repository)) {
            return ['name' => '', 'provider' => '', 'branch' => ''];
        }

        return [
            'name' => $this->stringValue(
                $repository['url'] ?? $repository['repository'] ?? $repository['full_name'] ?? $repository['name'] ?? '',
            ),
            'provider' => $this->stringValue($repository['provider'] ?? ''),
            'branch' => $this->stringValue($repository['branch'] ?? $repository['default_branch'] ?? ''),
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function username(string $domain, Server $target): string
    {
        $slug = preg_replace('/^https?:\/\//', '', strtolower($domain)) ?? '';
        $slug = preg_replace('/^www\./', '', $slug) ?? '';
        $slug = preg_replace('/[^a-z0-9]/', '', $slug) ?? '';
        $slug = preg_replace('/^\d+/', '', $slug) ?? '';
        $base = substr($slug, 0, 6);
        $blocked = array_unique(array_merge(
            config('core.reserved_user_names', []),
            [$target->getSshUser()],
            $target->isolatedUsers()->pluck('username')->all(),
        ));

        for ($index = 0; $index < 1000; $index++) {
            $candidate = $index === 0 ? $base : $base.$index;
            if (strlen($candidate) < 3 || strlen($candidate) > 32 || in_array($candidate, $blocked, true)) {
                continue;
            }

            return $candidate;
        }

        return 'siteimport';
    }
}
