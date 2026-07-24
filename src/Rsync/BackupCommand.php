<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Rsync;

use Illuminate\Contracts\Support\Arrayable;
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
        public string $dir,
        public string $timestamp,
    ) {}

    /**
     * Get the source path, anchored with `/./` so `--relative` recreates only the
     * recipe path (not the whole project path) under the backup folder.
     */
    public function origin(): string
    {
        return base_path().'/./'.$this->path;
    }

    /**
     * Get the destination folder for this backup run.
     */
    public function target(): string
    {
        return base_path("{$this->dir}/{$this->timestamp}").'/';
    }

    public function __toString(): string
    {
        $options = implode(' ', self::OPTIONS);

        return "rsync {$options} {$this->origin()} {$this->target()}";
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
            'target' => base_path("{$this->dir}/{$this->timestamp}/{$this->path}"),
            'options' => implode(' ', self::OPTIONS),
            'port' => '-',
        ];
    }
}
