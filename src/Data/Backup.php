<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Data;

final readonly class Backup
{
    /**
     * The folder-name format a timestamp is stamped with, and parsed back from
     * (see `BackupFolder::fromPath()`).
     */
    public const string FORMAT = 'Y-m-d_His';

    public function __construct(
        public string $dir,
        public string $timestamp,
    ) {}

    /**
     * Start a backup, stamping it with the current time.
     *
     * The only `now()` call in the backup flow, so every `BackupCommand` built from this
     * instance shares one timestamp even if the clock ticks over mid-run.
     */
    public static function now(string $dir): self
    {
        return new self($dir, now()->format(self::FORMAT));
    }
}
