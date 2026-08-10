<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use MarcoRieser\Sync\Data\Backup;
use MarcoRieser\Sync\Data\BackupFolder;
use MarcoRieser\Sync\Data\Recipe;
use MarcoRieser\Sync\Data\Remote;
use MarcoRieser\Sync\Enums\Operation;
use MarcoRieser\Sync\Exceptions\SyncException;
use MarcoRieser\Sync\PendingSync;
use MarcoRieser\Sync\Rsync\RsyncOptions;
use MarcoRieser\Sync\Sync;

beforeEach(function () {
    config([
        'sync.remotes' => [
            'production' => ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app', 'read_only' => true],
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
        ],
        // Unique per test (even under `pest --parallel`, which shares one Testbench
        // skeleton across the run) so these filesystem-touching tests can't collide.
        'sync.backup_dir' => $backupDir = '.sync-backups-'.Str::random(8),
    ]);

    $this->backupDir = $backupDir;
    $this->backupPath = base_path($backupDir);
});

afterEach(function () {
    File::deleteDirectory($this->backupPath);
});

it('resolves the singleton from the container', function () {
    expect(resolve(Sync::class))->toBeInstanceOf(Sync::class)
        ->and(resolve(Sync::class))->toBe(resolve(Sync::class));
});

it('lists remotes hydrated from the config', function () {
    $remotes = resolve(Sync::class)->remotes();

    expect($remotes)->toBeInstanceOf(Collection::class)
        ->and($remotes->keys()->all())->toBe(['production', 'staging'])
        ->and($remotes->get('production'))->toBeInstanceOf(Remote::class);
});

it('throws when no remotes are configured', function () {
    config(['sync.remotes' => []]);

    resolve(Sync::class)->remotes();
})->throws(SyncException::class, 'You need to define at least one remote in your config/sync.php file.');

it('lists recipes hydrated from the config', function () {
    $recipes = resolve(Sync::class)->recipes();

    expect($recipes->keys()->all())->toBe(['assets'])
        ->and($recipes->get('assets'))->toBeInstanceOf(Recipe::class);
});

it('throws when no recipes are configured', function () {
    config(['sync.recipes' => []]);

    resolve(Sync::class)->recipes();
})->throws(SyncException::class, 'You need to define at least one recipe in your config/sync.php file.');

it('resolves a single remote by name', function () {
    expect(resolve(Sync::class)->remote('staging'))->toBeInstanceOf(Remote::class);
});

it('throws for an unknown remote', function () {
    resolve(Sync::class)->remote('unknown');
})->throws(SyncException::class, 'The remote "unknown" is not defined in your config/sync.php file.');

it('resolves a single recipe by name', function () {
    expect(resolve(Sync::class)->recipe('assets'))->toBeInstanceOf(Recipe::class);
});

it('throws for an unknown recipe', function () {
    resolve(Sync::class)->recipe('unknown');
})->throws(SyncException::class, 'The recipe "unknown" is not defined in your config/sync.php file.');

