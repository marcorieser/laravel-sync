<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Data;

final readonly class Recipe
{
    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     * @param  array<int, string>  $excludesFrom
     */
    public function __construct(
        public string $name,
        public array $paths,
        public array $excludes = [],
        public array $excludesFrom = [],
    ) {}

    /**
     * Hydrate a recipe from its raw config array.
     *
     * Excludes and excludes-from files arrive separately, looked up by `Sync::recipes()` from the
     * `sync.excludes`/`sync.excludes_from` config keys, so `recipes` keeps the flat shape
     * `aerni/sync` config compatibility requires.
     *
     * Excludes-from separators are normalized here, once: the guard that validates these files
     * and the `--exclude-from=` flag built from them must address the same file, and POSIX reads
     * an unnormalized backslash path as one oddly-named segment rather than a nested path.
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     * @param  array<int, string>  $excludesFrom
     */
    public static function fromArray(string $name, array $paths, array $excludes = [], array $excludesFrom = []): self
    {
        return new self(
            name: $name,
            paths: $paths,
            excludes: $excludes,
            excludesFrom: array_map(fn (string $path) => str_replace('\\', '/', $path), $excludesFrom),
        );
    }
}
