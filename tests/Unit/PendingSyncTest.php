<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use MarcoRieser\Sync\Data\Backup;
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

it('builds no backup commands without a backup', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(Operation::Pull, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->backups())->toBeEmpty();
});

it('builds no backup commands for a push, even with a backup requested', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Push,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->backups())->toBeEmpty();
});

it('builds one backup command per unique recipe path on a pull with a backup requested', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->backups()->map->path->all())->toBe(['storage/app/assets/', 'storage/app/img/']);
});

it('runs the backup before the sync, then the sync commands', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->run())->toBeTrue();

    Process::assertRanTimes(fn ($process) => true, 2);
    Process::assertRan(fn ($process) => in_array('--relative', $process->command, true)
        && in_array('storage/app/assets/', $process->command, true)
        && $process->path === base_path());
    Process::assertRan(fn ($process) => in_array(
        'forge@1.2.3.4:/srv/app/storage/app/assets/',
        $process->command,
        true,
    ));
});

it('aborts before the pull when the backup fails', function () {
    Process::fake(fn ($process) => in_array('--relative', $process->command, true)
        ? Process::result(exitCode: 1)
        : Process::result());

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->run())->toBeFalse();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertNotRan(fn ($process) => in_array(
        'forge@1.2.3.4:/srv/app/storage/app/assets/',
        $process->command,
        true,
    ));
});
