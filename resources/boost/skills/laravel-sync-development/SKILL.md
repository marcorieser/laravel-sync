---
name: laravel-sync-development
description: >
  Configure and use the Laravel Sync package to sync files and folders between environments via rsync.
license: MIT
metadata:
  author: Marco Rieser
---

# Laravel Sync

Use this skill when a Laravel application needs to install or use the `marcorieser/laravel-sync` package to
push or pull files and folders between environments (e.g. local, staging, production) over `rsync`+`ssh`.

## Primary Goal

- apply the `marcorieser/laravel-sync` package's public API (config, `sync`, `sync:list`, `sync:commands`) in the smallest correct way

## Prerequisites

- `rsync` installed on both the local machine and the remote host
- a working `ssh` setup (agent or `~/.ssh/config`) between the local machine and the remote host — the package
  does not manage SSH keys or credentials itself

## Workflow

### 1. Install and publish the config

```bash
composer require marcorieser/laravel-sync
php artisan vendor:publish --tag="laravel-sync-config"
```

This publishes `config/sync.php` with four keys: `remotes`, `recipes`, `options`, `backup_dir`.

### 2. Define remotes

Each remote is keyed by name under `sync.remotes` and needs a `root` (absolute path on that remote):

```php
'remotes' => [
    'production' => [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'port' => 22, // optional, defaults to 22
        'root' => '/home/forge/example.com',
        'read_only' => env('SYNC_PRODUCTION_READ_ONLY', true), // optional, defaults to false
    ],
],
```

Omit both `user` and `host` to treat a remote as a local path — no `ssh` is used, and the `rsync` command runs
without the `-e ssh` flag or a `user@host:` prefix. Useful for syncing between two projects on the same machine.

A `read_only` remote rejects `push` (throws a clear error before anything runs) but still allows `pull`.

### 3. Define recipes

Each recipe under `sync.recipes` is a named list of paths, relative to the Laravel app's root, that get synced
together:

```php
'recipes' => [
    'assets' => ['storage/app/assets/', 'storage/app/img/'],
    'env' => ['.env'],
],
```

### 4. Set default rsync options (optional)

```php
'options' => [
    '--archive',
],
```

Used whenever a command doesn't receive explicit `-O`/`--option` flags.

### 5. Set the backup directory (optional)

```php
'backup_dir' => '.sync-backups',
```

Relative to the app's root. Used when `--backup` is passed on a real pull (see step 6).

### 6. Run a sync

```bash
php artisan sync {push|pull} {remote} {recipe...} [options]
```

Any of `operation`, `remote`, or `recipe` that is omitted is prompted for interactively — unless
`--no-interaction` is passed, in which case a missing required value fails fast with a clear error instead of
prompting. A real (non-dry) sync asks for confirmation before running unless `--dry` or `--no-interaction` is
set.

Options: `-O`/`--option=*` (override default rsync options, repeatable), `-D`/`--dry` (dry run with real-time
output), `-A`/`--all` (sync every recipe), `-B`/`--backup` (back up local files before a real pull), `-v`
(stream real-time output).

`--backup` only applies to a real (non-dry) `pull` — a push or a dry run silently ignores it, since only a
pull overwrites local files. Before the pull runs, the local files of the selected recipes are copied into
`base_path("{backup_dir}/{timestamp}/...")` via a fixed `rsync --archive --relative` pass (independent of the
sync's own rsync options); if that copy fails, the pull doesn't run. Pulling interactively without `--backup`
prompts "Back up the local files before pulling?" before the rsync-options prompt.

### 7. Preview before running (optional)

- `php artisan sync:list {push|pull} {remote} {recipe...}` — table of origin, target, options, and port
- `php artisan sync:commands {push|pull} {remote} {recipe...}` — prints the exact `rsync` command(s) that would run

Neither of these two commands syncs anything; they only resolve and display.

## Rules, References, and Templates

Read before executing:

- `config/sync.php` — the published config file with all available keys
- `README.md` — full usage examples and the options/commands table

## Examples

```bash
# Pull the "assets" recipe from "staging" to the local project
php artisan sync pull staging assets

# Push "assets" to "production" with custom rsync options
php artisan sync push production assets --option=-avh --option=--delete

# Dry-run a pull with real-time output
php artisan sync pull staging assets --dry

# Pull "assets" from "staging", backing up the local files first
php artisan sync pull staging assets --backup

# Sync every recipe non-interactively (e.g. in a deploy script)
php artisan sync push production --all --no-interaction

# Preview the exact rsync command without running it
php artisan sync:commands push production assets
```

## Anti-patterns

- do not document package internals (DTOs, the `Sync` service, `RsyncCommand`/`RsyncOptions`/`BackupCommand` value objects) here; keep the skill focused on adoption in Laravel apps
- do not suggest managing SSH keys, passwords, or host verification through this package — it relies entirely on the host machine's existing `ssh` setup
- do not add a `push`/`pull` for a `read_only` remote as a documented workaround; it is a deliberate guard
