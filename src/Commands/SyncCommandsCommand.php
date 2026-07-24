<?php

declare(strict_types=1);

namespace Sync\Sync\Commands;

use Illuminate\Console\Command;
use Sync\Sync\Commands\Concerns\ResolvesSyncInput;
use Sync\Sync\Rsync\RsyncCommand;

class SyncCommandsCommand extends Command
{
    use ResolvesSyncInput;

    /**
     * The command signature.
     */
    protected $signature = 'sync:commands
        {operation? : The operation to perform (push or pull)}
        {remote? : The remote to sync with}
        {recipe?* : The recipes defining the paths to sync}
        {--O|option=* : Override the default rsync options}
        {--A|all : Sync all recipes}
        {--D|dry : Preview the options used for a dry run}';

    /**
     * The command description.
     */
    protected $description = 'List the rsync commands that would be run';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (($pending = $this->resolvePendingSync()) === null) {
            return self::FAILURE;
        }

        $pending->commands()->each(
            fn (RsyncCommand $command) => $this->line((string) $command),
        );

        return self::SUCCESS;
    }
}
