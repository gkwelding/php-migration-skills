---
name: write-migration
description: "Write a database migration for a requested schema or data change in a Laravel (database/migrations) or Symfony / Doctrine Migrations project: add, alter, rename or drop columns and tables, add indexes, foreign keys or enum values, backfill data. Plans a zero-downtime sequence when the change is risky, writes the migration with a real down(), and verifies it against a disposable database only. Use when the user asks to 'add a column', 'create a migration', 'make this nullable', 'add an index on', 'rename this column', 'drop the legacy table', 'backfill', or 'generate a Doctrine migration for my entity change'. Not for checking existing or pending migrations without writing any (use review-migration), seeders or fixtures, or running migrations against a real database."
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Write Migration

Write the migration (or sequence of migrations) a schema or data change needs, in a way that's safe to deploy and roll back, following the project's existing conventions.

**Requested change:** $ARGUMENTS

If no change was given (the line above is empty or shows a literal `$ARGUMENTS` placeholder), look for model or entity changes with no migration yet: `git status --porcelain` and `git diff main...HEAD` (use the repo's default branch) on `app/Models/`, `src/Entity/` and mapping files. If you find any, propose the migration for them and confirm before writing. If there are none, ask what change is needed.

## Quality Standards

- Never edit or delete a migration that has shipped. Write a new one.
- Every `down()` undoes its `up()`, or throws with the reason it can't.
- Never run a migration, rollback or reset against a database that isn't disposable without asking first.
- Read the table's current definition before changing it. Don't guess lengths, nullability or defaults.
- No models or entities inside migrations.

---

## Step 1: Detect the Stack

1. **Framework and versions** from `composer.lock`: `laravel/framework` (and whether `doctrine/dbal` is installed on Laravel 10), or `doctrine/migrations`, `doctrine/doctrine-migrations-bundle`, `doctrine/dbal`, `doctrine/orm`.
2. **Production engine and version:** Laravel `config/database.php` default connection and `.env` / `.env.example` `DB_CONNECTION`. Symfony `DATABASE_URL` (`serverVersion`) and `doctrine.dbal.server_version`. If you can't tell, ask: MySQL, MariaDB and PostgreSQL lock differently.
3. **Test database** from `phpunit.xml`, `.env.testing`, `.env.test` (often SQLite).
4. **Conventions:** the migrations directory (`database/migrations/`, or `doctrine_migrations.migrations_paths`) and the three newest migrations: class style, naming, raw SQL or builder, `getDescription()`.

## Step 2: Read the Current State

1. Every migration that touches the affected tables (grep the table name in the migrations directory), so you know each column's current definition.
2. The model or entity, and code that uses the affected columns: grep for the column name in `app/`, `src/`, `resources/`, `config/`, `tests/`, factories and fixtures.
3. Whether any migration you'd be tempted to edit has shipped (`general/shipped-migrations.md`).

## Step 3: Plan

1. Classify the change with `general/zero-downtime.md`: safe in one migration, or a sequence across releases (required columns, renames, drops, type changes, constraints on existing data).
2. Check for large or busy tables (`general/large-tables.md`). If you don't know the size and the operation locks or rebuilds the table, ask, or plan as if it's large.
3. Split schema and data changes (`general/data-changes.md`).
4. Print the plan before writing:

```
## Migration plan: {change}

Release 1 (this change):
- {migration}: {what it does}; down(): {what it undoes / irreversible because ...}
- Code: {changes that must ship with or before it}

Release 2 (later, not written now):
- {migration}: {what it does}; ships after {condition}

Risks: {locks, table size, data that may violate a constraint, engine differences}
```

Write only the migrations for the current release. Describe later ones in the plan instead, so a drop doesn't ship before the code stops using the column.

## Step 4: Write

**Laravel:** `php artisan make:migration {name} --table={table}` (or `--create=`), then fill in `up()` and `down()` (`laravel/migrations.md`).

**Doctrine:** change the mapping, then `php bin/console doctrine:migrations:diff`, which connects to the database and needs a disposable one migrated to the latest version (`general/verification.md`). If none is available, use `doctrine:migrations:generate` and write the SQL by hand for the production engine. Either way, read and correct every generated statement (`symfony/doctrine-migrations.md`).

Then for both:

- Restate every attribute on changed columns, in `up()` and `down()`.
- Add the lock or algorithm clauses large tables need, and opt out of the transaction where the statement requires it.
- Fill in `getDescription()` (Doctrine) or a short comment for anything non-obvious (Laravel).

## Step 5: Verify

Follow `general/verification.md`:

1. `php -l` on each new migration.
2. Print the SQL: `migrate --pretend` / `doctrine:migrations:migrate --dry-run`.
3. On a disposable database only: migrate, roll back the new migrations, migrate again, then check the schema.
4. Run the test suite, or the tests touching the affected tables first.
5. Max 3 attempts to fix a failing migration. After that, stop and report.

## Step 6: Report

The plan, the files written, the commands run and which database each ran against, anything not run and why, and what the deploy needs (release order, backfill, table size to check).

---

## Troubleshooting

**No disposable database.** Do steps 1-2 of verification only, say the migration wasn't run, and give the user the commands to run it on a database of their choice. Don't create databases or containers without asking.

**The fix belongs in a shipped migration.** Write a new migration that corrects it. If the shipped one can never succeed anywhere, explain that and let the user decide whether to edit it.

**The Doctrine diff contains unrelated changes.** Keep them out of this migration and report them as drift (`doctrine:schema:validate`). Don't silently commit them.

**The test database is SQLite and the migration needs engine-specific SQL.** Branch on the driver only for engine options (lock clauses, `CONCURRENTLY`). Report that the test schema differs and that the SQL wasn't run on the production engine.

**Laravel 10 `change()` without doctrine/dbal.** MySQL and PostgreSQL work natively but drop unrestated attributes, while SQLite throws. Restate every attribute. Ask before `composer require doctrine/dbal`.

**The user wants the whole sequence (including the drop) now.** Write it, but put the deploy order and the code change each step waits for at the top of the report.

---

## Example

```
User: /write-migration make orders.currency required, default GBP for existing rows

Step 1: Laravel 12.50, MySQL 8.4 in production (DB_CONNECTION=mysql), SQLite in phpunit.xml.
        Anonymous-class migrations with return types.
Step 2: 2025_01_10_..._create_orders_table: string('currency', 3)->nullable().
        Order::$fillable includes currency. CheckoutService sets it; the admin importer doesn't.
        orders has ~40M rows (asked).
Step 3: Plan
        Release 1: 2025_06_10_..._backfill_orders_currency: chunkById(1000) sets 'GBP'
                   where NULL; down() no-op (nullable backfill, explained).
                   Code: importer sets currency.
        Release 2 (not written now): ->string('currency', 3)->default('GBP')->change(),
                   restating length; NOT NULL rebuilds the table in place (concurrent DML allowed),
                   add ->lock('none') so MySQL refuses instead of blocking writes.
        Risks: backfill takes minutes on 40M rows; suggested a queued job instead.
Step 4: Writes the backfill migration.
Step 5: php -l ok; migrate --pretend shows only the first SELECT (expected);
        temp SQLite file: migrate, rollback --step=1, migrate ok; php artisan test: green.
Step 6: Report with the release order.
```

---

## Rules Reference

Paths are relative to `./rules/`. Read the ones that apply before writing.

> `rules/` is identical in `review-migration/rules/`. CI keeps both copies the same.

### Always

- `general/shipped-migrations.md` - never edit shipped migrations, git checks, ordering, squashing
- `general/reversibility-and-transactions.md` - real `down()`, irreversible with a reason, transactions, MySQL implicit commits
- `general/zero-downtime.md` - expand/contract, required columns, renames, drops, type changes
- `general/verification.md` - disposable databases only, dry runs, migrate/rollback/migrate, tests

### By Target

| Change | Also read |
|---|---|
| **Laravel**, any change | `laravel/migrations.md` |
| **Doctrine / Symfony**, any change | `symfony/doctrine-migrations.md` |
| Index, constraint, NOT NULL or type change on a large or busy table | `general/large-tables.md` |
| Backfill, copying data between columns, reference data, anything that touches rows | `general/data-changes.md` |
