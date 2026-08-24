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
