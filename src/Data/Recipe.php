<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Data;

final readonly class Recipe
{
    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     */
    public function __construct(
        public string $name,
        public array $paths,
        public array $excludes = [],
    ) {}

    /**
     * Hydrate a recipe from its raw config array.
     *
     * Excludes arrive separately, looked up by `Sync::recipes()` from the `sync.excludes`
     * config key, so `recipes` keeps the flat shape `aerni/sync` config compatibility requires.
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     */
    public static function fromArray(string $name, array $paths, array $excludes = []): self
    {
        return new self(name: $name, paths: $paths, excludes: $excludes);
    }
}
