<?php

declare(strict_types=1);

namespace Vitamin2\Sync;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Rsync\BackupCommand;
use Vitamin2\Sync\Rsync\RsyncCommand;
use Vitamin2\Sync\Rsync\RsyncOptions;

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
            ->map(fn (array $entry) => new RsyncCommand(
                $this->operation,
                $this->remote,
                $entry['path'],
                $this->options->withExcludes($entry['excludes']),
            ));
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

        return $this->pathExcludes()
            ->map(fn (array $entry) => new BackupCommand($entry['path'], $this->backup));
    }

    /**
     * De-duplicated recipe path → union of excludes from every recipe containing it.
     * Not applied to backups() — a backup's BackupCommand is a fixed full copy.
     *
     * Not keyed by path in a native array/Collection: a purely-numeric path (e.g.
     * `"123"` in `releases/123/`) gets silently coerced to an int array key by PHP,
     * crashing the `string $path`-typed closures above under strict_types. Each path
     * is tracked as a value instead, found via strict `array_search(..., true)`.
     *
     * @return Collection<int, array{path: string, excludes: array<int, string>}>
     */
    private function pathExcludes(): Collection
    {
        return once(function () {
            /** @var list<array{path: string, excludes: array<int, string>}> $entries */
            $entries = [];

            foreach ($this->recipes as $recipe) {
                foreach ($recipe->paths as $path) {
                    $index = $this->indexOfPath($entries, $path);

                    if ($index === null) {
                        $entries[] = ['path' => $path, 'excludes' => $recipe->excludes];

                        continue;
                    }

                    $entries[$index] = [
                        'path' => $path,
                        'excludes' => array_values(array_unique([
                            ...$entries[$index]['excludes'],
                            ...$recipe->excludes,
                        ])),
                    ];
                }
            }

            return collect($entries);
        });
    }

    /**
     * Find the index of the entry for `$path`, or null if none exists yet — a plain
     * value search, not an array-key lookup (see `pathExcludes()`'s docblock for why).
     *
     * @param  list<array{path: string, excludes: array<int, string>}>  $entries
     */
    private function indexOfPath(array $entries, string $path): ?int
    {
        foreach ($entries as $index => $entry) {
            if ($entry['path'] === $path) {
                return $index;
            }
        }

        return null;
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
