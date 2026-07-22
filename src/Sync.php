<?php

declare(strict_types=1);

namespace Sync\Sync;

use Illuminate\Support\Collection;
use Sync\Sync\Data\Recipe;
use Sync\Sync\Data\Remote;
use Sync\Sync\Enums\Operation;
use Sync\Sync\Exceptions\SyncException;
use Sync\Sync\Rsync\RsyncOptions;

class Sync
{
    /**
     * Get all remotes defined in the config.
     *
     * @return Collection<string, Remote>
     */
    public function remotes(): Collection
    {
        /** @var array<string, array{user?: string, host?: string, root: string, port?: int, read_only?: bool}> $remotes */
        $remotes = config('sync.remotes', []);

        if ($remotes === []) {
            throw SyncException::noRemotesConfigured();
        }

        return collect($remotes)->map(
            fn (array $config, string $name) => Remote::fromArray($name, $config),
        );
    }

    /**
     * Get all recipes defined in the config.
     *
     * @return Collection<string, Recipe>
     */
    public function recipes(): Collection
    {
        /** @var array<string, array<int, string>> $recipes */
        $recipes = config('sync.recipes', []);

        if ($recipes === []) {
            throw SyncException::noRecipesConfigured();
        }

        return collect($recipes)->map(
            fn (array $paths, string $name) => Recipe::fromArray($name, $paths),
        );
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
     * Prepare a sync for the given operation, remote, recipes, and options.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function for(Operation $operation, Remote $remote, Collection $recipes, RsyncOptions $options): PendingSync
    {
        if ($operation === Operation::Push && $remote->readOnly) {
            throw SyncException::remoteIsReadOnly($remote->name);
        }

        return new PendingSync($operation, $remote, $recipes, $options);
    }
}
