# Evals

Checks whether the skills make Claude write migrations that deploy and roll back safely, and reviews that catch the problems that matter, compared with a plain prompt. Every score is mechanical except the optional blind review.

`run.sh` scaffolds a fresh Laravel app in `evals/.work/laravel`, adds the fixtures (customers and orders tables, models, a service that reads the columns, a factory-based test suite), commits them on `main` and tags `v1.0.0`, so the fixture migrations count as shipped. Then it runs `claude -p` on each task twice:

| Variant | Write task prompt | Review task prompt |
|---|---|---|
| `without` | "Write the Laravel migration for this change: `<change>` Any database you can reach from this checkout is a disposable copy." | "Review the migrations added or changed in the working tree (git status) before they are deployed. Report every problem with evidence and a concrete fix. Don't change any files." |
| `with` | `/write-migration <change> Any database ...`, with this repo's `skills/` copied into the app's `.claude/skills/` | `/review-migration the migrations added or changed in the working tree (git status)` |

The "disposable" sentence is in both prompts so `write-migration`'s rule against running migrations on a non-disposable database without asking doesn't stop it verifying (there's nobody to ask in `-p` mode). Each run starts from the baseline commit with an empty, migrated dev database (`database/database.sqlite`). Seed data never reaches the app the agent sees: like production data, it has to be anticipated.

## Write tasks

| Task | Change | What a naive migration gets wrong |
|---|---|---|
| `required-country` | Make `customers.country` required; existing customers without one get `GB` | `->nullable(false)->change()` without a backfill fails on the NULL rows; NOT NULL without a default breaks the old release's inserts |
| `rename-name` | Rename `customers.name` to `full_name` | `renameColumn()` breaks the running release (and, unless the code is updated, the test suite) |
| `unique-email` | Unique index on `customers.email`, merging duplicates into the oldest customer | The index fails on the duplicates; deleting duplicates without moving their orders orphans them |
| `orders-fk` | Foreign key `orders.customer_id` → `customers.id`, orphans set to NULL | On SQLite, Laravel's table rebuild adds the key and silently keeps the orphaned rows |

Scoring (`score.php`) builds a "production" SQLite file in the scratch app: the shipped migrations (as they are on disk after the run), the seed rows in `tasks.php` (NULL countries, a duplicate email, an order pointing at a deleted customer, a guest order), then the run's new migrations. One row per run in `results.csv`:

| Column | Meaning |
|---|---|
| `new_migrations` | Migration files the run added |
| `shipped_edited` | Shipped migrations modified or deleted (git diff against the baseline). Must be 0 |
| `cycle` | 1 if `migrate`, `migrate:rollback` and `migrate` again all succeed on the seeded database. A `down()` that throws scores 0: the deploy can't be rolled back |
| `down_restores` | 1 if the schema after the rollback (columns, types, nullability, defaults, indexes, foreign keys of every table) equals the schema before `up()` |
| `checks`, `failed_checks` | The task's SQL checks run right after `up()`: values backfilled, copied or merged, row counts kept, the constraint or index present. Checks named `old_release:` are queries the release still running during the deploy makes (`SELECT name ...`, an insert without the new column); they fail when a migration renames or drops in one step, or adds NOT NULL without a default |
| `tests`, `failures`, `errors` | The app's test suite (PHPUnit JUnit log), which runs every migration on in-memory SQLite through `RefreshDatabase` |
| `models_in_migrations` | New migrations that reference `App\Models\` or `foreignIdFor()`. Must be 0 |
| `cost_usd`, `turns`, `minutes` | From `claude -p --output-format json` |

Checks accept more than one valid approach: backfill-only and backfill-plus-constraint both pass `required-country`, and an add-copy rename passes `rename-name` where an in-place rename fails `old_release:read_name`. They measure the state after this release, though, so a plan that defers a step to a later release (the backfill of a staged rename, say) fails that step's checks. Read `failed_checks` next to the run's plan in `*.claude.txt` and the judge's reasons.

### Blind review

`judge.sh` (run at the end unless `JUDGE=0`) shows one tool-less `claude -p --json-schema` per write task the app before the change and both variants' diffs as A and B in random order, and picks A, B or tie for `deploy_safety` (zero-downtime ordering), `reversibility`, `data_safety` and `overall`, with a reason each. Verdicts go to `judge.csv`, mapped back to `with` / `without`. Re-judge a saved run with `evals/judge.sh evals/.work/results/<timestamp>`.

## Review task

`review` copies the planted migrations in `review/laravel/` into the app, uncommitted: one edit to a shipped migration and six new ones, one of them clean. `review/answer-key.json` lists the seven planted problems (edited shipped migration, NOT NULL without default, in-place rename of a used column, drop of a used column, empty `down()`, Eloquent model in a migration, `change()` dropping `nullable()`).

Each report goes to one tool-less `claude -p --json-schema` matcher call (`match.php`) with the answer key. The report has the skill names replaced, so the matcher can't tell the variants apart. `review.csv` has:

| Column | Meaning |
|---|---|
| `found`, `total`, `missed` | Answer-key problems the report identifies (recall) and the ids it missed |
| `extra_findings` | Problems the report says need a change that aren't in the key. Not all are wrong (a lock warning can be fair): read them in `review-<variant>.match.json`. A high count is noise a reviewer has to wade through |
| `clean_flagged` | 1 if the report says the clean migration needs a change: a definite false positive |
| `matcher_cost` | Cost of the matcher call |

## Proving the scores can fail

Before any real run, the harness was run with a stub `claude` first on `PATH` (checked with `command -v claude`) that writes canned migrations for `required-country`, a canned report and canned matcher/judge JSON:

| Stub | Score |
|---|---|
| Backfill then `->default('GB')->change()`, `down()` restores nullable | `shipped_edited` 0, `cycle` 1, `down_restores` 1, checks 5/5 |
| Same, plus an edit to the shipped `create_customers_table` migration | `shipped_edited` 1 |
| Same, but `down()` throws | `cycle` 0, `down_restores` 0 |
| `->change()` with no backfill | `cycle` 0, checks 3/5 (`no_null_country`, `backfilled_gb`) |

Hand-written migrations for the other tasks behave the same way: the merge-then-index and null-orphans-then-key migrations pass every check, a bare foreign key fails `orphan_nulled` and `no_violations`, and `renameColumn()` fails `old_release:read_name` and errors 2 tests.

## Running

Needs `composer`, `git`, PHP with `pdo_sqlite`, and the `claude` CLI.

```
evals/run.sh                 # every task
evals/run.sh write           # write tasks only
evals/run.sh review          # the review task
evals/run.sh rename          # tasks whose id contains "rename"
MODEL=claude-sonnet-5-5 BUDGET_USD=3 JUDGE=0 evals/run.sh write
```

The first run scaffolds the app into `evals/.work/` (gitignored) and reuses it afterwards; fixture edits are copied in on every run. Delete `evals/.work/laravel` to pick up a newer Laravel. Each run writes `results.csv`, `review.csv` and per-run logs (`*.claude.json`, `*.claude.txt`, `*.diff`, `*.migrate.txt`, `*.phpunit.txt`) to `evals/.work/results/<timestamp>/`. Add a write task by adding an entry with its checks to `tasks.php`.

**Cost:** `all` is 10 `claude -p` runs capped by `BUDGET_USD` (default 5) each, plus 2 matcher and 4 judge calls capped by `JUDGE_BUDGET_USD` (default 1) each.

**Safety:** everything runs against SQLite files inside the scratch app. Claude gets `Read`, `Write`, `Edit`, `Glob`, `Grep`, `php`, `vendor/bin/phpunit`, `composer dump-autoload` and read-only `git` for write tasks, and only reading tools, `php -l` and read-only `git` for the review. `DB_PORT=1` is set for the run so a stray MySQL connection fails instead of reaching a local server. A skill's `allowed-tools` can widen what the `with` variant may run while the skill is active, so don't run the evals on a machine whose databases you can't afford to lose to a mistyped command.

**Windows (Git Bash):** the script handles the traps found while building it.

- Git Bash rewrites `/write-migration ...` passed to a native exe into a file path, so `claude` runs with `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'`.
- Native Windows `php` can't open MSYS paths like `/tmp/...`, so every path given to `php` is relative to the app (the script `cd`s first).
- Laravel 11+ skeletons ship `laravel/pao`, which switches PHPUnit to JSON output when it detects an agent; scoring sets `PAO_DISABLE=1` and reads JUnit XML.
- For a stub `claude`, put its directory on `PATH` as `$(cygpath -u ...)`: a `C:/...` entry breaks on the colon. Check `command -v claude` before running.

The current `laravel/laravel` skeleton ships a `CLAUDE.md`/`AGENTS.md` for Laravel Boost. Both variants see it.

**Noise:** one sample per variant says little. Run each a few times before trusting a difference, with the same model and Laravel version. Symfony / Doctrine tasks aren't there yet.

## First result, one sample

`BUDGET_USD=3 evals/run.sh rename-name`, default model, Laravel 13.34, 2026-10-02. One sample per variant, so this shows the harness working, not a difference you can rely on.

| variant | new_migrations | shipped_edited | cycle | down_restores | checks | failed_checks | tests (fail/err) | models | cost_usd | turns | minutes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| without | 1 | 0 | 1 | 1 | 2/3 | `old_release:read_name` | 5 (0/0) | 0 | 0.39 | 24 | 4.3 |
| with | 1 | 0 | 1 | 1 | 1/3 | `full_name_copied`, `no_null_full_name` | 6 (0/0) | 0 | 0.59 | 13 | 1.5 |

- `without` used `renameColumn()` and updated the model, service, factory and tests. The deploy works and the suite passes, but the running release's `SELECT name ...` fails as soon as the migration runs. Its report said so and suggested add-copy-drop if zero downtime mattered.
- `with` checked that the `customers` migration had shipped (tag `v1.0.0`), then wrote release 1 of a four-release plan: a nullable `full_name` column and a model mutator writing both columns, plus a test for it. It moved the backfill to release 2, so that it runs only once no old code is still writing rows without `full_name`. The checks penalise that: they measure the state after this release, and existing rows have no `full_name` yet.
- Blind judge ($0.22): `with` won `deploy_safety`, `data_safety` and `overall`; `reversibility` was a tie. Its reasons noted the missing backfill.

Total spend: $1.20 (two runs and one judge call).
