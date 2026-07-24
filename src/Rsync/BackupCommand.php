<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Rsync;

use Illuminate\Contracts\Support\Arrayable;
use MarcoRieser\Sync\Data\Backup;
use Stringable;

/**
 * A local copy of one recipe path into a timestamped backup folder, run before a real
 * pull so the local files being overwritten aren't lost.
 *
 * @implements Arrayable<string, string>
 */
final readonly class BackupCommand implements Arrayable, Stringable
{
    /**
     * Fixed, not user-overridable: `--archive` for a faithful copy (permissions,
     * timestamps, symlinks, ...), `--relative` to recreate the path's directory
     * structure (and create intermediate dirs) under the backup folder.
     */
    private const array OPTIONS = ['--archive', '--relative'];

    public function __construct(
        public string $path,
        public Backup $backup,
    ) {}

    /**
     * Get the source path, relative to the project root, so `--relative` recreates
     * only the recipe path (not the whole absolute source path) under the backup
     * folder — this only works when the process itself runs with the project root
     * as its working directory (see `PendingSync::run()`).
     *
     * A `/./` anchor in the absolute path would do the same on GNU rsync, but macOS's
     * bundled `rsync` (openrsync, not GNU rsync) doesn't honor that anchor and
     * replicates the full absolute path instead — a relative path plus a matching
     * working directory is the one form both implementations agree on.
     */
    public function origin(): string
    {
        return $this->path;
    }

    /**
     * Get the destination folder for this backup run.
     */
    public function target(): string
    {
        return base_path("{$this->backup->dir}/{$this->backup->timestamp}").'/';
    }

    public function __toString(): string
    {
        $options = implode(' ', self::OPTIONS);

        return "(cd {$this->workingDirectory()} && rsync {$options} {$this->origin()} {$this->target()})";
    }

    /**
     * The working directory this command must run from, so its relative `origin()`
     * resolves against the project root.
     */
    public function workingDirectory(): string
    {
        return base_path();
    }

    /**
     * Get this command as an argument list, safe to hand directly to a process runner
     * without shell interpretation of paths or options.
     *
     * @return list<string>
     */
    public function toArgs(): array
    {
        return ['rsync', ...self::OPTIONS, $this->origin(), $this->target()];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'origin' => base_path($this->path),
            'target' => $this->target().$this->path,
            'options' => implode(' ', self::OPTIONS),
            'port' => '-',
        ];
    }
}
