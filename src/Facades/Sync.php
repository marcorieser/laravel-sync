<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MarcoRieser\Sync\Sync
 */
class Sync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MarcoRieser\Sync\Sync::class;
    }
}
