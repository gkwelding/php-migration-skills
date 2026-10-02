---
title: DDL on Large Tables
tags: migrations, mysql, postgresql, online-ddl, indexes, locks, concurrently
---

## DDL on Large Tables

A statement that's instant on a developer's database can lock a production table for minutes. When the table is large or busy, check what the change locks and ask the database to fail rather than lock more than you expected. If you don't know the table's size, ask, or flag it in the report.

### MySQL (InnoDB)

From the MySQL 8.4 Reference Manual, "Online DDL Operations":

| Operation | Algorithm | Concurrent DML |
|---|---|---|
| Add secondary index | `INPLACE`, no rebuild | Yes |
| Add column | `INSTANT` (the 8.4 default) | Yes |
| Drop column | `INSTANT` or `INPLACE` with rebuild | Yes |
| Rename column | `INSTANT` or `INPLACE` | Yes |
| Set / drop column default | `INSTANT` | Yes |
| Make column NOT NULL / NULL | `INPLACE`, rebuilds table (NOT NULL needs strict `sql_mode`) | Yes |
| Change column data type | `COPY` only | **No** |
| Add foreign key | `INPLACE` only with `foreign_key_checks` disabled, otherwise `COPY` | Yes |

Older servers differ: check the manual for the server's version.

**State the lock you expect.** "If the LOCK clause specifies a less restrictive level of locking than is permitted for a particular DDL operation, the statement fails with an error" (MySQL 8.4, "Online DDL Performance and Concurrency"). So `LOCK=NONE` makes MySQL refuse rather than quietly block writes. Tested on 8.4.6: `ALTER TABLE t MODIFY v VARCHAR(5), LOCK=NONE` fails with `LOCK=NONE is not supported. Reason: Cannot change column type INPLACE. Try LOCK=SHARED.`

**Metadata locks.** Even online DDL takes a brief exclusive metadata lock. It "may have to wait for concurrent transactions that hold metadata locks on the table", and while it waits it "blocks subsequent transactions on the table". A long-running query or open transaction on the table therefore stalls all traffic to it behind the `ALTER`. Run large-table DDL when no long transactions are open on the table.

### PostgreSQL

From the PostgreSQL 18 docs (ALTER TABLE, CREATE INDEX, Explicit Locking):

- `ALTER TABLE` takes `ACCESS EXCLUSIVE` "unless explicitly noted". That lock conflicts with everything, including plain `SELECT`.
- Plain `CREATE INDEX` takes `SHARE`. Reads continue, but inserts, updates and deletes block until the build finishes.
- `CREATE INDEX CONCURRENTLY` takes `SHARE UPDATE EXCLUSIVE` and doesn't block writes. It can't run inside a transaction block. If it fails (deadlock, uniqueness violation) it leaves an INVALID index behind. The recommended recovery is to drop it and run `CREATE INDEX CONCURRENTLY` again.
- Changing a column's type normally rewrites the table and its indexes.

`SET lock_timeout = '5s'` before risky DDL makes the statement give up instead of queueing indefinitely behind a long transaction.

**NOT NULL on a large table without a long exclusive scan:**

```sql
-- Migration 1 (fast): constraint added without checking existing rows
ALTER TABLE orders ADD CONSTRAINT orders_currency_not_null CHECK (currency IS NOT NULL) NOT VALID;
-- Migration 2: VALIDATE CONSTRAINT takes SHARE UPDATE EXCLUSIVE, not ACCESS EXCLUSIVE
ALTER TABLE orders VALIDATE CONSTRAINT orders_currency_not_null;
-- Migration 3: the scan is skipped because a valid CHECK proves no NULLs (PostgreSQL 12+)
ALTER TABLE orders ALTER COLUMN currency SET NOT NULL;
ALTER TABLE orders DROP CONSTRAINT orders_currency_not_null;
```

Foreign keys follow the same pattern: `ADD CONSTRAINT ... FOREIGN KEY ... NOT VALID`, then `VALIDATE CONSTRAINT`.

### Laravel

Added in Laravel 12.x and present in 13 (bisected against `illuminate/database` releases):

| Modifier | From | SQL |
|---|---|---|
| `->online()` on an index | 12.23 | PostgreSQL: `create index concurrently` |
| `->instant()` on a column | 12.41 | MySQL: `, algorithm=instant` |
| `->lock('none')` on an index, column or foreign key | 12.46 | MySQL: `, lock=none` |
| `->inplace()` on an index or foreign key | 13.33 | MySQL: `, algorithm=inplace` |

On older versions, use `DB::statement()` with the SQL written out. Check the installed version before using these: `grep -n "function\|@method" vendor/laravel/framework/src/Illuminate/Database/Schema/IndexDefinition.php`.

**Incorrect:** `->algorithm()` sets the index *type* (`USING btree`/`hash`/`gist`), not the online DDL algorithm.

```php
$table->index('channel')->algorithm('inplace');
// alter table `orders` add index `orders_channel_index` using inplace(`channel`)  -> syntax error
```

**Correct (MySQL, Laravel 12.46+):**

```php
$table->index('channel')->lock('none');
// alter table `orders` add index `orders_channel_index`(`channel`), lock=none
```

**Correct (PostgreSQL, Laravel 12.23+):** Laravel wraps each PostgreSQL migration in a transaction, and `CONCURRENTLY` can't run in one, so opt out:

```php
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('channel')->online();
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_channel_index');
    }
};
```

### Doctrine

`doctrine:migrations:diff` never adds `ALGORITHM`, `LOCK` or `CONCURRENTLY`. Edit the generated SQL, and on PostgreSQL make the migration non-transactional:

```php
public function isTransactional(): bool
{
    return false; // CREATE INDEX CONCURRENTLY can't run in a transaction
}

public function up(Schema $schema): void
{
    $this->addSql('CREATE INDEX CONCURRENTLY idx_orders_channel ON orders (channel)');
}

public function down(Schema $schema): void
{
    $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_orders_channel');
}
```

Declare the same index in the mapping (`#[ORM\Index(name: 'idx_orders_channel', columns: ['channel'])]`). An index that's in the database but not in the mapping shows up as `DROP INDEX` in the next diff.
