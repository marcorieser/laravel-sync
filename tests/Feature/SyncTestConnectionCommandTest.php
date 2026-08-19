<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    config([
        'sync.remotes' => [
            'production' => ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app', 'read_only' => true],
            'local' => ['root' => '/srv/local'],
        ],
    ]);
});

it('reports success without spawning a process for a local remote', function () {
    Process::fake();

    $this->artisan('sync:test-connection', ['remote' => 'local'])
        ->expectsOutputToContain('"local" is a local remote — no SSH connection needed.')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('reports success when the ssh connection and root path check succeed', function () {
    Process::fake();

    $this->artisan('sync:test-connection', ['remote' => 'production'])
        ->expectsOutputToContain('Connected to "production", and "/srv/app" exists on the remote.')
        ->assertSuccessful();

    Process::assertRan(function ($process) {
        $command = $process->command;

        return in_array('ssh', $command, true)
            && in_array('-p', $command, true)
            && in_array('forge@1.2.3.4', $command, true)
            && in_array("test -d '/srv/app'", $command, true);
    });
});

it('fails with the remote error output when the ssh connection fails', function () {
    Process::fake(fn () => Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 255));

    $this->artisan('sync:test-connection', ['remote' => 'production'])
        ->expectsOutputToContain('Could not connect to "production", or "/srv/app" does not exist on the remote.')
        ->expectsOutputToContain('Permission denied (publickey).')
        ->assertFailed();
});

it('fails without a trailing blank line when there is no remote error output', function () {
    Process::fake(fn () => Process::result(exitCode: 1));

    $this->artisan('sync:test-connection', ['remote' => 'production'])
        ->expectsOutputToContain('Could not connect to "production", or "/srv/app" does not exist on the remote.')
        ->assertFailed();
});

it('fails with a friendly error when the connection attempt times out', function () {
    Process::fake(function () {
        throw new ProcessTimedOutException(
            new SymfonyProcessTimedOutException(new SymfonyProcess(['ssh']), SymfonyProcessTimedOutException::TYPE_GENERAL),
            Process::result(exitCode: -1),
        );
    });

    $this->artisan('sync:test-connection', ['remote' => 'production'])
        ->expectsOutputToContain('Connecting to "production" timed out after 10 seconds.')
        ->assertFailed();
});

it('escapes a single quote in the remote root for the remote shell', function () {
    config(['sync.remotes.production.root' => "/srv/app's data"]);

    Process::fake();

    $this->artisan('sync:test-connection', ['remote' => 'production'])->assertSuccessful();

    Process::assertRan(fn ($process) => in_array("test -d '/srv/app'\\''s data'", $process->command, true));
});

it('fails with a friendly error for an unknown remote', function () {
    $this->artisan('sync:test-connection', ['remote' => 'missing'])
        ->expectsOutputToContain('The remote "missing" is not defined in your config/sync.php file.')
        ->assertFailed();
});

it('prompts for the remote when omitted interactively', function () {
    Process::fake();

    $this->artisan('sync:test-connection')
        ->expectsChoice('Which remote do you want to test?', 'production', ['production', 'local'])
        ->expectsOutputToContain('Connected to "production"')
        ->assertSuccessful();
});

it('fails with a friendly error when no remote is given non-interactively', function () {
    $this->artisan('sync:test-connection', ['--no-interaction' => true])
        ->expectsOutputToContain('You must specify a remote.')
        ->assertFailed();
});
