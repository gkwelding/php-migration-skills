<?php

// Matches a review report against evals/review/answer-key.json with one tool-less claude -p call.
// Usage: php evals/match.php prompt <report.txt>   prints the matcher prompt
//        php evals/match.php schema                prints the --json-schema
//        php evals/match.php score <matcher.json>  prints found,total,missed,extra_findings,clean_flagged,matcher_cost
//
// The report is shown without the skill names, so the matcher can't tell which variant wrote it.

[, $mode, $file] = $argv + [2 => ''];
$key = json_decode(file_get_contents(__DIR__.'/review/answer-key.json'), true);
$ids = array_column($key['issues'], 'id');

if ($mode === 'schema') {
    echo json_encode(['type' => 'object', 'properties' => [
        'found' => ['type' => 'object', 'properties' => array_fill_keys($ids, ['type' => 'boolean']), 'required' => $ids],
        'extra_findings' => ['type' => 'array', 'items' => ['type' => 'string']],
        'clean_flagged' => ['type' => 'boolean'],
    ], 'required' => ['found', 'extra_findings', 'clean_flagged']]);
} elseif ($mode === 'prompt') {
    $report = preg_replace('/\b(write|review)-migration\b/', 'the other tool', file_get_contents($file));
    $issues = implode("\n", array_map(fn ($i) => "- {$i['id']} ({$i['file']}): {$i['issue']}", $key['issues']));
    $clean = implode(', ', $key['clean']);
    echo <<<EOT
        An answer key of the problems planted in some Laravel database migrations is below, followed by a code review of those migrations.

        - found: for each answer-key id, true if the review identifies that problem in that file (any wording or severity counts; naming the file without the problem doesn't).
        - extra_findings: every other problem the review says needs a change before deploy that matches no answer-key entry, one short line each. Leave out deploy notes, suggested checks, and notes the review itself marks as minor or optional.
        - clean_flagged: true if the review says {$clean} needs a change (it has no planted problem).

        === Answer key ===
        {$issues}

        === Review ===
        {$report}
        EOT;
} else {
    $run = json_decode((string) @file_get_contents($file), true) ?? [];
    $m = $run['structured_output'] ?? null;
    $found = $m ? array_keys(array_filter($m['found'])) : [];
    echo implode(',', [
        $m ? count($found) : '', count($ids), implode(' ', $m ? array_diff($ids, $found) : []),
        $m ? count($m['extra_findings']) : '', $m ? (int) $m['clean_flagged'] : '',
        isset($run['total_cost_usd']) ? round($run['total_cost_usd'], 2) : '',
    ]), "\n";
}
