# Release Notes

## [Unreleased](https://github.com/marcorieser/laravel-sync/compare/v0.1.0...HEAD)

## [v0.1.0](https://github.com/marcorieser/laravel-sync/compare/main...v0.1.0) - 2026-07-24

Initial release.

- `sync`, `sync:list`, and `sync:commands` Artisan commands for pushing/pulling files and folders between environments via `rsync`
- Config-driven remotes and recipes (`config/sync.php`: `remotes`, `recipes`, `options`)
- Interactive prompts for anything left unspecified (operation, remote, recipes, rsync options), with a `--no-interaction` fallback for scripts/CI
- Guards against pushing to a `read_only` remote and against syncing a path with itself
- Full Pest test suite, 100% code and type coverage
