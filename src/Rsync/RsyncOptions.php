<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Rsync;

use Stringable;

final readonly class RsyncOptions implements Stringable
{
    /**
     * Rsync flags known to the package, with a human-readable label for prompts.
     */
    public const array AVAILABLE = [
        '--archive' => 'Archive mode (preserves permissions, timestamps, symlinks, ...)',
        '--delete' => 'Delete files on the target that no longer exist on the source',
        '--dry-run' => 'Perform a trial run without any changes made',
        '--verbose' => 'Increase verbosity',
        '--progress' => 'Show progress during transfer',
        '--compress' => 'Compress file data during the transfer',
        '--stats' => 'Show file transfer statistics',
        '--human-readable' => 'Output numbers in a human-readable format',
        '--itemize-changes' => 'Output a change-summary for all updates',
        '--update' => 'Skip files newer on the target',
        '--partial' => 'Keep partially transferred files',
        '--delete-after' => 'Delete files on the target after the transfer',
        '--checksum' => 'Skip based on checksum, not modification time & size',
        '--copy-links' => 'Transform symlinks into the referent file/dir',
        '--no-perms' => 'Do not preserve permissions',
        '--no-owner' => 'Do not preserve owner',
        '--no-group' => 'Do not preserve group',
    ];

    /**
     * Rsync flags that produce visible output while the sync runs.
     */
    public const array OUTPUT_PRODUCING = [
        '--verbose',
        '--progress',
        '--stats',
        '--itemize-changes',
        '--human-readable',
    ];

    /**
     * @param  array<int, string>  $flags
     */
    public function __construct(
        public array $flags,
    ) {}

    /**
     * Resolve the effective rsync options, adding the flags implied by a dry or verbose run.
     *
     * @param  array<int, string>  $flags
     */
    public static function resolve(array $flags, bool $dry, bool $verbose): self
    {
        $resolved = collect($flags);

        if ($dry) {
            $resolved = $resolved->merge(['--dry-run', ...self::OUTPUT_PRODUCING]);
        }

        if ($verbose) {
            $resolved = $resolved->merge(self::OUTPUT_PRODUCING);
        }

        return new self($resolved->filter(fn (string $flag) => $flag !== '')->unique()->values()->all());
    }

    /**
     * Whether any of the resolved flags produce visible output while syncing.
     *
     * Flags outside the curated `AVAILABLE` list (e.g. a raw `--option=` override) are of
     * unknown behavior and assumed to produce output, so streaming isn't silently suppressed.
     */
    public function producesOutput(): bool
    {
        foreach ($this->flags as $flag) {
            if (! array_key_exists($flag, self::AVAILABLE) || in_array($flag, self::OUTPUT_PRODUCING, true)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return implode(' ', $this->flags);
    }
}
