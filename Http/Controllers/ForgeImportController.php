<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControl;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Database\SchemaManager;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Forge\ForgeApiClient;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Forge\ForgeManifestBuilder;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import\CompatibilityChecker;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Jobs\RunImportJob;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Models\ImportRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ForgeImportController extends Controller
{
    private const SESSION_TOKEN = 'vito-forge-import.token';

    private const SUPPORTED_SITE_TYPES = ['laravel', 'php', 'php-blank', 'node', 'blank'];

    public function styles(): BinaryFileResponse
    {
        return response()->file(dirname(__DIR__, 2).'/resources/dist/importer.css', [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function index(Request $request): View
    {
        app(SchemaManager::class)->ensureInstalled();

        $project = $request->user()->currentProject;
        Gate::forUser($request->user())->authorize('view', $project);

        $servers = $project->servers()
            ->with('services')
            ->orderBy('name')
            ->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status->getText(),
                'php_versions' => $server->installedPHPVersions(),
                'services' => $server->services->pluck('name', 'type'),
            ])
            ->values();

        $sourceControls = SourceControl::query()
            ->whereIn('user_id', $project->servers()->select('user_id'))
            ->where(fn ($query) => $query->where('project_id', $project->id)->orWhereNull('project_id'))
            ->get()
            ->map(fn (SourceControl $sourceControl) => [
                'id' => $sourceControl->id,
                'provider' => $sourceControl->provider,
                'profile' => $sourceControl->profile,
            ])
            ->values();

        $connected = $request->session()->has(self::SESSION_TOKEN);
        $siteTypes = collect(config('site.types', []))
            ->only(self::SUPPORTED_SITE_TYPES)
            ->map(fn ($type, $id) => [
                'id' => $id,
                'label' => $type['label'] ?? $id,
            ])->values();
        $selectedServer = (int) $request->query('server', 0);
        $frontendConfig = [
            'connected' => $connected,
            'servers' => $servers,
            'sourceControls' => $sourceControls,
            'siteTypes' => $siteTypes,
            'selectedServer' => $selectedServer,
            'urls' => [
                'connect' => route('forge-importer.connect'),
                'organizations' => route('forge-importer.organizations'),
                'forgeServers' => route('forge-importer.forge-servers'),
                'forgeSites' => route('forge-importer.forge-sites'),
                'preview' => route('forge-importer.preview'),
                'runs' => route('forge-importer.runs.store'),
                'runBase' => url('/forge-importer/runs'),
            ],
        ];

        return view()->file(dirname(__DIR__, 2).'/resources/views/importer.blade.php', [
            'connected' => $connected,
            'servers' => $servers,
            'sourceControls' => $sourceControls,
            'siteTypes' => $siteTypes,
            'selectedServer' => $selectedServer,
            'maxSites' => (int) config('forge-import.max_sites_per_run', 10),
            'frontendConfig' => $frontendConfig,
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'min:10', 'max:2048']]);

        try {
            $organizations = (new ForgeManifestBuilder(new ForgeApiClient($validated['token'])))->organizations();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->put(self::SESSION_TOKEN, Crypt::encryptString($validated['token']));

        return response()->json([
            'connected' => true,
            'user' => $organizations[0]['name'] ?? 'Forge account',
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $request->session()->forget(self::SESSION_TOKEN);

        return response()->json(['connected' => false]);
    }

    public function organizations(Request $request): JsonResponse
    {
        return $this->forgeResponse($request, fn (ForgeManifestBuilder $builder) => $builder->organizations());
    }

    public function forgeServers(Request $request): JsonResponse
    {
        $validated = $request->validate(['organization' => ['required', 'string', 'max:255']]);

        return $this->forgeResponse($request, fn (ForgeManifestBuilder $builder) => $builder->servers($validated['organization']));
    }

    public function forgeSites(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:255'],
            'server' => ['required', 'string', 'max:255'],
        ]);

        return $this->forgeResponse(
            $request,
            fn (ForgeManifestBuilder $builder) => $builder->sites($validated['organization'], $validated['server'])
        );
    }

    public function preview(Request $request, CompatibilityChecker $checker): JsonResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:255'],
            'forge_server_id' => ['required', 'string', 'max:255'],
            'site_ids' => ['required', 'array', 'min:1', 'max:'.config('forge-import.max_sites_per_run', 10)],
            'site_ids.*' => ['required', 'string', 'max:255', 'distinct'],
            'target_server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'resources' => ['required', 'array'],
            'resources.*' => ['string', Rule::in(['domains', 'environment', 'database', 'deployment_script', 'cron_jobs', 'workers'])],
        ]);

        $target = Server::query()->findOrFail($validated['target_server_id']);
        Gate::forUser($request->user())->authorize('create', [Site::class, $target]);

        try {
            $builder = new ForgeManifestBuilder($this->client($request));
            $manifests = $builder->build(
                $validated['organization'],
                $validated['forge_server_id'],
                $validated['site_ids'],
                $validated['resources'],
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $planId = (string) Str::uuid();
        $encryptedPlan = Crypt::encryptString(json_encode($manifests, JSON_THROW_ON_ERROR));
        Cache::put($this->planKey($request, $planId), $encryptedPlan, now()->addMinutes((int) config('forge-import.plan_ttl_minutes', 30)));

        $sites = [];
        foreach ($manifests as $manifest) {
            $sites[] = array_merge($builder->redact($manifest), $checker->check($manifest, $target));
        }

        return response()->json([
            'plan_id' => $planId,
            'sites' => $sites,
            'expires_in_minutes' => (int) config('forge-import.plan_ttl_minutes', 30),
        ]);
    }

    public function storeRun(Request $request): JsonResponse
    {
        app(SchemaManager::class)->ensureInstalled();

        $validated = $request->validate([
            'plan_id' => ['required', 'uuid'],
            'organization' => ['required', 'string', 'max:255'],
            'forge_server_id' => ['required', 'string', 'max:255'],
            'target_server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'sites' => ['required', 'array', 'min:1', 'max:'.config('forge-import.max_sites_per_run', 10)],
            'sites.*.forge_site_id' => ['required', 'string', 'max:255', 'distinct'],
            'sites.*.enabled' => ['required', 'boolean'],
            'sites.*.domain' => ['required', 'string', 'max:255'],
            'sites.*.aliases' => ['array'],
            'sites.*.aliases.*' => ['string', 'max:255'],
            'sites.*.type' => ['required', 'string', Rule::in(array_intersect(self::SUPPORTED_SITE_TYPES, array_keys(config('site.types', []))))],
            'sites.*.user' => ['required', 'string', 'max:32'],
            'sites.*.php_version' => ['nullable', 'string', 'max:10'],
            'sites.*.source_control_id' => ['nullable', 'integer'],
            'sites.*.repository' => ['nullable', 'string', 'max:500'],
            'sites.*.branch' => ['nullable', 'string', 'max:255'],
            'sites.*.web_directory' => ['nullable', 'string', 'max:255'],
            'sites.*.port' => ['nullable', 'integer', 'between:1024,65535'],
            'sites.*.node_version' => ['nullable', 'string', 'max:20'],
            'sites.*.package_manager' => ['nullable', 'string', Rule::in(['node', 'pnpm', 'yarn', 'bun'])],
            'sites.*.start_command' => ['nullable', 'string', 'max:255'],
            'sites.*.composer' => ['boolean'],
            'sites.*.database' => ['required', 'array'],
            'sites.*.database.enabled' => ['required', 'boolean'],
            'sites.*.database.name' => ['nullable', 'alpha_dash', 'max:64'],
            'sites.*.database.username' => ['nullable', 'alpha_dash', 'max:64'],
            'sites.*.resources' => ['required', 'array'],
            'sites.*.resources.*' => ['boolean'],
        ]);

        $target = Server::query()->findOrFail($validated['target_server_id']);
        Gate::forUser($request->user())->authorize('create', [Site::class, $target]);

        $encryptedPlan = Cache::pull($this->planKey($request, $validated['plan_id']));
        try {
            $snapshot = is_string($encryptedPlan)
                ? json_decode(Crypt::decryptString($encryptedPlan), true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable) {
            $snapshot = null;
        }
        if (! is_array($snapshot)) {
            return response()->json(['message' => 'The preview expired. Generate a new preview before importing.'], 422);
        }

        $selectedIds = collect($validated['sites'])->where('enabled', true)->pluck('forge_site_id')->map(fn ($id) => (string) $id);
        $snapshotIds = collect($snapshot)->pluck('site.id')->map(fn ($id) => (string) $id);
        if ($selectedIds->isEmpty() || $selectedIds->diff($snapshotIds)->isNotEmpty()) {
            return response()->json(['message' => 'The selected sites do not match the generated preview.'], 422);
        }

        $run = ImportRun::query()->create([
            'user_id' => $request->user()->id,
            'project_id' => $target->project_id,
            'target_server_id' => $target->id,
            'organization' => $validated['organization'],
            'forge_server_id' => $validated['forge_server_id'],
            'status' => 'pending',
            'progress' => 0,
            'current_step' => 'Queued',
            'snapshot' => $snapshot,
            'selection' => ['sites' => array_values(array_filter($validated['sites'], fn (array $site) => $site['enabled']))],
            'result' => ['sites' => []],
        ]);

        RunImportJob::dispatch($run->id);

        return response()->json($run->publicStatus(), 202);
    }

    public function showRun(Request $request, ImportRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);

        return response()->json($run->fresh()->publicStatus());
    }

    public function retryRun(Request $request, ImportRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);
        if (! in_array($run->status, ['failed', 'partial'], true)) {
            return response()->json(['message' => 'Only failed or partial imports can be retried.'], 422);
        }

        $result = $run->result ?? ['sites' => []];
        foreach ($result['sites'] ?? [] as &$siteResult) {
            $failedResource = in_array('failed', $siteResult['resources'] ?? [], true);
            if (($siteResult['state'] ?? null) !== 'failed' && ! $failedResource) {
                continue;
            }

            $site = ! empty($siteResult['vito_site_id']) ? Site::query()->find($siteResult['vito_site_id']) : null;
            if ($site && $site->isInstallationFailed()) {
                app(\App\Actions\Site\RetrySite::class)->retry($site);
                $siteResult['state'] = 'waiting';
            } elseif ($site) {
                $siteResult['state'] = 'configuring';
            } else {
                $siteResult['state'] = 'pending';
                unset($siteResult['vito_site_id']);
            }
            unset($siteResult['error']);
        }
        unset($siteResult);

        $run->update(['status' => 'pending', 'error' => null, 'current_step' => 'Queued for retry', 'result' => $result]);
        RunImportJob::dispatch($run->id);

        return response()->json($run->fresh()->publicStatus(), 202);
    }

    public function cancelRun(Request $request, ImportRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);
        if (! in_array($run->status, ['pending', 'running', 'waiting'], true)) {
            return response()->json(['message' => 'This import can no longer be cancelled.'], 422);
        }

        $run->update(['status' => 'cancelled', 'current_step' => 'Cancelled by user']);

        return response()->json($run->fresh()->publicStatus());
    }

    private function forgeResponse(Request $request, callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback(new ForgeManifestBuilder($this->client($request)))]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function client(Request $request): ForgeApiClient
    {
        $encrypted = $request->session()->get(self::SESSION_TOKEN);
        if (! is_string($encrypted)) {
            abort(401, 'Connect a Forge API token first.');
        }

        return new ForgeApiClient(Crypt::decryptString($encrypted));
    }

    private function planKey(Request $request, string $planId): string
    {
        return 'vito-forge-import:plan:'.$request->user()->id.':'.$planId;
    }

    private function authorizeRun(Request $request, ImportRun $run): void
    {
        abort_unless($run->user_id === $request->user()->id || $request->user()->is_admin, 403);
        abort_unless($run->project_id === $request->user()->currentProject->id, 403);
    }
}
