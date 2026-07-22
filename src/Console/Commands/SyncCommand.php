<?php

declare(strict_types=1);

namespace Sync\Sync\Console\Commands;

use Illuminate\Console\Command;

class SyncCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-sync:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-sync.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Sync placeholder command executed.');

        return self::SUCCESS;
    }
}
