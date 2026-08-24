<?php

namespace App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import;

class RepositoryNormalizer
{
    public function normalize(mixed $repository): string
    {
        if (! is_string($repository)) {
            return '';
        }

        $repository = trim($repository);
        if ($repository === '') {
            return '';
        }

        $host = '';
        $path = $repository;

        if (preg_match('#^[^@/\s]+@([^:/\s]+):(.+)$#', $repository, $matches) === 1) {
            $host = strtolower($matches[1]);
            $path = $matches[2];
        } elseif (filter_var($repository, FILTER_VALIDATE_URL) !== false) {
            $host = strtolower((string) parse_url($repository, PHP_URL_HOST));
            $path = (string) parse_url($repository, PHP_URL_PATH);
        }

        $path = trim(rawurldecode($path), '/');

        if ($host === 'api.github.com') {
            $path = preg_replace('#^repos/#', '', $path) ?? $path;
        } elseif ($host === 'api.bitbucket.org') {
            $path = preg_replace('#^(?:2\.0/)?repositories/#', '', $path) ?? $path;
        }

        return preg_replace('/\.git$/i', '', $path) ?? $path;
    }

    public function provider(mixed $repository): string
    {
        if (! is_string($repository)) {
            return '';
        }

        $value = strtolower($repository);

        return match (true) {
            str_contains($value, 'github.com') => 'github',
            str_contains($value, 'gitlab.com') => 'gitlab',
            str_contains($value, 'bitbucket.org') => 'bitbucket',
            str_contains($value, 'gitea.com') => 'gitea',
            default => '',
        };
    }
}
