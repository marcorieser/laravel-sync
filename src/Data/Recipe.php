<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Data;

final readonly class Recipe
{
    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(
        public string $name,
        public array $paths,
    ) {}

    /**
     * Hydrate a recipe from its raw config array.
     *
     * No transformation needed here (unlike `Remote::fromArray()`), but kept for a
     * symmetric hydration API between the two config DTOs.
     *
     * @param  array<int, string>  $paths
     */
    public static function fromArray(string $name, array $paths): self
    {
        return new self(name: $name, paths: $paths);
    }
}
