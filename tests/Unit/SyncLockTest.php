<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Vitamin2\Sync\Concurrency\SyncLock;

beforeEach(function () {
    $this->lockPath = storage_path('framework/testing/sync-lock-test-'.uniqid('', true).'.lock');
});

afterEach(function () {
    File::delete($this->lockPath);
});

it('acquires an uncontended lock', function () {
    $lock = new SyncLock($this->lockPath);

    expect($lock->acquire())->toBeTrue();

    $lock->release();
});

it('fails to acquire a lock already held by another handle', function () {
    File::ensureDirectoryExists(dirname($this->lockPath));
    $handle = fopen($this->lockPath, 'c');
    flock($handle, LOCK_EX | LOCK_NB);

    $lock = new SyncLock($this->lockPath);

    expect($lock->acquire())->toBeFalse();

    flock($handle, LOCK_UN);
    fclose($handle);
});

it('can acquire a lock again once it is released', function () {
    $first = new SyncLock($this->lockPath);
    expect($first->acquire())->toBeTrue();
    $first->release();

    $second = new SyncLock($this->lockPath);
    expect($second->acquire())->toBeTrue();

    $second->release();
});

it('fails to acquire when the lock file cannot be opened', function () {
    // A plain file where the lock's parent directory should be makes `fopen()` fail,
    // without permission tricks (unreliable when tests run as root).
    $blockedParent = storage_path('framework/testing/sync-lock-blocked-'.uniqid('', true));

    if (! is_dir(dirname($blockedParent))) {
        @mkdir(dirname($blockedParent), recursive: true);
    }

    File::put($blockedParent, '');

    $lock = new SyncLock($blockedParent.'/nested.lock');

    expect($lock->acquire())->toBeFalse();

    File::delete($blockedParent);
});

it('is a no-op success to acquire again while already holding the lock', function () {
    $lock = new SyncLock($this->lockPath);

    expect($lock->acquire())->toBeTrue()
        ->and($lock->acquire())->toBeTrue();

    $lock->release();
});

it('is safe to release without ever acquiring', function () {
    $lock = new SyncLock($this->lockPath);

    $lock->release();

    expect($lock->acquire())->toBeTrue();

    $lock->release();
});
