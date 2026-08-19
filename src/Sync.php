<?php

declare(strict_types=1);

namespace Vitamin2\Sync;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Rsync\RestoreCommand;
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

            // Read as a whole array and indexed into by literal key, not
            // `config("sync.excludes.{$name}")`: a recipe name containing a "." (e.g.
            // "assets.images") would otherwise be misread by `config()`'s own
            // dot-notation as a nested path instead of the literal key.
            /** @var array<string, array<int, string>> $excludes */
            $excludes = config('sync.excludes', []);

            return collect($recipes)->map(
                fn (array $paths, string $name) => Recipe::fromArray(
                    $name,
                    $paths,
                    self::filterStrings((array) ($excludes[$name] ?? [])),
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
     * Start a backup for the given recipes, guarding both that `backup_dir` is safe to
     * write into and that it doesn't nest inside a recipe path — the single place a
     * `Backup` gets created from config, so neither guard can be skipped by a caller
     * forgetting to run it separately.
     *
     * Built from `guardBackupDirSafe()`'s returned, dot-collapsed directory rather than
     * the raw configured string, so the `Backup` this produces — and everything that
     * later reads `$backup->dir` (e.g. `BackupCommand::target()`) — agrees with exactly
     * what was validated as safe.
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
        // The dot-collapsed directory `guardBackupDirSafe()` returns, not the raw
        // configured string: a redundant ".." through a not-yet-existing intermediate
        // directory (e.g. "storage/tmp/../.sync-backups") would still be validated as
        // safe, but the raw string handed to `glob()` below would then need
        // "storage/tmp" to actually exist to resolve through it — silently finding
        // nothing even though the (collapsed) directory the guard approved is real.
        $dir = base_path($this->guardBackupDirSafe($this->backupDir()));

        // `glob()`, not `File::isDirectory()` + `File::directories()`: that two-step
        // check-then-act pair races if the directory is removed in between (by another
        // process, or a concurrent `sync:backups-clean` run) — Laravel's Finder-backed
        // `directories()` throws on a now-missing directory. `glob()` is a single call
        // that never throws, returning an empty list either way.
        //
        // `$dir` itself is escaped (only the trailing "/*" is left as an actual
        // wildcard): a configured `backup_dir` containing a glob metacharacter (e.g.
        // "backups*") would otherwise widen the pattern to match sibling directories
        // too, and `--all` would delete timestamp-named folders outside `backup_dir`.
        $directories = glob($this->escapeGlobPattern($dir).'/*', GLOB_ONLYDIR) ?: [];

        // Excludes symlinks even though `GLOB_ONLYDIR` (and thus `is_dir()`) follows
        // them: a symlinked entry here would let `sync:backups-clean` hand
        // `File::deleteDirectory()` a path whose *contents* live outside `backup_dir`,
        // silently wiping whatever the link points at instead of a real backup folder.
        //
        // `tryFromPath()`, not a separate validity check followed by `fromPath()`: both
        // would parse the folder name against `Backup::FORMAT`, so checking validity
        // and hydrating separately would parse every real backup folder's name twice.
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
     * Re-checks the folder is still safe to act on immediately before deleting (see
     * `isUnsafeToActOn()`) — without it, `File::deleteDirectory()` would follow a symlink
     * (at the leaf or an ancestor) and delete whatever it now points at instead of a real
     * backup folder.
     *
     * `File::deleteDirectory()`'s own return value isn't trustworthy: it reports success
     * once the top-level directory existed, even when an individual file inside failed to
     * delete (in which case the directory itself survives, non-empty) — checking the
     * directory is actually gone afterward is the only reliable signal.
     */
    public function deleteBackup(BackupFolder $folder): bool
    {
        if ($this->isUnsafeToActOn($folder)) {
            return false;
        }

        File::deleteDirectory($folder->path);

        return ! File::isDirectory($folder->path);
    }

    /**
     * Restore a backup folder's contents back onto the project root.
     *
     * Runs `rsync` directly, not via `PendingSync`: a restore has no remote, recipe, or
     * rsync-option shape to build one of those from, just a single local copy.
     */
    public function restoreBackup(BackupFolder $folder, bool $dry, ?Closure $onOutput = null): bool
    {
        if ($this->isUnsafeToActOn($folder)) {
            return false;
        }

        return Process::forever()->run((new RestoreCommand($folder, $dry))->toArgs(), $onOutput)->successful();
    }

    /**
     * Whether a backup folder is no longer safe to delete or restore, re-checked
     * immediately before acting. Shared by `deleteBackup()` and `restoreBackup()`: both
     * have an interactive flow (a `multiselect()`/`select()` plus a `confirm()` prompt)
     * between listing and acting — an arbitrarily long, user-paced window during which
     * the folder could have changed (a concurrent process, or a race).
     *
     * Two independent checks, not one:
     *
     * - `is_link()` on the folder itself: catches the *leaf* being swapped for a symlink
     *   since listing, regardless of what it now points at — `backups()`'s own
     *   listing-time filter already excludes symlinks, so any leaf symlink here is new.
     * - Real-path containment against the project root: `backup_dir` itself is allowed
     *   to be a symlink (see `guardBackupDirSafe()`), validated only at listing time —
     *   catches that *ancestor* symlink being repointed outside the project afterward,
     *   which `is_link()` on the leaf alone can't see (the leaf can be a perfectly real
     *   directory at the *new*, now-external location `backup_dir` resolves to).
     *
     * Mirrors `guardBackupDirNotEscapingRootOnDisk()`'s same real-path check for
     * `backup_dir` itself; unlike that guard, no "walk up to the nearest existing
     * ancestor" is needed here, since the folder is already known to exist.
     */
    private function isUnsafeToActOn(BackupFolder $folder): bool
    {
        if (is_link($folder->path)) {
            return true;
        }

        $normalizedReal = $this->normalizeRealpath(realpath($folder->path));
        $normalizedRoot = $this->normalizeRealpath(realpath(base_path()));

        return $normalizedReal === null || $normalizedRoot === null || ! $this->isPathWithin($normalizedReal, $normalizedRoot);
    }

    /**
     * Select backups by retention criteria: older than `$olderThan` days (if given),
     * excluding the `$keep` newest (if given) — so the two combine into the common
     * "delete anything old, but never touch the N most recent" rotation, and `--keep`
     * alone still works as a plain backup-count cap.
     *
     * `$backups` must already be sorted newest-first (as `backups()` returns them), so
     * the N newest are simply its first N.
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
     * Takes the directory to check explicitly, rather than always reading
     * `$this->backupDir()` itself — `prepare()` needs to validate a specific `Backup`'s
     * own `dir`, which could differ from the currently configured value.
     *
     * First resolves the directory lexically (no filesystem access, so this works even
     * before the directory exists) and refuses it when it's blank, absolute (a leading
     * "/" or a Windows drive letter like "C:"), resolves to the project root itself, or
     * ever steps above the root via a ".." segment. `backup_dir` is documented as
     * relative to the project root, so the absolute case is refused explicitly — without
     * it, a path like "/tmp" would have its leading slash silently dropped by the
     * segment parser below and be misread as the relative path "tmp" instead of being
     * rejected. Then, if the directory (or one of its parents) already exists, resolves
     * it for real and refuses it if a symlink leads outside the project (or straight at
     * the project root) — the lexical check alone can't catch a `backup_dir` that looks
     * like a normal, contained relative path but is actually a symlink to somewhere else.
     *
     * Returns the dot-collapsed relative path it just validated, so a caller that goes
     * on to actually read or write that directory uses exactly what was checked, not
     * the raw (possibly ".."-laden) configured string.
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
     * without touching the filesystem.
     *
     * Shared by `guardBackupDirSafe()` and `guardBackupNotNested()`, both of which
     * reject the path outright if a ".." pops above where collapsing started —
     * reported via `$escaped` — rather than trusting a caller to have run the other
     * guard first.
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
     * Compares the two `realpath()` results case-sensitively (separators normalized,
     * but NOT lowercased like `normalizePath()` does elsewhere in this class) — this is
     * the one comparison in the class that resolves symlinks against real disk state to
     * decide whether a path is safe, so folding case here would let a symlink into a
     * case-differing sibling directory (a genuinely different directory on a
     * case-sensitive filesystem, e.g. Linux) be misread as "inside" the project root.
     * The other, non-disk-resolving comparisons in this class stay case-folded on
     * purpose: they're not deciding filesystem safety, just matching a config-provided
     * path against `base_path()`'s own formatting.
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
     * Normalize a `realpath()` result for the on-disk containment check: separators
     * only, deliberately NOT case-folded (see `guardBackupDirNotEscapingRootOnDisk()`).
     * A failed `realpath()` (`false`) normalizes to `null` rather than being treated as
     * a literal path.
     */
    private function normalizeRealpath(string|false $path): ?string
    {
        return $path === false ? null : str_replace('\\', '/', $path);
    }

    /**
     * Sum the size, in bytes, of every file (including hidden ones, e.g. a backed-up
     * `.env`) under the given directory, recursing into subdirectories.
     *
     * Walks the tree with `scandir()`, not `File::allFiles()`: the latter is
     * Finder-backed and throws if the directory vanishes between being listed by
     * `backups()` and being sized here — the same class of race `backups()` itself
     * avoids by using `glob()` instead of `File::directories()`. Suppressed and
     * defaulted to an empty list on failure, `scandir()` never throws either, so a
     * vanished folder naturally sizes to 0 with no separate check needed. Unlike
     * `glob()`, it also lists hidden entries and regular ones in a single pass (no
     * "/*" + "/.*" pair needed) and never interprets `$path` as a pattern, so a
     * backed-up file or directory containing a glob metacharacter in its own name
     * (e.g. "report[final].csv" is a perfectly valid filename) can't widen it either.
     *
     * Never follows a symlinked entry: `rsync --archive` (how a backup is populated)
     * preserves symlinks from the source tree verbatim, so a backup folder can contain
     * one pointing back at an ancestor of itself — recursing into it would re-walk the
     * same real directories over and over (inflating the reported size) until the
     * built-up path finally exceeds the OS path-length limit.
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
     * Escape glob metacharacters ("*", "?", "[", "]") in a literal filesystem path, so
     * it can be used as the fixed prefix of a `glob()` pattern without any of its own
     * characters being reinterpreted as wildcards.
     *
     * Wraps each metacharacter in its own single-character bracket expression (e.g.
     * "*" becomes "[*]") — the standard glob-quoting idiom, and one that works
     * cross-platform: `glob()`'s other escaping mechanism (a literal backslash) is
     * ambiguous with the path separator on Windows, but "[" and "]" never are.
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
     * Compares `$backup->dir` dot-collapsed, not as the raw configured string: a
     * redundant ".." segment (e.g. "storage/tmp/../app/assets/.sync-backups") resolves
     * on disk to a path nested inside a recipe directory, but a plain string comparison
     * against the uncollapsed literal wouldn't recognize it as such and would let it
     * through.
     *
     * Also refuses `$backup->dir` outright if it steps above the project root — public,
     * like this method, so a caller isn't relying purely on convention (running
     * `guardBackupDirSafe()` first) for that half of the safety check too.
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
     * Guard both that `backup_dir` is safe to write into and that it doesn't nest
     * inside a recipe path, for a `Backup` `prepare()` didn't create itself (so it
     * can't already trust `startBackup()`'s own guarding — a caller-supplied `Backup`
     * might carry a dir `guardBackupDirSafe()` never validated at all).
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
