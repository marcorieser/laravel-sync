<?php

declare(strict_types=1);

namespace Sync\Sync\Exceptions;

use RuntimeException;

class SyncException extends RuntimeException
{
    /**
     * No operation was given and none could be prompted for.
     */
    public static function operationRequired(): self
    {
        return new self('You must specify an operation: "push" or "pull".');
    }

    /**
     * The given operation string is not a valid operation.
     */
    public static function invalidOperation(string $value): self
    {
        return new self(sprintf('Invalid operation "%s". Expected "push" or "pull".', $value));
    }

    /**
     * No remote was given and none could be prompted for.
     */
    public static function remoteRequired(): self
    {
        return new self('You must specify a remote.');
    }

    /**
     * No remotes are defined in the package config.
     */
    public static function noRemotesConfigured(): self
    {
        return new self('You need to define at least one remote in your config/sync.php file.');
    }

    /**
     * No recipes are defined in the package config.
     */
    public static function noRecipesConfigured(): self
    {
        return new self('You need to define at least one recipe in your config/sync.php file.');
    }

    /**
     * The given remote name does not exist in the config.
     */
    public static function unknownRemote(string $name): self
    {
        return new self(sprintf('The remote "%s" is not defined in your config/sync.php file.', $name));
    }

    /**
     * The given recipe name does not exist in the config.
     */
    public static function unknownRecipe(string $name): self
    {
        return new self(sprintf('The recipe "%s" is not defined in your config/sync.php file.', $name));
    }

    /**
     * The given remote is read-only and cannot be pushed to.
     */
    public static function remoteIsReadOnly(string $name): self
    {
        return new self(sprintf('The remote "%s" is read-only and cannot be pushed to.', $name));
    }

    /**
     * No recipe was selected and `--all` was not passed.
     */
    public static function noRecipeSelected(): self
    {
        return new self('You must select at least one recipe, or pass --all to sync every recipe.');
    }
}
