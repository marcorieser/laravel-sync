<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use MarcoRieser\Sync\Data\BackupFolder;
use MarcoRieser\Sync\Sync;

beforeEach(function () {
    // Unique per test (even under `pest --parallel`, which shares one Testbench
    // skeleton across the run) so these filesystem-touching tests can't collide.
    config(['sync.backup_dir' => $backupDir = '.sync-backups-'.Str::random(8)]);

    $this->backupDir = $backupDir;
    $this->backupPath = base_path($backupDir);
});

afterEach(function () {
    File::deleteDirectory($this->backupPath);
});

it('reports no backups found when the backup directory is empty', function () {
    $this->artisan('sync:backups-clean', ['--no-interaction' => true])
        ->expectsOutputToContain('No backups found')
        ->assertSuccessful();
});

it('deletes every backup with --all --no-interaction, leaving the backup directory and strays intact', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530/storage/app/assets");
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-25_090000");
    File::put("{$this->backupPath}/README.txt", 'not a backup');

    $this->artisan('sync:backups-clean', ['--all' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Deleted 2 backup(s)')
        ->assertSuccessful();

    expect(File::isDirectory("{$this->backupPath}/2026-07-24_134530"))->toBeFalse()
        ->and(File::isDirectory("{$this->backupPath}/2026-07-25_090000"))->toBeFalse()
        ->and(File::isDirectory($this->backupPath))->toBeTrue()
        ->and(File::exists("{$this->backupPath}/README.txt"))->toBeTrue();
});

it('fails with a friendly error when run non-interactively without --all', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-clean', ['--no-interaction' => true])
        ->expectsOutputToContain('You must select at least one backup, or pass --all to delete every backup.')
        ->assertFailed();

    expect(File::isDirectory("{$this->backupPath}/2026-07-24_134530"))->toBeTrue();
});

it('deletes the backups picked from the interactive multiselect, after confirming', function () {
    $this->travelTo(Date::parse('2026-07-26 10:00:00'));

    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-25_090000");

    $newer = BackupFolder::fromPath("{$this->backupPath}/2026-07-25_090000", 0);
    $older = BackupFolder::fromPath("{$this->backupPath}/2026-07-24_134530", 0);

    $this->artisan('sync:backups-clean')
        ->expectsChoice(
            'Which backups do you want to delete?',
            [$newer->name],
            [$newer->name => $newer->label(), $older->name => $older->label()],
        )
        ->expectsConfirmation(sprintf(
            'You are about to permanently delete 1 backup(s) (%s) from "%s". Are you sure?',
            Number::fileSize(0, precision: 1),
            $this->backupDir,
        ), 'yes')
        ->expectsOutputToContain('Deleted 1 backup(s)')
        ->assertSuccessful();

    expect(File::isDirectory("{$this->backupPath}/2026-07-25_090000"))->toBeFalse()
        ->and(File::isDirectory("{$this->backupPath}/2026-07-24_134530"))->toBeTrue();
});

it('deletes nothing when the confirmation is declined', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-clean', ['--all' => true])
        ->expectsConfirmation(sprintf(
            'You are about to permanently delete 1 backup(s) (%s) from "%s". Are you sure?',
            Number::fileSize(0, precision: 1),
            $this->backupDir,
        ), 'no')
        ->expectsOutputToContain('Cleanup aborted.')
        ->assertSuccessful();

    expect(File::isDirectory("{$this->backupPath}/2026-07-24_134530"))->toBeTrue();
});

it('skips the confirmation prompt with --force', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-clean', ['--all' => true, '--force' => true])
        ->doesntExpectOutputToContain('permanently delete')
        ->expectsOutputToContain('Deleted 1 backup(s)')
        ->assertSuccessful();

    expect(File::isDirectory("{$this->backupPath}/2026-07-24_134530"))->toBeFalse();
});

it('previews the backups that --dry would delete, without deleting anything', function () {
    $this->travelTo(Date::parse('2026-07-24 14:45:30'));

    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-clean', ['--all' => true, '--dry' => true, '--no-interaction' => true])
        ->expectsOutputToContain('2026-07-24_134530')
        ->expectsOutputToContain('Dry run completed. Nothing was deleted.')
        ->assertSuccessful();

    expect(File::isDirectory("{$this->backupPath}/2026-07-24_134530"))->toBeTrue();
});

it('fails with a friendly error when the backup directory resolves outside the project', function () {
    config(['sync.backup_dir' => '../outside']);

    $this->artisan('sync:backups-clean', ['--all' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Set a backup_dir inside your project.')
        ->assertFailed();
});

it('reports a friendly error when a backup fails to delete', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    // Matched against the path `Sync::backups()` actually resolves (not a hand-built
    // string) — `glob()` returns OS-native separators, and a literal "/" join would
    // mismatch Mockery's exact string match on Windows.
    $folder = resolve(Sync::class)->backups()->sole();

    File::partialMock()
        ->shouldReceive('deleteDirectory')
        ->once()
        ->with($folder->path)
        ->andReturn(false);

    $this->artisan('sync:backups-clean', ['--all' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Failed to delete 1 backup(s): "2026-07-24_134530".')
        ->assertFailed();
});

it('reports both the successful deletes and every failure on a partial delete', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-25_090000");

    $failing = resolve(Sync::class)->backups()->firstWhere('name', '2026-07-24_134530');

    File::partialMock()
        ->shouldReceive('deleteDirectory')
        ->once()
        ->with($failing->path)
        ->andReturn(false);

    $this->artisan('sync:backups-clean', ['--all' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Deleted 1 backup(s)')
        ->expectsOutputToContain('Failed to delete 1 backup(s): "2026-07-24_134530".')
        ->assertFailed();

    expect(File::isDirectory("{$this->backupPath}/2026-07-24_134530"))->toBeTrue()
        ->and(File::isDirectory("{$this->backupPath}/2026-07-25_090000"))->toBeFalse();
});