it('refuses to push to a read-only remote', function () {
    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('production'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(SyncException::class, 'The remote "production" is read-only and cannot be pushed to.');

it('allows pulling from a read-only remote', function () {
    $sync = resolve(Sync::class);

    $pending = $sync->prepare(Operation::Pull, $sync->remote('production'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('allows pushing to a remote that is not read-only', function () {
    $sync = resolve(Sync::class);

    $pending = $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('refuses to sync a path with itself', function () {
    config([
        'sync.remotes' => ['here' => ['root' => base_path()]],
        'sync.recipes' => ['assets' => ['storage/app/assets/']],
    ]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('here'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(SyncException::class, 'The origin and target path for "storage/app/assets/" are the same. Refusing to sync a path with itself.');

it('refuses to sync a path with itself even when the remote root only differs by case', function () {
    config([
        'sync.remotes' => ['here' => ['root' => strtoupper(base_path())]],
        'sync.recipes' => ['assets' => ['storage/app/assets/']],
    ]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('here'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(SyncException::class);

it('refuses to back up when the backup directory is the recipe path itself', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('storage/app/assets', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(
    SyncException::class,
    'The backup directory "storage/app/assets" is the same as, or inside, the recipe path "storage/app/assets/". Choose a backup_dir outside the recipe paths you back up.',
);

it('refuses to back up when the backup directory is nested inside a recipe path', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('storage/app/assets/.sync-backups', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);

it('refuses to back up when the backup directory only differs by case from the recipe path', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('STORAGE/APP/ASSETS', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);

it('allows backing up when the backup directory is outside the recipe paths', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('.sync-backups', '2026-07-24_134530');

    $pending = $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('does not guard against a nested backup directory on a push, since a push never backs up', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('storage/app/assets/.sync-backups', '2026-07-24_134530');

    $pending = $sync->prepare(
        Operation::Push,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('lists backup folders newest first', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-25_090000");

    $backups = resolve(Sync::class)->backups();

    expect($backups)->toBeInstanceOf(Collection::class)
        ->and($backups->pluck('name')->all())->toBe(['2026-07-25_090000', '2026-07-24_134530'])
        ->and($backups->first())->toBeInstanceOf(BackupFolder::class);
});

it('ignores folders whose name is not a backup timestamp', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/not-a-backup");

    expect(resolve(Sync::class)->backups()->pluck('name')->all())->toBe(['2026-07-24_134530']);
});

it('ignores a folder that is digit-shaped but not an actual valid date', function () {
    // "2026-13-45_999999" has the right digit counts but rolls over to a different,
    // valid date when parsed — a loose "digit shape" check alone would wrongly accept it.
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/2026-13-45_999999");

    expect(resolve(Sync::class)->backups()->pluck('name')->all())->toBe(['2026-07-24_134530']);
});

it('returns no backups when the backup directory does not exist', function () {
    expect(resolve(Sync::class)->backups())->toBeInstanceOf(Collection::class)
        ->and(resolve(Sync::class)->backups())->toHaveCount(0);
});

it('sums hidden files into a backup folder\'s size', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::put("{$this->backupPath}/2026-07-24_134530/visible.txt", str_repeat('a', 100));
    File::put("{$this->backupPath}/2026-07-24_134530/.env", str_repeat('b', 50));

    expect(resolve(Sync::class)->backups()->first()->size)->toBe(150);
});

it('does not memoize the backup list, so it reflects a delete made earlier in the same run', function () {
    $sync = resolve(Sync::class);

    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    expect($sync->backups())->toHaveCount(1);

    File::deleteDirectory("{$this->backupPath}/2026-07-24_134530");
    expect($sync->backups())->toHaveCount(0);
});

it('allows a normal backup directory', function () {
    resolve(Sync::class)->guardBackupDirSafe();
})->throwsNoExceptions();

it('refuses a blank backup directory', function () {
    config(['sync.backup_dir' => '']);

    resolve(Sync::class)->guardBackupDirSafe();
})->throws(
    SyncException::class,
    'The backup directory "" resolves outside your project, or to the project root itself. Set a backup_dir inside your project.',
);

it('refuses a backup directory that resolves to the project root itself', function () {
    config(['sync.backup_dir' => '.']);

    resolve(Sync::class)->guardBackupDirSafe();
})->throws(SyncException::class);

it('refuses a backup directory that steps above the project root', function () {
    config(['sync.backup_dir' => '../outside']);

    resolve(Sync::class)->guardBackupDirSafe();
})->throws(SyncException::class);

it('refuses a backup directory that steps above the root before coming back down', function () {
    config(['sync.backup_dir' => 'storage/../../outside']);

    resolve(Sync::class)->guardBackupDirSafe();
})->throws(SyncException::class);

it('refuses a backup directory that is a symlink escaping the project', function () {
    $target = sys_get_temp_dir().'/sync-outside-'.Str::random(8);
    File::ensureDirectoryExists($target);

    $linkDir = 'sync-backups-symlink-'.Str::random(8);
    $linkPath = base_path($linkDir);

    if (! @symlink($target, $linkPath)) {
        File::deleteDirectory($target);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    config(['sync.backup_dir' => $linkDir]);

    try {
        expect(fn () => resolve(Sync::class)->guardBackupDirSafe())->toThrow(SyncException::class);
    } finally {
        @unlink($linkPath);
        File::deleteDirectory($target);
    }
});

it('allows a backup directory that is a symlink staying inside the project', function () {
    File::ensureDirectoryExists("{$this->backupPath}-real");

    $linkDir = 'sync-backups-symlink-'.Str::random(8);
    $linkPath = base_path($linkDir);

    if (! @symlink("{$this->backupPath}-real", $linkPath)) {
        File::deleteDirectory("{$this->backupPath}-real");
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    config(['sync.backup_dir' => $linkDir]);

    try {
        resolve(Sync::class)->guardBackupDirSafe();
    } finally {
        @unlink($linkPath);
        File::deleteDirectory("{$this->backupPath}-real");
    }
})->throwsNoExceptions();

it('refuses to prepare a pull with an unsafe backup directory', function () {
    config(['sync.backup_dir' => '../outside']);

    $sync = resolve(Sync::class);
    $backup = new Backup('../outside', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);
