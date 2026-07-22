<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Sync\Sync\Data\Recipe;
use Sync\Sync\Data\Remote;
use Sync\Sync\Enums\Operation;
use Sync\Sync\PendingSync;
use Sync\Sync\Rsync\RsyncOptions;

beforeEach(function () {
    $this->remote = Remote::fromArray('production', ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app']);
});

it('builds one command per unique recipe path', function () {
    $recipes = collect([
        new Recipe('assets', ['storage/app/assets/', 'storage/app/img/']),
        new Recipe('env', ['storage/app/assets/', '.env']),
    ]);

    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->commands()->map->path->all())->toBe([
        'storage/app/assets/',
        'storage/app/img/',
        '.env',
    ]);
});

it('runs one process per resolved command', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    $pending->run();

    Process::assertRanTimes(fn ($process) => true, 2);
    Process::assertRan(fn ($process) => str_contains($process->command, 'storage/app/assets/'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'storage/app/img/'));
});
