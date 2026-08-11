<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Ssh;

use MarcoRieser\Sync\Data\Remote;
use Stringable;

/**
 * An `ssh` connectivity check for `sync:test-connection` — authenticates and confirms
 * the remote's `root` exists, in one round trip.
 */
final readonly class ConnectionCommand implements Stringable
{
    /**
     * Fail fast instead of hanging: `BatchMode=yes` disables interactive/password auth
     * entirely (agent/key auth only, matching how every other command in this package
     * connects), and `ConnectTimeout=5` bounds how long the initial handshake itself is
     * allowed to take.
     */
    private const array SSH_OPTIONS = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5'];

    public function __construct(
        public Remote $remote,
    ) {}

    public function __toString(): string
    {
        return implode(' ', $this->toArgs());
    }

    /**
     * Get this command as an argument list, safe to hand directly to a process runner
     * without shell interpretation of paths or options.
     *
     * Checks that `root` actually exists on the remote in the same round trip as the
     * auth check itself (`test -d`, run by the *remote* shell via the trailing command
     * argument) — the failure this command exists to catch early is as much a
     * misconfigured `root` as a broken SSH connection.
     *
     * @return list<string>
     */
    public function toArgs(): array
    {
        return [
            'ssh',
            ...self::SSH_OPTIONS,
            '-p', (string) $this->remote->port,
            "{$this->remote->user}@{$this->remote->host}",
            "test -d {$this->escapeRemotePath($this->remote->root)}",
        ];
    }

    /**
     * Single-quote a path for the *remote* POSIX shell that runs the trailing `ssh`
     * command argument — a local `escapeshellarg()` targets the control machine's own
     * shell (and its quoting rules on Windows don't even match POSIX), not the one this
     * string is actually interpreted by.
     */
    private function escapeRemotePath(string $path): string
    {
        return "'".str_replace("'", "'\\''", $path)."'";
    }
}
