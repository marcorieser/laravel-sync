<?php

declare(strict_types=1);

namespace Sync\Sync\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sync\Sync\SyncServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SyncServiceProvider::class,
        ];
    }
}
