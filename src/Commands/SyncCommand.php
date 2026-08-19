<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Vitamin2\Sync\Commands\Concerns\ConfirmsUnlessSkipped;
use Vitamin2\Sync\Commands\Concerns\ResolvesSyncInput;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\PendingSync;

use function Laravel\Prompts\confirm;

class SyncCommand extends Command
{
    use ConfirmsUnlessSkipped;
    use ResolvesSyncInput;

    /**
     * The command signature.
     */
    protected $signature = 'sync
        {operation? : The operation to perform (push or pull)}
        {remote? : The remote to sync with}
        {recipe?* : The recipes defining the paths to sync}
        {--O|option=* : Override the default rsync options}
        {--A|all : Sync all recipes}
        {--D|dry : Perform a dry run of the sync}
        {--B|backup : Back up local files before a real pull}';

    /**
     * The command description.
     */
    protected $description = 'Sync files and folders between environments via rsync';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! ($pending = $this->resolvePendingSync()) instanceof PendingSync) {
            return self::FAILURE;
        }

        $lock = $this->syncService()->lock($pending->remote);

        try {
            if (! $lock->acquire()) {
                throw SyncException::lockUnavailable($pending->remote->name);
            }

            return $this->runPending($pending);
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function runPending(PendingSync $pending): int
    {
        $dry = (bool) $this->option('dry');

        if (! $this->confirmUnlessSkipped($dry, fn () => $this->confirmSync($pending))) {
            $this->comment('Sync aborted.');

            return self::SUCCESS;
        }

        $shouldStreamOutput = $dry || $pending->options->producesOutput();
        $onOutput = $shouldStreamOutput ? fn (string $type, string $output) => $this->output->write($output) : null;

        if ($pending->backup instanceof Backup) {
            $this->comment('Backing up local files...');

            if (! $pending->runBackup($onOutput)) {
                $this->error('Backup failed. Nothing was synced — your local files are untouched.');

                return self::FAILURE;
            }
        }

        if (! $pending->runSync($onOutput)) {
            $this->error($dry ? 'Dry run failed.' : 'Sync failed.');

            return self::FAILURE;
        }

        $this->info($dry ? 'Dry run completed successfully.' : 'Sync completed successfully.');

        return self::SUCCESS;
    }

    /**
     * `sync` is the one command that actually runs the backup it confirms, so it
     * overrides the trait's `false` default.
     */
    protected function promptsForBackupConfirmation(): bool
    {
        return true;
    }

    private function confirmSync(PendingSync $pending): bool
    {
        $names = $pending->recipes->map(fn (Recipe $recipe) => $recipe->name)->implode(' and ');
        $preposition = $pending->operation === Operation::Push ? 'to' : 'from';

        return confirm(
            label: sprintf(
                'You are about to %s "%s" %s "%s". Are you sure?',
                $pending->operation->value,
                $names,
                $preposition,
                $pending->remote->name,
            ),
            default: false,
        );
    }
}
