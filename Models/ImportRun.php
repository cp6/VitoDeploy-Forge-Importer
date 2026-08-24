<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property int $target_server_id
 * @property string $organization
 * @property string $forge_server_id
 * @property string $status
 * @property int $progress
 * @property ?string $current_step
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed> $selection
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ImportRun extends Model
{
    protected $table = 'forge_import_runs';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'project_id' => 'integer',
        'target_server_id' => 'integer',
        'progress' => 'integer',
        'snapshot' => 'encrypted:array',
        'selection' => 'encrypted:array',
        'result' => 'encrypted:array',
    ];

    protected $hidden = ['snapshot', 'selection'];

    public function publicStatus(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'progress' => $this->progress,
            'current_step' => $this->current_step,
            'result' => $this->result ?? ['sites' => []],
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
