<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Concurrency;

/**
 * A non-blocking, per-remote exclusive lock file, guarding against two `sync` runs
 * (e.g. a cron job and a human, or two humans) racing on the same remote at once.
 *
 * Backed by `flock()`, not `Cache::lock()`: the package can't assume the host app
 * configures an atomic cache driver (the `array` driver, for one, isn't shared across
 * processes), while a lock file works regardless of cache config and is released
 * automatically by the OS if the holding process dies without calling `release()`.
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
        // If this instance already holds the lock, calling `acquire()` again is a no-op
        // that succeeds immediately — not a release-then-reacquire. Actually dropping and
        // retaking the OS-level lock here would open a window where a racing process could
        // grab it in between, making a caller that believes it already holds the lock see
        // `acquire()` unexpectedly return false.
        if (is_resource($this->handle)) {
            return true;
        }

        $directory = dirname($this->path);

        // Suppressed, and not `File::ensureDirectoryExists()`: two `sync` processes
        // racing to create the same not-yet-existing lock directory for the first time
        // both pass its exists-check before either finishes `mkdir()`, so the loser's
        // unsuppressed `mkdir()` would throw instead of simply finding the directory
        // already there.
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
