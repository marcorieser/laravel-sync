<?php

declare(strict_types=1);

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
