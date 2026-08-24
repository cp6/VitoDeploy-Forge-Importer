<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Plugin;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\ServerFeatures\OpenImporter;
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
        ->assertSee('Import from Laravel Forge');
});

test('the importer serves its compiled Tailwind stylesheet', function () {
    $this->get(route('forge-importer.styles'))
        ->assertOk()
        ->assertHeader('content-type', 'text/css; charset=UTF-8');

    expect(filesize(dirname(__DIR__, 2).'/resources/dist/importer.css'))->toBeGreaterThan(1000);
});

test('the server feature action for opening the importer is active', function () {
    expect((new OpenImporter($this->server))->active())->toBeTrue();
});

test('the server feature modal action navigates to the importer', function () {
    $this->withHeader('X-Inertia', 'true')
        ->post(route('server-features.action', [
            'server' => $this->server,
            'feature' => 'forge-importer',
            'action' => 'open',
        ]))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('forge-importer.index', [
            'server' => $this->server->id,
        ]));
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
