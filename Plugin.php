<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterServerFeature;
use App\Plugins\RegisterServerFeatureAction;
use App\Plugins\RegisterViews;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Database\SchemaManager;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Http\Controllers\ForgeImportController;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\ServerFeatures\OpenImporter;
use Illuminate\Support\Facades\Route;

class Plugin extends AbstractPlugin
{
    protected string $name = 'Forge Site Importer';

    protected string $description = 'Preview and import one or many Laravel Forge sites into VitoDeploy.';

    public function boot(): void
    {
        $this->registerConfiguration();

        RegisterViews::make('vito-forge-import')
            ->path(__DIR__.'/resources/views')
            ->register();

        $this->registerRoutes();
        $this->registerServerFeature();
    }

    public function install(): void
    {
        app(SchemaManager::class)->ensureInstalled();
    }

    public function enable(): void
    {
        app(SchemaManager::class)->ensureInstalled();
    }

    public function uninstall(): void
    {
        app(SchemaManager::class)->uninstall();
    }

    private function registerConfiguration(): void
    {
        $defaults = require __DIR__.'/config/forge-import.php';
        config(['forge-import' => array_replace_recursive($defaults, config('forge-import', []))]);
    }

    private function registerRoutes(): void
    {
        Route::middleware(['web', 'auth', 'has-project'])
            ->prefix('forge-importer')
            ->name('forge-importer.')
            ->group(function (): void {
                Route::get('/', [ForgeImportController::class, 'index'])->name('index');
                Route::post('/connect', [ForgeImportController::class, 'connect'])->name('connect');
                Route::delete('/connect', [ForgeImportController::class, 'disconnect'])->name('disconnect');
                Route::get('/forge/organizations', [ForgeImportController::class, 'organizations'])->name('organizations');
                Route::get('/forge/servers', [ForgeImportController::class, 'forgeServers'])->name('forge-servers');
                Route::get('/forge/sites', [ForgeImportController::class, 'forgeSites'])->name('forge-sites');
                Route::post('/preview', [ForgeImportController::class, 'preview'])->name('preview');
                Route::post('/runs', [ForgeImportController::class, 'storeRun'])->name('runs.store');
                Route::get('/runs/{run}', [ForgeImportController::class, 'showRun'])->name('runs.show');
                Route::post('/runs/{run}/retry', [ForgeImportController::class, 'retryRun'])->name('runs.retry');
                Route::post('/runs/{run}/cancel', [ForgeImportController::class, 'cancelRun'])->name('runs.cancel');
            });

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    private function registerServerFeature(): void
    {
        RegisterServerFeature::make('forge-importer')
            ->label('Forge Importer')
            ->description('Import one or more Laravel Forge sites into this server')
            ->register();

        RegisterServerFeatureAction::make('forge-importer', 'open')
            ->label('Open Importer')
            ->handler(OpenImporter::class)
            ->register();
    }
}
