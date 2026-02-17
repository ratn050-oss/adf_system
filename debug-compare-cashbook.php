<?php
/**
 * Debug: Compare Cash Book Data Between Databases
 * Verifies data isolation between businesses
 */
define('APP_ACCESS', true);
require_once 'config/config.php';

echo "<!DOCTYPE html><html><head><title>Cash Book Data Comparison</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    h2 { margin-top: 0; color: #333; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #f0f0f0; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    pre { background: #eee; padding: 15px; overflow-x: auto; }
</style></head><body>";

echo "<h1>🔍 Cash Book Data Comparison</h1>";

// Session info
echo "<div class='card'>";
echo "<h2>1. Current Session</h2>";
echo "<table>";
echo "<tr><th>Variable</th><th>Value</th></tr>";
echo "<tr><td>active_business_id</td><td><strong>" . ($_SESSION['active_business_id'] ?? 'NOT SET') . "</strong></td></tr>";
echo "<tr><td>business_id (numeric)</td><td>" . ($_SESSION['business_id'] ?? 'NOT SET') . "</td></tr>";
echo "<tr><td>ACTIVE_BUSINESS_ID constant</td><td><strong>" . ACTIVE_BUSINESS_ID . "</strong></td></tr>";
echo "<tr><td>BUSINESS_NAME constant</td><td>" . BUSINESS_NAME . "</td></tr>";
echo "</table>";
echo "</div>";

// Compare databases
$databases = [
    'Hotel (adf_narayana_hotel)' => 'adf_narayana_hotel',
    'Ben\'s Cafe (adf_benscafe)' => 'adf_benscafe'
];

// Check if production
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

if ($isProduction) {
    $databases = [
        'Hotel (adfb2574_narayana_hotel)' => 'adfb2574_narayana_hotel',
        'Ben\'s Cafe (adfb2574_Adf_Bens)' => 'adfb2574_Adf_Bens'
    ];
}

echo "<div class='card'>";
echo "<h2>2. Cash Book Data in Each Database</h2>";

foreach ($databases as $label => $dbName) {
    echo "<h3>$label</h3>";
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Count records
        $countStmt = $pdo->query("SELECT COUNT(*) FROM cash_book");
        $total = $countStmt->fetchColumn();
        
        // Sum amounts
        $incomeStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE transaction_type = 'income'");
        $totalIncome = $incomeStmt->fetchColumn();
        
        $expenseStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE transaction_type = 'expense'");
        $totalExpense = $expenseStmt->fetchColumn();
        
        echo "<p>Total Records: <strong>$total</strong></p>";
        echo "<p>Total Income: <strong>Rp " . number_format($totalIncome, 0, ',', '.') . "</strong></p>";
        echo "<p>Total Expense: <strong>Rp " . number_format($totalExpense, 0, ',', '.') . "</strong></p>";
        
        // Show last 5 transactions
        $stmt = $pdo->query("SELECT id, description, amount, transaction_type, transaction_date, created_at FROM cash_book ORDER BY created_at DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($rows) > 0) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Description</th><th>Amount</th><th>Type</th><th>Date</th><th>Created</th></tr>";
            foreach ($rows as $row) {
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                echo "<td>Rp " . number_format($row['amount'], 0, ',', '.') . "</td>";
                echo "<td>{$row['transaction_type']}</td>";
                echo "<td>{$row['transaction_date']}</td>";
                echo "<td>{$row['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠️ No cash_book records found in this database</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
    }
    echo "<hr>";
}
echo "</div>";

// Check current Database::getInstance() connection
echo "<div class='card'>";
echo "<h2>3. Database::getInstance() Connection Check</h2>";
require_once 'config/database.php';
$db = Database::getInstance();
$currentDb = Database::getCurrentDatabase();

echo "<p>Current Connected Database: <strong class='success'>$currentDb</strong></p>";

// Verify by querying
try {
    $result = $db->fetchOne("SELECT COUNT(*) as total FROM cash_book");
    echo "<p>cash_book count in current connection: <strong>{$result['total']}</strong></p>";
} catch (Exception $e) {
    echo "<p class='error'>Error querying cash_book: " . $e->getMessage() . "</p>";
}

// Expected database
$businessConfig = require 'config/businesses/' . ACTIVE_BUSINESS_ID . '.php';
$expectedDb = $businessConfig['database'];
if ($isProduction) {
    $dbMapping = [
        'adf_system' => 'adfb2574_adf',
        'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
        'adf_benscafe' => 'adfb2574_Adf_Bens'
    ];
    $expectedDb = $dbMapping[$expectedDb] ?? $expectedDb;
}

echo "<p>Expected Database (from business config): <strong>$expectedDb</strong></p>";

if ($currentDb === $expectedDb) {
    echo "<p class='success'>✅ Database connection is CORRECT!</p>";
} else {
    echo "<p class='error'>❌ Database MISMATCH! Connected to wrong database!</p>";
    echo "<p>This means the cash_book data being shown is from the wrong business.</p>";
}
echo "</div>";

// Check divisions to identify content
echo "<div class='card'>";
echo "<h2>4. Division Check (to identify database content)</h2>";
try {
    $result = $db->fetchAll("SELECT id, division_name FROM divisions LIMIT 5");
    echo "<p>Divisions in current connection:</p><ul>";
    foreach ($result as $row) {
        echo "<li>{$row['division_name']} (ID: {$row['id']})</li>";
    }
    echo "</ul>";
    echo "<p class='warning'>If these are hotel-related divisions but you're on Ben's Cafe, the database connection is wrong.</p>";
} catch (Exception $e) {
    echo "<p class='warning'>No divisions table or query error: " . $e->getMessage() . "</p>";
}
echo "</div>";

echo "</body></html>";
