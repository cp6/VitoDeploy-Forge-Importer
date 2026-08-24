<?php

use App\Models\Site;
use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import\ContentTranslator;

test('it translates Forge deployment variables and site paths', function () {
    $site = new Site([
        'domain' => 'example.com',
        'path' => '/home/example/example.com',
    ]);

    $translated = (new ContentTranslator)->deploymentScript(
        "cd \$FORGE_SITE_ROOT\n\$FORGE_PHP artisan migrate\ncd /home/forge/example.com",
        $site,
    );

    expect($translated)
        ->toContain('cd $SITE_PATH')
        ->toContain('$PHP_PATH artisan migrate')
        ->toContain('/home/example/example.com')
        ->not->toContain('FORGE_SITE_ROOT');
});
