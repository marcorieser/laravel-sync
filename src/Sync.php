<?php

declare(strict_types=1);

namespace MarcoRieser\Sync;

use Illuminate\Support\Collection;
use MarcoRieser\Sync\Data\Backup;
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
        return (string) config('sync.backup_dir', '.sync-backups');
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
        foreach ($recipes->flatMap(fn (Recipe $recipe) => $recipe->paths)->unique() as $path) {
            // On Windows, a local remote's `root` (typically base_path()) carries
            // backslashes that Remote::path() doesn't normalize, only base_path() here
            // does — so both sides need normalizing before comparing, not just one.
            $remotePath = str_replace('\\', '/', $remote->path($path));
            $localPath = str_replace('\\', '/', base_path($path));

            if ($remotePath === $localPath) {
                throw SyncException::samePath($path);
            }
        }
    }
}
