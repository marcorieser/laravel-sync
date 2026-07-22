<?php

declare(strict_types=1);

namespace Sync\Sync\Data;

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
     * @param  array<int, string>  $paths
     */
    public static function fromArray(string $name, array $paths): self
    {
        return new self(name: $name, paths: $paths);
    }
}
