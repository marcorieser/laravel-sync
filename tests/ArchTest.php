<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Vitamin2\Sync')
    ->toUseStrictTypes();

// `make:command` and `make:provider` emit docblocks that only restate the symbol name.
// Pint can't catch these — no PHP-CS-Fixer rule matches a docblock on its summary text —
// so they're asserted away here instead. See the comment guidelines in AGENTS.md.
it('has no framework stub docblocks left by the generators', function () {
    $stubs = [
        'The command signature.',
        'The command description.',
        'Execute the console command.',
        'Register any application services.',
        'Bootstrap any application services.',
    ];

    $offenders = collect(File::allFiles(__DIR__.'/../src'))
        ->flatMap(function (SplFileInfo $file) use ($stubs) {
            $contents = $file->getContents();

            return collect($stubs)
                ->filter(fn (string $stub) => str_contains($contents, '* '.$stub))
                ->map(fn (string $stub) => $file->getRelativePathname().': '.$stub);
        })
        ->values();

    expect($offenders)->toBeEmpty($offenders->implode(', '));
});
