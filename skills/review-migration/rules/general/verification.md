---
title: Verifying Migrations
tags: migrations, verification, rollback, test-database, dry-run
---

## Verifying Migrations

### Only Disposable Databases

Never run `migrate`, a rollback, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `db:seed`, `doctrine:migrations:migrate`, `doctrine:migrations:execute`, `doctrine:schema:update --force` or `doctrine:fixtures:load` against the user's development, staging or production database without asking first. They can't be undone by the next command.

Before running anything, work out which database the command will reach:

- **Laravel:** read `.env`, `.env.testing` and the `<env>` entries in `phpunit.xml`, and see which variables `config/database.php` uses for the connection (including `DB_URL`). Real environment variables beat `.env`: Laravel's dotenv repository is immutable, so `DB_DATABASE=... php artisan migrate` works. **But if `bootstrap/cache/config.php` exists, the configuration is cached: no `.env` is read and environment variable overrides do nothing.** Check for that file first, and don't delete it without asking.
- **Symfony:** `.env`, `.env.local`, `.env.test`, `.env.test.local` (the `APP_ENV` decides which load). `DATABASE_URL` set in the real environment beats the files. `config/packages/doctrine.yaml` may set `dbname_suffix` under `when@test`. That option has no effect on SQLite.

Disposable targets, in order of preference:

1. The test database the project's own test suite uses (`.env.testing`, `phpunit.xml`, `.env.test`), when it's a separate database or container.
2. A database service the project already defines (Sail, DDEV, Lando, a `docker compose` test service), with a throwaway database name.
3. A temporary SQLite file, but only when every migration is SQLite-compatible. Raw MySQL or Postgres SQL won't run on it, and passing on SQLite doesn't prove the production engine accepts the SQL.

If none is available, don't create infrastructure or databases without asking. Do the static checks, print the SQL (below), and report that the migration wasn't run.

### Static Checks First

- `php -l` on each new migration.
- Print the SQL without running it:
  - Laravel: `php artisan migrate --pretend` (and `migrate:rollback --pretend`). Pretend mode returns empty results for `select`, so chunked backfills print only their first `SELECT`.
  - Doctrine: `doctrine:migrations:migrate --dry-run`, or `--dry-run --write-sql=<dir>` to write the SQL to a file. **`--write-sql` without `--dry-run` executes the migrations and also writes the file** (verified on doctrine/migrations 3.9.7).
- Neither mode is read-only, and both connect to the configured database, so the "disposable only" rule applies to them too. `migrate --pretend` creates the `migrations` table if it's missing, and runs any non-database code in `up()`. Doctrine's `--dry-run` created `doctrine_migration_versions` on an empty database (tested), and still runs any statement a migration sends directly through `$this->connection` in `up()`.

### The Cycle

On the disposable database:

1. Migrate everything pending.
2. Roll back only the new migrations:
   - Laravel: `migrate:rollback --step=N`
   - Doctrine: `doctrine:migrations:execute 'DoctrineMigrations\VersionYYYYMMDDHHMMSS' --down` per migration, newest first
3. Migrate again.
4. Check the result:
   - Laravel: `php artisan db:table <table>` (Laravel 10 needs doctrine/dbal for this command)
   - Doctrine: `doctrine:schema:validate` should report the mapping in sync with the database

Step 3 failing (`already exists`, `duplicate column`) means `down()` didn't undo `up()`. If `down()` deliberately throws, skip steps 2 and 3 for that migration and say why.

Where data matters (backfills, column copies, constraints on existing rows), insert a few representative rows (NULLs, duplicates, orphans) before step 1, so the migration runs against data rather than an empty table.

### Then the Test Suite

Run the project's tests (`php artisan test` or `vendor/bin/phpunit`, `vendor/bin/pest`), or the subset touching the changed tables first. Laravel's `RefreshDatabase` runs every migration's `up()` on the test connection, so this catches a migration that breaks the test schema. It never runs `down()`, and it uses whatever engine the tests use: note in the report if that isn't the production engine.

### Report

Say which database each command ran against, the commands and their result, anything you didn't run and why, and what the deploy needs: release ordering, a backfill to run, a table size to check.
