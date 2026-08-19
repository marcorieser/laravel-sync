<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands\Concerns;

use Closure;
use Illuminate\Console\Command;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Sync;

use function Laravel\Prompts\select;

/**
 * Shared remote resolution for any command with a `{remote? : ...}` argument — split out
 * of `ResolvesSyncInput` (which composes this) so a command that resolves only a remote,
 * like `sync:test-connection`, doesn't have to mix in that trait's whole shape just to
 * reuse this piece. Larastan validates a trait's option/argument references against each
 * using command's own `$signature`, and `ResolvesSyncInput` references options (`--all`,
 * `--dry`, ...) a remote-only command doesn't have — this trait only ever touches the
 * `remote` argument, which every command using it declares identically.
 *
 * @mixin Command
 */
trait ResolvesRemote
{
    protected function syncService(): Sync
    {
        return resolve(Sync::class);
    }

    protected function resolveRemote(string $label = 'Which remote do you want to sync with?'): Remote
    {
        $sync = $this->syncService();

        $value = $this->resolveArgumentOrPrompt(
            argument: 'remote',
            label: $label,
            options: $sync->remotes()->keys()->all(),
            missingException: fn () => SyncException::remoteRequired(),
        );

        return $sync->remote($value);
    }

    /**
     * Read a command argument, prompting for it interactively when missing,
     * and fail with `$missingException` when it's still not a non-empty string.
     *
     * @param  array<int|string, string>  $options
     * @param  Closure(): SyncException  $missingException
     */
    private function resolveArgumentOrPrompt(string $argument, string $label, array $options, Closure $missingException): string
    {
        $value = $this->argument($argument);

        if (! is_string($value) && $this->input->isInteractive()) {
            $value = select(label: $label, options: $options);
        }

        if (! is_string($value) || $value === '') {
            throw $missingException();
        }

        return $value;
    }
}
