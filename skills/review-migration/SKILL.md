---
name: review-migration
description: "Review pending or new database migrations in a Laravel or Symfony / Doctrine Migrations project before they're merged or deployed, without changing anything. Checks for edited shipped migrations, missing or wrong down(), Laravel change() dropping attributes, zero-downtime problems (required columns, renames, dropping columns code still uses), long locks on large tables, CONCURRENTLY inside a transaction, MySQL implicit commits, models used in migrations, unbatched backfills, and suspicious Doctrine diffs. Use when the user asks to 'review this migration', 'is this migration safe', 'check the migrations before deploy', 'what will this migration lock', or reviews a PR containing migrations. Not for writing or fixing migrations (use write-migration) or for running them."
allowed-tools: Read, Glob, Grep, Bash(git diff:*), Bash(git log:*), Bash(git status:*), Bash(git show:*), Bash(git branch:*), Bash(git tag:*), Bash(php -l:*)
---

# Review Migration

Review migrations before they're merged or deployed and report what will break, lock or lose data, with the fix for each finding. This skill is read-only: it changes no files and runs no migrations.

**Migrations to review:** $ARGUMENTS

If no target was given (the line above is empty or shows a literal `$ARGUMENTS` placeholder), review what this branch changes in the migrations directory: `git diff --name-status main...HEAD` (use the repo's default branch) plus `git status --porcelain`, limited to `database/migrations/` or the Doctrine `migrations_paths`. Added files are the migrations to review. Modified (`M`) or deleted (`D`) files that exist on the default branch are findings in their own right. If nothing changed, ask which migrations to review.

## Quality Standards

- Read-only. Don't edit files and don't run `migrate`, `--pretend`, `--dry-run` or rollbacks. Both "dry" modes connect to the database and can write to it (`general/verification.md`). Suggest commands for the user to run instead.
- Evidence for every finding: the file and line, the earlier migration that defines the column, the grep hit showing code still uses it, or the rule it breaks.
- Concrete fixes, not "consider". Say what the migration (or the sequence) should be.
- Don't invent problems to fill the report. "No issues found" is a valid result.

---

## Step 1: Context

1. Framework and versions from `composer.lock`: `laravel/framework` (and `doctrine/dbal` on Laravel 10), or `doctrine/migrations`, `doctrine/dbal`, `doctrine/orm`.
2. Production engine and version (`config/database.php` / `.env.example` `DB_CONNECTION`, `DATABASE_URL`, `doctrine.dbal.server_version`) and the test engine (`phpunit.xml`, `.env.testing`, `.env.test`). If you can't tell the production engine, say so and review for MySQL and PostgreSQL.
3. Read the rules (see Rules Reference).

## Step 2: Read Each Migration and What It Touches

1. The migration itself, `up()` and `down()`.
2. Every earlier migration touching the same tables, to know each column's definition before this change.
3. Code using the affected tables and columns: grep the column and table names in `app/`, `src/`, `config/`, `resources/`, factories, fixtures and tests. Also the model (`$fillable`, `$casts`, `$hidden`, `$appends`, accessors) or the entity mapping.
4. Shipped status of every modified or deleted migration (`general/shipped-migrations.md`).

## Step 3: Check

Go through each item, for each migration:

1. **Shipped migrations:** edited or deleted migrations that are on the default branch, a tag or a remote branch. New migrations dated earlier than migrations on the default branch, or depending on a later-dated one.
2. **Reversibility:** `down()` missing or empty (Laravel rollback then "succeeds" silently), not the inverse of `up()`, or irreversible without saying so. `change()` in `down()` restating the old definition.
3. **`change()` attributes** (Laravel 11+, and 10 without doctrine/dbal): every modifier the column had (nullable, default, comment, unsigned, length) restated.
4. **Zero-downtime:** NOT NULL column without a default on an existing table; in-place renames of columns or tables; drops of columns or tables the code still uses; type changes; new unique, foreign key or NOT NULL constraints with no check that existing rows comply; enum values removed before the code stopped writing them.
5. **Locks on large tables:** what each statement locks on the production engine (`general/large-tables.md`). Missing `lock('none')` / `ALGORITHM` / `LOCK` clauses, or a missing `online()` / `CONCURRENTLY`. `->algorithm('inplace')` used for online DDL. `CONCURRENTLY` in a migration that runs in a transaction.
6. **Transactions:** DDL and DML mixed in one migration on MySQL. Doctrine DDL-only MySQL migrations left transactional. `--all-or-nothing` in use with a non-transactional migration.
7. **Data:** models or entities used in migrations (including `foreignIdFor()`); unbatched `UPDATE`s on large tables; `chunk()` where the callback updates the filtered column; Doctrine writes sent through `$this->connection` in `up()` instead of `addSql()`; data that production needs living only in seeders or fixtures.
8. **Doctrine-generated SQL:** renames that came out as drop + add, unrelated column changes that came out as renames, unexpected `DROP TABLE` / `DROP INDEX`, changes unrelated to the feature (drift), SQL for the wrong platform.
9. **Laravel builder traps:** `constrained()` before `nullable()`, a guessed `constrained()` table that doesn't exist, `after()` relied on outside MySQL, raw SQL that the test engine can't run.

## Step 4: Report

```
## Migration Review

### {file}
**Verdict:** Safe to deploy | Needs changes | Blocker
- **[Blocker]** {what is wrong}. Evidence: {file:line / grep hit / earlier migration}. Fix: {concrete change}.
- **[Risk]** {what may go wrong in production, and under which condition (table size, engine)}. Fix: {...}.
- **[Note]** {minor or style point}.

### Deploy notes
- {release ordering, code that must ship first, backfills to run, tables whose size to check}

### Suggested checks (for the user to run on a disposable database)
- {e.g. php artisan migrate --pretend; migrate, migrate:rollback --step=1, migrate}
```

- **Blocker:** loses data, breaks the running release, fails on deploy, or edits history.
- **Risk:** depends on table size, engine or timing.
- **Note:** everything else.

Close by naming the next step: `write-migration` can write the fixes.

---

## Troubleshooting

**Production engine unknown.** Review against MySQL and PostgreSQL both, and mark the engine-dependent findings.

**Table size unknown.** Raise lock findings as Risks, saying what the statement locks and that it matters only above a certain size. Ask the user for the row count.

**The user asks you to fix the issues.** This skill doesn't edit. Hand over to `write-migration` with the findings.

**The user asks you to run the migrations.** Only on a database you've confirmed is disposable, following `general/verification.md`. Otherwise give the commands.

---

## Example

```
User: /review-migration

Step 1: Symfony 7.3, doctrine/migrations 3.9, ORM 3.5, PostgreSQL 16 (serverVersion=16),
        SQLite in .env.test.
Step 2: Branch adds migrations/Version20250610091500.php (generated by diff);
        migrations/Version20250301120000.php modified (on main since March).

## Migration Review

### migrations/Version20250301120000.php
**Verdict:** Blocker
- **[Blocker]** Edited after shipping: on origin/main and tag v2.4.0 (git tag --contains).
  The change (VARCHAR(100) -> VARCHAR(150)) won't reach any existing database.
  Fix: revert the edit; put the change in a new migration.

### migrations/Version20250610091500.php
**Verdict:** Needs changes
- **[Blocker]** ALTER TABLE customer ADD full_name VARCHAR(150) NOT NULL, DROP name:
  the rename plus length change was diffed as drop + add, so every name is lost.
  Fix: add full_name nullable, backfill from name, switch reads, drop name in a later release.
- **[Risk]** CREATE INDEX idx_customer_email ON customer (email): plain CREATE INDEX takes
  SHARE (blocks writes for the build). Fix: CREATE INDEX CONCURRENTLY with
  isTransactional() returning false, in its own migration.
- **[Note]** getDescription() is empty.

### Deploy notes
- The full_name sequence needs two releases; Customer::$name mapping stays until release 2.

Next step: write-migration can write the corrected migrations.
```

---

## Rules Reference

Read all of these before reviewing. Paths are relative to `./rules/`.

> `rules/` is identical in `write-migration/rules/`. CI keeps both copies the same.

### Always

- `general/shipped-migrations.md` - edited or deleted shipped migrations, git checks, ordering
- `general/reversibility-and-transactions.md` - `down()`, irreversible migrations, transactions, MySQL implicit commits
- `general/zero-downtime.md` - required columns, renames, drops, type changes, constraints on existing data
- `general/large-tables.md` - MySQL online DDL, PostgreSQL lock levels and `CONCURRENTLY`, Laravel and Doctrine syntax
- `general/data-changes.md` - schema vs data migrations, no models, batching, seeders and fixtures
- `general/verification.md` - why `--pretend` / `--dry-run` aren't read-only, what to suggest the user runs

### By Target

| Migrations in | Also read |
|---|---|
| **Laravel** (`database/migrations/`) | `laravel/migrations.md` |
| **Doctrine / Symfony** (`migrations/` or `migrations_paths`) | `symfony/doctrine-migrations.md` |
