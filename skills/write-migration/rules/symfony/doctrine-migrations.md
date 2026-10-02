---
title: Doctrine Migrations
tags: symfony, doctrine, migrations, diff, dbal, schema-validate
---

## Doctrine Migrations

Checked against doctrine/migrations 3.9.7, doctrine/doctrine-migrations-bundle 4.0.1, doctrine/dbal 4.5.0 and doctrine/orm 3.7.3. Behaviour was run on SQLite and MySQL 8.4.6. Command names below are the bundle's (`doctrine:migrations:*`). Without the bundle they're `migrations:*`.

### Generate, Then Read Every Line

`doctrine:migrations:diff` compares the ORM mapping with the **database the command connects to** and writes a migration with the difference. Run it against a disposable database that is already migrated to the latest version. Against an out-of-date database, the diff repeats changes that earlier migrations already make. Use `doctrine:migrations:generate` for an empty migration (data changes, hand-written SQL).

The generated SQL is a starting point. The template says so itself: `this up() migration is auto-generated, please modify it to your needs`. Check each statement for the problems below.

**Renames come out as drop + add, and unrelated changes as renames.** The DBAL comparator treats a dropped and an added column as a rename only when their definitions are identical and there is exactly one candidate. Tested with DBAL 4.5 on the MySQL platform:

| Mapping change | Generated SQL |
|---|---|
| Rename `name` → `full_name`, same definition | `ALTER TABLE users CHANGE name full_name VARCHAR(255) NOT NULL` |
| Rename `name` → `full_name` **and** length 255 → 100 | `ALTER TABLE users ADD full_name VARCHAR(100) NOT NULL, DROP name` (data lost) |
| Remove `legacy_code`, add unrelated `nickname`, same type | `ALTER TABLE users CHANGE legacy_code nickname VARCHAR(255) DEFAULT NULL` (old data now in `nickname`) |
| Rename table `users` → `members` | `CREATE TABLE members ...` + `DROP TABLE users` (data lost) |

Even a correct rename breaks the running release, so renames follow `general/zero-downtime.md`.

**Drops of things the mapping doesn't know about.** A table, column or index that exists in the database but not in the mapping comes out as `DROP`. This includes tables created outside the ORM and indexes created by hand, such as a `CONCURRENTLY` index not declared with `#[ORM\Index]`. Remove those statements, then fix the cause: declare the index in the mapping, or exclude the tables with `doctrine.dbal.schema_filter`.

**Changes you didn't make.** If the diff contains changes unrelated to your mapping edit, the database and mapping have drifted, or the connection's platform doesn't match. Check `doctrine:schema:validate`, and whether `server_version` in `doctrine.yaml` matches the real server. Don't commit that noise inside your migration. Report it.

**Platform.** The SQL is written for the platform the diff connected to. A diff generated against SQLite contains SQLite SQL, and by default nothing stops it running on MySQL. Tested on 3.9.7: the platform guard (`$this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform, ...)`) is only generated with `--check-database-platform=1`. A bare `--check-database-platform` with no value doesn't add it. Generate against the production engine.

**The generated `down()`** is the reverse diff, with the same problems. Read it as carefully as `up()`.

Useful diff options: `--formatted` (needs doctrine/sql-formatter), `--filter-expression=<regex>`, `--namespace`, `--allow-empty-diff`, `--from-empty-schema`.

### How a Migration Executes

`addSql()` only **queues** SQL. Doctrine runs `preUp()`, then `up()`, then the queued SQL (plus SQL for any changes made to the `$schema` argument), then `postUp()`. Anything run directly through `$this->connection` inside `up()` executes immediately, before the queued SQL. **It also executes under `--dry-run`.**

**Incorrect:**

```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE orders ADD currency VARCHAR(3) DEFAULT NULL');
    $this->connection->executeStatement("UPDATE orders SET currency = 'GBP'"); // runs first: column doesn't exist yet
}
```

Tested: a `CREATE TABLE` sent with `executeStatement()` in `up()` ran during `migrations:migrate --dry-run`, and a table queued with `addSql()` wasn't visible to code running in `up()`.

**Correct:** queue writes with `addSql()`. Reads in `up()` (to plan batches) are fine, but they see the schema as it was before this migration (`general/data-changes.md`).

```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE orders ADD currency VARCHAR(3) DEFAULT NULL');
}
```

The backfill goes in its own migration (`general/data-changes.md`).

### Migration Methods

| Method | Behaviour |
|---|---|
| `getDescription()` | Shown in `status`/`list` and in the migrate log. Fill it in |
| `isTransactional()` | Defaults to `true`. Return `false` for `CONCURRENTLY` and for DDL-only migrations on MySQL (`general/reversibility-and-transactions.md`) |
| `down()` | Default aborts with `No down() migration implemented` |
| `throwIrreversibleMigrationException($reason)` | Use in `down()` for changes that can't be undone |
| `abortIf($cond, $msg)` | Fails the migration |
| `skipIf($cond, $msg)` | Skips it, **without recording it**, so it's attempted again on every run (tested) |
| `warnIf($cond, $msg)` | Logs a warning and continues |

### Commands

| Command | Use |
|---|---|
| `doctrine:migrations:status`, `doctrine:migrations:list` | What has and hasn't run |
| `doctrine:migrations:migrate --dry-run` | Print the queued SQL without executing it (direct `$this->connection` calls still run) |
| `doctrine:migrations:migrate --dry-run --write-sql=<dir>` | Write the SQL to a file. **Without `--dry-run`, `--write-sql` also executes** (tested on 3.9.7) |
| `doctrine:migrations:migrate [first\|prev\|next\|latest\|current+N\|<FQCN>]` | Migrate up or down to a version |
| `doctrine:migrations:migrate --all-or-nothing` | One transaction for the whole run. Refuses if any migration is non-transactional |
| `doctrine:migrations:execute <FQCN> --up\|--down` | Run one migration in one direction |
| `doctrine:migrations:up-to-date` | Exits `1` when migrations are pending: useful in CI |
| `doctrine:schema:validate` | Mapping is valid and in sync with the database. `--skip-sync` checks only the mapping |
| `doctrine:migrations:version --add\|--delete` | Marks a version as run or not run **without running it**. Only with the user's agreement |
| `doctrine:migrations:rollup` | Deletes every version record and records the single remaining one. Squashing only, with agreement |
| `doctrine:schema:update --force` | Applies the mapping directly, bypassing migrations. Don't use it in a project that has migrations (`--dump-sql` to inspect is fine) |

Migrations live where `doctrine_migrations.migrations_paths` in `config/packages/doctrine_migrations.yaml` points (commonly `migrations/` with namespace `DoctrineMigrations`). Class names are `VersionYYYYMMDDHHMMSS`.

### Mapping and Migration Ship Together, Except Drops

A migration that adds a column ships with the mapping that uses it. A migration that drops a mapped column ships one release **after** the mapping is removed: the ORM selects every mapped column by name, so the old release fails on every load of the entity (`general/zero-downtime.md`).
