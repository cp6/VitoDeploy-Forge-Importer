<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImport\Forge\ForgeApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('it follows Forge cursor pagination', function () {
    config(['forge-import.base_url' => 'https://forge.example/api']);

    $page = 0;
    Http::fake(function () use (&$page) {
        $page++;

        return $page === 1 ? Http::response([
            'data' => [['id' => 'one', 'attributes' => ['name' => 'One']]],
            'links' => ['next' => '/orgs?page[cursor]=next'],
        ]) : Http::response([
            'data' => [['id' => 'two', 'attributes' => ['name' => 'Two']]],
            'links' => ['next' => null],
        ]);
    });

    $items = (new ForgeApiClient('secret'))->paginate('/orgs');

    expect(array_column($items, 'id'))->toBe(['one', 'two']);
});

test('it does not duplicate the api path in Forge pagination links', function () {
    config(['forge-import.base_url' => 'https://forge.example/api']);

    $page = 0;
    Http::fake(function ($request) use (&$page) {
        $page++;

        if ($page === 1) {
            return Http::response([
            'data' => [['id' => 'one']],
            'links' => ['next' => '/api/orgs?page[cursor]=next'],
            ]);
        }

        expect($request->url())->toStartWith('https://forge.example/api/orgs?')
            ->not->toContain('/api/api/');

        return Http::response([
            'data' => [['id' => 'two']],
            'links' => ['next' => null],
        ]);
    });

    $items = (new ForgeApiClient('secret'))->paginate('/orgs');

    expect(array_column($items, 'id'))->toBe(['one', 'two']);
});
