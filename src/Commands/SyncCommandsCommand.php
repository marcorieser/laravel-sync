<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Commands;

use Illuminate\Console\Command;
use MarcoRieser\Sync\Commands\Concerns\ResolvesSyncInput;
use MarcoRieser\Sync\PendingSync;
use Stringable;

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
        {--D|dry : Preview the options used for a dry run}
        {--B|backup : Preview the backup that would run before a real pull}';

    /**
     * The command description.
     */
    protected $description = 'List the rsync commands that would be run';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! ($pending = $this->resolvePendingSync()) instanceof PendingSync) {
            return self::FAILURE;
        }

        $pending->backups()
            ->concat($pending->commands())
            ->each(fn (Stringable $command) => $this->line((string) $command));

        return self::SUCCESS;
    }
}
