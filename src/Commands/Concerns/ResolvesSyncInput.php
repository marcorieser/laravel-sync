<?php

declare(strict_types=1);

namespace Sync\Sync\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\OutputInterface;
use Sync\Sync\Data\Recipe;
use Sync\Sync\Data\Remote;
use Sync\Sync\Enums\Operation;
use Sync\Sync\Exceptions\SyncException;
use Sync\Sync\PendingSync;
use Sync\Sync\Rsync\RsyncOptions;
use Sync\Sync\Sync;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Shared argument/option resolution for the sync commands.
 *
 * Every command using this trait must declare the following in its signature:
 * `{operation?} {remote?} {recipe?*} {--O|option=*} {--D|dry} {--A|all}`.
 *
 * @mixin Command
 */
trait ResolvesSyncInput
{
    /**
     * Resolve the operation, remote, recipes, and rsync options for this run, and prepare the sync.
     *
     * Prompts for anything missing when running interactively. Returns null (after printing
     * a friendly error) when the given input references config that doesn't exist, or when
     * the resolved operation and remote violate a sync guard (e.g. pushing to a read-only remote).
     */
    protected function resolvePendingSync(): ?PendingSync
    {
        try {
            return $this->sync()->for(
                $this->resolveOperation(),
                $this->resolveRemote(),
                $this->resolveRecipes(),
                $this->resolveOptions(),
            );
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return null;
        }
    }

    protected function sync(): Sync
    {
        return app(Sync::class);
    }

    protected function resolveOperation(): Operation
    {
        $value = $this->argument('operation');

        if (! is_string($value) && $this->input->isInteractive()) {
            $value = select(
                label: 'Which operation do you want to perform?',
                options: Operation::options(),
            );
        }

        if (! is_string($value) || $value === '') {
            throw SyncException::operationRequired();
        }

        return Operation::fromInput($value);
    }

    protected function resolveRemote(): Remote
    {
        $sync = $this->sync();
        $value = $this->argument('remote');

        if (! is_string($value) && $this->input->isInteractive()) {
            $value = select(
                label: 'Which remote do you want to sync with?',
                options: $sync->remotes()->keys()->all(),
            );
        }

        if (! is_string($value) || $value === '') {
            throw SyncException::remoteRequired();
        }

        return $sync->remote($value);
    }

    /**
     * @return Collection<int, Recipe>
     */
    protected function resolveRecipes(): Collection
    {
        $sync = $this->sync();

        if ($this->option('all')) {
            return $sync->recipes()->values();
        }

        $names = array_values(array_filter((array) $this->argument('recipe'), 'is_string'));

        if ($names === [] && $this->input->isInteractive()) {
            $names = confirm(label: 'Sync all recipes?', default: false)
                ? $sync->recipes()->keys()->all()
                : multiselect(
                    label: 'Which recipes do you want to sync?',
                    options: $sync->recipes()->keys()->all(),
                    required: true,
                );
        }

        $names = array_values(array_map('strval', $names));

        if ($names === []) {
            throw SyncException::noRecipeSelected();
        }

        return collect($names)->map(fn (string $name) => $sync->recipe($name))->values();
    }

    protected function resolveOptions(): RsyncOptions
    {
        $configDefaults = array_values(array_filter((array) config('sync.options', []), 'is_string'));
        $cli = array_values(array_filter((array) $this->option('option'), 'is_string'));

        $flags = match (true) {
            $cli !== [] => $cli,
            $this->input->isInteractive() => multiselect(
                label: 'Which rsync options do you want to use?',
                options: RsyncOptions::AVAILABLE,
                default: $configDefaults,
            ),
            default => $configDefaults,
        };

        return RsyncOptions::resolve(
            flags: array_values(array_map('strval', $flags)),
            dry: (bool) $this->option('dry'),
            verbose: $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE,
        );
    }
}
