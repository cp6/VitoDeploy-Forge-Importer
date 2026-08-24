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

test('it detects Laravel and uses Vito defaults while retaining Forge reference values', function () {
    $manifest = [
        'site' => [
            'id' => '44',
            'name' => 'artisan-detect.example',
            'user' => 'forge',
            'app_type' => 'php',
            'web_directory' => '/home/forge/artisan-detect.example/current/public',
            'deployment_script' => 'php artisan migrate --force',
            'repository' => 'cp6/laravel-example',
        ],
        'domains' => [],
    ];

    $result = (new CompatibilityChecker)->check($manifest, $this->server);

    expect($result['defaults'])
        ->type->toBe('laravel')
        ->user->toBe('artisa')
        ->forge_user->toBe('forge')
        ->web_directory->toBe('public')
        ->forge_web_directory->toBe('/home/forge/artisan-detect.example/current/public');
});

test('it matches database environment values against Forge and Vito resources', function () {
    $this->server->databases()->create([
        'name' => 'example_db',
        'charset' => 'utf8mb3',
        'collation' => 'utf8mb3_general_ci',
    ]);
    $this->server->databaseUsers()->create([
        'username' => 'example_user',
        'password' => 'secret-password',
        'databases' => ['example_db'],
        'host' => 'localhost',
        'permission' => 'admin',
    ]);
    $manifest = [
        'site' => ['id' => '45', 'name' => 'database.example', 'app_type' => 'laravel'],
        'domains' => [],
        'environment' => "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=example_db\nDB_USERNAME=example_user\nDB_PASSWORD=forge-secret",
        'databases' => [['id' => '10', 'name' => 'example_db']],
        'database_users' => [['id' => '11', 'name' => 'example_user']],
    ];

    $result = (new CompatibilityChecker)->check($manifest, $this->server);
    $database = $result['defaults']['database'];

    expect($database)
        ->enabled->toBeTrue()
        ->forge_database_match->toBeTrue()
        ->forge_user_match->toBeTrue()
        ->vito_database_match->toBeTrue()
        ->vito_user_match->toBeTrue()
        ->has_environment_password->toBeTrue()
        ->not->toHaveKey('password')
        ->and(collect($result['checks'])->firstWhere('key', 'database'))
        ->toMatchArray([
            'label' => 'Database configuration found',
            'status' => 'matched',
            'value' => 'mysql',
        ]);
});

test('it reports a detected sqlite connection without requiring Vito database provisioning', function () {
    $manifest = [
        'site' => ['id' => '46', 'name' => 'sqlite.example', 'app_type' => 'laravel'],
        'domains' => [],
        'environment' => "DB_CONNECTION=sqlite\nDB_DATABASE=/home/forge/sqlite.example/database/database.sqlite",
    ];

    $result = (new CompatibilityChecker)->check($manifest, $this->server);
    $check = collect($result['checks'])->firstWhere('key', 'database');

    expect($check)->toMatchArray(['status' => 'matched', 'value' => 'sqlite'])
        ->and($result['defaults']['database']['enabled'])->toBeFalse()
        ->and($result['defaults']['database']['reason'])->toContain('not supported for automatic setup');
});
