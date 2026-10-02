---
title: Reversibility and Transactions
tags: migrations, down, rollback, transactions, mysql, implicit-commit
---

## Reversibility and Transactions

### Every Migration Gets a Real `down()`

`down()` undoes exactly what `up()` did, in reverse order, and leaves the schema as the previous migration left it.

The frameworks fail differently when `down()` is missing:

- **Laravel:** the base `Migration` class has no `down()`. The migrator only calls it `if (method_exists(...))`, so a missing or empty `down()` makes `migrate:rollback` report success and delete the migration's record while the schema stays changed. The next `migrate` then fails (`table already exists`, `duplicate column`).
- **Doctrine:** `AbstractMigration::down()` aborts with `No down() migration implemented for "..."`. That's safe, but it blocks every rollback past this migration.

**Incorrect:**

```php
public function up(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->string('channel', 20)->nullable();
        $table->index('channel');
    });
}

public function down(): void
{
    //
}
```

**Correct:**

```php
public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropIndex(['channel']);
        $table->dropColumn('channel');
    });
}
```

### Irreversible Is Allowed, Silent Is Not

Some changes can't be undone: dropping a column or table loses its data, and narrowing a column or merging rows loses information. Say so explicitly, with the reason:

```php
// Doctrine
public function down(Schema $schema): void
{
    $this->throwIrreversibleMigrationException('Drops orders.legacy_ref; the data cannot be restored.');
}
```

```php
// Laravel (no built-in exception for this)
public function down(): void
{
    throw new RuntimeException('Irreversible: drops orders.legacy_ref; the data cannot be restored.');
}
```

A `down()` that recreates a dropped column is fine when the column only needs to exist again (e.g. so the previous release's code can run), but say in a comment that the data is not restored.

### `down()` for `change()` Restates the Old Definition

In Laravel 11+ `change()` drops any modifier you don't restate (`laravel/migrations.md`). That applies to `down()` too: restate the column exactly as it was before, with its old length, nullability, default and comment.

### Transactions

| | Wraps each migration in a transaction | Opt out |
|---|---|---|
| Laravel | Only when the schema grammar supports schema transactions: PostgreSQL and SQL Server. **Not MySQL, MariaDB or SQLite.** | `public $withinTransaction = false;` |
| Doctrine | Every migration by default (`isTransactional()` returns `true`; bundle option `doctrine_migrations.transactional`, default `true`) | Override `isTransactional()` to return `false` |

Doctrine's `--all-or-nothing` (bundle option `all_or_nothing`, default `false`) wraps the whole run in one transaction and refuses to start if any migration in the plan is non-transactional.

### MySQL Commits on DDL

MySQL commits implicitly before and after DDL such as `ALTER TABLE`, `CREATE TABLE`, `CREATE INDEX`, `DROP TABLE`, `RENAME TABLE` and `TRUNCATE TABLE` (MySQL 8.4 Reference Manual, "Statements That Cause an Implicit Commit"). A transaction around a MySQL migration doesn't make it atomic.

Tested on MySQL 8.4.6 with doctrine/migrations 3.9.7: a transactional migration running `CREATE TABLE posts`, `INSERT INTO posts`, then a failing `INSERT` left the table and the first row in place, with nothing recorded in `doctrine_migration_versions`. Re-running then fails on `CREATE TABLE`. Laravel on MySQL has no transaction at all, so it ends in the same state.

What to do on MySQL:

- One logical change per migration, so a failure leaves at most one half-applied step to fix by hand.
- Keep data changes (DML) out of the migration that does the DDL. Neither order is atomic: whatever ran before the failure stays.
- For Doctrine migrations that are pure DDL on MySQL, override `isTransactional()` to return `false`. doctrine/migrations raises a deprecation when it tries to commit a transaction MySQL already committed, and suggests exactly this.

```php
public function isTransactional(): bool
{
    return false; // MySQL commits implicitly on DDL
}
```

### Statements That Can't Run in a Transaction

PostgreSQL's `CREATE INDEX CONCURRENTLY` and `DROP INDEX CONCURRENTLY` can't run inside a transaction block (PostgreSQL 18 docs, CREATE INDEX and DROP INDEX). On Postgres, Laravel wraps migrations in a transaction and Doctrine wraps every migration, so either migration must opt out (`large-tables.md`). Put a concurrent index in a migration of its own.
