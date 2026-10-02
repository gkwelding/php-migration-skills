---
title: Zero-Downtime Ordering
tags: migrations, deploy, expand-contract, rename, drop-column, not-null
---

## Zero-Downtime Ordering

During a deploy, the old release runs against the new schema: migrations usually run before the new code takes traffic, and rolling deploys serve both releases at once. Every migration must work with **the release currently running and the one being deployed**. A change that breaks the running release goes out as several migrations across several releases (expand, migrate, contract).

Ask which deploy model the project uses if it matters and isn't obvious. Small apps that accept a maintenance window may not need the multi-release sequence. Say what you assumed.

### Adding a Required Column

**Incorrect** (one migration, existing table with rows, running code doesn't know the column):

```php
$table->string('currency', 3);   // NOT NULL, no default
```

What happens depends on the engine. Tested: SQLite rejects it (`Cannot add a NOT NULL column with default value NULL`). MySQL 8.4 accepted it and silently filled existing rows with `''`. PostgreSQL uses NULL for existing rows when there is no default (PostgreSQL docs, ALTER TABLE), which violates NOT NULL. Even when the `ALTER` succeeds, the running release's inserts don't set the column. On MySQL in strict mode they fail with `Field 'currency' doesn't have a default value`.

**Correct:** spread across releases.

1. Migration: add the column nullable.
2. Code: write the column on every insert and update. Steps 1 and 2 can ship together, because the old code ignores a nullable column.
3. Backfill existing rows in batches (`data-changes.md`).
4. Migration: make it NOT NULL, or add the constraint or index (`large-tables.md` for doing this without long locks).

**Shortcut when a constant default is right:** `->default('GBP')` on a NOT NULL column is safe in one step, because old code inserting without the column gets the default. On PostgreSQL a non-volatile default is stored in the table's metadata without a rewrite (PostgreSQL 12 and 18 docs, ALTER TABLE). On MySQL 8.4, adding a column is `INSTANT` by default. A volatile default (the docs' example is `clock_timestamp()`) makes PostgreSQL rewrite the table and its indexes.

### Renaming a Column or Table

An in-place rename (`renameColumn()`, `Schema::rename()`, `ALTER TABLE ... RENAME`, or a Doctrine diff that comes out as `CHANGE old new`) breaks the running release the moment it commits: the old code still uses the old name.

**Correct:** add, copy, switch, drop, across releases.

1. Migration: add the new column (nullable). Code: write both columns.
2. Backfill the new column from the old one in batches.
3. Code: read the new column, still writing both.
4. Code: stop writing the old column. If it's NOT NULL without a default, make it nullable first (otherwise inserts fail as above).
5. Migration, in a later release: drop the old column.

Renaming a table works the same way: create the new table, write both, backfill, switch reads, then drop the old table.

### Dropping a Column

Code stops using the column one release **before** the migration drops it.

- **Laravel:** remove it from `$fillable`/`$guarded`, `$casts`/`casts()`, `$hidden`, `$visible`, `$appends`, accessors and mutators, factories, API resources, Form Request rules, and any query, `select()`, `orderBy()` or `where()` naming it. Eloquent selects `*`, so reads keep working after the drop, but any write naming the column fails. Tested on Laravel 13: after the column was gone, `User::find()` worked and `User::create(['legacy' => ...])` threw `table users has no column named legacy`.
- **Doctrine:** remove the property's mapping (`#[ORM\Column]`) first. The ORM selects every mapped column by name, so once the column is gone, **every** load of that entity fails. Tested on ORM 3.7: `find()` threw `no such column: t0.legacy`. Removing the mapping makes `doctrine:migrations:diff` propose the drop straight away. Don't ship that drop in the same release.
- In both: if the column is NOT NULL without a default, code that no longer writes it can't insert rows. Make it nullable (or give it a default) in the release that stops using it.

Then drop it in a later migration with an irreversible or recreate-only `down()` (`reversibility-and-transactions.md`).

### Changing a Column's Type

Prefer a new column plus backfill (as for renames) when the conversion can fail or the table is large. MySQL 8.4 changes a column's data type only with `ALGORITHM=COPY`, which rebuilds the table and blocks concurrent DML (MySQL 8.4 Reference Manual, "Online DDL Operations"). In PostgreSQL, changing a type "will normally cause the entire table and its indexes to be rewritten" under an `ACCESS EXCLUSIVE` lock.

### Constraints on Existing Data

A new unique index, foreign key, NOT NULL or check constraint fails part-way through the deploy if existing rows break it. Before adding one, query for the rows that would break it (duplicates, orphans, NULLs) and clean them in an earlier data migration. In review, ask whether that was done.

### Enum Values

- Adding a value: the migration ships before (or with) the code that writes it.
- Removing a value: the code stops writing it, then a data migration moves existing rows, then the schema change ships.
