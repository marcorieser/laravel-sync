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
 * Uses `ResolvesRemote`, not the full `ResolvesSyncInput`: this command resolves only a
 * remote, and Larastan checks a trait's option references against each using command's own
 * `$signature`, so the full trait would fail analysis over options declared here.
 */
class SyncTestConnectionCommand extends Command
{
    use ResolvesRemote;

    /**
     * Bounds the whole SSH round trip, on top of `ConnectionCommand`'s own `ConnectTimeout`,
     * in case the remote command hangs once connected.
     */
    private const int TIMEOUT_SECONDS = 10;

    protected $signature = 'sync:test-connection
        {remote? : The remote to test}';

    protected $description = 'Test the SSH connection (and root path) for a remote';

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

        // A separate `line()`, not appended to the `error()` above: Laravel's output assertions
        // match individual writes, so an embedded newline isn't matched as two lines.
        if (($errorOutput = trim($result->errorOutput())) !== '') {
            $this->line($errorOutput);
        }

        return self::FAILURE;
    }
}
