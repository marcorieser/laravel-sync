<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Tests;

use MarcoRieser\Sync\SyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SyncServiceProvider::class,
        ];
    }
}
