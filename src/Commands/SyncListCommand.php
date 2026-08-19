<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Support\Arrayable;
use Vitamin2\Sync\Commands\Concerns\ResolvesSyncInput;
use Vitamin2\Sync\PendingSync;

use function Laravel\Prompts\table;

class SyncListCommand extends Command
{
    use ResolvesSyncInput;

    protected $signature = 'sync:list
        {operation? : The operation to perform (push or pull)}
        {remote? : The remote to sync with}
        {recipe?* : The recipes defining the paths to sync}
        {--O|option=* : Override the default rsync options}
        {--A|all : Sync all recipes}
        {--D|dry : Preview the options used for a dry run}
        {--B|backup : Preview the backup that would run before a real pull}';

    protected $description = 'List the origin, target, options, and port for a sync';

    public function handle(): int
    {
        if (! ($pending = $this->resolvePendingSync()) instanceof PendingSync) {
            return self::FAILURE;
        }

        $rows = $pending->backups()
            ->concat($pending->commands())
            ->map(fn (Arrayable $command) => array_values($command->toArray()));

        table(
            headers: ['Origin', 'Target', 'Options', 'Port'],
            rows: $rows->all(),
        );

        return self::SUCCESS;
    }
}
