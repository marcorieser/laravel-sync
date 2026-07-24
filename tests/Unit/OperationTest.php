<?php

declare(strict_types=1);

use MarcoRieser\Sync\Enums\Operation;

it('resolves push and pull from a valid string', function () {
    expect(Operation::fromInput('push'))->toBe(Operation::Push)
        ->and(Operation::fromInput('pull'))->toBe(Operation::Pull);
});

it('throws for an invalid operation string', function () {
    Operation::fromInput('sideways');
})->throws(ValueError::class, 'Invalid operation "sideways". Expected "push" or "pull".');

it('labels each operation', function () {
    expect(Operation::Push->label())->toBe('Push')
        ->and(Operation::Pull->label())->toBe('Pull');
});

it('lists all operations as value => label for prompts', function () {
    expect(Operation::options())->toBe([
        'push' => 'Push',
        'pull' => 'Pull',
    ]);
});
