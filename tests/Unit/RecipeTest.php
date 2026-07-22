<?php

declare(strict_types=1);

use Sync\Sync\Data\Recipe;

it('hydrates a name and its paths', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/', 'storage/app/img/']);

    expect($recipe->name)->toBe('assets')
        ->and($recipe->paths)->toBe(['storage/app/assets/', 'storage/app/img/']);
});
