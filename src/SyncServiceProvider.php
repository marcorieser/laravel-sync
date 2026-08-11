<?php

declare(strict_types=1);

namespace MarcoRieser\Sync;

use Illuminate\Support\ServiceProvider;
use MarcoRieser\Sync\Commands\SyncBackupsCleanCommand;
use MarcoRieser\Sync\Commands\SyncCommand;
use MarcoRieser\Sync\Commands\SyncCommandsCommand;
use MarcoRieser\Sync\Commands\SyncListCommand;
use MarcoRieser\Sync\Commands\SyncTestConnectionCommand;
use Override;

class SyncServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sync.php', 'sync');

        $this->app->singleton(Sync::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sync.php' => config_path('sync.php'),
        ], ['laravel-sync', 'laravel-sync-config']);

        $this->commands([
            SyncCommand::class,
            SyncListCommand::class,
            SyncCommandsCommand::class,
            SyncBackupsCleanCommand::class,
            SyncTestConnectionCommand::class,
        ]);
    }
}
