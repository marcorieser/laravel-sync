<?php

declare(strict_types=1);

namespace MarcoRieser\Sync;

use Illuminate\Support\Collection;
use MarcoRieser\Sync\Data\Backup;
use MarcoRieser\Sync\Data\BackupFolder;
use MarcoRieser\Sync\Data\Recipe;
use MarcoRieser\Sync\Data\Remote;
use MarcoRieser\Sync\Enums\Operation;
use MarcoRieser\Sync\Exceptions\SyncException;
use MarcoRieser\Sync\Rsync\RsyncOptions;

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
     * Start a backup for the given recipes, guarding both that `backup_dir` is safe to
     * write into and that it doesn't nest inside a recipe path — the single place a
     * `Backup` gets created from config, so neither guard can be skipped by a caller
     * forgetting to run it separately.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function startBackup(Collection $recipes): Backup
    {
        $backup = Backup::now($this->backupDir());

        $this->guardBackup($backup, $recipes);

        return $backup;
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
        $this->guardBackupDirSafe($this->backupDir());

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
     * Takes the directory to check explicitly, rather than always reading
     * `$this->backupDir()` itself — `prepare()` needs to validate a specific `Backup`'s
     * own `dir`, which could differ from the currently configured value.
     *
     * First resolves the directory lexically (no filesystem access, so this works even
     * before the directory exists) and refuses it when it's blank, resolves to the
     * project root itself, or ever steps above the root via a ".." segment. Then, if the
     * directory (or one of its parents) already exists, resolves it for real and refuses
     * it if a symlink leads outside the project (or straight at the project root) — the
     * lexical check alone can't catch a `backup_dir` that looks like a normal, contained
     * relative path but is actually a symlink to somewhere else.
     */
    public function guardBackupDirSafe(string $dir): void
    {
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
     * directory and refuse it unless that real, symlink-resolved path is contained by
     * the project root — rejecting a path that escapes the root entirely.
     *
     * The upward walk is guaranteed to terminate: `$ancestor` starts as a subpath of
     * `base_path()`, the running app's own root, so it's always reached at the latest.
     * Unlike the walk itself, `realpath()` isn't trusted to succeed just because
     * `file_exists()` did (a real TOCTOU window, e.g. a concurrent `sync:backups-clean`
     * run) — failing to resolve either side is treated as unsafe, not skipped.
     *
     * Separately refuses the directory resolving to the project root itself — but only
     * when the *full* configured directory already exists there (no walking up was
     * needed): a `backup_dir` that simply hasn't been created yet naturally walks all
     * the way up to the (always-existing) project root as its nearest ancestor, which
     * is expected and safe, not the same as `backup_dir` itself being a symlink
     * pointing straight at the root.
     *
     * @param  array<int, string>  $segments
     */
    private function guardBackupDirNotEscapingRootOnDisk(string $dir, array $segments): void
    {
        $target = base_path(implode('/', $segments));
        $ancestor = $target;

        while (! file_exists($ancestor)) {
            $ancestor = dirname($ancestor);
        }

        $real = realpath($ancestor);
        $root = realpath(base_path());
        $normalizedReal = $real === false ? null : $this->normalizePath($real);
        $normalizedRoot = $root === false ? null : $this->normalizePath($root);

        if ($normalizedReal === null || $normalizedRoot === null || ! $this->isPathWithin($normalizedReal, $normalizedRoot)) {
            throw SyncException::backupDirUnsafe($dir);
        }

        if ($ancestor === $target && $normalizedReal === $normalizedRoot) {
            throw SyncException::backupDirUnsafe($dir);
        }
    }

    /**
     * Sum the size, in bytes, of every file (including hidden ones, e.g. a backed-up
     * `.env`) under the given directory, recursing into subdirectories.
     *
     * Walks the tree with `glob()`, not `File::allFiles()`: the latter is Finder-backed
     * and throws if the directory vanishes between being listed by `backups()` and
     * being sized here — the same class of race `backups()` itself avoids by using
     * `glob()` instead of `File::directories()`. `glob()` on a missing directory
     * returns nothing rather than throwing, so a vanished folder naturally sizes to 0
     * with no separate check needed.
     */
    private function directorySize(string $path): int
    {
        $size = 0;

        foreach ([...glob("{$path}/*", GLOB_NOSORT) ?: [], ...glob("{$path}/.*", GLOB_NOSORT) ?: []] as $entry) {
            $name = basename($entry);

            if ($name === '.') {
                continue;
            }

            if ($name === '..') {
                continue;
            }

            $size += (is_dir($entry) ? $this->directorySize($entry) : @filesize($entry)) ?: 0;
        }

        return $size;
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
            $this->guardBackup($backup, $recipes);
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

            if ($this->isPathWithin($backupPath, $recipePath)) {
                throw SyncException::backupDirNested($backup->dir, $path);
            }
        }
    }

    /**
     * Guard both that `backup_dir` is safe to write into and that it doesn't nest
     * inside a recipe path — the pair `prepare()` and `startBackup()` both need to run
     * on a `Backup`, whether it was just created or handed in by the caller.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    private function guardBackup(Backup $backup, Collection $recipes): void
    {
        $this->guardBackupDirSafe($backup->dir);
        $this->guardBackupNotNested($backup, $recipes);
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

    /**
     * Whether `$path` is the same as, or nested inside, `$ancestor`. Both must already
     * be normalized (via `normalizePath()`) and trailing-slash-free.
     */
    private function isPathWithin(string $path, string $ancestor): bool
    {
        return str_starts_with($path.'/', $ancestor.'/');
    }
}
