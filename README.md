<div align="center">
    <h1>Laravel Sync</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://img.shields.io/packagist/v/marcorieser/laravel-sync.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://img.shields.io/packagist/dependency-v/marcorieser/laravel-sync/php.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://badge.laravel.cloud/badge/marcorieser/laravel-sync?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/marcorieser/laravel-sync/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/marcorieser/laravel-sync/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://img.shields.io/packagist/dt/marcorieser/laravel-sync.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A git-like artisan command to easily sync files and folders between environments via `rsync`.

## Requirements

- `rsync` on both your local machine and the remote host
- A working `ssh` setup between your local machine and the remote host (agent or `~/.ssh/config`)

## Installation

You can install the package via Composer:

```bash
composer require marcorieser/laravel-sync
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-sync-config"
```

This publishes `config/sync.php`:

```php
return [

    'remotes' => [

        // 'production' => [
        //     'user' => 'forge',
        //     'host' => '104.26.3.113',
        //     'port' => 22,
        //     'root' => '/home/forge/example.com',
        //     'read_only' => env('SYNC_PRODUCTION_READ_ONLY', true),
        // ],

    ],

    'recipes' => [

        // 'assets' => ['storage/app/assets/', 'storage/app/img/'],

    ],

    'options' => [
        '--archive',
    ],

];
```

### Remotes

Each remote needs a `root` path. Add `user` and `host` to sync with an actual server over `ssh`; omit both to
treat the remote as a plain local path (handy for syncing between two projects on the same machine, no `ssh`
involved).

| Key | Description |
| --- | --- |
| `user` | The username to log in to the host. Omit together with `host` for a local remote. |
| `host` | The IP address or hostname of the server. Omit together with `user` for a local remote. |
| `port` | The SSH port to use. Defaults to `22`. |
| `root` | The absolute path to the project's root folder. |
| `read_only` | When `true`, blocks `push` to this remote. Defaults to `false`. |

### Recipes

Recipes name a set of paths, relative to your project's root, that belong together:

```php
'recipes' => [
    'assets' => ['storage/app/assets/', 'storage/app/img/'],
    'env' => ['.env'],
],
```

### Options

The default `rsync` options, used whenever `--option` isn't passed on the command line:

```php
'options' => [
    '--archive',
],
```

### Backup Directory

Relative to your project's root. When `--backup` is passed on a real pull, the local files
of the selected recipes are copied here, into a timestamped folder, before the pull runs:

```php
'backup_dir' => '.sync-backups',
```

Each backed-up pull adds another timestamped folder; nothing prunes old ones automatically.
Add `backup_dir` to your `.gitignore` and clean it out periodically.

## Usage

```bash
php artisan sync {push|pull} {remote} {recipe...} [options]
```

| Command | Description |
| --- | --- |
| `sync` | Run the sync. |
| `sync:list` | Preview the origin, target, options, and port in a table, without syncing. |
| `sync:commands` | Print the `rsync` commands that would be run, without syncing. |

| Option | Description |
| --- | --- |
| `-O`, `--option=*` | Override the default rsync options. Repeatable. |
| `-D`, `--dry` | Perform a dry run, with real-time output. |
| `-A`, `--all` | Sync every configured recipe. |
| `-B`, `--backup` | Back up local files to `backup_dir` before a real pull. |
| `-v` | Show real-time output while syncing (progress, stats, ...). |

Any argument you omit is prompted for interactively (operation, remote, recipes, and rsync options), unless
you pass `--no-interaction`, in which case a missing required value fails fast with a clear error instead of
prompting — and any real (non-dry) sync runs immediately without a confirmation prompt.

Use `--dry` for a dry run, not `--option=--dry-run` — only `--dry` skips the confirmation prompt, forces
real-time output, and reports it as a dry run instead of a completed sync.

`--backup` only applies to a real (non-dry) pull — a pull is the only operation that overwrites your local
files, so a push (or a dry run) ignores it. Before the pull runs, the local files of the selected recipes
are copied into a timestamped folder under `backup_dir` (e.g. `.sync-backups/2026-07-24_134530/`), using a
fixed `--archive --relative` copy independent of your chosen rsync options. If you don't pass `--backup` and
you're pulling interactively, you're asked whether to back up before you're asked which rsync options to use.

### Examples

```bash
# Pull the "assets" recipe from "staging"
php artisan sync pull staging assets

# Push "assets" to "production" with custom rsync options
php artisan sync push production assets --option=-avh --option=--delete

# Preview a pull as a dry run, with real-time output
php artisan sync pull staging assets --dry

# Back up local "assets" files before pulling
php artisan sync pull staging assets --backup

# Sync every recipe
php artisan sync push production --all

# Preview what would run, without syncing
php artisan sync:list pull staging assets
php artisan sync:commands pull staging assets

# Fully interactive
php artisan sync
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Sync! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Marco Rieser](https://github.com/marcorieser)
- [All Contributors](../../contributors)

## License

Laravel Sync is open-sourced software licensed under the [MIT license](LICENSE.md).
