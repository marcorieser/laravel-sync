<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Sync\Sync\Data\Recipe;
use Sync\Sync\Data\Remote;
use Sync\Sync\Enums\Operation;
use Sync\Sync\Exceptions\SyncException;
use Sync\Sync\PendingSync;
use Sync\Sync\Rsync\RsyncOptions;
use Sync\Sync\Sync;

beforeEach(function () {
    config([
        'sync.remotes' => [
            'production' => ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app', 'read_only' => true],
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
        ],
    ]);
});

it('resolves the singleton from the container', function () {
    expect(app(Sync::class))->toBeInstanceOf(Sync::class)
        ->and(app(Sync::class))->toBe(app(Sync::class));
});

it('lists remotes hydrated from the config', function () {
    $remotes = app(Sync::class)->remotes();

    expect($remotes)->toBeInstanceOf(Collection::class)
        ->and($remotes->keys()->all())->toBe(['production', 'staging'])
        ->and($remotes->get('production'))->toBeInstanceOf(Remote::class);
});

it('throws when no remotes are configured', function () {
    config(['sync.remotes' => []]);

    app(Sync::class)->remotes();
})->throws(SyncException::class, 'You need to define at least one remote in your config/sync.php file.');

it('lists recipes hydrated from the config', function () {
    $recipes = app(Sync::class)->recipes();

    expect($recipes->keys()->all())->toBe(['assets'])
        ->and($recipes->get('assets'))->toBeInstanceOf(Recipe::class);
});

it('throws when no recipes are configured', function () {
    config(['sync.recipes' => []]);

    app(Sync::class)->recipes();
})->throws(SyncException::class, 'You need to define at least one recipe in your config/sync.php file.');

it('resolves a single remote by name', function () {
    expect(app(Sync::class)->remote('staging'))->toBeInstanceOf(Remote::class);
});

it('throws for an unknown remote', function () {
    app(Sync::class)->remote('unknown');
})->throws(SyncException::class, 'The remote "unknown" is not defined in your config/sync.php file.');

it('resolves a single recipe by name', function () {
    expect(app(Sync::class)->recipe('assets'))->toBeInstanceOf(Recipe::class);
});

it('throws for an unknown recipe', function () {
    app(Sync::class)->recipe('unknown');
})->throws(SyncException::class, 'The recipe "unknown" is not defined in your config/sync.php file.');

it('refuses to push to a read-only remote', function () {
    $sync = app(Sync::class);

    $sync->for(Operation::Push, $sync->remote('production'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(SyncException::class, 'The remote "production" is read-only and cannot be pushed to.');

it('allows pulling from a read-only remote', function () {
    $sync = app(Sync::class);

    $pending = $sync->for(Operation::Pull, $sync->remote('production'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('allows pushing to a remote that is not read-only', function () {
    $sync = app(Sync::class);

    $pending = $sync->for(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

    expect($pending)->toBeInstanceOf(PendingSync::class);
});
