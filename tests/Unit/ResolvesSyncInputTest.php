<?php

declare(strict_types=1);

use Vitamin2\Sync\Commands\SyncCommand;

/**
 * `defaultOptionsForPrompt()` is private, and the multiselect prompt it feeds is
 * mocked out entirely during `expectsChoice()` assertions (Symfony's real default/
 * choices validation never runs), so this exercises it directly via reflection —
 * the only way to observe that a default never references an excluded option.
 */
beforeEach(function () {
    $this->call = fn (array $configDefaults, bool $verbose, bool $backup): array => (new ReflectionMethod(
        SyncCommand::class,
        'defaultOptionsForPrompt',
    ))->invoke(resolve(SyncCommand::class), $configDefaults, $verbose, $backup);
});

it('keeps the default untouched when nothing is excluded', function () {
    expect(($this->call)(['--archive', '--backup'], verbose: false, backup: false))
        ->toBe(['--archive', '--backup']);
});

it('drops --backup from the default when backup is active', function () {
    expect(($this->call)(['--archive', '--backup'], verbose: false, backup: true))
        ->toBe(['--archive']);
});

it('drops output-producing flags from the default when verbose is active', function () {
    expect(($this->call)(['--archive', '--progress'], verbose: true, backup: false))
        ->toBe(['--archive']);
});

it('drops both kinds of excluded flags from the default at once', function () {
    expect(($this->call)(['--archive', '--progress', '--backup'], verbose: true, backup: true))
        ->toBe(['--archive']);
});
