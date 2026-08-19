<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Vitamin2\Sync\Commands\SyncCommand;
use Vitamin2\Sync\Commands\SyncCommandsCommand;
use Vitamin2\Sync\Commands\SyncListCommand;
use Vitamin2\Sync\Commands\SyncTestConnectionCommand;
use Vitamin2\Sync\Sync;
use Vitamin2\Sync\SyncServiceProvider as PackageServiceProvider;

it('merges the package config', function () {
    expect(config('sync.options'))->toBe(['--archive'])
        ->and(config('sync.remotes'))->toBe([])
        ->and(config('sync.recipes'))->toBe([]);
});

it('registers the sync service as a singleton', function () {
    expect(resolve(Sync::class))->toBe(resolve(Sync::class));
});

it('registers the sync, sync:list, sync:commands, and sync:test-connection artisan commands', function () {
    expect(Artisan::all())
        ->toHaveKey('sync')
        ->toHaveKey('sync:list')
        ->toHaveKey('sync:commands')
        ->toHaveKey('sync:test-connection')
        ->and(Artisan::all()['sync'])->toBeInstanceOf(SyncCommand::class)
        ->and(Artisan::all()['sync:list'])->toBeInstanceOf(SyncListCommand::class)
        ->and(Artisan::all()['sync:commands'])->toBeInstanceOf(SyncCommandsCommand::class)
        ->and(Artisan::all()['sync:test-connection'])->toBeInstanceOf(SyncTestConnectionCommand::class);
});

it('registers the config publish tag', function () {
    $paths = ServiceProvider::pathsToPublish(PackageServiceProvider::class, 'laravel-sync-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([config_path('sync.php')]);
});

it('does not register commands or publishable config outside the console', function () {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->once()->andReturnFalse();

    $provider = Mockery::mock(PackageServiceProvider::class, [$app])->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldNotReceive('publishes');
    $provider->shouldNotReceive('commands');

    $provider->boot();
});
