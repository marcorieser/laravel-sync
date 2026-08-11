<?php

declare(strict_types=1);

namespace MarcoRieser\Sync;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use MarcoRieser\Sync\Data\Backup;
use MarcoRieser\Sync\Data\Recipe;
use MarcoRieser\Sync\Data\Remote;
use MarcoRieser\Sync\Enums\Operation;
use MarcoRieser\Sync\Rsync\BackupCommand;
use MarcoRieser\Sync\Rsync\RsyncCommand;
use MarcoRieser\Sync\Rsync\RsyncOptions;

final readonly class PendingSync
{
    /**
     * A backup only ever applies to a pull — normalized here (not just left to the
     * caller) so `backup !== null` reliably implies a backup will run, regardless of
     * which operation a caller constructs this with.
     */
    public ?Backup $backup;

    /**
     * @param  Collection<int, Recipe>  $recipes
     */
    public function __construct(
        public Operation $operation,
        public Remote $remote,
        public Collection $recipes,
        public RsyncOptions $options,
        ?Backup $backup = null,
    ) {
        $this->backup = $operation === Operation::Pull ? $backup : null;
    }

    /**
     * Build one rsync command per resolved, de-duplicated recipe path, each with that
     * path's own recipe excludes layered onto the sync's shared rsync options.
     *
     * @return Collection<int, RsyncCommand>
     */
    public function commands(): Collection
    {
        return $this->pathExcludes()
            ->map(fn (array $excludes, string $path) => new RsyncCommand(
                $this->operation,
                $this->remote,
                $path,
                $this->options->withExcludes($excludes),
            ))
            ->values();
    }

    /**
     * Build one backup command per resolved, de-duplicated recipe path.
     *
     * Empty unless a backup was requested — the constructor already normalizes
     * `$backup` to null for anything but a pull, so this needs no operation check.
     *
     * @return Collection<int, BackupCommand>
     */
    public function backups(): Collection
    {
        if (! $this->backup instanceof Backup) {
            /** @var Collection<int, BackupCommand> $empty */
            $empty = collect();

            return $empty;
        }

        return $this->pathExcludes()->keys()
            ->map(fn (string $path) => new BackupCommand($path, $this->backup));
    }

    /**
     * Map each resolved, de-duplicated recipe path to the union of exclude patterns
     * from every selected recipe that includes it — a path can appear in more than one
     * recipe, and each contributes its own excludes to that path's merged rsync
     * command. Not applied to a backup pass (see `backups()`): a backed-up pull's own
     * `BackupCommand` is a fixed, independent full copy, not affected by the sync's
     * rsync options either.
     *
     * Each path's contributing recipes' exclude lists are collected as-is during the
     * loop and only deduplicated once, after the loop, rather than re-deduplicating
     * the growing list on every recipe that shares the path.
     *
     * @return Collection<string, list<string>>
     */
    private function pathExcludes(): Collection
    {
        return once(function () {
            $excludes = [];

            foreach ($this->recipes as $recipe) {
                foreach ($recipe->paths as $path) {
                    $excludes[$path][] = $recipe->excludes;
                }
            }

            return collect($excludes)->map(
                fn (array $lists) => array_values(array_unique(array_merge(...$lists))),
            );
        });
    }

    /**
     * Run the backup (if any), then every rsync command, one process at a time.
     *
     * The backup always runs first and must fully succeed before the sync starts —
     * otherwise we'd risk overwriting local files we failed to protect.
     *
     * @return bool Whether every command completed successfully.
     */
    public function run(?Closure $onOutput = null): bool
    {
        if (! $this->runBackup($onOutput)) {
            return false;
        }

        return $this->runSync($onOutput);
    }

    /**
     * Run the backup only, one process at a time.
     *
     * Exposed separately from `run()` so a caller can report a backup failure
     * distinctly from a sync failure — the two mean very different things for a
     * feature whose purpose is protecting local files.
     *
     * @return bool Whether every backup command completed successfully.
     */
    public function runBackup(?Closure $onOutput = null): bool
    {
        return $this->backups()
            ->map(fn (BackupCommand $command) => Process::forever()
                ->path($command->workingDirectory())
                ->run($command->toArgs(), $onOutput))
            ->every(fn (ProcessResult $result) => $result->successful());
    }

    /**
     * Run every rsync command, one process at a time.
     *
     * @return bool Whether every command completed successfully.
     */
    public function runSync(?Closure $onOutput = null): bool
    {
        return $this->commands()
            ->map(fn (RsyncCommand $command) => Process::forever()->run($command->toArgs(), $onOutput))
            ->every(fn (ProcessResult $result) => $result->successful());
    }
}
