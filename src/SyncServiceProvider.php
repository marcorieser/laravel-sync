<?php

declare(strict_types=1);

namespace Sync\Sync;

use Illuminate\Support\ServiceProvider;
use Sync\Sync\Commands\SyncCommand;
use Sync\Sync\Commands\SyncCommandsCommand;
use Sync\Sync\Commands\SyncListCommand;

class SyncServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
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
        ]);
    }
}
