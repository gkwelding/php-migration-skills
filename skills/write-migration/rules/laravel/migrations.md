---
title: Laravel Migrations
tags: laravel, migrations, schema-builder, blueprint, sqlite, foreign-keys, artisan
---

## Laravel Migrations

Checked against laravel/framework 10.50, 11.57, 12.69 and 13.34. Behaviour was run on SQLite 3.53 and MySQL 8.4.6.

### Shape

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
```

Create it with `php artisan make:migration add_channel_to_orders_table --table=orders` (`--create=orders` for a new table) so the timestamp and stub match the project. Copy the style of the project's newest migrations (anonymous class or named class, return types).

Class properties: `protected $connection` (a non-default connection), `public $withinTransaction = true` (only has an effect on PostgreSQL and SQL Server). Laravel 12.4+ adds `shouldRun(): bool`. A migration skipped this way isn't recorded, so it's checked again on every `migrate`.

### Version Differences

| | 10 | 11, 12, 13 |
|---|---|---|
| `->change()` | Depends on whether `doctrine/dbal` is installed. With it (and no `Schema::useNativeSchemaOperationsIfPossible()`), attributes you don't restate are **kept**. Without it, MySQL/PostgreSQL use native SQL and **drop** them, as in 11+ | Native. Attributes you don't restate are **dropped** |
| SQLite `->change()` | `RuntimeException: Changing columns for table "..." requires Doctrine DBAL` without DBAL | Works (rebuilds the table) |
| SQLite `dropForeign()` | `BadMethodCallException: SQLite doesn't support dropping foreign keys` | Works with a column array (`dropForeign(['user_id'])`). A name string throws `This database driver does not support dropping foreign keys by name.` |
| `->online()`, `->instant()`, `->lock()`, `->inplace()` | No | 12.23+, 12.41+, 12.46+, 13.33+ (`general/large-tables.md`) |

### `change()` Restates the Whole Column (11+)

**Incorrect** (column was `string('title')->nullable()->default('untitled')->comment('Shown in lists')`):

```php
$table->string('title', 100)->change();
```

On MySQL 8.4 this ran `alter table posts modify title varchar(100) not null` on Laravel 13, and on Laravel 10 without doctrine/dbal, dropping the NULL, the default and the comment. On SQLite (Laravel 11+) the rebuilt table came out as `"title" varchar not null`. Laravel 10 with doctrine/dbal 3.10 ran `CHANGE title title VARCHAR(100) DEFAULT 'untitled' COMMENT 'Shown in lists'` and kept all three. On Laravel 10, check `composer.lock` for doctrine/dbal before writing `change()`. Restating every attribute is correct either way.

**Correct:**

```php
$table->string('title', 100)->nullable()->default('untitled')->comment('Shown in lists')->change();
```

Read the column's current definition from the migrations that created and last changed it (or `php artisan db:table posts` on a disposable database) before writing `change()`. `change()` doesn't touch indexes: add or drop them explicitly.

After an upgrade from 10, old migrations that used `change()` produce a different schema on fresh databases (tests, CI). The Laravel 11 upgrade guide suggests `schema:dump` (`general/shipped-migrations.md`).

### Foreign Keys

`foreignId('x_id')->constrained()` guesses the table by removing `_id` and pluralising the rest. Modifiers for the **column** must come **before** `constrained()`, which returns the foreign key definition.

**Incorrect:**

```php
$table->foreignId('author_id')->constrained()->nullable();
```

Tested on Laravel 13: this produced `"author_id" integer not null` referencing a table called `authors`. The column stayed NOT NULL and the guessed table may not exist.

**Correct:**

```php
$table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
```

Actions: `cascadeOnDelete()`, `restrictOnDelete()`, `nullOnDelete()`, `noActionOnDelete()`, plus the `...OnUpdate()` versions. `nullOnDelete()` needs a nullable column.

Reversing: `dropConstrainedForeignId('author_id')` drops the constraint and the column. `dropForeign(['author_id'])` (array, conventional name `{table}_{column}_foreign`) drops only the constraint. A string argument is the constraint name.

Avoid `foreignIdFor(User::class)` in migrations: it instantiates the model (`general/data-changes.md`).

### Enums

`enum('status', [...])` is a native `ENUM` on MySQL and MariaDB. On PostgreSQL and SQLite it becomes `varchar` with a `CHECK (status in (...))` constraint.

- **MySQL:** adding a value means `->change()` with the full list **and** every modifier (default, nullable). `ENUM` is a type change, so check `general/large-tables.md` for big tables.
- **PostgreSQL:** `enum()->change()` compiles to `alter column "status" type varchar(255) check (...)` (seen with `--pretend` on 13.34). The ALTER TABLE synopsis doesn't allow a CHECK there. Change the CHECK constraint with `DB::statement()` instead, or store a `string()` and validate with a PHP enum cast.

### MySQL-Only Modifiers

`after()`, `first()`, column `charset()` and table `->engine()` only exist in the MySQL/MariaDB grammar. (`collation()` also works on PostgreSQL and SQLite.) On SQLite, `after('id')` is silently ignored and the column goes at the end, so tests on SQLite won't catch a wrong column name in `after()`.

Raw SQL in `DB::statement()` must run on every engine the project migrates: production and the test connection. If MySQL-only SQL is unavoidable, branch on `DB::getDriverName()` and say in the report that the test schema differs.

### SQLite as the Test Database

- 11+: `change()`, `dropForeign([...])` and similar operations rebuild the table: copy, drop, recreate. That's fine for tests. It's also why a migration passing on SQLite says little about locking on MySQL or PostgreSQL.
- SQLite rejects `ADD COLUMN ... NOT NULL` without a default on a non-empty table, while MySQL fills in `''` (tested). Don't rely on either behaviour (`general/zero-downtime.md`).

### Artisan Commands

| Command | Use |
|---|---|
| `migrate --pretend` | Print the SQL without running it (selects return nothing, so backfills don't show their writes) |
| `migrate:status` | Which migrations have run |
| `migrate --step` | Record each migration in its own batch, so they roll back individually |
| `migrate:rollback --step=N` / `--batch=N` / `--pretend` | Roll back the last N migrations or one batch |
| `migrate --isolated` | Take a cache lock so only one server migrates during a multi-server deploy |
| `migrate --force` | Required to run in the `production` environment; never add it on your own |
| `schema:dump [--prune]` | Squash into `database/schema/{connection}-schema.sql`. `--prune` deletes migration files |
| `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` | Drop or roll back everything. Disposable databases only |
