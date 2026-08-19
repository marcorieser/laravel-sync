<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Enums;

use ValueError;

enum Operation: string
{
    case Push = 'push';
    case Pull = 'pull';

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
     * This message is for developers; `SyncException::invalidOperation()` composes the one
     * users see.
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
