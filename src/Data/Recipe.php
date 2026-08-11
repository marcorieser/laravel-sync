<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Data;

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
     * Hydrate a recipe from its raw config array, plus this recipe's own excludes —
     * looked up by `Sync::recipes()` from the separate `sync.excludes` config key
     * (keyed by recipe name), not from `$paths` itself, so `recipes` stays the plain
     * `array<string, array<int, string>>` shape `aerni/sync` config compatibility
     * requires.
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     */
    public static function fromArray(string $name, array $paths, array $excludes = []): self
    {
        return new self(name: $name, paths: $paths, excludes: $excludes);
    }
}
