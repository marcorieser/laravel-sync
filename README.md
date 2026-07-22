<div align="center">
    <h1>Laravel Sync</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://img.shields.io/packagist/v/marcorieser/laravel-sync.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://img.shields.io/packagist/php-v/marcorieser/laravel-sync.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://badge.laravel.cloud/badge/marcorieser/laravel-sync?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/marcorieser/laravel-sync/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/marcorieser/laravel-sync/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/marcorieser/laravel-sync"><img src="https://img.shields.io/packagist/dt/marcorieser/laravel-sync.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A git-like artisan command to easily sync files and folders between environments

## Installation

You can install the package via Composer:

```bash
composer require marcorieser/laravel-sync
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-sync"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-sync-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="laravel-sync-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="laravel-sync-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="laravel-sync-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="laravel-sync-assets"
```

## Usage

<!-- Add a basic usage example here. -->

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
