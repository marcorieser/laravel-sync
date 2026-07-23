<?php

declare(strict_types=1);

use Sync\Sync\Tests\TestCase;

// Forces pest-plugin-type-coverage to skip its Pokio fork runtime, which races on a
// shared cache file and corrupts it (upstream bug: pestphp/pest-plugin-type-coverage#58).
// Remove once that's fixed upstream: https://github.com/marcorieser/laravel-sync/issues/2
$_ENV['__PEST_PLUGIN_ENV'] ??= 'testing';

uses(TestCase::class)->in(__DIR__);
