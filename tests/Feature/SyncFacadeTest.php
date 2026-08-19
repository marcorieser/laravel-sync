<?php

declare(strict_types=1);

use Vitamin2\Sync\Facades\Sync as SyncFacade;
use Vitamin2\Sync\Sync;

beforeEach(function () {
    config([
        'sync.remotes' => [
            'staging' => ['host' => '1.2.3.4', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
        ],
    ]);
});

it('resolves the underlying Sync service', function () {
    expect(SyncFacade::getFacadeRoot())->toBeInstanceOf(Sync::class)
        ->and(SyncFacade::getFacadeRoot())->toBe(resolve(Sync::class));
});

it('delegates calls to the underlying Sync singleton', function () {
    expect(SyncFacade::remotes()->keys()->all())->toBe(['staging'])
        ->and(SyncFacade::remote('staging'))->toEqual(resolve(Sync::class)->remote('staging'));
});
