<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use MarcoRieser\Sync\Data\Backup;

it('stamps a backup with the current time, formatted for a folder name', function () {
    $this->travelTo(Carbon::parse('2026-07-24 13:45:30'));

    $backup = Backup::now('.sync-backups');

    expect($backup->dir)->toBe('.sync-backups')
        ->and($backup->timestamp)->toBe('2026-07-24_134530');
});
