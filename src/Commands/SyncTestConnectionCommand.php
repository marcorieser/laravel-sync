<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Commands\Concerns\ResolvesRemote;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Ssh\ConnectionCommand;

/**
 * Uses `ResolvesRemote`, not the full `ResolvesSyncInput` — this command resolves only a
 * remote, none of the operation/recipes/rsync-option shape that trait is built around
 * (and Larastan checks a trait's option/argument references against each using command's
 * own `$signature`, so mixing in the whole thing here would fail analysis over options
 * this command doesn't have).
 */
class SyncTestConnectionCommand extends Command
{
    use ResolvesRemote;

    /**
     * Bounds the whole SSH round trip, on top of `ConnectionCommand`'s own
     * `ConnectTimeout` — a safety net against the remote command itself hanging once
     * connected.
     */
    private const int TIMEOUT_SECONDS = 10;

    /**
     * The command signature.
     */
    protected $signature = 'sync:test-connection
        {remote? : The remote to test}';

    /**
     * The command description.
     */
    protected $description = 'Test the SSH connection (and root path) for a remote';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $remote = $this->resolveRemote('Which remote do you want to test?');
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($remote->isLocal()) {
            $this->info(sprintf('"%s" is a local remote — no SSH connection needed.', $remote->name));

            return self::SUCCESS;
        }

        $this->comment(sprintf('Connecting to "%s@%s:%d"...', $remote->user, $remote->host, $remote->port));

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run((new ConnectionCommand($remote))->toArgs());
        } catch (ProcessTimedOutException) {
            $this->error(sprintf(
                'Connecting to "%s" timed out after %d seconds.',
                $remote->name,
                self::TIMEOUT_SECONDS,
            ));

            return self::FAILURE;
        }

        if ($result->successful()) {
            $this->info(sprintf('Connected to "%s", and "%s" exists on the remote.', $remote->name, $remote->root));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Could not connect to "%s", or "%s" does not exist on the remote.',
            $remote->name,
            $remote->root,
        ));

        // A separate `line()` call, not appended to the `error()` message above: Laravel's
        // command-testing assertions (`expectsOutputToContain()`) match against individual
        // output writes, and a single write's embedded newline isn't reliably matched as
        // two separate lines.
        if (($errorOutput = trim($result->errorOutput())) !== '') {
            $this->line($errorOutput);
        }

        return self::FAILURE;
    }
}
