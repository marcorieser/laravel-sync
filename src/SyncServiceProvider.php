<?php

declare(strict_types=1);

namespace Sync\Sync;

use Illuminate\Support\ServiceProvider;
use Sync\Sync\Console\Commands\SyncCommand;

class SyncServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-sync.php', 'laravel-sync');

        $this->app->singleton(Sync::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/laravel-sync.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-sync');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-sync');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-sync.php' => config_path('laravel-sync.php'),
        ], ['laravel-sync', 'laravel-sync-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-sync'),
        ], ['laravel-sync', 'laravel-sync-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/laravel-sync'),
        ], ['laravel-sync', 'laravel-sync-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/laravel-sync'),
        ], ['laravel-sync', 'laravel-sync-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-sync', 'laravel-sync-migrations']);

        $this->commands([
            SyncCommand::class,
        ]);
    }
}
