<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Commands\Concerns;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use MarcoRieser\Sync\Data\Backup;
use MarcoRieser\Sync\Data\Recipe;
use MarcoRieser\Sync\Data\Remote;
use MarcoRieser\Sync\Enums\Operation;
use MarcoRieser\Sync\Exceptions\SyncException;
use MarcoRieser\Sync\PendingSync;
use MarcoRieser\Sync\Rsync\RsyncOptions;
use MarcoRieser\Sync\Sync;
use Symfony\Component\Console\Output\OutputInterface;
use ValueError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Shared argument/option resolution for the sync commands.
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
     *
     * Guards are run here as each of their inputs becomes available, so a violation fails
     * before the next prompt instead of after it — by the time every value is known, they've
     * all already passed, so this builds the `PendingSync` directly instead of going through
     * `Sync::prepare()`, which would only re-run the same guards a second time.
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

            $backup = null;

            if ($this->resolveBackup($operation)) {
                $backup = $sync->startBackup();
                $sync->guardBackupNotNested($backup, $recipes);
            }

            return new PendingSync($operation, $remote, $recipes, $this->resolveOptions($backup instanceof Backup), $backup);
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return null;
        }
    }

    protected function syncService(): Sync
    {
        return resolve(Sync::class);
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

    /**
     * Resolve whether local files should be backed up before this run.
     *
     * Only ever applies to a real (non-dry) pull, since only a pull overwrites local
     * files. `--backup` decides it outright; otherwise, when pulling interactively,
     * confirm before the rsync-options prompt so it's asked up front — but only for
     * commands that actually run something (see `promptsForBackupConfirmation()`), so
     * a preview command never implies an action it doesn't take.
     */
    protected function resolveBackup(Operation $operation): bool
    {
        if ($operation !== Operation::Pull || (bool) $this->option('dry')) {
            return false;
        }

        if ($this->option('backup')) {
            return true;
        }

        return $this->promptsForBackupConfirmation()
            && $this->input->isInteractive()
            && confirm(label: 'Back up the local files before pulling?', default: false);
    }

    /**
     * Whether this command should interactively confirm a backup before assuming one.
     *
     * Defaults to `false`: most consumers of this trait (`sync:list`, `sync:commands`)
     * only preview and never call `runBackup()`/`runSync()`, so asking would misleadingly
     * imply an action is about to happen. `sync` is the exception — it runs what it
     * resolves, so it overrides this to `true`. `--backup` still works explicitly either
     * way; this only gates the interactive confirm.
     */
    protected function promptsForBackupConfirmation(): bool
    {
        return false;
    }

    protected function resolveOptions(bool $backup): RsyncOptions
    {
        $configDefaults = $this->syncService()->defaultOptions();
        $verbose = $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
        $cli = collect(Sync::filterStrings((array) $this->option('option')))
            ->filter(fn (string $flag) => $flag !== '')
            ->values()
            ->all();

        $flags = match (true) {
            $cli !== [] => $cli,
            $this->input->isInteractive() => multiselect(
                label: 'Which rsync options do you want to use?',
                options: $this->orderOptionsByDefault($configDefaults, $verbose, $backup),
                default: $this->defaultOptionsForPrompt($configDefaults, $verbose, $backup),
            ),
            default => $configDefaults,
        };

        return RsyncOptions::resolve(
            flags: collect($flags)->map(fn (mixed $flag) => (string) $flag)->values()->all(),
            dry: (bool) $this->option('dry'),
            verbose: $verbose,
            backup: $backup,
        );
    }

    /**
     * Read a command argument, prompting for it interactively when missing,
     * and fail with `$missingException` when it's still not a non-empty string.
     *
     * @param  array<int|string, string>  $options
     * @param  Closure(): SyncException  $missingException
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
    private function orderOptionsByDefault(array $configDefaults, bool $verbose, bool $backup): array
    {
        return collect(RsyncOptions::AVAILABLE)
            ->reject(fn (string $label, string $flag) => $this->isExcludedFromOptionsPrompt($flag, $verbose, $backup))
            ->sortBy(fn (string $label, string $flag) => in_array($flag, $configDefaults, true) ? 0 : 1)
            ->all();
    }

    /**
     * Filter the config-default flags down to what the `multiselect()` prompt's
     * default selection should show.
     *
     * Must exclude exactly what `orderOptionsByDefault()` excludes from the choices
     * themselves — a default referencing an option that isn't offered breaks the
     * prompt when accepted as-is.
     *
     * @param  array<int, string>  $configDefaults
     * @return array<int, string>
     */
    private function defaultOptionsForPrompt(array $configDefaults, bool $verbose, bool $backup): array
    {
        return collect($configDefaults)
            ->reject(fn (string $flag) => $this->isExcludedFromOptionsPrompt($flag, $verbose, $backup))
            ->values()
            ->all();
    }

    /**
     * Whether a flag should be excluded from the rsync-options prompt entirely
     * (both its choices and its default selection).
     *
     * When `$verbose` is true, `RsyncOptions::OUTPUT_PRODUCING` flags are excluded —
     * `resolve()` force-adds them regardless of what's picked here, so leaving them
     * pickable would let a user "uncheck" a flag that stays on anyway. Likewise, when
     * `$backup` is true, `--backup` is excluded — `resolve()` strips it regardless,
     * since the package's own backup pass already covers it.
     */
    private function isExcludedFromOptionsPrompt(string $flag, bool $verbose, bool $backup): bool
    {
        return ($verbose && in_array($flag, RsyncOptions::OUTPUT_PRODUCING, true))
            || ($backup && $flag === '--backup');
    }
}
