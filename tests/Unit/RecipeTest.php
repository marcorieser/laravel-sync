<?php

declare(strict_types=1);

use MarcoRieser\Sync\Data\Recipe;

it('hydrates a name and its paths', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/', 'storage/app/img/']);

    expect($recipe->name)->toBe('assets')
        ->and($recipe->paths)->toBe(['storage/app/assets/', 'storage/app/img/'])
        ->and($recipe->excludes)->toBe([]);
});

it('hydrates the given excludes', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/'], ['*.log', 'node_modules/']);

    expect($recipe->excludes)->toBe(['*.log', 'node_modules/']);
});
