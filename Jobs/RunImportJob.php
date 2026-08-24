<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImport\Jobs;

use App\Actions\CronJob\CreateCronJob;
use App\Actions\Site\CreateSite;
use App\Actions\Site\UpdateDeploymentScript;
use App\Actions\Site\UpdateEnv;
use App\Actions\Worker\CreateWorker;
use App\Enums\SiteStatus;
use App\Models\Server;
use App\Models\Site;
use App\Vito\Plugins\Cp6\VitoDeployForgeImport\Import\ContentTranslator;
use App\Vito\Plugins\Cp6\VitoDeployForgeImport\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        if (($resources['environment'] ?? false) && is_string($manifest['environment'])) {
            $this->attempt($siteResult, 'environment', fn () => app(UpdateEnv::class)->update($site, ['env' => $manifest['environment']]));
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
