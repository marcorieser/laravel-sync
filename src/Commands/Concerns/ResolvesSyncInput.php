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
use ValueError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Shared argument/option resolution for the sync commands.
 *
 * Every command using this trait must build its `$signature` from `self::SIGNATURE`,
 * followed by its own `{--D|dry}` option (its description differs per command).
 *
 * @mixin Command
 */
trait ResolvesSyncInput
{
    /**
     * The `operation`/`remote`/`recipe`/`--option`/`--all` portion shared by every command
     * signature. Append the command-specific `--D|dry` option after it.
     */
    public const string SIGNATURE = '
        {operation? : The operation to perform (push or pull)}
        {remote? : The remote to sync with}
        {recipe?* : The recipes defining the paths to sync}
        {--O|option=* : Override the default rsync options}
        {--A|all : Sync all recipes}';

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
            $sync = $this->sync();
            $operation = $this->resolveOperation();
            $remote = $this->resolveRemote();

            $sync->guardReadOnly($operation, $remote);

            return $sync->build($operation, $remote, $this->resolveRecipes(), $this->resolveOptions());
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

        try {
            return Operation::fromInput($value);
        } catch (ValueError $exception) {
            throw SyncException::invalidOperation($exception);
        }
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

        $names = Sync::filterStrings((array) $this->argument('recipe'));

        if ($names === [] && $this->input->isInteractive()) {
            $names = confirm(label: 'Sync all recipes?', default: false)
                ? $sync->recipes()->keys()->all()
                : multiselect(
                    label: 'Which recipes do you want to sync?',
                    options: $sync->recipes()->keys()->all(),
                    required: true,
                );
        }

        $names = collect($names)->map(fn (mixed $name) => (string) $name)->values()->all();

        if ($names === []) {
            throw SyncException::noRecipeSelected();
        }

        return collect($names)->map(fn (string $name) => $sync->recipe($name))->values();
    }

    protected function resolveOptions(): RsyncOptions
    {
        $configDefaults = $this->sync()->defaultOptions();
        $cli = Sync::filterStrings((array) $this->option('option'));

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
            flags: collect($flags)->map(fn (mixed $flag) => (string) $flag)->values()->all(),
            dry: (bool) $this->option('dry'),
            verbose: $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE,
        );
    }
}
