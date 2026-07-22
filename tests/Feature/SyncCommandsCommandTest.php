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

it('prints the rsync command for a push without running it', function () {
    $this->artisan('sync:commands', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain(sprintf(
            "rsync -e 'ssh -p 22' --archive %s forge@5.6.7.8:/srv/staging/storage/app/assets/",
            base_path('storage/app/assets/'),
        ))
        ->assertSuccessful();
});

it('reverses origin and target for a pull', function () {
    $this->artisan('sync:commands', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain(sprintf(
            "rsync -e 'ssh -p 22' --archive forge@5.6.7.8:/srv/staging/storage/app/assets/ %s",
            base_path('storage/app/assets/'),
        ))
        ->assertSuccessful();
});
