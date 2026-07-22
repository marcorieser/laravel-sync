<?php

declare(strict_types=1);

namespace Sync\Sync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Sync\Sync\Sync
 */
class Sync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sync\Sync\Sync::class;
    }
}
