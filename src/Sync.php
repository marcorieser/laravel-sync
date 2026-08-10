<?php

declare(strict_types=1);

namespace MarcoRieser\Sync;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use MarcoRieser\Sync\Data\Backup;
use MarcoRieser\Sync\Data\BackupFolder;
use MarcoRieser\Sync\Data\Recipe;
use MarcoRieser\Sync\Data\Remote;
use MarcoRieser\Sync\Enums\Operation;
use MarcoRieser\Sync\Exceptions\SyncException;
use MarcoRieser\Sync\Rsync\RsyncOptions;
use Symfony\Component\Finder\SplFileInfo;

class Sync
{
    /**
     * Get all remotes defined in the config.
     *
     * @return Collection<string, Remote>
     */
    public function remotes(): Collection
    {
        return once(function () {
            /** @var array<string, array{user?: string, host?: string, root: string, port?: int, read_only?: bool}> $remotes */
            $remotes = config('sync.remotes', []);

            if ($remotes === []) {
                throw SyncException::noRemotesConfigured();
            }

            return collect($remotes)->map(
                fn (array $config, string $name) => Remote::fromArray($name, $config),
            );
        });
    }

    /**
     * Get all recipes defined in the config.
     *
     * @return Collection<string, Recipe>
     */
    public function recipes(): Collection
    {
        return once(function () {
            /** @var array<string, array<int, string>> $recipes */
            $recipes = config('sync.recipes', []);

            if ($recipes === []) {
                throw SyncException::noRecipesConfigured();
            }

            return collect($recipes)->map(
                fn (array $paths, string $name) => Recipe::fromArray($name, $paths),
            );
        });
    }

    /**
     * Get the default rsync options defined in the config.
     *
     * @return array<int, string>
     */
    public function defaultOptions(): array
    {
        return once(fn () => self::filterStrings((array) config('sync.options', [])));
    }

    /**
     * Get the configured backup directory, relative to the project's root.
     */
    public function backupDir(): string
    {
        $value = config('sync.backup_dir', '.sync-backups');

        return is_string($value) ? $value : '.sync-backups';
    }

    /**
     * Start a backup, guarding that `backup_dir` is safe to write into first — the
     * single place a `Backup` gets created from config, so that guard can't be skipped
     * by a caller forgetting to run it separately.
     */
    public function startBackup(): Backup
    {
        $this->guardBackupDirSafe();

        return Backup::now($this->backupDir());
    }

    /**
     * List the timestamped backup folders on disk under `backup_dir`, newest first.
     *
     * Not memoized with `once()` like `remotes()`/`recipes()`/`defaultOptions()` — this
     * list must reflect a delete made earlier in the same request (`sync:backups-clean`).
     *
     * @return Collection<int, BackupFolder>
     */
    public function backups(): Collection
    {
        $this->guardBackupDirSafe();

        $dir = base_path($this->backupDir());

        // `glob()`, not `File::isDirectory()` + `File::directories()`: that two-step
        // check-then-act pair races if the directory is removed in between (by another
        // process, or a concurrent `sync:backups-clean` run) — Laravel's Finder-backed
        // `directories()` throws on a now-missing directory. `glob()` is a single call
        // that never throws, returning an empty list either way.
        $directories = glob("{$dir}/*", GLOB_ONLYDIR) ?: [];

        return collect($directories)
            ->filter(fn (string $path) => BackupFolder::isValidName(basename($path)))
            ->map(fn (string $path) => BackupFolder::fromPath($path, $this->directorySize($path)))
            ->sortByDesc(fn (BackupFolder $folder) => $folder->name)
            ->values();
    }

    /**
     * Guard against a `backup_dir` that would write or delete outside the project.
     *
     * First resolves the configured directory lexically (no filesystem access, so this
     * works even before the directory exists) and refuses it when it's blank, resolves
     * to the project root itself, or ever steps above the root via a ".." segment. Then,
     * if the directory (or one of its parents) already exists, resolves it for real and
     * refuses it if a symlink leads outside the project — the lexical check alone can't
     * catch a `backup_dir` that looks like a normal, contained relative path but is
     * actually a symlink to somewhere else.
     */
    public function guardBackupDirSafe(): void
    {
        $dir = $this->backupDir();
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', trim($dir))) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw SyncException::backupDirUnsafe($dir);
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw SyncException::backupDirUnsafe($dir);
        }

