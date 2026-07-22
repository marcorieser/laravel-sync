<?php

declare(strict_types=1);

namespace Sync\Sync\Commands;

use Illuminate\Console\Command;
use Sync\Sync\Commands\Concerns\ResolvesSyncInput;
use Sync\Sync\Data\Recipe;
use Sync\Sync\Enums\Operation;
use Sync\Sync\PendingSync;

use function Laravel\Prompts\confirm;

class SyncCommand extends Command
{
    use ResolvesSyncInput;

    /**
     * The command signature.
     */
    protected $signature = 'sync
        {operation? : The operation to perform (push or pull)}
        {remote? : The remote to sync with}
        {recipe?* : The recipes defining the paths to sync}
        {--O|option=* : Override the default rsync options}
        {--D|dry : Perform a dry run of the sync}
        {--A|all : Sync all recipes}';

    /**
     * The command description.
     */
    protected $description = 'Sync files and folders between environments via rsync';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (($pending = $this->resolvePendingSync()) === null) {
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');

        if (! $dry && $this->input->isInteractive() && ! $this->confirmSync($pending)) {
            $this->comment('Sync aborted.');

            return self::SUCCESS;
        }

        $shouldStreamOutput = $dry || $pending->options->producesOutput();

        $pending->run(
            $shouldStreamOutput ? fn (string $type, string $output) => $this->output->write($output) : null,
        );

        $this->info($dry ? 'Dry run completed successfully.' : 'Sync completed successfully.');

        return self::SUCCESS;
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
