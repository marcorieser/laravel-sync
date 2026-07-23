<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Sync\Sync\Rsync\RsyncOptions;

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
    Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true)
        && in_array(base_path('storage/app/assets/'), $process->command, true));
});

it('runs a real sync without confirmation when not interactive', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Sync completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('falls back to config default options when --option is passed an empty string', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--option' => [''], '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('--archive', $process->command, true));
});

it('fails when the underlying rsync process fails', function () {
    Process::fake(fn () => Process::result(exitCode: 1));

    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Sync failed.')
        ->assertFailed();
});

it('syncs every recipe with --all', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--all' => true, '--no-interaction' => true])
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('fails with a friendly error for an invalid operation instead of crashing', function () {
    $this->artisan('sync', ['operation' => 'sideways', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('Invalid operation "sideways". Expected "push" or "pull".')
        ->assertFailed();

    Process::assertNothingRan();
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

it('fails with a friendly error when no recipes are configured', function () {
    config(['sync.recipes' => []]);

    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--all' => true, '--no-interaction' => true])
        ->expectsOutputToContain('You need to define at least one recipe in your config/sync.php file.')
        ->assertFailed();

    Process::assertNothingRan();
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

it('aborts when the user declines the confirmation prompt', function () {
    $this->artisan('sync', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--option' => ['--archive']])
        ->expectsConfirmation('You are about to pull "assets" from "staging". Are you sure?')
        ->expectsOutputToContain('Sync aborted.')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('syncs every recipe when the user confirms "sync all recipes?"', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--option' => ['--archive']])
        ->expectsConfirmation('Sync all recipes?', 'yes')
        ->expectsConfirmation('You are about to push "assets and env" to "staging". Are you sure?', 'yes')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('prompts for anything missing, interactively, before syncing', function () {
    $this->artisan('sync')
        ->expectsChoice('Which operation do you want to perform?', 'push', ['push' => 'Push', 'pull' => 'Pull'])
        ->expectsChoice('Which remote do you want to sync with?', 'staging', ['production', 'staging'])
        ->expectsConfirmation('Sync all recipes?')
        ->expectsChoice('Which recipes do you want to sync?', ['assets'], ['assets', 'env'])
        ->expectsChoice('Which rsync options do you want to use?', ['--archive'], RsyncOptions::AVAILABLE)
        ->expectsConfirmation('You are about to push "assets" to "staging". Are you sure?', 'yes')
        ->expectsOutputToContain('Sync completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});
