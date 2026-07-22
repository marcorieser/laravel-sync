<?php

declare(strict_types=1);

use Sync\Sync\Sync;

it('resolves the singleton', function () {
    expect(app(Sync::class))->toBeInstanceOf(Sync::class);
});

it('returns the same instance from the container', function () {
    expect(app(Sync::class))->toBe(app(Sync::class));
});

it('merges the package config', function () {
    expect(config('laravel-sync.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('laravel-sync::messages.placeholder'))->toBe('Sync placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('laravel-sync::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('laravel-sync:placeholder')
        ->expectsOutputToContain('Sync placeholder command executed.')
        ->assertSuccessful();
});
