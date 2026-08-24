<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Jobs;

use App\Actions\CronJob\CreateCronJob;
use App\Actions\Database\CreateDatabase;
use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\LinkUser;
use App\Actions\Site\CreateSite;
use App\Actions\Site\UpdateDeploymentScript;
use App\Actions\Site\UpdateEnv;
use App\Actions\Worker\CreateWorker;
use App\Enums\SiteStatus;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\Site;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import\ContentTranslator;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import\EnvironmentValues;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class RunImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 120;

    public int $timeout = 180;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('default');
    }

    public function handle(ContentTranslator $translator): void
    {
        $run = ImportRun::query()->find($this->runId);
        if (! $run || in_array($run->status, ['complete', 'cancelled'], true)) {
            return;
        }

        $run->update(['status' => 'running']);
        $selection = $run->selection['sites'] ?? [];
        $result = $run->result ?? ['sites' => []];
        $result['sites'] ??= [];

        foreach ($selection as $index => $selected) {
            $forgeId = (string) $selected['forge_site_id'];
            $siteResult = $result['sites'][$forgeId] ?? ['state' => 'pending', 'resources' => [], 'warnings' => []];

            $hasFailedResources = in_array('failed', $siteResult['resources'] ?? [], true);
            if ($siteResult['state'] === 'complete' && ! $hasFailedResources) {
                continue;
            }
            if ($siteResult['state'] === 'failed') {
                continue;
            }

            $manifest = $this->manifest($run->snapshot, $forgeId);
            if ($manifest === null) {
                $siteResult['state'] = 'failed';
                $siteResult['error'] = 'Forge snapshot is missing.';
                $result['sites'][$forgeId] = $siteResult;
                $this->saveResult($run, $result, $index, count($selection), 'Snapshot missing');
                continue;
            }

            if (empty($siteResult['vito_site_id'])) {
                try {
                    $site = $this->createSite($run, $selected);
                    $siteResult['vito_site_id'] = $site->id;
                    $siteResult['domain'] = $site->domain;
                    $siteResult['state'] = 'waiting';
                    $result['sites'][$forgeId] = $siteResult;
                    $this->saveResult($run, $result, $index, count($selection), 'Installing '.$site->domain);
                    $run->update(['status' => 'waiting']);
                    $this->release((int) config('forge-import.poll_seconds', 20));

                    return;
                } catch (Throwable $e) {
                    $siteResult['state'] = 'failed';
                    $siteResult['error'] = $e->getMessage();
                    $result['sites'][$forgeId] = $siteResult;
                    $this->saveResult($run, $result, $index, count($selection), 'Failed to create '.$selected['domain']);
                    continue;
                }
            }

            $site = Site::query()->find($siteResult['vito_site_id']);
            if (! $site) {
                $siteResult['state'] = 'failed';
                $siteResult['error'] = 'The created Vito site no longer exists.';
                $result['sites'][$forgeId] = $siteResult;
                continue;
            }

            if ($site->status === SiteStatus::INSTALLATION_FAILED) {
                $siteResult['state'] = 'failed';
                $siteResult['error'] = $site->last_error ?: 'Vito site installation failed.';
                $result['sites'][$forgeId] = $siteResult;
                continue;
            }

            if (! $site->isReady()) {
                $siteResult['state'] = 'waiting';
                $result['sites'][$forgeId] = $siteResult;
                $this->saveResult($run, $result, $index, count($selection), 'Waiting for '.$site->domain);
                $run->update(['status' => 'waiting']);
                $this->release((int) config('forge-import.poll_seconds', 20));

                return;
            }

            $siteResult['state'] = 'configuring';
            $result['sites'][$forgeId] = $siteResult;
            $this->saveResult($run, $result, $index, count($selection), 'Importing configuration for '.$site->domain);
            $this->importResources($site, $manifest, $selected, $siteResult, $translator);
            $siteResult['state'] = 'complete';
            $result['sites'][$forgeId] = $siteResult;
            $this->saveResult($run, $result, $index + 1, count($selection), 'Completed '.$site->domain);
        }

        $failed = collect($result['sites'])->filter(fn (array $siteResult) =>
            ($siteResult['state'] ?? null) === 'failed'
            || in_array('failed', $siteResult['resources'] ?? [], true)
        )->count();
        $run->update([
            'status' => $failed > 0 ? 'partial' : 'complete',
            'progress' => 100,
            'current_step' => $failed > 0 ? 'Finished with failures' : 'Import complete',
            'result' => $result,
            'error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        ImportRun::query()->whereKey($this->runId)->update([
            'status' => 'failed',
            'current_step' => 'Import job failed',
            'error' => $exception?->getMessage() ?? 'Unknown import failure',
        ]);
    }

    private function createSite(ImportRun $run, array $selected): Site
    {
        $server = Server::query()->findOrFail($run->target_server_id);
        $type = $selected['type'];
        $input = [
            'type' => $type,
            'domain' => $selected['domain'],
            'aliases' => ($selected['resources']['domains'] ?? false) ? ($selected['aliases'] ?? []) : [],
            'user' => $selected['user'],
            'php_version' => $selected['php_version'] ?? null,
            'source_control' => $selected['source_control_id'] ?? null,
            'repository' => $selected['repository'] ?? null,
            'branch' => $selected['branch'] ?? 'main',
            'web_directory' => $selected['web_directory'] ?? '',
            'composer' => (bool) ($selected['composer'] ?? false),
            'port' => $selected['port'] ?? 3000,
            'node_version' => $selected['node_version'] ?? '22',
            'package_manager' => $selected['package_manager'] ?? 'node',
            'start_command' => $selected['start_command'] ?? 'npm start',
            'use_source_control' => ! empty($selected['source_control_id']) && ! empty($selected['repository']),
        ];

        return app(CreateSite::class)->create($server, $input);
    }

    private function importResources(Site $site, array $manifest, array $selected, array &$siteResult, ContentTranslator $translator): void
    {
        $resources = $selected['resources'] ?? [];
        $forgeDomain = (string) ($manifest['site']['domain'] ?? $manifest['site']['name'] ?? '');
        $environment = is_string($manifest['environment'] ?? null) ? $manifest['environment'] : null;

        if (($resources['database'] ?? false) && ($selected['database']['enabled'] ?? false)) {
            try {
                $credentials = $this->configureDatabase($site->server, $selected['database']);
                $siteResult['resources']['database'] = 'imported';
                if ($environment !== null) {
                    if (app(EnvironmentValues::class)->get($environment, 'DB_URL') !== '') {
                        $credentials['DB_URL'] = $this->databaseUrl($credentials);
                    }
                    if (app(EnvironmentValues::class)->get($environment, 'DATABASE_URL') !== '') {
                        $credentials['DATABASE_URL'] = $this->databaseUrl($credentials);
                    }
                    $environment = app(EnvironmentValues::class)->replace($environment, $credentials);
                }
            } catch (Throwable $e) {
                $siteResult['resources']['database'] = 'failed';
                $siteResult['warnings'][] = 'database: '.$e->getMessage();
            }
        }

        if (($resources['environment'] ?? false) && $environment !== null) {
            $this->attempt($siteResult, 'environment', fn () => app(UpdateEnv::class)->update($site, ['env' => $environment]));
        }

        if (($resources['deployment_script'] ?? false) && is_string($manifest['deployment_script'])) {
            $script = $translator->deploymentScript($manifest['deployment_script'], $site);
            $this->attempt($siteResult, 'deployment_script', fn () => app(UpdateDeploymentScript::class)->update(
                $site->deploymentScript,
                ['script' => $script, 'restart_workers' => true],
            ));
        }

        if ($resources['cron_jobs'] ?? false) {
            foreach ($manifest['cron_jobs'] as $index => $job) {
                $this->attempt($siteResult, 'cron_job_'.($job['id'] ?: $index), function () use ($site, $job, $translator, $forgeDomain): void {
                    $command = $translator->command((string) ($job['command'] ?? ''), $site, $forgeDomain);
                    $exists = \App\Models\CronJob::query()
                        ->where('site_id', $site->id)
                        ->where('command', $command)
                        ->exists();
                    if ($exists) {
                        return;
                    }
                    app(CreateCronJob::class)->create($site->server, [
                        'name' => $job['name'] ?? null,
                        'command' => $command,
                        'user' => $site->user,
                        'frequency' => 'custom',
                        'custom' => $this->cron($job),
                    ], $site);
                });
            }
        }

        if ($resources['workers'] ?? false) {
            foreach ($manifest['workers'] as $index => $worker) {
                $this->attempt($siteResult, 'worker_'.($worker['id'] ?: $index), function () use ($site, $worker, $translator, $forgeDomain, $index): void {
                    $name = $worker['name'] ?? 'forge-worker-'.($index + 1);
                    if ($site->workers()->where('name', $name)->exists()) {
                        return;
                    }
                    app(CreateWorker::class)->create($site->server, [
                        'name' => $name,
                        'command' => $translator->command((string) ($worker['command'] ?? ''), $site, $forgeDomain),
                        'user' => $site->user,
                        'auto_start' => true,
                        'auto_restart' => true,
                        'numprocs' => max(1, (int) ($worker['processes'] ?? $worker['numprocs'] ?? 1)),
                    ], $site);
                });
            }
        }
    }

    /** @return array<string, string|int> */
    private function configureDatabase(Server $server, array $selection): array
    {
        $name = (string) ($selection['name'] ?? '');
        $username = (string) ($selection['username'] ?? '');
        if ($name === '' || $username === '') {
            throw new \RuntimeException('Choose both a database name and database user.');
        }

        $service = $server->database();
        if ($service === null) {
            throw new \RuntimeException('The Vito destination has no database service.');
        }

        /** @var ?Database $database */
        $database = $server->databases()->where('name', $name)->first();
        /** @var ?DatabaseUser $databaseUser */
        $databaseUser = $server->databaseUsers()->where('username', $username)->first();
        $password = $databaseUser?->password ?: Str::random(40);

        if ($database === null) {
            [$charset, $collation] = $this->databaseDefaults($server);
            $input = ['name' => $name, 'charset' => $charset, 'collation' => $collation];
            if ($databaseUser !== null) {
                $input += ['user' => true, 'existing_user_id' => $databaseUser->id];
            } else {
                $input += ['username' => $username, 'password' => $password];
            }
            $database = app(CreateDatabase::class)->create($server, $input);
            $databaseUser ??= $server->databaseUsers()->where('username', $username)->first();
        } elseif ($databaseUser === null) {
            $databaseUser = app(CreateDatabaseUser::class)->create($server, [
                'username' => $username,
                'password' => $password,
                'permission' => 'admin',
            ], [$database->name]);
        } elseif (! in_array($database->name, $databaseUser->databases ?? [], true)) {
            app(LinkUser::class)->link($databaseUser, [
                'databases' => array_values(array_unique([...($databaseUser->databases ?? []), $database->name])),
            ]);
        }

        if ($databaseUser === null || $databaseUser->password === '') {
            throw new \RuntimeException('The matching Vito database user has no retrievable password; choose a new username.');
        }

        $connection = match ($service->name) {
            'postgresql', 'postgres' => 'pgsql',
            'mariadb' => 'mariadb',
            default => 'mysql',
        };

        return [
            'DB_CONNECTION' => $connection,
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => $connection === 'pgsql' ? 5432 : 3306,
            'DB_DATABASE' => $database->name,
            'DB_USERNAME' => $databaseUser->username,
            'DB_PASSWORD' => $databaseUser->password,
        ];
    }

    /** @return array{string, string} */
    private function databaseDefaults(Server $server): array
    {
        $service = $server->database();
        $charset = (string) data_get($service?->type_data, 'defaultCharset', '');
        if ($charset === '') {
            $charset = (string) array_key_first((array) data_get($service?->type_data, 'charsets', []));
        }
        $collation = (string) data_get($service?->type_data, 'charsets.'.$charset.'.default', '');
        if ($charset === '' || $collation === '') {
            throw new \RuntimeException('Vito has no charset/collation metadata for this database service. Sync the service first.');
        }

        return [$charset, $collation];
    }

    /** @param array<string, string|int> $credentials */
    private function databaseUrl(array $credentials): string
    {
        $scheme = $credentials['DB_CONNECTION'] === 'pgsql' ? 'postgresql' : (string) $credentials['DB_CONNECTION'];

        return $scheme.'://'.rawurlencode((string) $credentials['DB_USERNAME'])
            .':'.rawurlencode((string) $credentials['DB_PASSWORD'])
            .'@'.$credentials['DB_HOST'].':'.$credentials['DB_PORT'].'/'.rawurlencode((string) $credentials['DB_DATABASE']);
    }

    private function attempt(array &$siteResult, string $resource, callable $callback): void
    {
        try {
            $callback();
            $siteResult['resources'][$resource] = 'imported';
        } catch (Throwable $e) {
            $siteResult['resources'][$resource] = 'failed';
            $siteResult['warnings'][] = $resource.': '.$e->getMessage();
        }
    }

    private function cron(array $job): string
    {
        if (! empty($job['cron'])) {
            return (string) $job['cron'];
        }

        return match (strtolower((string) ($job['frequency'] ?? ''))) {
            'minutely', 'every minute' => '* * * * *',
            'hourly' => '0 * * * *',
            'daily', 'nightly' => '0 0 * * *',
            'weekly' => '0 0 * * 0',
            'monthly' => '0 0 1 * *',
            default => '* * * * *',
        };
    }

    private function manifest(array $snapshot, string $forgeId): ?array
    {
        foreach ($snapshot as $manifest) {
            if ((string) data_get($manifest, 'site.id') === $forgeId) {
                return $manifest;
            }
        }

        return null;
    }

    private function saveResult(ImportRun $run, array $result, int $completed, int $total, string $step): void
    {
        $run->update([
            'result' => $result,
            'progress' => $total > 0 ? min(99, (int) floor(($completed / $total) * 100)) : 0,
            'current_step' => $step,
        ]);
    }
}
