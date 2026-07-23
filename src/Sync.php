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
     * @var ?Collection<string, Remote>
     */
    private ?Collection $remotes = null;

    /**
     * @var ?Collection<string, Recipe>
     */
    private ?Collection $recipes = null;

    /**
     * Get all remotes defined in the config.
     *
     * @return Collection<string, Remote>
     */
    public function remotes(): Collection
    {
        if ($this->remotes !== null) {
            return $this->remotes;
        }

        /** @var array<string, array{user?: string, host?: string, root: string, port?: int, read_only?: bool}> $remotes */
        $remotes = config('sync.remotes', []);

        if ($remotes === []) {
            throw SyncException::noRemotesConfigured();
        }

        return $this->remotes = collect($remotes)->map(
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
        if ($this->recipes !== null) {
            return $this->recipes;
        }

        /** @var array<string, array<int, string>> $recipes */
        $recipes = config('sync.recipes', []);

        if ($recipes === []) {
            throw SyncException::noRecipesConfigured();
        }

        return $this->recipes = collect($recipes)->map(
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
        $this->guardReadOnly($operation, $remote);

        return new PendingSync($operation, $remote, $recipes, $options);
    }

    /**
     * Guard against pushing to a read-only remote.
     */
    public function guardReadOnly(Operation $operation, Remote $remote): void
    {
        if ($operation === Operation::Push && $remote->readOnly) {
            throw SyncException::remoteIsReadOnly($remote->name);
        }
    }
}
