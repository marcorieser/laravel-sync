<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use MarcoRieser\Sync\Data\Recipe;
use MarcoRieser\Sync\Data\Remote;
use MarcoRieser\Sync\Enums\Operation;
use MarcoRieser\Sync\PendingSync;
use MarcoRieser\Sync\Rsync\RsyncOptions;

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
    Process::assertRan(fn ($process) => in_array(base_path('storage/app/assets/'), $process->command, true));
    Process::assertRan(fn ($process) => in_array(base_path('storage/app/img/'), $process->command, true));
});

it('runs each command as an argument list instead of a shell string', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    $pending->run();

    Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === 'rsync');
});

it('returns true when every command succeeds', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->run())->toBeTrue();
});

it('returns false when any command fails', function () {
    Process::fake(fn ($process) => in_array(base_path('storage/app/img/'), $process->command, true)
        ? Process::result(exitCode: 1)
        : Process::result());

    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->run())->toBeFalse();

    Process::assertRanTimes(fn ($process) => true, 2);
});
