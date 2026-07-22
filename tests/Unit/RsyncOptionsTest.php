<?php

declare(strict_types=1);

use Sync\Sync\Rsync\RsyncOptions;

it('keeps the given flags, deduplicated', function () {
    $options = RsyncOptions::resolve(['--archive', '--compress', '--archive'], dry: false, verbose: false);

    expect($options->flags)->toBe(['--archive', '--compress']);
});

it('adds the dry-run flags on a dry run', function () {
    $options = RsyncOptions::resolve(['--archive'], dry: true, verbose: false);

    expect($options->flags)->toContain('--archive', '--dry-run', '--human-readable', '--progress', '--stats', '--verbose');
});

it('adds the output flags on a verbose run', function () {
    $options = RsyncOptions::resolve(['--archive'], dry: false, verbose: true);

    expect($options->flags)->toContain('--archive', '--human-readable', '--progress', '--stats', '--verbose')
        ->and($options->flags)->not->toContain('--dry-run');
});

it('does not duplicate flags already present when merging dry-run additions', function () {
    $options = RsyncOptions::resolve(['--archive', '--verbose'], dry: true, verbose: false);

    expect(array_count_values($options->flags)['--verbose'])->toBe(1);
});

it('renders the flags as a space separated string', function () {
    $options = new RsyncOptions(['--archive', '--compress']);

    expect((string) $options)->toBe('--archive --compress');
});

it('reports whether any flag produces visible output', function () {
    expect((new RsyncOptions(['--archive']))->producesOutput())->toBeFalse()
        ->and((new RsyncOptions(['--archive', '--progress']))->producesOutput())->toBeTrue();
});
