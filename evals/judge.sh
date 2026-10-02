#!/usr/bin/env bash
# Blind side-by-side review of the two variants' changes for each write task in a results folder.
# The changes are shown as A and B in random order; verdicts are mapped back to with/without.
#
# Usage: evals/judge.sh <results folder>     (run.sh calls this at the end unless JUDGE=0)
# Env:   MODEL             model for claude -p (default: your claude default)
#        JUDGE_BUDGET_USD  spend cap per comparison (default 1)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$1" # relative paths from here also work with a native Windows php
budget=${JUDGE_BUDGET_USD:-1}

criteria=(deploy_safety reversibility data_safety overall)
props=""
for c in "${criteria[@]}"; do
    props+="\"$c\":{\"type\":\"object\",\"properties\":{\"winner\":{\"type\":\"string\",\"enum\":[\"A\",\"B\",\"tie\"]},\"reason\":{\"type\":\"string\"}},\"required\":[\"winner\",\"reason\"]},"
done
required=$(printf '"%s",' "${criteria[@]}")
schema="{\"type\":\"object\",\"properties\":{${props%,}},\"required\":[${required%,}]}"

# a_was says which variant was shown as A; the reasons refer to the changes as A and B.
echo "task,criterion,winner,a_was,reason" > judge.csv

tail -n +2 results.csv | cut -d, -f1 | sort -u | while read -r task; do
    [ -f "$task-with.diff" ] && [ -f "$task-without.diff" ] || continue
    if (( RANDOM % 2 )); then a=with b=without; else a=without b=with; fi

    {
        cat <<'EOF'
Two changes, A and B, were written independently for the same requested database change in a Laravel app.
The app is in production with existing rows, and the migrations below have shipped (on main, tagged v1.0.0).
During a deploy the old release keeps running until the new code is live.

For each criterion, pick A, B or tie, and give one sentence that cites something specific in the changes:
- deploy_safety: zero-downtime ordering. The old release keeps working while and after the migrations run; risky steps (renames, drops, new constraints) are split across releases or made safe; no long locks.
- reversibility: down() really undoes up(), or throws with the reason it can't.
- data_safety: no rows or values lost or corrupted on existing data.
- overall: the change you would rather deploy.
EOF
        echo
        echo "=== Requested change ==="
        php -r '$t = (require $argv[1])["tasks"][$argv[2]]; echo $t["change"], "\n";' "$root/evals/tasks.php" "$task"
        echo
        echo "=== App before the change ==="
        (cd "$root/evals/fixtures/laravel" && find . -name '*.php' | sort | while read -r f; do echo "// ${f#./}"; cat "$f"; echo; done)
        echo "=== Change A ==="
        cat "$task-$a.diff"
        echo "=== Change B ==="
        cat "$task-$b.diff"
    } > "$task.judge-prompt.txt"

    echo "== judging $task (A = $a)"
    claude -p --tools "" --setting-sources project --output-format json --json-schema "$schema" --max-budget-usd "$budget" \
        --no-session-persistence ${MODEL:+--model "$MODEL"} \
        < "$task.judge-prompt.txt" > "$task.judge.json" 2> "$task.judge.err" || echo "   judge failed, see $task.judge.err"

    php -r '
        [, $json, $a, $b, $task] = $argv;
        $verdict = json_decode((string) @file_get_contents($json), true)["structured_output"] ?? [];
        $out = fopen("judge.csv", "a");
        foreach ($verdict as $criterion => $v) {
            $winner = ["A" => $a, "B" => $b][$v["winner"]] ?? "tie";
            fputcsv($out, [$task, $criterion, $winner, $a, $v["reason"]], escape: "");
        }
    ' "$task.judge.json" "$a" "$b" "$task"
done

php -r '
    $rows = array_map(fn ($l) => str_getcsv($l, escape: ""), array_slice(file("judge.csv", FILE_IGNORE_NEW_LINES), 1));
    $tally = [];
    foreach ($rows as [, $criterion, $winner]) {
        $tally[$criterion][$winner] = ($tally[$criterion][$winner] ?? 0) + 1;
    }
    printf("\n%-14s %5s %8s %4s\n", "criterion", "with", "without", "tie");
    foreach ($tally as $criterion => $t) {
        printf("%-14s %5d %8d %4d\n", $criterion, $t["with"] ?? 0, $t["without"] ?? 0, $t["tie"] ?? 0);
    }
'
echo
echo "Verdicts with reasons: $1/judge.csv"
