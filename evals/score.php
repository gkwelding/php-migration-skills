<?php

// Scores one write run. Run from the scratch app's root; paths are relative so a native Windows php works.
// Usage: php evals/score.php <task id> <output prefix>
// Prints: new_migrations,shipped_edited,cycle,down_restores,checks,failed_checks,tests,failures,errors,models_in_migrations
//
// "Production" is a SQLite file: the shipped migrations (the files in the baseline commit, as they
// are on disk now), the seed rows from tasks.php, then the run's new migrations on top.

[, $id, $out] = $argv;
$eval = require __DIR__.'/tasks.php';
$task = $eval['tasks'][$id];

$lines = fn (string $cmd) => array_values(array_filter(explode("\n", trim((string) shell_exec($cmd)))));
$shipped = $lines('git ls-tree --name-only HEAD database/migrations/');
$edited = $lines('git diff --name-only --no-renames --diff-filter=MD HEAD -- database/migrations');
$new = array_values(array_diff(glob('database/migrations/*.php'), $shipped));

function run(array $cmd, string $log, array $env = []): bool
{
    $p = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env + getenv());

    return proc_close($p) === 0;
}

// The suite as the project runs it: phpunit.xml's in-memory SQLite, every migration through RefreshDatabase.
// PAO_DISABLE=1: laravel/pao would switch PHPUnit to JSON output under an agent.
@unlink("$out.junit.xml");
run([PHP_BINARY, 'vendor/bin/phpunit', "--log-junit=$out.junit.xml"], "$out.phpunit.txt", ['PAO_DISABLE' => '1']);
$suite = is_file("$out.junit.xml") ? simplexml_load_file("$out.junit.xml")->testsuite : null;

$db = 'database/eval.sqlite';
@unlink($db);
touch($db);
$log = "$out.migrate.txt";
$artisan = fn (string ...$args) => run([PHP_BINARY, 'artisan', ...$args, '--force', '--no-interaction'], $log, ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db]);
$pdo = fn () => new PDO("sqlite:$db", options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Column, index and foreign key shape of every table, to compare before up() and after down().
$schema = function () use ($pdo): string {
    $c = $pdo();
    $shape = [];
    foreach ($c->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'migrations' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $shape[$t] = [
            $c->query("SELECT name, type, \"notnull\", dflt_value, pk FROM pragma_table_info('$t')")->fetchAll(PDO::FETCH_NUM),
            $c->query("SELECT l.\"unique\", group_concat(i.name) FROM pragma_index_list('$t') l, pragma_index_info(l.name) i GROUP BY l.name ORDER BY 2, 1")->fetchAll(PDO::FETCH_NUM),
            $c->query("SELECT \"table\", \"from\", \"to\" FROM pragma_foreign_key_list('$t') ORDER BY 2")->fetchAll(PDO::FETCH_NUM),
        ];
    }

    return json_encode($shape);
};

$cycle = 0;
$restores = '';
$failed = [];
// The seed can fail too: an edited shipped migration may not fit the data production already has.
$seed = function () use ($pdo, $eval, $log): bool {
    try {
        return $pdo()->exec($eval['seed']) !== false;
    } catch (PDOException $e) {
        file_put_contents($log, "seed failed: {$e->getMessage()}\n", FILE_APPEND);

        return false;
    }
};
if ($artisan('migrate', ...array_map(fn ($f) => "--path=$f", $shipped)) && $seed()) {
    $before = $schema();
    $up = $new && $artisan('migrate');

    // Checks see the state right after up(); a transaction keeps their inserts out of the cycle.
    $c = $pdo();
    $c->beginTransaction();
    foreach ($task['checks'] as $name => $check) {
        try {
            $value = $c->query($check['sql'])->fetchColumn();
            $ok = !array_key_exists('expect', $check)
                || ($check['expect'] === null ? $value === null : (string) $value === (string) $check['expect']);
        } catch (PDOException) {
            $ok = false;
        }
        $ok || $failed[] = $name;
    }
    $c->rollBack();
    unset($c);

    if ($up) {
        $down = $artisan('migrate:rollback');
        $restores = (int) ($down && $schema() === $before);
        $cycle = (int) ($down && $artisan('migrate'));
    }
} else {
    $failed = array_keys($task['checks']);
}

$models = count(array_filter($new, fn ($f) => preg_match('/\bApp\\\\Models\\\\|\bforeignIdFor\s*\(/', file_get_contents($f))));

echo implode(',', [
    count($new), count($edited), $cycle, $restores,
    (count($task['checks']) - count($failed)).'/'.count($task['checks']), implode(' ', $failed),
    $suite['tests'] ?? '', $suite['failures'] ?? '', $suite['errors'] ?? '',
    $models,
]), "\n";
