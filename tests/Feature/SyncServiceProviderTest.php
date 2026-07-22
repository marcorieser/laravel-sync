<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Sync\Sync\Commands\SyncCommand;
use Sync\Sync\Commands\SyncCommandsCommand;
use Sync\Sync\Commands\SyncListCommand;
use Sync\Sync\Sync;
use Sync\Sync\SyncServiceProvider as PackageServiceProvider;

it('merges the package config', function () {
    expect(config('sync.options'))->toBe(['--archive'])
        ->and(config('sync.remotes'))->toBe([])
        ->and(config('sync.recipes'))->toBe([]);
});

it('registers the sync service as a singleton', function () {
    expect(app(Sync::class))->toBe(app(Sync::class));
});

it('registers the sync, sync:list, and sync:commands artisan commands', function () {
    expect(Artisan::all())
        ->toHaveKey('sync')
        ->toHaveKey('sync:list')
        ->toHaveKey('sync:commands')
        ->and(Artisan::all()['sync'])->toBeInstanceOf(SyncCommand::class)
        ->and(Artisan::all()['sync:list'])->toBeInstanceOf(SyncListCommand::class)
        ->and(Artisan::all()['sync:commands'])->toBeInstanceOf(SyncCommandsCommand::class);
});

it('registers the config publish tag', function () {
    $paths = ServiceProvider::pathsToPublish(PackageServiceProvider::class, 'laravel-sync-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([config_path('sync.php')]);
});
