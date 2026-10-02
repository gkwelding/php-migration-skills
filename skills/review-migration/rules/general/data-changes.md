---
title: Data Migrations, Backfills and Seeders
tags: migrations, data, backfill, batching, models, seeders, fixtures
---

## Data Migrations, Backfills and Seeders

### Schema and Data in Separate Migrations

A migration either changes the schema or changes rows. Keep them apart: on MySQL nothing makes a mixed migration atomic (`reversibility-and-transactions.md`), a slow backfill shouldn't hold up a DDL change, and a failed backfill can then be re-run on its own.

Write data migrations so they can run twice safely: filter on the rows that still need the change (`WHERE currency IS NULL`), not on everything.

### No Models or Entities in Migrations

A migration has to work against the schema as it was at that point, but a model or entity always reflects the latest code. On a fresh database (CI, a new developer, `RefreshDatabase`) an old migration that uses a model then runs against a model written for a later schema. On top of that, Eloquent models bring global scopes (`SoftDeletes` hides trashed rows), casts, mutators, events and observers (which may send mail or dispatch jobs). Doctrine entities bring lifecycle callbacks and listeners.

**Incorrect:**

```php
Order::whereNull('currency')->each(fn (Order $order) => $order->update(['currency' => 'GBP']));
```

**Correct:** the query builder (Laravel) or the DBAL connection (Doctrine), with table and column names written out.

The same applies to schema helpers that load a model. Laravel's `foreignIdFor(User::class)` instantiates `User` to read its table and key name. Write `foreignId('user_id')->constrained('users')` instead.

Doctrine migrations get `$this->connection`, `$this->platform` and `$this->sm` (schema manager). Don't fetch the `EntityManager` or repositories into a migration.

### Backfill in Batches

A single `UPDATE orders SET ...` over a large table locks every row it changes until it commits, and the deploy waits for it to finish. Update in bounded batches, each committed on its own.

**Laravel:** `chunkById()`, not `chunk()`. `chunk()` pages with `OFFSET`, so updating the column you filter on skips rows. Tested on Laravel 13 with 2,500 NULL rows in chunks of 1,000: `chunk()` left 1,000 rows NULL, while `chunkById()` updated them all.

```php
public function up(): void
{
    DB::table('orders')
        ->whereNull('currency')
        ->chunkById(1000, function (Collection $orders): void {
            DB::table('orders')
                ->whereIn('id', $orders->pluck('id'))
                ->update(['currency' => 'GBP']);
        });
}
```

On PostgreSQL, set `public $withinTransaction = false;` so each batch commits instead of the whole backfill running in one transaction.

`migrate --pretend` doesn't show backfill writes: while pretending, `select` returns an empty array, so the chunk callback never runs and only the first `SELECT` is printed.

**Doctrine:** do the reads in `up()`, but queue the writes with `addSql()`. Anything run directly on `$this->connection` inside `up()` executes immediately, even under `--dry-run` (`symfony/doctrine-migrations.md`).

```php
public function isTransactional(): bool
{
    return false; // commit each batch on its own
}

public function up(Schema $schema): void
{
    $maxId = (int) $this->connection->fetchOne('SELECT MAX(id) FROM orders');

    for ($from = 1; $from <= $maxId; $from += 5000) {
        $this->addSql(
            'UPDATE orders SET currency = :currency WHERE currency IS NULL AND id BETWEEN :from AND :to',
            ['currency' => 'GBP', 'from' => $from, 'to' => $from + 4999],
        );
    }
}
```

**Very large tables:** a backfill that takes minutes holds up the deploy while it runs. Suggest a queued job or console command run after the deploy instead, with the NOT NULL migration shipping once it has finished.

### `down()` for a Data Migration

If the old values can be restored (e.g. you copied a column), restore them. If they can't, say so: throw for an irreversible change, or write a no-op with a comment when undoing isn't needed (a backfill of a nullable column). Don't leave an empty `down()` without explaining why.

### Seeders and Fixtures Aren't Migrations

- **Laravel seeders** aren't tracked. `db:seed` runs them again every time, and `migrate --seed` / `migrate:fresh --seed` run `DatabaseSeeder`. Reference data that production needs (roles, statuses, settings rows) goes in a migration, written to be safe on re-run (`insertOrIgnore()`, `upsert()`). Demo and test data stays in seeders and factories.
- **Doctrine fixtures** (`doctrine:fixtures:load`) purge the database first unless `--append` is passed. They're for development and test data only. Production data goes in a migration.
