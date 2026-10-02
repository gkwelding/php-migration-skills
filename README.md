# PHP Migration Skills

Agent skills for writing and reviewing database schema migrations safely in Laravel (migrations) and Symfony (Doctrine Migrations).

The skills cover what models most often get wrong about migrations: editing ones that have already run, `down()` methods that don't undo `up()`, Laravel's `change()` silently dropping attributes, one-step renames and drops that break the running release, DDL that locks a large table, `CONCURRENTLY` inside a transaction, and Doctrine diffs taken on trust.

**Read → Plan → Write → Verify on a disposable database**

## Skills

| Skill | What it does |
|---|---|
| `write-migration` | Reads the current schema and the code using it, plans the change (several releases when it needs them), writes the migration with a real `down()`, then lints it, prints its SQL, runs migrate → rollback → migrate on a disposable database and runs the tests. |
| `review-migration` | Read-only review of pending or new migrations before merge or deploy. Reports Blockers, Risks and Notes with evidence and a concrete fix for each, plus deploy notes. Runs nothing against a database. |

## What's Covered

**All projects:** shipped migrations are never edited or deleted (checked through git: default branch, tags, remote branches); ordering and squashing; a real `down()` or an explicit irreversible one; which migrations run in a transaction; MySQL implicit commits on DDL; zero-downtime sequences (required columns, add-copy-drop renames, dropping columns only after the code stops reading them, type changes, constraints on existing data, enum values); large tables (MySQL 8.4 online DDL and `LOCK=NONE`, PostgreSQL lock levels, `CREATE INDEX CONCURRENTLY`, `NOT VALID` + `VALIDATE CONSTRAINT`); data migrations kept apart from schema migrations, no models or entities, batched backfills; seeders and fixtures aren't migrations; verification on disposable databases only.

**Laravel 10-13:** `change()` with and without doctrine/dbal, SQLite limits in tests, `foreignId()->constrained()` ordering traps, `foreignIdFor()` loading models, enums per engine, MySQL-only modifiers, `online()` / `instant()` / `lock()` / `inplace()` and the versions that added them, `--pretend`, `--step`, `--isolated`, `schema:dump`, `$withinTransaction`, `shouldRun()`.

**Doctrine Migrations 3.x:** reviewing `doctrine:migrations:diff` output (rename detection, unexpected drops, drift, platform), `addSql()` queueing vs direct connection calls, `isTransactional()`, `--dry-run` and `--write-sql`, `execute --up/--down`, `up-to-date`, `doctrine:schema:validate`, and why a mapped column can't be dropped in the same release as its mapping.

## Install

### Claude Code plugin

```
/plugin marketplace add gkwelding/php-unit-tests-skills
/plugin install php-migration-skills@blackpug
```

Commands become `/php-migration-skills:write-migration <change>` and `/php-migration-skills:review-migration [files]`.

### Copy into a project or user skills folder

```
cp -r skills/write-migration skills/review-migration ~/.claude/skills/
# or per project:
cp -r skills/* .claude/skills/
```

### claude.ai

Build the packages, then upload `dist/write-migration.skill` and `dist/review-migration.skill` (Settings → Capabilities → Skills):

```
sh scripts/build-skills.sh
```

The script packages the committed files at `HEAD`, so commit edits first.

## Usage

```
/write-migration add a nullable channel column to orders
/write-migration rename customers.name to full_name
/write-migration          # no change given: looks for model/entity changes without a migration
/review-migration database/migrations/2025_06_10_090000_add_channel_to_orders_table.php
/review-migration         # no target: migrations added or changed on this branch
```

## Ground Rules the Skills Enforce

- Migrations, rollbacks, resets and fixture loads never run against a database that isn't disposable without asking first. That includes `--pretend` and `--dry-run`, which connect to the database and can write to it.
- Shipped migrations are never edited or deleted. Changes go in a new migration.
- Every `down()` undoes its `up()`, or throws with the reason it can't.
- Risky changes are split across releases, and only the current release's migrations are written.
- No models or entities inside migrations.
- `review-migration` changes nothing.

## Layout

```
skills/
├── write-migration/
│   ├── SKILL.md
│   └── rules/
│       ├── general/     # shipped migrations, reversibility/transactions, zero-downtime,
│       │                # large tables, data changes, verification
│       ├── laravel/     # schema builder by version, SQLite, foreign keys, enums, artisan
│       └── symfony/     # Doctrine diff review, addSql semantics, commands, schema validation
└── review-migration/
    ├── SKILL.md
    └── rules/           # identical copy, CI-checked
scripts/build-skills.sh  # packages dist/*.skill for claude.ai
evals/                   # with/without comparison on a scratch Laravel app
```

`rules/` exists in both skills so each can be installed alone. CI (`.github/workflows/check-rules.yml`) fails if the copies differ. Check locally with:

```
diff -r skills/write-migration/rules skills/review-migration/rules
```

## Status

First version. Every API, option and behaviour the rules name was checked against the source of laravel/framework 10.50, 11.57, 12.69 and 13.34 (version boundaries bisected against `illuminate/database` releases), doctrine/migrations 3.9.7, doctrine/doctrine-migrations-bundle 4.0.1, doctrine/dbal 4.5.0 and doctrine/orm 3.7.3. Behaviour claims marked "tested" were run against SQLite 3.53 and a throwaway MySQL 8.4.6. MySQL and PostgreSQL locking facts come from the MySQL 8.4 Reference Manual and the PostgreSQL 18 (and 12) documentation, cited where used. PostgreSQL behaviour wasn't run against a server.

The [evals](evals/README.md) run on Laravel and SQLite only so far.

## Evals

`evals/run.sh` runs Claude on a scratch Laravel app with and without the skills and scores the results mechanically. Write tasks (make a column required, rename a column the code uses, add a unique index over duplicates, add a foreign key over orphaned rows) are scored on whether shipped migrations stay untouched, `migrate` → `rollback` → `migrate` succeeds on a seeded SQLite copy of production, `down()` restores the schema, the data and the old release's queries survive, the tests pass and no models are used. The review task is scored on recall and false positives against an answer key of planted problems. Everything runs on SQLite inside the scratch app. See [evals/README.md](evals/README.md) for the measures, cost and the first result.

## Licence

MIT. See [LICENSE](LICENSE).
