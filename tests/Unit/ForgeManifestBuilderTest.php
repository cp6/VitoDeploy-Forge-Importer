<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Forge\ForgeApiClient;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Forge\ForgeManifestBuilder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('it reads the primary domain and aliases from the current Forge site resource', function () {
    config(['forge-import.base_url' => 'https://forge.example/api']);

    Http::fake([
        'https://forge.example/api/orgs/example/sites/42' => Http::response([
            'data' => [
                'id' => '42',
                'type' => 'sites',
                'attributes' => [
                    'name' => 'example.com',
                    'aliases' => ['www.example.com', 'example.net'],
                    'app_type' => 'laravel',
                ],
            ],
        ]),
    ]);

    $manifests = (new ForgeManifestBuilder(new ForgeApiClient('secret')))
        ->build('example', '10', ['42'], ['domains']);

    expect($manifests[0]['domains'])->toBe([
        ['name' => 'example.com', 'primary' => true],
        ['name' => 'www.example.com', 'primary' => false],
        ['name' => 'example.net', 'primary' => false],
    ]);
});

test('it discovers database schemas and users without exposing their fields beyond the safe preview', function () {
    config(['forge-import.base_url' => 'https://forge.example/api']);

    Http::fake([
        'https://forge.example/api/orgs/example/servers/10/database/schemas' => Http::response([
            'data' => [['id' => '7', 'attributes' => ['name' => 'example_db', 'status' => 'installed']]],
            'links' => ['next' => null],
        ]),
        'https://forge.example/api/orgs/example/servers/10/database/users' => Http::response([
            'data' => [['id' => '8', 'attributes' => ['name' => 'example_user', 'status' => 'installed', 'password' => 'must-not-leak']]],
            'links' => ['next' => null],
        ]),
        'https://forge.example/api/orgs/example/sites/42' => Http::response([
            'data' => ['id' => '42', 'attributes' => ['name' => 'example.com']],
        ]),
    ]);

    $builder = new ForgeManifestBuilder(new ForgeApiClient('secret'));
    $manifest = $builder->build('example', '10', ['42'], ['database'])[0];
    $preview = $builder->redact($manifest);

    expect($manifest['databases'][0]['name'])->toBe('example_db')
        ->and($manifest['database_users'][0]['name'])->toBe('example_user')
        ->and($preview['database_users'][0])->not->toHaveKey('password');
});
