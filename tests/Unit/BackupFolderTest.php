<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use MarcoRieser\Sync\Data\BackupFolder;

it('parses the name, path, size, and created-at timestamp from a folder path', function () {
    $folder = BackupFolder::fromPath(base_path('.sync-backups/2026-07-24_134530'), 1024);

    expect($folder->name)->toBe('2026-07-24_134530')
        ->and($folder->path)->toBe(base_path('.sync-backups/2026-07-24_134530'))
        ->and($folder->size)->toBe(1024)
        ->and($folder->createdAt->toDateTimeString())->toBe('2026-07-24 13:45:30');
});

it('renders a human-readable label with the name, size, and age', function () {
    $this->travelTo(Date::parse('2026-07-24 14:45:30'));

    $folder = BackupFolder::fromPath(base_path('.sync-backups/2026-07-24_134530'), 1_200_000);

    expect($folder->label())->toBe(sprintf(
        '2026-07-24_134530 (%s, %s)',
        Number::fileSize(1_200_000, precision: 1),
        '1 hour ago',
    ));
});

it('refuses a folder whose name is not a valid backup timestamp', function () {
    BackupFolder::fromPath(base_path('.sync-backups/not-a-backup'), 0);
})->throws(
    InvalidArgumentException::class,
    '"not-a-backup" is not a valid backup timestamp (expected the "Y-m-d_His" format).',
);

it('exposes size and age formatted for display', function () {
    $this->travelTo(Date::parse('2026-07-24 14:45:30'));

    $folder = BackupFolder::fromPath(base_path('.sync-backups/2026-07-24_134530'), 1_200_000);

    expect($folder->formattedSize())->toBe(Number::fileSize(1_200_000, precision: 1))
        ->and($folder->age())->toBe('1 hour ago');
});

it('hydrates a backup folder from a valid path via tryFromPath()', function () {
    $folder = BackupFolder::tryFromPath(base_path('.sync-backups/2026-07-24_134530'), fn () => 1024);

    expect($folder)->toBeInstanceOf(BackupFolder::class)
        ->and($folder->name)->toBe('2026-07-24_134530')
        ->and($folder->size)->toBe(1024);
});

it('returns null from tryFromPath() for an invalid name, without calling the size resolver', function () {
    $called = false;

    $folder = BackupFolder::tryFromPath(base_path('.sync-backups/not-a-backup'), function () use (&$called) {
        $called = true;

        return 0;
    });

    expect($folder)->toBeNull()
        ->and($called)->toBeFalse();
});
