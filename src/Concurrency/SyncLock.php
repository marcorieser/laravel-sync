<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Concurrency;

/**
 * A non-blocking, per-remote exclusive lock file, guarding against two `sync` runs
 * racing on the same remote.
 *
 * Backed by `flock()`, not `Cache::lock()`: the package can't assume the host app
 * configures a cache driver that's atomic across processes (`array` isn't), and the OS
 * releases a file lock automatically if the holding process dies.
 */
final class SyncLock
{
    /**
     * @var resource|null
     */
    private $handle;

    public function __construct(
        public readonly string $path,
    ) {}

    /**
     * Try to acquire the lock, without blocking. Returns false immediately if another
     * process already holds it, or if the lock file couldn't be opened.
     */
    public function acquire(): bool
    {
        // Already held: succeed without dropping and retaking the OS lock, which would
        // open a window for a racing process to grab it in between.
        if (is_resource($this->handle)) {
            return true;
        }

        $directory = dirname($this->path);

        // Suppressed, not `File::ensureDirectoryExists()`: two processes racing to create
        // this directory both pass its exists-check, and the loser's `mkdir()` throws.
        if (! is_dir($directory)) {
            @mkdir($directory, recursive: true);
        }

        $handle = @fopen($this->path, 'c');

        if (! is_resource($handle)) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * Release the lock, if held. Safe to call even when `acquire()` was never called
     * or didn't succeed.
     */
    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }
}
