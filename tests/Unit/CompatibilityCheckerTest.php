<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import\CompatibilityChecker;
use Tests\TestCase;

uses(TestCase::class);

test('it maps the nested repository returned by the current Forge API', function () {
    $manifest = [
        'site' => [
            'id' => '42',
            'name' => 'forge-preview.example',
            'user' => 'forge',
            'app_type' => 'laravel',
            'repository' => [
                'provider' => 'github',
                'url' => 'cp6/example',
                'branch' => 'develop',
                'status' => 'installed',
            ],
        ],
        'domains' => [],
    ];

    $result = (new CompatibilityChecker)->check($manifest, $this->server);

    expect($result['defaults'])
        ->repository->toBe('cp6/example')
        ->branch->toBe('develop')
        ->source_control_provider->toBe('github');
});

test('it remains compatible with legacy string repository values', function () {
    $manifest = [
        'site' => [
            'id' => '43',
            'name' => 'legacy-preview.example',
            'user' => 'forge',
            'app_type' => 'laravel',
            'repository' => 'cp6/legacy-example',
            'repository_provider' => 'gitlab',
            'repository_branch' => 'main',
        ],
        'domains' => [],
    ];

    $result = (new CompatibilityChecker)->check($manifest, $this->server);

    expect($result['defaults'])
        ->repository->toBe('cp6/legacy-example')
        ->branch->toBe('main')
        ->source_control_provider->toBe('gitlab');
});
