<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Vitamin2\Sync\Commands\Concerns\ConfirmsUnlessSkipped;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Sync;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\table;

/**
 * Doesn't use `ResolvesSyncInput` — it resolves no operation, remote, recipes, or
 * rsync options, so that trait's shape doesn't apply here.
 */
class SyncBackupsCleanCommand extends Command
{
    use ConfirmsUnlessSkipped;

    /**
     * The command signature.
     */
    protected $signature = 'sync:backups-clean
        {--A|all : Delete every backup}
        {--D|dry : Preview which backups would be deleted}
        {--F|force : Skip the confirmation prompt}
        {--K|keep= : Keep the N newest backups, deleting the rest}
        {--older-than= : Delete backups older than N days}';

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
            // Validated before backups() and the empty check: a malformed value or
            // --all conflict must fail the same way whether or not backups exist —
            // no silent "No backups found" on a typo'd flag the one time the dir's empty.
            [$keep, $olderThan] = $this->resolveRetentionOptions();

            $backups = $sync->backups();

            if ($backups->isEmpty()) {
                $this->info(sprintf('No backups found in "%s".', $sync->backupDir()));

                return self::SUCCESS;
            }

            $selected = $this->resolveSelection($backups, $keep, $olderThan, $sync);

            if ($selected->isEmpty()) {
                $this->info('No backups match the given retention criteria.');

                return self::SUCCESS;
            }
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

        return $this->deleteSelection($selected, $sync);
    }

    /**
     * Resolve which backups to delete: by retention criteria (`--keep`/`--older-than`)
     * when either is given, every backup with `--all`, an interactive multiselect
     * otherwise, or a friendly error when nothing applies.
     *
     * @param  Collection<int, BackupFolder>  $backups
     * @return Collection<int, BackupFolder>
     */
    private function resolveSelection(Collection $backups, ?int $keep, ?int $olderThan, Sync $sync): Collection
    {
        if ($keep !== null || $olderThan !== null) {
            return $sync->filterByRetention($backups, $keep, $olderThan);
        }

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

        return $backups->whereIn('name', $names, true)->values();
    }

    /**
     * `--all` conflict is checked by presence alone, before parsing, so
     * `--all --keep=abc` reports the conflict rather than a value error that would
     * misleadingly suggest the number format is the problem.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveRetentionOptions(): array
    {
        $hasRetentionOption = $this->option('keep') !== null || $this->option('older-than') !== null;

        if ($hasRetentionOption && (bool) $this->option('all')) {
            throw SyncException::conflictingBackupSelection();
        }

        if (! $hasRetentionOption) {
            return [null, null];
        }

        return [
            $this->resolveNonNegativeIntOption('keep'),
            // Bounded, unlike `--keep`: fed into now()->subDays(), a huge value wraps
            // Carbon's day arithmetic back around to a cutoff near *now*, silently
            // inverting intent from "keep almost everything" to "delete everything".
            // `--keep` has no such arithmetic — take() on a huge value just harmlessly
            // takes the whole (smaller) backup list.
            $this->resolveNonNegativeIntOption('older-than', self::MAX_OLDER_THAN_DAYS),
        ];
    }

    /**
     * Comfortably beyond any real backup-retention window (over 100 years), while
     * staying far short of where `Carbon::subDays()`'s day-to-timestamp arithmetic
     * risks overflowing.
     */
    private const int MAX_OLDER_THAN_DAYS = 36500;

    private function resolveNonNegativeIntOption(string $option, ?int $max = null): ?int
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            // Not reachable via `{--keep=}`'s single-value definition, but option()
            // is typed generically across all options, so this guards the cast below.
            throw SyncException::invalidRetentionValue($option, get_debug_type($value));
        }

        if (! ctype_digit($value)) {
            throw SyncException::invalidRetentionValue($option, $value);
        }

        $parsed = (int) $value;

        if ($max !== null && $parsed > $max) {
            throw SyncException::retentionValueTooLarge($option, $value, $max);
        }

        return $parsed;
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
                $folder->formattedSize(),
                $folder->age(),
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
                'You are about to permanently delete %d %s (%s) from "%s". Are you sure?',
                $selected->count(),
                Str::plural('backup', $selected->count()),
                Number::fileSize($selected->sum(fn (BackupFolder $folder) => $folder->size), precision: 1),
                $sync->backupDir(),
            ),
            default: false,
        );
    }

    /**
     * @param  Collection<int, BackupFolder>  $selected
     */
    private function deleteSelection(Collection $selected, Sync $sync): int
    {
        $freed = 0;
        $failed = [];

        foreach ($selected as $folder) {
            if (! $sync->deleteBackup($folder)) {
                $failed[] = $folder->name;

                continue;
            }

            $freed += $folder->size;
        }

        $deleted = $selected->count() - count($failed);

        if ($deleted > 0) {
            $this->info(sprintf(
                'Deleted %d %s, freeing %s.',
                $deleted,
                Str::plural('backup', $deleted),
                Number::fileSize($freed, precision: 1),
            ));
        }

        if ($failed !== []) {
            $this->error(sprintf(
                'Failed to delete %d %s: %s.',
                count($failed),
                Str::plural('backup', count($failed)),
                implode(', ', array_map(fn (string $name) => "\"{$name}\"", $failed)),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
