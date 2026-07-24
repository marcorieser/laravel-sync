<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Data;

final readonly class Backup
{
    public function __construct(
        public string $dir,
        public string $timestamp,
    ) {}

    /**
     * Start a backup, stamping it with the current time.
     *
     * The single call site for `now()` in the backup flow, so every `BackupCommand`
     * built from this instance shares one timestamp, even if the clock ticks over a
     * second mid-run.
     */
    public static function now(string $dir): self
    {
        return new self($dir, now()->format('Y-m-d_His'));
    }
}
