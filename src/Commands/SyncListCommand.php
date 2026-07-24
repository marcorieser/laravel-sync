<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Commands;

use Illuminate\Console\Command;
use MarcoRieser\Sync\Commands\Concerns\ResolvesSyncInput;
use MarcoRieser\Sync\Rsync\RsyncCommand;

use function Laravel\Prompts\table;

class SyncListCommand extends Command
{
    use ResolvesSyncInput;

    /**
     * The command signature.
     */
    protected $signature = 'sync:list
        {operation? : The operation to perform (push or pull)}
        {remote? : The remote to sync with}
        {recipe?* : The recipes defining the paths to sync}
        {--O|option=* : Override the default rsync options}
        {--A|all : Sync all recipes}
        {--D|dry : Preview the options used for a dry run}';

    /**
     * The command description.
     */
    protected $description = 'List the origin, target, options, and port for a sync';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (($pending = $this->resolvePendingSync()) === null) {
            return self::FAILURE;
        }

        $commands = $pending->commands();

        table(
            headers: ['Origin', 'Target', 'Options', 'Port'],
            rows: $commands->map(fn (RsyncCommand $command) => array_values($command->toArray()))->all(),
        );

        return self::SUCCESS;
    }
}
