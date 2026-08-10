<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use MarcoRieser\Sync\Commands\Concerns\ConfirmsUnlessSkipped;
use MarcoRieser\Sync\Data\BackupFolder;
use MarcoRieser\Sync\Exceptions\SyncException;
use MarcoRieser\Sync\Sync;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\table;

class SyncBackupsCleanCommand extends Command
{
    use ConfirmsUnlessSkipped;

    /**
     * The command signature.
     */
    protected $signature = 'sync:backups-clean
        {--A|all : Delete every backup}
        {--D|dry : Preview which backups would be deleted}
        {--F|force : Skip the confirmation prompt}';

    /**
     * The command description.
     */
    protected $description = 'Delete backup folders created by a backed-up pull';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sync = resolve(Sync::class);

        try {
            $sync->guardBackupDirSafe();

            $backups = $sync->backups();

            if ($backups->isEmpty()) {
                $this->info(sprintf('No backups found in "%s".', $sync->backupDir()));

                return self::SUCCESS;
            }

            $selected = $this->resolveSelection($backups);
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('dry')) {
            $this->previewSelection($selected);

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        if (! $this->confirmUnlessSkipped($force, fn () => $this->confirmDeletion($selected, $sync))) {
            $this->comment('Cleanup aborted.');

            return self::SUCCESS;
        }

        return $this->deleteSelection($selected);
    }

    /**
     * Resolve which backups to delete: every backup with `--all`, an interactive
     * multiselect otherwise, or a friendly error when neither applies.
     *
     * @param  Collection<int, BackupFolder>  $backups
     * @return Collection<int, BackupFolder>
     */
    private function resolveSelection(Collection $backups): Collection
    {
        if ((bool) $this->option('all')) {
            return $backups;
        }

        if (! $this->input->isInteractive()) {
            throw SyncException::noBackupSelected();
        }

        $names = multiselect(
            label: 'Which backups do you want to delete?',
            options: $backups->mapWithKeys(fn (BackupFolder $folder) => [$folder->name => $folder->label()])->all(),
            required: true,
        );

        return $backups->filter(fn (BackupFolder $folder) => in_array($folder->name, $names, true))->values();
    }

    /**
     * @param  Collection<int, BackupFolder>  $selected
     */
    private function previewSelection(Collection $selected): void
    {
        table(
            headers: ['Backup', 'Size', 'Created'],
            rows: $selected->map(fn (BackupFolder $folder) => [
                $folder->name,
                Number::fileSize($folder->size, precision: 1),
                $folder->createdAt->diffForHumans(),
            ])->all(),
        );

        $this->info('Dry run completed. Nothing was deleted.');
    }

    /**
     * @param  Collection<int, BackupFolder>  $selected
     */
    private function confirmDeletion(Collection $selected, Sync $sync): bool
    {
        return confirm(
            label: sprintf(
                'You are about to permanently delete %d backup(s) (%s) from "%s". Are you sure?',
                $selected->count(),
                Number::fileSize($selected->sum(fn (BackupFolder $folder) => $folder->size), precision: 1),
                $sync->backupDir(),
            ),
            default: false,
        );
    }

    /**
     * @param  Collection<int, BackupFolder>  $selected
     */
    private function deleteSelection(Collection $selected): int
    {
        $freed = 0;
        $failed = [];

        foreach ($selected as $folder) {
            File::deleteDirectory($folder->path);

            // `deleteDirectory()`'s return value isn't trustworthy: it reports success
            // once the top-level directory existed, even when an individual file inside
            // failed to delete (in which case the directory itself survives, non-empty).
            // Checking the directory is actually gone is the only reliable signal.
            if (File::isDirectory($folder->path)) {
                $failed[] = $folder->name;

                continue;
            }

            $freed += $folder->size;
        }

        $deleted = $selected->count() - count($failed);

        if ($deleted > 0) {
            $this->info(sprintf(
                'Deleted %d backup(s), freeing %s.',
                $deleted,
                Number::fileSize($freed, precision: 1),
            ));
        }

        if ($failed !== []) {
            $this->error(sprintf(
                'Failed to delete %d backup(s): %s.',
                count($failed),
                implode(', ', array_map(fn (string $name) => "\"{$name}\"", $failed)),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
