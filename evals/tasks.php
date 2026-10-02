<?php

// Eval tasks. `php evals/tasks.php` prints one "id|kind|change" line per task for run.sh;
// score.php requires this file for the seed rows and each write task's checks.
//
// Every check is one SQL statement run against a SQLite copy of "production": the shipped
// migrations, these seed rows, then the run's new migrations. `expect` is the first column of
// the first row (null means SQL NULL); a check without `expect` only has to run without an error.
// Checks named old_release: are queries the release still running during the deploy makes.

$eval = [
    'seed' => <<<'SQL'
        INSERT INTO customers (id, name, email, country, created_at, updated_at) VALUES
            (1, 'Ada Lovelace', 'ada@example.com', 'GB', '2025-01-11 09:00:00', '2025-01-11 09:00:00'),
            (2, 'Grace Hopper', 'grace@example.com', NULL, '2025-01-12 09:00:00', '2025-01-12 09:00:00'),
            (3, 'Alan Turing', 'alan@example.com', NULL, '2025-01-13 09:00:00', '2025-01-13 09:00:00'),
            (4, 'Grace B. Hopper', 'grace@example.com', 'US', '2025-02-01 09:00:00', '2025-02-01 09:00:00'),
            (5, 'Edsger Dijkstra', 'edsger@example.com', 'NL', '2025-02-02 09:00:00', '2025-02-02 09:00:00');
        INSERT INTO orders (id, customer_id, status, total_pence, notes, created_at, updated_at) VALUES
            (1, 1, 'shipped', 1200, 'Leave at door', '2025-01-20 09:00:00', '2025-01-20 09:00:00'),
            (2, 2, 'shipped', 800, NULL, '2025-01-21 09:00:00', '2025-01-21 09:00:00'),
            (3, 4, 'pending', 1500, NULL, '2025-02-03 09:00:00', '2025-02-03 09:00:00'),
            (4, 99, 'shipped', 400, 'Customer account deleted', '2025-01-22 09:00:00', '2025-01-22 09:00:00'),
            (5, NULL, 'pending', 999, 'Guest checkout', '2025-02-04 09:00:00', '2025-02-04 09:00:00'),
            (6, 3, 'pending', 2100, NULL, '2025-02-05 09:00:00', '2025-02-05 09:00:00');
        SQL,

    'tasks' => [
        'required-country' => [
            'kind' => 'write',
            'change' => "make customers.country required. Existing customers without a country should get 'GB'.",
            'checks' => [
                'no_null_country' => ['sql' => 'SELECT COUNT(*) FROM customers WHERE country IS NULL', 'expect' => 0],
                'backfilled_gb' => ['sql' => 'SELECT country FROM customers WHERE id = 2', 'expect' => 'GB'],
                'kept_existing' => ['sql' => 'SELECT country FROM customers WHERE id = 4', 'expect' => 'US'],
                'rows_kept' => ['sql' => 'SELECT COUNT(*) FROM customers', 'expect' => 5],
                'old_release:insert_without_country' => ['sql' => "INSERT INTO customers (name, email) VALUES ('Old Release', 'old@example.com')"],
            ],
        ],
        'rename-name' => [
            'kind' => 'write',
            'change' => 'rename customers.name to full_name.',
            'checks' => [
                'full_name_copied' => ['sql' => 'SELECT full_name FROM customers WHERE id = 1', 'expect' => 'Ada Lovelace'],
                'no_null_full_name' => ['sql' => 'SELECT COUNT(*) FROM customers WHERE full_name IS NULL', 'expect' => 0],
                'old_release:read_name' => ['sql' => 'SELECT name FROM customers WHERE id = 1', 'expect' => 'Ada Lovelace'],
            ],
        ],
        'unique-email' => [
            'kind' => 'write',
            'change' => 'add a unique index on customers.email. Some existing customers share an email address: keep the oldest one (lowest id), move the other customers\' orders to it, then delete the duplicates.',
            'checks' => [
                'unique_index' => ['sql' => "SELECT COUNT(*) FROM pragma_index_list('customers') l, pragma_index_info(l.name) i WHERE l.\"unique\" = 1 AND i.name = 'email'", 'expect' => 1],
                'oldest_kept' => ['sql' => "SELECT id FROM customers WHERE email = 'grace@example.com'", 'expect' => 2],
                'others_kept' => ['sql' => 'SELECT COUNT(*) FROM customers', 'expect' => 4],
                'orders_moved' => ['sql' => 'SELECT customer_id FROM orders WHERE id = 3', 'expect' => 2],
                'orders_kept' => ['sql' => 'SELECT COUNT(*) FROM orders', 'expect' => 6],
            ],
        ],
        'orders-fk' => [
            'kind' => 'write',
            'change' => 'add a foreign key from orders.customer_id to customers.id. Guest orders keep a NULL customer_id. Some existing orders point at customers that no longer exist: set their customer_id to NULL.',
            'checks' => [
                'foreign_key' => ['sql' => "SELECT COUNT(*) FROM pragma_foreign_key_list('orders') WHERE \"table\" = 'customers' AND \"from\" = 'customer_id'", 'expect' => 1],
                'orphan_nulled' => ['sql' => 'SELECT customer_id FROM orders WHERE id = 4', 'expect' => null],
                'others_untouched' => ['sql' => 'SELECT COUNT(*) FROM orders WHERE id <> 4 AND customer_id IS NOT NULL', 'expect' => 4],
                'orders_kept' => ['sql' => 'SELECT COUNT(*) FROM orders', 'expect' => 6],
                'no_violations' => ['sql' => 'SELECT COUNT(*) FROM pragma_foreign_key_check', 'expect' => 0],
                'old_release:guest_order' => ['sql' => "INSERT INTO orders (customer_id, status, total_pence) VALUES (NULL, 'pending', 100)"],
            ],
        ],
        // Planted migrations in evals/review/laravel, answer key in evals/review/answer-key.json.
        'review' => ['kind' => 'review'],
    ],
];

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    foreach ($eval['tasks'] as $id => $task) {
        echo $id, '|', $task['kind'], '|', $task['change'] ?? '', "\n";
    }
}

return $eval;
