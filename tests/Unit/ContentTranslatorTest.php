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

test('it converts the current default Forge Laravel deployment script to Vito', function () {
    $site = new Site([
        'domain' => 'example.com',
        'path' => '/home/example/example.com',
    ]);

    $translated = (new ContentTranslator)->deploymentScript(<<<'SCRIPT'
cd /home/forge/example.com
git pull origin $FORGE_SITE_BRANCH
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

if [ -f artisan ]; then
    $FORGE_PHP artisan migrate --force
fi
SCRIPT, $site);

    expect($translated)
        ->toContain('cd /home/example/example.com')
        ->toContain('git pull origin $BRANCH')
        ->toContain('composer install --no-dev')
        ->toContain('$PHP_PATH artisan migrate --force')
        ->toContain('# PHP-FPM reload is managed by Vito.')
        ->not->toContain('FORGE_')
        ->not->toContain('PHP_PATH_FPM')
        ->not->toContain('sudo -S service');
});

test('it converts Markdown-escaped Forge deployment scripts to Vito', function () {
    $site = new Site([
        'domain' => 'demo.myidlers.com',
        'path' => '/home/demomyidlers/demo.myidlers.com',
    ]);

    $translated = (new ContentTranslator)->deploymentScript(<<<'SCRIPT'
cd /home/demomyidlers/demo.myidlers.com
git fetch origin
git reset --hard origin/main
git pull origin $FORGE\_SITE\_BRANCH
\
$FORGE\_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
\
( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $PHP\_PATH\_FPM reload ) 9>/tmp/fpmlock
\
if [ -f artisan ]; then
    $PHP\_PATH artisan migrate --force
fi
SCRIPT, $site);

    expect($translated)
        ->toContain('git pull origin $BRANCH')
        ->toContain('composer install --no-dev')
        ->toContain('$PHP_PATH artisan migrate --force')
        ->toContain('# PHP-FPM reload is managed by Vito.')
        ->not->toContain('FORGE')
        ->not->toContain('PHP_PATH_FPM')
        ->not->toContain("\n\\\n")
        ->not->toContain('sudo -S service');
});

test('it translates Forge variables in cron jobs and worker commands', function () {
    $site = new Site(['path' => '/home/example/example.com']);

    $translated = (new ContentTranslator)->command(
        'cd /home/forge/example.com && $FORGE_PHP artisan queue:work --commit=$FORGE_DEPLOY_COMMIT',
        $site,
        'example.com',
    );

    expect($translated)->toBe(
        'cd /home/example/example.com && $PHP_PATH artisan queue:work --commit=$COMMIT_ID',
    );
});

test('it rejects unsupported Forge variables instead of importing a broken command', function () {
    $site = new Site(['path' => '/home/example/example.com']);

    expect(fn () => (new ContentTranslator)->command('echo $FORGE_MANUAL_DEPLOY', $site))
        ->toThrow(RuntimeException::class, 'unsupported Forge variables: FORGE_MANUAL_DEPLOY');
});
