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
        return $this->recipes
            ->flatMap(fn (Recipe $recipe) => $recipe->paths)
            ->unique()
            ->values()
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

        return $this->recipes
            ->flatMap(fn (Recipe $recipe) => $recipe->paths)
            ->unique()
            ->values()
            ->map(fn (string $path) => new BackupCommand($path, $this->backup->dir, $this->backup->timestamp));
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
        $backedUp = $this->backups()
            ->map(fn (BackupCommand $command) => Process::forever()->run($command->toArgs(), $onOutput))
            ->every(fn (ProcessResult $result) => $result->successful());

        if (! $backedUp) {
            return false;
        }

        return $this->commands()
            ->map(fn (RsyncCommand $command) => Process::forever()->run($command->toArgs(), $onOutput))
            ->every(fn (ProcessResult $result) => $result->successful());
    }
}
