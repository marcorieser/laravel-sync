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
     * Whether a folder name is a valid backup timestamp, without hydrating it.
     *
     * The single place that decides this — `Sync::backups()` uses it to filter
     * candidate folders, rather than a separately maintained regex that could drift
     * out of sync with what `fromPath()` actually accepts.
     */
    public static function isValidName(string $name): bool
    {
        return self::parse($name) !== false;
    }

    /**
     * Hydrate a backup folder from its absolute path and size on disk.
     *
     * The folder's name is its own timestamp (`Backup::FORMAT`, see `Backup::now()`), so
     * `createdAt` is parsed straight from it instead of reading the filesystem's own
     * (less reliable, and platform-dependent) creation/modification time.
     */
    public static function fromPath(string $path, int $size): self
    {
        return self::tryFromPath($path, fn () => $size) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a valid backup timestamp (expected the "%s" format).',
            basename($path),
            Backup::FORMAT,
        ));
    }

    /**
     * Hydrate a backup folder from its absolute path, or return null if the name isn't
     * a valid backup timestamp — without throwing, and parsing the name only once.
     *
     * `$size` is a callback, not a plain value: `Sync::backups()` needs to reject an
     * invalid folder name before paying for its (potentially expensive, recursive)
     * size calculation, and this is the single point where validity is known — calling
     * `isValidName()` first and `fromPath()` after would parse the same name twice.
     *
     * @param  callable(): int  $size
     */
    public static function tryFromPath(string $path, callable $size): ?self
    {
        $name = basename($path);
        $parsed = self::parse($name);

        if ($parsed === false) {
            return null;
        }

        return new self(name: $name, path: $path, size: $size(), createdAt: Date::instance($parsed));
    }

    /**
     * Parse a folder name against `Backup::FORMAT`, rejecting it if the format doesn't
     * match.
     *
     * Uses the native `DateTimeImmutable::createFromFormat()` (not `Carbon::createFromFormat()`)
     * because the native parser genuinely returns `false` on a mismatched format instead of
     * throwing, so an invalid name fails predictably here instead of via an uncaught Carbon
     * parse exception.
     *
     * Also rejects a structurally-shaped but out-of-range name (e.g. "2026-13-45_999999")
     * that the native parser would otherwise silently roll over into a different, valid
     * date — caught by reformatting the parsed result and requiring it to round-trip back
     * to the exact original string.
     */
    private static function parse(string $name): DateTimeImmutable|false
    {
        $parsed = DateTimeImmutable::createFromFormat(Backup::FORMAT, $name);

        if ($parsed === false || $parsed->format(Backup::FORMAT) !== $name) {
            return false;
        }

        return $parsed;
    }

    /**
     * A human-readable label for interactive prompts and previews, e.g.
     * "2026-07-24_134530 (12.4 MB, 2 weeks ago)".
     */
    public function label(): string
    {
        return sprintf('%s (%s, %s)', $this->name, $this->formattedSize(), $this->age());
    }

    /**
     * The folder's size, formatted for display (e.g. "12.4 MB").
     *
     * Shared by `label()` and the `sync:backups-clean --dry` preview table, so the two
     * can't silently disagree on formatting.
     */
    public function formattedSize(): string
    {
        return Number::fileSize($this->size, precision: 1);
    }

    /**
     * How long ago the folder was created, formatted for display (e.g. "2 weeks ago").
     */
    public function age(): string
    {
        return $this->createdAt->diffForHumans();
    }
}
