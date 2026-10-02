---
title: Shipped Migrations Are Immutable
tags: migrations, git, history, ordering
---

## Shipped Migrations Are Immutable

Both frameworks record each migration that has run by name: Laravel in the `migrations` table (`config/database.php` → `migrations.table`), Doctrine in `doctrine_migration_versions` by default. A recorded migration is never run again, so editing it changes nothing on databases where it has run, while fresh databases (CI, new developers) get the edited version. The environments then drift apart without any error.

Deleting one is no better. Laravel's rollback prints `Migration not found` for a recorded migration whose file is gone and skips it. Doctrine's `migrations:migrate` warns `You have N previously executed migrations in the database that are not registered migrations.` and asks whether to continue.

### Has It Shipped?

Treat a migration as shipped if it is on the default branch, in any tag, or on any remote branch that might have been deployed to staging or a review environment. Find the commit that added it, then ask git where that commit is:

```bash
git log --diff-filter=A --format=%H -- database/migrations/2025_03_01_120000_add_status_to_orders.php
git branch -r --contains <sha>
git tag --contains <sha>
```

Any output from the last two commands means shipped. If the file is untracked or only on the current local branch, it hasn't shipped and can be edited, but it may still have run against the user's local database. In that case, ask the user to roll it back before you edit it (Laravel `migrate:rollback --step=1`, Doctrine `migrations:execute <version> --down`). Don't run that against their database yourself.

**Incorrect** (column needs to be nullable after all; existing migration is on `main`):

```php
// 2025_03_01_120000_add_status_to_orders.php, edited in place
$table->string('status')->nullable();   // was ->default('pending')
```

**Correct:** a new migration that makes the change.

```php
// 2025_06_10_090000_make_orders_status_nullable.php
Schema::table('orders', function (Blueprint $table) {
    $table->string('status')->nullable()->default('pending')->change();
});
```

The only exception is a migration that can never succeed (it fails on every database it reaches). Even then, say so and let the user decide.

### Ordering

New migrations sort after everything already on the default branch: Laravel by the filename timestamp, Doctrine by version class name (`VersionYYYYMMDDHHMMSS`). Generate them with `make:migration` / `doctrine:migrations:generate` or `doctrine:migrations:diff` rather than inventing a timestamp.

Both frameworks run pending migrations that are older than the newest executed one, so a branch merged late still runs. On a fresh database they run in sort order, though, so a migration must not depend on a table or column created by a later-dated one. After a rebase, check that the new migration still sorts after the migrations it depends on.

### Squashed Schemas

Laravel's `schema:dump` writes `database/schema/{connection}-schema.sql`, and `migrate` loads it first on a database where no migrations have run. `schema:dump --prune` deletes the migration files. Treat both like editing shipped migrations: only when the user asks for it, and with `--prune` only once every environment has run all the migrations being removed.
