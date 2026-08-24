<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import\EnvironmentValues;

test('it reads and safely rewrites database environment values', function () {
    $values = new EnvironmentValues;
    $environment = <<<'ENV'
APP_NAME="My app"
DB_DATABASE=forge_db
DB_PASSWORD="old secret"
ENV;

    expect($values->get($environment, 'DB_DATABASE'))->toBe('forge_db')
        ->and($values->get($environment, 'DB_PASSWORD'))->toBe('old secret');

    $updated = $values->replace($environment, [
        'DB_DATABASE' => 'vito_db',
        'DB_PASSWORD' => 'new secret$value',
        'DB_HOST' => '127.0.0.1',
    ]);

    expect($updated)->toContain('DB_DATABASE=vito_db')
        ->toContain('DB_PASSWORD="new secret\\$value"')
        ->toContain('DB_HOST=127.0.0.1');
});
