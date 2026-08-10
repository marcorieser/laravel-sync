<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Data;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * One timestamped folder on disk under `backup_dir`, created by a backed-up pull.
 */
final readonly class BackupFolder
{
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public Carbon $createdAt,
    ) {}

    /**
     * Hydrate a backup folder from its absolute path and size on disk.
     *
     * The folder's name is its own timestamp (`Y-m-d_His`, see `Backup::now()`), so
     * `createdAt` is parsed straight from it instead of reading the filesystem's own
     * (less reliable, and platform-dependent) creation/modification time.
     *
     * Uses the native `DateTimeImmutable::createFromFormat()` (not `Carbon::createFromFormat()`)
     * because the native parser genuinely returns `false` on a mismatched format instead of
     * throwing, so an invalid name fails predictably here instead of via an uncaught Carbon
     * parse exception.
     */
    public static function fromPath(string $path, int $size): self
    {
        $name = basename($path);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d_His', $name);

        if ($parsed === false) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid backup timestamp (expected the "Y-m-d_His" format).',
                $name,
            ));
        }

        return new self(name: $name, path: $path, size: $size, createdAt: Date::instance($parsed));
    }

    /**
     * A human-readable label for interactive prompts and previews, e.g.
     * "2026-07-24_134530 (12.4 MB, 2 weeks ago)".
     */
    public function label(): string
    {
        return sprintf(
            '%s (%s, %s)',
            $this->name,
            Number::fileSize($this->size, precision: 1),
            $this->createdAt->diffForHumans(),
        );
    }
}
