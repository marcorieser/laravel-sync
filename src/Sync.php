<?php

declare(strict_types=1);

namespace Vitamin2\Sync;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Vitamin2\Sync\Concurrency\SyncLock;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Rsync\RsyncOptions;

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

            // Indexed by literal key, not `config("sync.excludes.{$name}")`: a recipe name
            // containing a "." would be misread by `config()`'s dot-notation.
            /** @var array<string, array<int, string>> $excludes */
            $excludes = config('sync.excludes', []);
            /** @var array<string, array<int, string>> $excludesFrom */
            $excludesFrom = config('sync.excludes_from', []);

            return collect($recipes)->map(
                fn (array $paths, string $name) => Recipe::fromArray(
                    $name,
                    $paths,
                    self::filterStrings((array) ($excludes[$name] ?? [])),
                    self::filterStrings((array) ($excludesFrom[$name] ?? [])),
                ),
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
     * Start a backup for the given recipes.
     *
     * The single place a `Backup` is created from config, so neither guard can be skipped.
     * Built from `guardBackupDirSafe()`'s dot-collapsed return value, not the raw
     * configured string, so `$backup->dir` matches exactly what was validated.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function startBackup(Collection $recipes): Backup
    {
        $backup = Backup::now($this->guardBackupDirSafe($this->backupDir()));

        $this->guardBackupNotNested($backup, $recipes);

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
        // The dot-collapsed directory, not the raw configured string: a ".." through a
        // not-yet-existing intermediate would validate as safe but resolve to nothing here.
        $dir = base_path($this->guardBackupDirSafe($this->backupDir()));

        // `glob()`, not `File::isDirectory()` + `File::directories()`: that check-then-act
        // pair races a concurrent `sync:backups-clean`, and Finder-backed `directories()`
        // throws on a now-missing directory. `$dir` is escaped so a `backup_dir` containing
        // a glob metacharacter can't widen the pattern onto sibling directories.
        $directories = glob($this->escapeGlobPattern($dir).'/*', GLOB_ONLYDIR) ?: [];

        // Rejects symlinks even though `GLOB_ONLYDIR` follows them: a symlinked entry would
        // let `sync:backups-clean` delete contents living outside `backup_dir`.
        return collect($directories)
            ->reject(fn (string $path) => is_link($path))
            ->map(fn (string $path) => BackupFolder::tryFromPath($path, fn () => $this->directorySize($path)))
            ->filter()
            ->sortByDesc(fn (BackupFolder $folder) => $folder->name)
            ->values();
    }

    /**
     * Delete a single backup folder from disk, reporting whether it actually succeeded.
     *
     * Re-checks `is_link()` despite `backups()` already filtering symlinks: the interactive
     * prompts in `sync:backups-clean` leave a user-paced window in which the folder could
     * be swapped for a symlink, which `File::deleteDirectory()` would follow.
     *
     * Its return value isn't trustworthy either — it reports success whenever the top-level
     * directory existed, even if files inside failed to delete. Only re-checking is reliable.
     */
    public function deleteBackup(BackupFolder $folder): bool
    {
        if (is_link($folder->path)) {
            return false;
        }

        File::deleteDirectory($folder->path);

        return ! File::isDirectory($folder->path);
    }

    /**
     * Older than `$olderThan` days, excluding the `$keep` newest — combining into the
     * usual "delete anything old, but never the N most recent" rotation.
     *
     * `$backups` must already be sorted newest-first, as `backups()` returns it.
     *
     * @param  Collection<int, BackupFolder>  $backups
     * @return Collection<int, BackupFolder>
     */
    public function filterByRetention(Collection $backups, ?int $keep, ?int $olderThan): Collection
    {
        $candidates = $backups;

        if ($olderThan !== null) {
            $cutoff = now()->subDays($olderThan);
            $candidates = $candidates->filter(fn (BackupFolder $folder) => $folder->createdAt->lt($cutoff));
        }

        if ($keep !== null) {
            $candidates = $candidates->whereNotIn('name', $backups->take($keep)->pluck('name'), true);
        }

        return $candidates->values();
    }

    /**
     * Guard against a `backup_dir` that would write or delete outside the project.
     *
     * Takes the directory explicitly rather than reading `$this->backupDir()`, since
     * `prepare()` validates a specific `Backup`'s own `dir`.
     *
     * Checks lexically first (no filesystem access, so it works before the directory
     * exists), refusing blank, absolute, root-resolving, or root-escaping paths. Absolute
     * is refused explicitly because the segment parser would otherwise drop the leading
     * "/" and misread "/tmp" as relative "tmp". Then, if the directory or a parent exists,
     * resolves symlinks and refuses one leading outside the project — a lexical check
     * can't catch a contained-looking path that is really a symlink elsewhere.
     *
     * Returns the dot-collapsed path it validated, so callers use exactly what was checked.
     */
    public function guardBackupDirSafe(string $dir): string
    {
        $normalized = str_replace('\\', '/', trim($dir));

        if ($normalized !== '' && ($normalized[0] === '/' || preg_match('#^[A-Za-z]:#', $normalized) === 1)) {
            throw SyncException::backupDirUnsafe($dir);
        }

        [$segments, $escaped] = $this->collapseDotSegments($normalized);

        if ($escaped || $segments === []) {
            throw SyncException::backupDirUnsafe($dir);
        }

        $this->guardBackupDirNotEscapingRootOnDisk($dir, $segments);

        return implode('/', $segments);
    }

    /**
     * Lexically collapse "." and ".." segments out of an already-`/`-normalized path,
     * without touching the filesystem. `$escaped` reports a ".." that popped above where
     * collapsing started, which every caller rejects outright.
     *
     * @return array{0: array<int, string>, 1: bool}
     */
    private function collapseDotSegments(string $normalized): array
    {
        $segments = [];
        $escaped = false;

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    $escaped = true;

                    continue;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return [$segments, $escaped];
    }

    /**
     * Resolve the nearest existing ancestor of the (lexically already-safe) backup
     * directory and refuse it unless that symlink-resolved path sits inside the project
     * root. The walk always terminates, since `$ancestor` starts under `base_path()`.
     *
     * `realpath()` is not trusted to succeed just because `file_exists()` did — a real
     * TOCTOU window — so a failure on either side counts as unsafe.
     *
     * Resolving to the project root is refused only when the full directory already
     * exists there: a not-yet-created `backup_dir` legitimately walks up to the root.
     *
     * Compares case-sensitively, deliberately unlike `normalizePath()` elsewhere in this
     * class. This is the one comparison deciding filesystem safety from real disk state,
     * so folding case would let a symlink into a case-differing sibling read as "inside".
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

        $normalizedReal = $this->normalizeRealpath(realpath($ancestor));
        $normalizedRoot = $this->normalizeRealpath(realpath(base_path()));

        if ($normalizedReal === null || $normalizedRoot === null || ! $this->isPathWithin($normalizedReal, $normalizedRoot)) {
            throw SyncException::backupDirUnsafe($dir);
        }

        if ($ancestor === $target && $normalizedReal === $normalizedRoot) {
            throw SyncException::backupDirUnsafe($dir);
        }
    }

    /**
     * Normalize a `realpath()` result: separators only, deliberately NOT case-folded (see
     * `guardBackupDirNotEscapingRootOnDisk()`). A failed `realpath()` becomes `null`.
     */
    private function normalizeRealpath(string|false $path): ?string
    {
        return $path === false ? null : str_replace('\\', '/', $path);
    }

    /**
     * Sum the size in bytes of every file under a directory, including hidden ones.
     *
     * `scandir()`, not `File::allFiles()`: the latter is Finder-backed and throws if the
     * directory vanishes between `backups()` listing it and this sizing it. Suppressed,
     * `scandir()` never throws, so a vanished folder sizes to 0. It also lists hidden and
     * regular entries in one pass and never treats `$path` as a glob pattern.
     *
     * Never follows a symlinked entry: `rsync --archive` preserves symlinks verbatim, so a
     * backup can contain one pointing at its own ancestor — recursing would loop until the
     * path exceeds the OS length limit.
     */
    private function directorySize(string $path): int
    {
        $size = 0;

        foreach (@scandir($path) ?: [] as $name) {
            if ($name === '.') {
                continue;
            }

            if ($name === '..') {
                continue;
            }

            $entry = "{$path}/{$name}";

            if (is_link($entry)) {
                continue;
            }

            $size += (is_dir($entry) ? $this->directorySize($entry) : @filesize($entry)) ?: 0;
        }

        return $size;
    }

    /**
     * Escape glob metacharacters in a literal path so it can prefix a `glob()` pattern.
     *
     * Wraps each in a single-character bracket expression ("*" becomes "[*]") rather than
     * backslash-escaping, which is ambiguous with the Windows path separator.
     */
    private function escapeGlobPattern(string $path): string
    {
        return preg_replace('/[*?\[\]]/', '[$0]', $path) ?? $path;
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
     * Get the concurrency guard for a remote.
     *
     * Keyed by the remote's physical target (`host:port` plus `root`, or just `root` when
     * local) rather than its config name or SSH `user`, so aliased entries pointing at the
     * same directory contend for the same lock — the race being guarded is two `rsync`
     * processes writing one path, which `user` has no bearing on.
     *
     * The identity is canonicalized (dot segments, duplicate slashes, case) before hashing,
     * so aliases differing only cosmetically still collide. Case-folding deliberately
     * over-locks two case-differing paths on a case-sensitive filesystem rather than risk
     * missing a real race on a case-insensitive one. `xxh128` because the arch preset
     * rejects `md5` as a weak hash, though this is only a filename.
     */
    public function lock(Remote $remote): SyncLock
    {
        [$rootSegments] = $this->collapseDotSegments(str_replace('\\', '/', $remote->root));
        $root = '/'.implode('/', $rootSegments);

        $identity = $remote->isLocal()
            ? $root
            : sprintf('%s:%d%s', $remote->host, $remote->port, $root);

        $identity = $this->normalizePath($identity);

        return new SyncLock(storage_path('framework/cache/sync-locks/'.hash('xxh128', $identity).'.lock'));
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
        $this->guardExcludesFromFilesExist($recipes);

        if ($backup instanceof Backup && $operation === Operation::Pull) {
            $this->guardBackup($backup, $recipes);
        }

        return new PendingSync($operation, $remote, $recipes, $options, $backup);
    }

    /**
     * Guard against pushing to a read-only remote.
     *
     * Public as well as called by `prepare()`, so a command can fail fast before
     * prompting for recipes and options.
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
     * Guard that every `excludes_from` file configured for the given recipes actually
     * exists as a file, and doesn't resolve outside the project root — checked only for
     * the recipes being synced, not every configured recipe, so a broken `excludes_from`
     * path on a recipe that isn't part of this run doesn't block it. `rsync` itself would
     * fail with a much less friendly error partway through the transfer otherwise.
     *
     * Refuses an absolute path (a leading "/" or a Windows drive letter like "C:") the
     * same way `guardBackupDirSafe()` does for `backup_dir`: `base_path()` doesn't error
     * on one, it silently `ltrim()`s the leading separator (see `join_paths()`) and
     * rebases it under the project root instead — so an absolute-looking configured path
     * would otherwise resolve to a different, most likely nonexistent, file without any
     * indication that it was never read as an absolute path to begin with.
     *
     * Also reuses `collapseDotSegments()` (also used by `guardBackupDirSafe()`) to
     * lexically refuse a path that steps above the project root via a ".." segment —
     * `base_path()` is plain string concatenation, so an unchecked ".." would otherwise
     * resolve on disk to a real path outside the project, both for this guard's own
     * `File::isFile()` check and for the `--exclude-from=` flag `rsync` itself reads.
     *
     * `File::isFile()`, not `File::exists()`: the latter is also true for a directory (or
     * for a blank entry, since `base_path('')` resolves to the always-existing project
     * root), which would silently pass this guard and only surface as rsync's own raw
     * error once `--exclude-from=` actually points `rsync` at something that isn't a file.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardExcludesFromFilesExist(Collection $recipes): void
    {
        foreach ($recipes as $recipe) {
            foreach ($recipe->excludesFrom as $path) {
                $normalized = str_replace('\\', '/', $path);

                if ($normalized !== '' && ($normalized[0] === '/' || preg_match('#^[A-Za-z]:#', $normalized) === 1)) {
                    throw SyncException::excludesFromFileUnsafe($recipe->name, $path);
                }

                [, $escaped] = $this->collapseDotSegments($normalized);

                if ($escaped) {
                    throw SyncException::excludesFromFileUnsafe($recipe->name, $path);
                }

                if (! File::isFile(base_path($path))) {
                    throw SyncException::excludesFromFileMissing($recipe->name, $path);
                }
            }
        }
    }

    /**
     * Guard against the backup directory being the same as, or nested inside, a recipe
     * path being backed up — otherwise a pull's backup pass would copy the (growing)
     * backup folder into itself.
     *
     * Compares dot-collapsed, not raw: a ".." segment can land inside a recipe directory
     * that a plain string comparison against the literal wouldn't recognize.
     *
     * Also refuses a `dir` stepping above the project root, so a caller isn't relying on
     * the convention of having run `guardBackupDirSafe()` first.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardBackupNotNested(Backup $backup, Collection $recipes): void
    {
        [$backupSegments, $escaped] = $this->collapseDotSegments(str_replace('\\', '/', $backup->dir));

        if ($escaped) {
            throw SyncException::backupDirUnsafe($backup->dir);
        }

        $backupPath = $this->normalizePath(rtrim(base_path(implode('/', $backupSegments)), '/'));

        foreach ($this->resolvedPaths($recipes) as $path) {
            $recipePath = $this->normalizePath(rtrim(base_path($path), '/'));

            if ($this->isPathWithin($backupPath, $recipePath)) {
                throw SyncException::backupDirNested($backup->dir, $path);
            }
        }
    }

    /**
     * Run both backup-dir guards for a caller-supplied `Backup`, which — unlike one from
     * `startBackup()` — may carry a dir that was never validated.
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
     * Both sides need it: on Windows a local remote's `root` carries backslashes that
     * `Remote::path()` doesn't normalize. Case is folded because macOS and Windows are
     * case-insensitive by default, so two paths differing only by case are one directory.
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
