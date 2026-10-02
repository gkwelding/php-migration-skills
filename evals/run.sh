#!/usr/bin/env bash
# Run each task with and without the skills in a scratch Laravel app, then score it.
# Write tasks: shipped migrations untouched, migrate -> rollback -> migrate on a seeded SQLite copy,
# schema and data checks, the test suite. Review tasks: recall against an answer key.
#
# Usage: evals/run.sh [all|write|review|<part of a task id>]   e.g. evals/run.sh rename
# Env:   MODEL             model for claude -p (default: your claude default)
#        BUDGET_USD        spend cap per claude run (default 5)
#        JUDGE_BUDGET_USD  spend cap per matcher or judge call (default 1)
#        WORK              scratch directory for the scaffolded app (default evals/.work)
#        JUDGE             0 skips the blind side-by-side review of the write tasks (evals/judge.sh)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
work=${WORK:-$root/evals/.work}
budget=${BUDGET_USD:-5}
judge_budget=${JUDGE_BUDGET_USD:-1}
which=${1:-all}
stamp=$(date +%Y%m%d-%H%M%S)
results=$work/results/$stamp
dir=$work/laravel
mkdir -p "$results"
echo "task,variant,new_migrations,shipped_edited,cycle,down_restores,checks,failed_checks,tests,failures,errors,models_in_migrations,cost_usd,turns,minutes" > "$results/results.csv"
echo "task,variant,found,total,missed,extra_findings,clean_flagged,matcher_cost,cost_usd,turns,minutes" > "$results/review.csv"

# Said in both variants' prompts, so the skill's "disposable databases only" rule lets it verify.
note="Any database you can reach from this checkout is a disposable copy."

if [ ! -d "$dir/.git" ]; then
    rm -rf "$dir"
    composer create-project -n --quiet laravel/laravel "$dir"
    (cd "$dir" && git init -q -b main)
fi

# Back to the baseline: the skeleton plus the fixtures, committed on main and tagged as released,
# and a dev database migrated to it (empty, like a developer's copy).
reset() {
    (cd "$dir" && if git rev-parse -q --verify HEAD > /dev/null; then git reset -q --hard && git clean -qfd; fi)
    cp -r "$root/evals/fixtures/laravel/." "$dir/"
    (cd "$dir" && git add -A && { git diff --cached --quiet || git -c user.name=eval -c user.email=eval@localhost commit -qm "Release 1.0.0"; } \
        && git tag -f v1.0.0 > /dev/null \
        && rm -f database/*.sqlite* && touch database/database.sqlite && php artisan migrate --force -q)
}

# cost_usd,turns,minutes from claude -p --output-format json
stats() {
    php -r '$r = json_decode((string) @file_get_contents($argv[1]), true) ?? [];
        echo isset($r["total_cost_usd"]) ? round($r["total_cost_usd"], 2) : "", ",", $r["num_turns"] ?? "", ",",
            isset($r["duration_ms"]) ? round($r["duration_ms"] / 60000, 1) : "";' "$1"
}

run_one() {
    local id=$1 kind=$2 change=$3 variant=$4 name=$1-$4 prompt tools
    # Relative to $dir, so they also work with a native Windows php.
    local out=../results/$stamp/$name

    reset
    tools="Read,Glob,Grep,Bash(php -l:*),Bash(git diff:*),Bash(git status:*),Bash(git log:*),Bash(git show:*),Bash(git branch:*),Bash(git tag:*)"
    if [ "$kind" = review ]; then
        cp -r "$root/evals/review/laravel/." "$dir/"
        target="the migrations added or changed in the working tree (git status)"
        [ "$variant" = with ] && prompt="/review-migration $target" \
            || prompt="Review $target before they are deployed. Report every problem with evidence and a concrete fix. Don't change any files."
    else
        tools="Write,Edit,Bash(php:*),Bash(vendor/bin/phpunit:*),Bash(composer dump-autoload:*),$tools"
        [ "$variant" = with ] && prompt="/write-migration $change $note" \
            || prompt="Write the Laravel migration for this change: $change $note"
    fi
    if [ "$variant" = with ]; then
        mkdir -p "$dir/.claude/skills"
        cp -r "$root"/skills/* "$dir/.claude/skills/"
    fi

    echo "== $name"
    # Prompt on stdin: as an argument, Git Bash rewrites "/write-migration" into a file path, and MSYS_NO_PATHCONV
    # would leak into Claude's own shell. --setting-sources project keeps user plugins and hooks out.
    # DB_PORT=1 makes a stray MySQL connection fail instead of reaching a local server.
    (cd "$dir" && printf '%s' "$prompt" | DB_PORT=1 claude -p --setting-sources project ${MODEL:+--model "$MODEL"} \
        --max-budget-usd "$budget" --no-session-persistence --permission-mode acceptEdits --output-format json \
        --allowedTools "$tools" > "$out.claude.json" 2> "$out.claude.err") || echo "   claude exited non-zero, see $results/$name.claude.err"
    (cd "$dir" && php -r '$j = json_decode((string) @file_get_contents($argv[1]), true); file_put_contents($argv[2], $j["result"] ?? "");' \
        "$out.claude.json" "$out.claude.txt")
    (cd "$dir" && git add -A && git diff --cached -- . ':!.claude' > "$out.diff" && git reset -q)

    if [ "$kind" = review ]; then
        # One tool-less call per report, blind to the variant.
        (cd "$dir" && php "$root/evals/match.php" prompt "$out.claude.txt" > "$out.match-prompt.txt" \
            && claude -p --tools "" --setting-sources project --output-format json --json-schema "$(php "$root/evals/match.php" schema)" \
                --max-budget-usd "$judge_budget" --no-session-persistence ${MODEL:+--model "$MODEL"} \
                < "$out.match-prompt.txt" > "$out.match.json" 2> "$out.match.err") || echo "   matcher failed, see $results/$name.match.err"
        echo "$id,$variant,$(cd "$dir" && php "$root/evals/match.php" score "$out.match.json"),$(cd "$dir" && stats "$out.claude.json")" >> "$results/review.csv"
    else
        echo "$id,$variant,$(cd "$dir" && php "$root/evals/score.php" "$id" "$out"),$(cd "$dir" && stats "$out.claude.json")" >> "$results/results.csv"
    fi
}

while IFS='|' read -r id kind change; do
    [ "$which" = all ] || [ "$which" = "$kind" ] || [[ "$id" == *"$which"* ]] || continue
    for variant in without with; do
        run_one "$id" "$kind" "$change" "$variant"
    done
done < <(php "$root/evals/tasks.php" | tr -d '\r')

for csv in results review; do
    [ "$(wc -l < "$results/$csv.csv")" -gt 1 ] || continue
    echo
    column -s, -t < "$results/$csv.csv" 2>/dev/null || cat "$results/$csv.csv"
done
echo
echo "Logs, diffs, results.csv and review.csv: $results"

[ "${JUDGE:-1}" = 0 ] || [ "$(wc -l < "$results/results.csv")" -le 1 ] || bash "$root/evals/judge.sh" "$results"
