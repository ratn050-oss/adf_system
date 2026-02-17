<?php
/**
 * Force Ben's Cafe Session and Debug Cash Book Data
 * This will force switch to Ben's Cafe and show what data is available
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'includes/business_helper.php';

// Force switch to Ben's Cafe
$_SESSION['active_business_id'] = 'bens-cafe';
$_SESSION['business_id'] = 2; // Ben's Cafe numeric ID

echo "<!DOCTYPE html><html><head><title>Ben's Cafe Force Debug</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    h2 { margin-top: 0; color: #333; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #d4a373; color: white; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; }
    pre { background: #eee; padding: 15px; overflow-x: auto; }
</style></head><body>";

echo "<h1>☕ Ben's Cafe Force Debug</h1>";

// Session forced
echo "<div class='card'>";
echo "<h2>1. Session Forced to Ben's Cafe</h2>";
echo "<table>";
echo "<tr><td>active_business_id</td><td><strong>{$_SESSION['active_business_id']}</strong></td></tr>";
echo "<tr><td>business_id</td><td><strong>{$_SESSION['business_id']}</strong></td></tr>";
echo "</table>";
echo "</div>";

// Check if production
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

// Now directly connect to Ben's Cafe database
echo "<div class='card'>";
echo "<h2>2. Direct Database Connection to Ben's Cafe DB</h2>";

$bensDbName = $isProduction ? 'adfb2574_Adf_Bens' : 'adf_benscafe';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bensDbName, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p>Connected to: <strong class='success'>$bensDbName</strong></p>";
    
    // Check cash_book table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'cash_book'");
    if ($tableCheck->rowCount() > 0) {
        echo "<p class='success'>✅ cash_book table EXISTS</p>";
        
        // Count records
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM cash_book");
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "<p>Total cash_book records: <strong>$total</strong></p>";
        
        if ($total == 0) {
            echo "<p class='warning'>⚠️ cash_book table is EMPTY. No transactions recorded for Ben's Cafe yet.</p>";
            echo "<p>This is why dashboard shows zero or appears to show Hotel data - there's simply no Ben's Cafe data.</p>";
        } else {
            // Show the data
            $dataStmt = $pdo->query("SELECT id, description, amount, transaction_type, transaction_date FROM cash_book ORDER BY created_at DESC LIMIT 10");
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h3>Latest cash_book records:</h3>";
            echo "<table>";
            echo "<tr><th>ID</th><th>Description</th><th>Amount</th><th>Type</th><th>Date</th></tr>";
            foreach ($rows as $row) {
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                echo "<td>Rp " . number_format($row['amount'], 0, ',', '.') . "</td>";
                echo "<td>{$row['transaction_type']}</td>";
                echo "<td>{$row['transaction_date']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p class='error'>❌ cash_book table does NOT exist!</p>";
    }
    
    // Check divisions for context
    echo "<h3>Divisions in Ben's Cafe DB:</h3>";
    $divStmt = $pdo->query("SELECT id, division_code, division_name FROM divisions LIMIT 10");
    $divisions = $divStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($divisions as $div) {
        echo "<li>{$div['division_code']} - {$div['division_name']}</li>";
    }
    echo "</ul>";
    
    // Compare with Hotel database
    echo "<h3>Comparison with Hotel DB:</h3>";
    $hotelDbName = $isProduction ? 'adfb2574_narayana_hotel' : 'adf_narayana_hotel';
    try {
        $hotelPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $hotelDbName, DB_USER, DB_PASS);
        $hotelPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $hotelCount = $hotelPdo->query("SELECT COUNT(*) FROM cash_book")->fetchColumn();
        echo "<p>Hotel cash_book records: <strong>$hotelCount</strong></p>";
        
        if ($total == 0 && $hotelCount > 0) {
            echo "<p class='warning'>⚠️ Ben's Cafe has NO cash_book data, but Hotel has $hotelCount records.</p>";
            echo "<p>The dashboard for Ben's Cafe should show ZERO values, not Hotel data.</p>";
            echo "<p><strong>If you're seeing non-zero values on Ben's Cafe dashboard, the database connection might be wrong, or you're actually viewing Hotel dashboard.</strong></p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>Cannot connect to Hotel DB: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Cannot connect to Ben's Cafe DB: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test Database::getInstance() after forcing session
echo "<div class='card'>";
echo "<h2>3. Test Database::getInstance() After Session Force</h2>";

// Clear singleton
$reflection = new ReflectionClass('Database');
$instanceProp = $reflection->getProperty('instance');
$instanceProp->setAccessible(true);
$instanceProp->setValue(null, null);

// Reload config to re-define constants (this won't work because constants are already defined)
// But we can test the Database class behavior

require_once 'config/database.php';
$db = Database::getInstance(true); // Force new connection

echo "<p>Database::getCurrentDatabase(): <strong>" . Database::getCurrentDatabase() . "</strong></p>";

$businessConfig = require 'config/businesses/bens-cafe.php';
$expectedDb = $businessConfig['database'];
if ($isProduction) {
    $dbMapping = [
        'adf_benscafe' => 'adfb2574_Adf_Bens'
    ];
    $expectedDb = $dbMapping[$expectedDb] ?? $expectedDb;
}
echo "<p>Expected (from bens-cafe.php): <strong>$expectedDb</strong></p>";

if (Database::getCurrentDatabase() === $expectedDb) {
    echo "<p class='success'>✅ Database connection matches Ben's Cafe!</p>";
} else {
    echo "<p class='error'>❌ MISMATCH! Database singleton connected to wrong DB!</p>";
    echo "<p>This is likely because ACTIVE_BUSINESS_ID constant was already set before we forced the session.</p>";
    echo "<p><strong>To fix this properly, user must switch business via the Business Switcher dropdown in the UI.</strong></p>";
}
echo "</div>";

// Fix suggestion
echo "<div class='card'>";
echo "<h2>4. Diagnosis & Fix</h2>";
echo "<ol>";
echo "<li><strong>Switch Business via UI:</strong> Use the 'Switch Business' dropdown in the sidebar to select Ben's Cafe. This properly sets the session before constants are defined.</li>";
echo "<li><strong>SQL Data Issue:</strong> The adf_benscafe database may have been created by cloning adf_narayana_hotel, but the cash_book table should be empty. Verify the database was set up correctly.</li>";
echo "<li><strong>Login to correct business:</strong> Login directly via Ben's Cafe login URL if available.</li>";
echo "</ol>";
echo "<p><a href='index.php' style='padding: 10px 20px; background: #92400e; color: white; text-decoration: none; border-radius: 5px;'>Go to Dashboard</a></p>";
echo "</div>";

echo "</body></html>";
