<?php

declare(strict_types=1);

namespace Sync\Sync\Enums;

use ValueError;

enum Operation: string
{
    case Push = 'push';
    case Pull = 'pull';

    /**
     * Get a human-readable label for the operation.
     */
    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push',
            self::Pull => 'Pull',
        };
    }

    /**
     * Resolve an operation from a raw string, throwing when it isn't a known operation.
     *
     * The friendly error a user sees is composed one layer up, by
     * `SyncException::invalidOperation()`.
     */
    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? throw new ValueError(
            sprintf('Invalid operation "%s". Expected "push" or "pull".', $value),
        );
    }

    /**
     * Get the available operations as [value => label] for interactive prompts.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            fn (self $operation) => [$operation->value => $operation->label()],
        )->all();
    }
}
