<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImport\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs($this->user);
    $plugin = new Plugin;
    $plugin->install();
    $plugin->boot();
});

test('authenticated users can open the importer', function () {
    $this->get(route('forge-importer.index'))
        ->assertOk()
        ->assertSee('Laravel Forge Site Importer');
});

test('a valid Forge token can be connected without returning it', function () {
    Http::fake([
        'https://forge.laravel.com/api/orgs' => Http::response([
            'data' => [['id' => '1', 'attributes' => ['name' => 'Test Organization']]],
            'links' => ['next' => null],
        ]),
    ]);

    $response = $this->postJson(route('forge-importer.connect'), ['token' => 'forge-test-secret-token']);

    $response
        ->assertOk()
        ->assertJson(['connected' => true, 'user' => 'Test Organization'])
        ->assertJsonMissing(['token' => 'forge-test-secret-token']);
});

test('unauthenticated users cannot open importer routes', function () {
    auth()->logout();

    $this->get(route('forge-importer.index'))->assertRedirect();
});
