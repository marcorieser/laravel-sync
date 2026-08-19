<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vitamin2\Sync\SyncServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SyncServiceProvider::class,
        ];
    }
}
