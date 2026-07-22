<?php

declare(strict_types=1);

namespace Sync\Sync\Rsync;

use Stringable;

final readonly class RsyncOptions implements Stringable
{
    /**
     * Rsync flags known to the package, with a human-readable label for prompts.
     */
    public const array AVAILABLE = [
        '--archive' => 'Archive mode (preserves permissions, timestamps, symlinks, ...)',
        '--compress' => 'Compress file data during the transfer',
        '--verbose' => 'Increase verbosity',
        '--progress' => 'Show progress during transfer',
        '--delete' => 'Delete files on the target that no longer exist on the source',
        '--dry-run' => 'Perform a trial run without any changes made',
        '--stats' => 'Show file transfer statistics',
        '--human-readable' => 'Output numbers in a human-readable format',
        '--delete-after' => 'Delete files on the target after the transfer',
        '--partial' => 'Keep partially transferred files',
        '--update' => 'Skip files newer on the target',
        '--checksum' => 'Skip based on checksum, not modification time & size',
        '--copy-links' => 'Transform symlinks into the referent file/dir',
        '--itemize-changes' => 'Output a change-summary for all updates',
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

        return new self($resolved->filter()->unique()->values()->all());
    }

    /**
     * Whether any of the resolved flags produce visible output while syncing.
     */
    public function producesOutput(): bool
    {
        return array_intersect($this->flags, self::OUTPUT_PRODUCING) !== [];
    }

    public function __toString(): string
    {
        return implode(' ', $this->flags);
    }
}
