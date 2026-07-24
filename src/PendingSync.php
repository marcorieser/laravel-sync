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
     * @param  Collection<int, Recipe>  $recipes
     */
    public function __construct(
        public Operation $operation,
        public Remote $remote,
        public Collection $recipes,
        public RsyncOptions $options,
        public ?Backup $backup = null,
    ) {}

    /**
     * Build one rsync command per resolved, de-duplicated recipe path.
     *
     * @return Collection<int, RsyncCommand>
     */
    public function commands(): Collection
    {
        return $this->resolvedPaths()
            ->map(fn (string $path) => new RsyncCommand($this->operation, $this->remote, $path, $this->options));
    }

    /**
     * Build one backup command per resolved, de-duplicated recipe path.
     *
     * Empty unless a backup was requested and this is a pull — backups only ever
     * protect the local files a pull is about to overwrite.
     *
     * @return Collection<int, BackupCommand>
     */
    public function backups(): Collection
    {
        if ($this->backup === null || $this->operation !== Operation::Pull) {
            return collect();
        }

        return $this->resolvedPaths()
            ->map(fn (string $path) => new BackupCommand($path, $this->backup));
    }

    /**
     * Get every recipe path, flattened and de-duplicated.
     *
     * @return Collection<int, string>
     */
    private function resolvedPaths(): Collection
    {
        return once(fn () => $this->recipes
            ->flatMap(fn (Recipe $recipe) => $recipe->paths)
            ->unique()
            ->values());
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
