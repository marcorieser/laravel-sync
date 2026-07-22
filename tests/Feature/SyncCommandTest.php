<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake();

    config([
        'sync.remotes' => [
            'production' => ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app', 'read_only' => true],
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
            'env' => ['.env'],
        ],
        'sync.options' => ['--archive'],
    ]);
});

it('runs a dry sync without asking for confirmation', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--dry' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Dry run completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertRan(fn ($process) => str_contains($process->command, '--dry-run') && str_contains($process->command, 'storage/app/assets/'));
});

it('runs a real sync without confirmation when not interactive', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Sync completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('syncs every recipe with --all', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--all' => true, '--no-interaction' => true])
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('fails with a friendly error when the operation is missing and cannot be prompted for', function () {
    $this->artisan('sync', ['remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('You must specify an operation: "push" or "pull".')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error when the remote is missing and cannot be prompted for', function () {
    $this->artisan('sync', ['operation' => 'push', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('You must specify a remote.')
        ->assertFailed();
});

it('fails with a friendly error for an unknown remote', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'unknown', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The remote "unknown" is not defined in your config/sync.php file.')
        ->assertFailed();
});

it('fails with a friendly error for an unknown recipe', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['unknown'], '--no-interaction' => true])
        ->expectsOutputToContain('The recipe "unknown" is not defined in your config/sync.php file.')
        ->assertFailed();
});

it('fails when no recipe is given and --all is not passed', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--no-interaction' => true])
        ->expectsOutputToContain('You must select at least one recipe, or pass --all to sync every recipe.')
        ->assertFailed();
});

it('refuses to push to a read-only remote', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'production', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The remote "production" is read-only and cannot be pushed to.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('allows pulling from a read-only remote', function () {
    $this->artisan('sync', ['operation' => 'pull', 'remote' => 'production', 'recipe' => ['assets'], '--no-interaction' => true])
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});
