<?php
require_once __DIR__ . '/modules/cqc-projects/db-helper.php';
$pdo = getCQCDatabaseConnection();

echo "<h2>CQC Database Debug</h2>";

// Test the exact query that cashbook uses
echo "<h3>Testing Cashbook Query:</h3>";
$rows = $pdo->query("
    SELECT 
        cb.*,
        COALESCE(d.division_name, 'Unknown') as division_name,
        COALESCE(d.division_code, '-') as division_code,
        COALESCE(c.category_name, 'Unknown') as category_name,
        COALESCE(u.full_name, 'System') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN users u ON cb.created_by = u.id
    ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($rows) . " transactions</p>";
echo "<table border='1'><tr><th>ID</th><th>Date</th><th>Type</th><th>Amount</th><th>Division</th><th>Category</th><th>Description</th></tr>";
foreach ($rows as $r) {
    echo "<tr><td>{$r['id']}</td><td>{$r['transaction_date']}</td><td>{$r['transaction_type']}</td><td>" . number_format($r['amount']) . "</td><td>{$r['division_name']}</td><td>{$r['category_name']}</td><td>{$r['description']}</td></tr>";
}
echo "</table>";

// Check user with ID 8
echo "<h3>Check User ID 8:</h3>";
$user = $pdo->query("SELECT * FROM users WHERE id = 8")->fetch(PDO::FETCH_ASSOC);
if ($user) {
    echo "<pre>" . print_r($user, true) . "</pre>";
} else {
    echo "<p style='color:red'>User ID 8 not found!</p>";
    echo "<p>Creating user ID 8...</p>";
    $pdo->exec("INSERT INTO users (id, username, full_name, role) VALUES (8, 'user8', 'User 8', 'admin') ON DUPLICATE KEY UPDATE full_name='User 8'");
}
