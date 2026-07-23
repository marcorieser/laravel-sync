<?php

declare(strict_types=1);

namespace Sync\Sync;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Sync\Sync\Data\Recipe;
use Sync\Sync\Data\Remote;
use Sync\Sync\Enums\Operation;
use Sync\Sync\Rsync\RsyncCommand;
use Sync\Sync\Rsync\RsyncOptions;

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
     * Run every rsync command, one process at a time.
     *
     * @return bool Whether every command completed successfully.
     */
    public function run(?Closure $onOutput = null): bool
    {
        return $this->commands()
            ->map(fn (RsyncCommand $command) => Process::forever()->run($command->toArgs(), $onOutput))
            ->every(fn (ProcessResult $result) => $result->successful());
    }
}
