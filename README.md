# Laravel Migration Preflight

A Laravel package that validates migrations before execution to prevent schema-related failures.

## Installation

```bash
composer require samody/laravel-migration-preflight
```

## Usage

```bash
php artisan migrate:preflight
```

## Features

- **Foreign Key Validation**: Detect missing referenced tables for `foreignId()->constrained()` and `foreign()->references()->on()`.
- **Table Existence Checks**: Ensure tables exist when using `Schema::table()`.
- **Column Existence Checks**: Verify columns exist when using `after()`, `dropColumn()`, or `renameColumn()`.
- **Configurable**: Toggle specific checks in `config/preflight.php`.
- **Smart Pluralization**: Correctly guesses referenced table names using Laravel's `Str::plural()`.
- **Zero-Migration Safety**: Handles fresh databases and empty migration folders without errors.

## Example Output

```text
Preflight FAILED:

2024_01_01_000001_create_orders_table
 - Missing referenced table 'users' for 'user_id'
```
