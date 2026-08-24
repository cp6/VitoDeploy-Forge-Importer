<?php

use App\Vito\Plugins\Cp6\VitoDeployForgeImporter\Import\RepositoryNormalizer;

test('it converts clone URLs to the repository path Vito expects', function (string $input, string $expected) {
    expect((new RepositoryNormalizer)->normalize($input))->toBe($expected);
})->with([
    'GitHub HTTPS' => ['https://github.com/cp6/example.git', 'cp6/example'],
    'GitHub SSH' => ['git@github.com:cp6/example.git', 'cp6/example'],
    'GitLab subgroup' => ['ssh://git@gitlab.com/cp6/team/example.git', 'cp6/team/example'],
    'Bitbucket HTTPS' => ['https://bitbucket.org/cp6/example/', 'cp6/example'],
    'already normalized' => ['cp6/example', 'cp6/example'],
]);

test('it infers common source-control providers from repository URLs', function (string $input, string $expected) {
    expect((new RepositoryNormalizer)->provider($input))->toBe($expected);
})->with([
    ['https://github.com/cp6/example', 'github'],
    ['git@gitlab.com:cp6/example.git', 'gitlab'],
    ['https://bitbucket.org/cp6/example', 'bitbucket'],
]);
