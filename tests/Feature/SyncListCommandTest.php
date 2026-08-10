<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use MarcoRieser\Sync\Rsync\RsyncOptions;

beforeEach(function () {
    config([
        'sync.remotes' => [
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
        ],
        'sync.options' => ['--archive'],
    ]);
});

it('lists the origin, target, options, and port for a push', function () {
    $this->artisan('sync:list', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('forge@5.6.7.8:/srv/staging/storage/app/assets/')
        ->assertSuccessful();
});

it('lists the origin, target, options, and port for a pull', function () {
    $this->artisan('sync:list', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('forge@5.6.7.8:/srv/staging/storage/app/assets/')
        ->assertSuccessful();
});

it('fails with a friendly error for an unknown remote', function () {
    $this->artisan('sync:list', ['operation' => 'push', 'remote' => 'unknown', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The remote "unknown" is not defined in your config/sync.php file.')
        ->assertFailed();
});

it('lists the backup row before the pull row when --backup is passed', function () {
    $this->travelTo(Carbon::parse('2026-07-24 13:45:30'));

    $this->artisan('sync:list', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain(base_path('.sync-backups/2026-07-24_134530/storage/app/assets/'))
        ->assertSuccessful();
});

it('lists no backup row for a push, even with --backup passed', function () {
    $this->artisan('sync:list', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->doesntExpectOutputToContain('.sync-backups')
        ->assertSuccessful();
});

it('never asks to back up interactively, since this command only previews', function () {
    $this->artisan('sync:list', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets']])
        ->expectsChoice('Which rsync options do you want to use?', ['--archive'], RsyncOptions::AVAILABLE)
        ->assertSuccessful();
});
