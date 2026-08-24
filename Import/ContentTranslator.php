<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import;

use App\Models\Site;
use RuntimeException;

class ContentTranslator
{
    public function deploymentScript(string $content, Site $site): string
    {
        $content = $this->removeForgeFpmRestart($content);
        $content = $this->translateVariables($content);
        $content = str_replace('/home/forge/'.$site->domain, $site->path, $content);
        $this->guardAgainstUnsupportedForgeVariables($content);

        return $content;
    }

    public function command(string $command, Site $site, ?string $forgeDomain = null): string
    {
        $command = $this->translateVariables($command);
        if ($forgeDomain) {
            $command = str_replace('/home/forge/'.$forgeDomain, $site->path, $command);
        }
        $this->guardAgainstUnsupportedForgeVariables($command);

        return $command;
    }

    private function translateVariables(string $content): string
    {
        // Keep longer names before FORGE_PHP so prefix replacement can never
        // turn FORGE_PHP_FPM into the invalid PHP_PATH_FPM variable.
        return str_replace(
            [
                '${FORGE_SITE_BRANCH}', '$FORGE_SITE_BRANCH',
                '${FORGE_SITE_PATH}', '$FORGE_SITE_PATH',
                '${FORGE_SITE_ROOT}', '$FORGE_SITE_ROOT',
                '${FORGE_COMPOSER}', '$FORGE_COMPOSER',
                '${FORGE_DEPLOY_COMMIT}', '$FORGE_DEPLOY_COMMIT',
                '${FORGE_PHP}', '$FORGE_PHP',
            ],
            [
                '${BRANCH}', '$BRANCH',
                '${SITE_PATH}', '$SITE_PATH',
                '${SITE_PATH}', '$SITE_PATH',
                'composer', 'composer',
                '${COMMIT_ID}', '$COMMIT_ID',
                '${PHP_PATH}', '$PHP_PATH',
            ],
            $content,
        );
    }

    private function removeForgeFpmRestart(string $content): string
    {
        $content = preg_replace(
            '~^\h*\(\h*flock\b.*?9>/tmp/fpmlock\h*$\R?~ms',
            "# PHP-FPM reload is managed by Vito.\n",
            $content,
        ) ?? $content;

        return preg_replace(
            '~^.*\$\{?FORGE_PHP_FPM\}?.*$\R?~m',
            "# PHP-FPM reload is managed by Vito.\n",
            $content,
        ) ?? $content;
    }

    private function guardAgainstUnsupportedForgeVariables(string $content): void
    {
        preg_match_all('/\$\{?(FORGE_[A-Z0-9_]+)\}?/', $content, $matches);
        $variables = array_values(array_unique($matches[1]));
        if ($variables !== []) {
            throw new RuntimeException(
                'The deployment content uses unsupported Forge variables: '.implode(', ', $variables).'.',
            );
        }
    }
}
