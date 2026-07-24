<?php

declare(strict_types=1);

use MarcoRieser\Sync\Data\Backup;
use MarcoRieser\Sync\Rsync\BackupCommand;

beforeEach(function () {
    $this->backup = new Backup('.sync-backups', '2026-07-24_134530');
});

it('builds a relative source path, so --relative recreates just the recipe path', function () {
    $command = new BackupCommand('storage/app/assets/', $this->backup);

    expect($command->origin())->toBe('storage/app/assets/')
        ->and($command->target())->toBe(base_path('.sync-backups/2026-07-24_134530').'/');
});

it('builds a single-file path the same way', function () {
    $command = new BackupCommand('.env', $this->backup);

    expect($command->origin())->toBe('.env')
        ->and($command->target())->toBe(base_path('.sync-backups/2026-07-24_134530').'/');
});

it('runs from the project root, so the relative source path resolves correctly', function () {
    $command = new BackupCommand('storage/app/assets/', $this->backup);

    expect($command->workingDirectory())->toBe(base_path());
});

it('renders the command as a string with the fixed archive and relative flags, wrapped in a cd', function () {
    $command = new BackupCommand('storage/app/assets/', $this->backup);

    expect((string) $command)->toBe(sprintf(
        '(cd %s && rsync --archive --relative storage/app/assets/ %s/)',
        base_path(),
        base_path('.sync-backups/2026-07-24_134530'),
    ));
});

it('builds an argument list without shell interpretation of paths or options', function () {
    $command = new BackupCommand('storage/app/assets/', $this->backup);

    expect($command->toArgs())->toBe([
        'rsync',
        '--archive',
        '--relative',
        'storage/app/assets/',
        base_path('.sync-backups/2026-07-24_134530').'/',
    ]);
});

it('converts to a human-readable array with absolute paths', function () {
    $command = new BackupCommand('.env', $this->backup);

    expect($command->toArray())->toBe([
        'origin' => base_path('.env'),
        'target' => base_path('.sync-backups/2026-07-24_134530/.env'),
        'options' => '--archive --relative',
        'port' => '-',
    ]);
});
