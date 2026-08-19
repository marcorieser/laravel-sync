<?php

declare(strict_types=1);

use Vitamin2\Sync\Data\Recipe;

it('hydrates a name and its paths', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/', 'storage/app/img/']);

    expect($recipe->name)->toBe('assets')
        ->and($recipe->paths)->toBe(['storage/app/assets/', 'storage/app/img/'])
        ->and($recipe->excludes)->toBe([])
        ->and($recipe->excludesFrom)->toBe([]);
});

it('hydrates the given excludes', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/'], ['*.log', 'node_modules/']);

    expect($recipe->excludes)->toBe(['*.log', 'node_modules/']);
});

it('hydrates the given excludes-from files', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/'], [], ['.rsync-excludes']);

    expect($recipe->excludesFrom)->toBe(['.rsync-excludes']);
});

it('normalizes Windows-style backslash separators in excludes-from files', function () {
    // The guard and the `--exclude-from=` flag both read this value, so it is normalized once
    // here rather than by each of them.
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/'], [], ['storage\\app\\.rsync-excludes']);

    expect($recipe->excludesFrom)->toBe(['storage/app/.rsync-excludes']);
});
