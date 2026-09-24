<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\ServerFeatures\OpenImporter;
use Tests\TestCase;

uses(TestCase::class);

test('it builds the importer link without requiring named plugin routes', function () {
    $form = (new OpenImporter($this->server))->form();

    expect($form?->toArray()[0]['link']['url'] ?? null)
        ->toBe(url('/forge-importer').'?server='.$this->server->id);
});
