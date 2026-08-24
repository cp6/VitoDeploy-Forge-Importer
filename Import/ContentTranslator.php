<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import;

use App\Models\Site;

class ContentTranslator
{
    public function deploymentScript(string $content, Site $site): string
    {
        return str_replace(
            ['$FORGE_SITE_ROOT', '${FORGE_SITE_ROOT}', '$FORGE_PHP', '${FORGE_PHP}', '/home/forge/'.$site->domain],
            ['$SITE_PATH', '${SITE_PATH}', '$PHP_PATH', '${PHP_PATH}', $site->path],
            $content,
        );
    }

    public function command(string $command, Site $site, ?string $forgeDomain = null): string
    {
        $search = ['$FORGE_SITE_ROOT', '${FORGE_SITE_ROOT}', '$FORGE_PHP', '${FORGE_PHP}'];
        $replace = ['$SITE_PATH', '${SITE_PATH}', '$PHP_PATH', '${PHP_PATH}'];

        if ($forgeDomain) {
            $search[] = '/home/forge/'.$forgeDomain;
            $replace[] = $site->path;
        }

        return str_replace($search, $replace, $command);
    }
}