        $this->guardBackupDirNotEscapingRootOnDisk($dir, $segments);
    }

    /**
     * Resolve the nearest existing ancestor of the (lexically already-safe) backup
     * directory and refuse it if that real, symlink-resolved path lies outside the
     * project root.
     *
     * The upward walk is guaranteed to terminate: `$ancestor` starts as a subpath of
     * `base_path()`, which the running app's own root, so it always exists and is
     * reached at the latest. `realpath()` is trusted to succeed once `file_exists()`
     * has just confirmed the path is there.
     *
     * @param  array<int, string>  $segments
     */
    private function guardBackupDirNotEscapingRootOnDisk(string $dir, array $segments): void
    {
        $ancestor = base_path(implode('/', $segments));

        while (! file_exists($ancestor)) {
            $ancestor = dirname($ancestor);
        }

        $root = realpath(base_path());
        $real = realpath($ancestor);

        if ($root !== false && $real !== false
            && ! str_starts_with($this->normalizePath($real).'/', $this->normalizePath($root).'/')) {
            throw SyncException::backupDirUnsafe($dir);
        }
    }

    /**
     * Sum the size, in bytes, of every file (including hidden ones, e.g. a backed-up
     * `.env`) under the given directory.
     */
    private function directorySize(string $path): int
    {
        return (int) collect(File::allFiles($path, hidden: true))
            ->sum(fn (SplFileInfo $file) => $file->getSize());
    }

    /**
     * Filter a mixed array down to its string values, reindexed.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<int, string>
     */
    public static function filterStrings(array $values): array
    {
        return collect($values)->filter(fn (mixed $value) => is_string($value))->values()->all();
    }

    /**
     * Get a single remote by name.
     */
    public function remote(string $name): Remote
    {
        return $this->remotes()->get($name) ?? throw SyncException::unknownRemote($name);
    }

    /**
     * Get a single recipe by name.
     */
    public function recipe(string $name): Recipe
    {
        return $this->recipes()->get($name) ?? throw SyncException::unknownRecipe($name);
    }

    /**
     * Prepare a guarded sync for the given operation, remote, recipes, and options.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function prepare(Operation $operation, Remote $remote, Collection $recipes, RsyncOptions $options, ?Backup $backup = null): PendingSync
    {
        $this->guardReadOnly($operation, $remote);
        $this->guardNotSamePath($remote, $recipes);

        if ($backup instanceof Backup && $operation === Operation::Pull) {
            $this->guardBackupDirSafe();
            $this->guardBackupNotNested($backup, $recipes);
        }

        return new PendingSync($operation, $remote, $recipes, $options, $backup);
    }

    /**
     * Guard against pushing to a read-only remote.
     *
     * Exposed separately (not just internally by `prepare()`) so a caller resolving its
     * own input can fail fast before doing further work (e.g. prompting for recipes and
     * options), without that caller having to bypass `prepare()`'s guard to do so.
     */
    public function guardReadOnly(Operation $operation, Remote $remote): void
    {
        if ($operation === Operation::Push && $remote->readOnly) {
            throw SyncException::remoteIsReadOnly($remote->name);
        }
    }

    /**
     * Guard against syncing a recipe path with itself (e.g. a "local" remote whose
     * root equals the project's base path).
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardNotSamePath(Remote $remote, Collection $recipes): void
    {
        foreach ($this->resolvedPaths($recipes) as $path) {
            $remotePath = $this->normalizePath($remote->path($path));
            $localPath = $this->normalizePath(base_path($path));

            if ($remotePath === $localPath) {
                throw SyncException::samePath($path);
            }
        }
    }

    /**
     * Guard against the backup directory being the same as, or nested inside, a recipe
     * path being backed up — otherwise a pull's backup pass would copy the (growing)
     * backup folder into itself.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardBackupNotNested(Backup $backup, Collection $recipes): void
    {
        $backupPath = $this->normalizePath(rtrim(base_path($backup->dir), '/'));

        foreach ($this->resolvedPaths($recipes) as $path) {
            $recipePath = $this->normalizePath(rtrim(base_path($path), '/'));

            if (str_starts_with($backupPath.'/', $recipePath.'/')) {
                throw SyncException::backupDirNested($backup->dir, $path);
            }
        }
    }

    /**
     * Get every recipe path, flattened and de-duplicated.
     *
     * @param  Collection<int, Recipe>  $recipes
     * @return Collection<int, string>
     */
    private function resolvedPaths(Collection $recipes): Collection
    {
        return $recipes->flatMap(fn (Recipe $recipe) => $recipe->paths)->unique();
    }

    /**
     * Normalize a path for cross-platform, case-insensitive-filesystem-safe comparison.
     *
     * On Windows, a local remote's `root` (typically `base_path()`) carries backslashes
     * that `Remote::path()` doesn't normalize, only `base_path()` does — so any path
     * compared against another needs normalizing first, not just one side. Case is
     * folded too: macOS (APFS) and Windows (NTFS) are case-insensitive by default, so
     * two paths differing only by case can be the same directory on disk.
     */
    private function normalizePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }
}
