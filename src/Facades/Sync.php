<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Vitamin2\Sync\Sync
 */
class Sync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vitamin2\Sync\Sync::class;
    }
}
