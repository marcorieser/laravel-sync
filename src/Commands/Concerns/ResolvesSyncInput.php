<?php

declare(strict_types=1);

namespace Sync\Sync\Commands\Concerns;

use Closure;
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
            $sync = $this->syncService();
            $operation = $this->resolveOperation();
            $remote = $this->resolveRemote();

            $sync->guardReadOnly($operation, $remote);

            $recipes = $this->resolveRecipes();

            $sync->guardNotSamePath($remote, $recipes);

            return $sync->prepare($operation, $remote, $recipes, $this->resolveOptions());
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return null;
        }
    }

    protected function syncService(): Sync
    {
        return app(Sync::class);
    }

    protected function resolveOperation(): Operation
    {
        $value = $this->resolveArgumentOrPrompt(
            argument: 'operation',
            label: 'Which operation do you want to perform?',
            options: Operation::options(),
            missingException: fn () => SyncException::operationRequired(),
        );

        try {
            return Operation::fromInput($value);
        } catch (ValueError $exception) {
            throw SyncException::invalidOperation($exception);
        }
    }

    protected function resolveRemote(): Remote
    {
        $sync = $this->syncService();

        $value = $this->resolveArgumentOrPrompt(
            argument: 'remote',
            label: 'Which remote do you want to sync with?',
            options: $sync->remotes()->keys()->all(),
            missingException: fn () => SyncException::remoteRequired(),
        );

        return $sync->remote($value);
    }

    /**
     * @return Collection<int, Recipe>
     */
    protected function resolveRecipes(): Collection
    {
        $sync = $this->syncService();

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

        // A purely numeric recipe name (e.g. "2024") comes back as an int here, since PHP
        // coerces numeric-string array keys to int wherever recipe names pass through an
        // array key (config, `recipes()->keys()`, `multiselect()`'s selected values).
        $names = collect($names)->map(fn (mixed $name) => (string) $name)->values()->all();

        if ($names === []) {
            throw SyncException::noRecipeSelected();
        }

        return collect($names)->map(fn (string $name) => $sync->recipe($name))->values();
    }

    protected function resolveOptions(): RsyncOptions
    {
        $configDefaults = $this->syncService()->defaultOptions();
        $cli = collect(Sync::filterStrings((array) $this->option('option')))
            ->filter(fn (string $flag) => $flag !== '')
            ->values()
            ->all();

        $flags = match (true) {
            $cli !== [] => $cli,
            $this->input->isInteractive() => multiselect(
                label: 'Which rsync options do you want to use?',
                options: $this->orderOptionsByDefault($configDefaults),
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

    /**
     * Read a command argument, prompting for it interactively when missing,
     * and fail with `$missingException` when it's still not a non-empty string.
     *
     * @param  array<int|string, string>  $options
     */
    private function resolveArgumentOrPrompt(string $argument, string $label, array $options, Closure $missingException): string
    {
        $value = $this->argument($argument);

        if (! is_string($value) && $this->input->isInteractive()) {
            $value = select(label: $label, options: $options);
        }

        if (! is_string($value) || $value === '') {
            throw $missingException();
        }

        return $value;
    }

    /**
     * Move the config-default flags to the front of the options list, so they're
     * easiest to spot (and already pre-checked) in the `multiselect()` prompt.
     *
     * @param  array<int, string>  $configDefaults
     * @return array<string, string>
     */
    private function orderOptionsByDefault(array $configDefaults): array
    {
        return collect(RsyncOptions::AVAILABLE)
            ->sortBy(fn (string $label, string $flag) => in_array($flag, $configDefaults, true) ? 0 : 1)
            ->all();
    }
}
