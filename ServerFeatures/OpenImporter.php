<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\ServerFeatures;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;

class OpenImporter extends Action
{
    public function name(): string
    {
        return 'Open Forge Importer';
    }

    public function active(): bool
    {
        return false;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('open_importer')
                ->alert()
                ->label('Forge Site Importer')
                ->description('Open the importer to preview and migrate one or more Forge sites. ')
                ->link('Open importer', route('forge-importer.index', ['server' => $this->server->id])),
        ]);
    }

    public function handle(Request $request): void
    {
        // The form contains a direct link to the importer; there is no server mutation.
    }
}
