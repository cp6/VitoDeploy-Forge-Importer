<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImport\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaManager
{
    public function ensureInstalled(): void
    {
        if (Schema::hasTable('forge_import_runs')) {
            return;
        }

        Schema::create('forge_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('target_server_id')->index();
            $table->string('organization');
            $table->string('forge_server_id');
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('current_step')->nullable();
            $table->longText('snapshot');
            $table->longText('selection');
            $table->longText('result')->nullable();
            $table->longText('error')->nullable();
            $table->timestamps();
        });
    }

    public function uninstall(): void
    {
        if ((bool) config('forge-import.drop_tables_on_uninstall', false)) {
            Schema::dropIfExists('forge_import_runs');
        }
    }
}
