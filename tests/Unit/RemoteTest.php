<?php

declare(strict_types=1);

use MarcoRieser\Sync\Data\Remote;

it('hydrates from a full config array', function () {
    $remote = Remote::fromArray('production', [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'port' => 1431,
        'root' => '/home/forge/example.com',
        'read_only' => true,
    ]);

    expect($remote->name)->toBe('production')
        ->and($remote->user)->toBe('forge')
        ->and($remote->host)->toBe('104.26.3.113')
        ->and($remote->port)->toBe(1431)
        ->and($remote->root)->toBe('/home/forge/example.com')
        ->and($remote->readOnly)->toBeTrue();
});

it('defaults port to 22 and read_only to false when omitted', function () {
    $remote = Remote::fromArray('staging', [
        'user' => 'forge',
        'host' => '1.2.3.4',
        'root' => '/home/forge/example.com',
    ]);

    expect($remote->port)->toBe(22)
        ->and($remote->readOnly)->toBeFalse();
});

it('trims a trailing slash from the root path', function () {
    $remote = Remote::fromArray('staging', [
        'user' => 'forge',
        'host' => '1.2.3.4',
        'root' => '/home/forge/example.com/',
    ]);

    expect($remote->root)->toBe('/home/forge/example.com');
});

it('is local when the host is missing', function () {
    $remote = Remote::fromArray('local', ['root' => '/var/www/example.com']);

    expect($remote->isLocal())->toBeTrue();
});

it('is not local when a host is configured', function () {
    $remote = Remote::fromArray('production', ['host' => '1.2.3.4', 'root' => '/srv/app']);

    expect($remote->isLocal())->toBeFalse();
});

it('builds a user@host:path for a remote host', function () {
    $remote = Remote::fromArray('production', [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'root' => '/home/forge/example.com',
    ]);

    expect($remote->path('storage/app/assets/'))->toBe('forge@104.26.3.113:/home/forge/example.com/storage/app/assets/');
});

it('builds a plain path for a local remote', function () {
    $remote = Remote::fromArray('local', ['root' => '/var/www/example.com']);

    expect($remote->path('storage/app/assets/'))->toBe('/var/www/example.com/storage/app/assets/');
});

it('collapses duplicate slashes when joining the root and the relative path', function () {
    $remote = Remote::fromArray('local', ['root' => '/var/www/example.com/']);

    expect($remote->path('/storage/app/assets/'))->toBe('/var/www/example.com/storage/app/assets/');
});
