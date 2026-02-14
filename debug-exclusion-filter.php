<?php
// Debug script to check exclusion filter logic
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';

echo "<h2>🔍 Debug Exclusion Filter Logic</h2>";

// Simulate the exact code from index.php
$ownerCapitalAccountIds = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessIdentifier = ACTIVE_BUSINESS_ID;
    $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
    $businessId = $businessMapping[$businessIdentifier] ?? 1;
    
    echo "<p><strong>Business Identifier:</strong> {$businessIdentifier}</p>";
    echo "<p><strong>Mapped Business ID:</strong> {$businessId}</p>";
    
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>Owner Capital Account IDs:</strong> ";
    if (empty($ownerCapitalAccountIds)) {
        echo "<span style='color: red;'>EMPTY ARRAY! ❌</span>";
    } else {
        echo "<span style='color: green;'>[" . implode(', ', $ownerCapitalAccountIds) . "] ✅</span>";
    }
    echo "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Build exclusion clause
$excludeOwnerCapital = '';
if (!empty($ownerCapitalAccountIds)) {
    $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

echo "<hr>";
echo "<p><strong>Exclusion Clause:</strong></p>";
if ($excludeOwnerCapital === '') {
    echo "<pre style='color: red; background: #ffe6e6; padding: 10px;'>EMPTY STRING! This means NO exclusion will happen! ❌</pre>";
} else {
    echo "<pre style='color: green; background: #e6ffe6; padding: 10px;'>{$excludeOwnerCapital}</pre>";
}

// Test the actual query
echo "<hr>";
echo "<h3>🧪 Test Monthly Income Query</h3>";

try {
    require_once 'config/database.php';
    $db = Database::getInstance();
    
    $thisMonth = date('Y-m');
    
    $query = "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
              WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = :month" . $excludeOwnerCapital;
    
    echo "<p><strong>Full Query:</strong></p>";
    echo "<pre style='background: #f0f0f0; padding: 10px;'>" . str_replace(':month', "'{$thisMonth}'", $query) . "</pre>";
    
    $monthlyIncomeResult = $db->fetchAll($query, ['month' => $thisMonth]);
    $monthlyIncome = $monthlyIncomeResult[0]['total'] ?? 0;
    
    echo "<p style='font-size: 1.2em;'><strong>Result:</strong> <span style='color: blue;'>Rp " . number_format($monthlyIncome, 0, ',', '.') . "</span></p>";
    
    // Compare with expected
    echo "<hr>";
    echo "<h3>Expected Result:</h3>";
    echo "<p>Transaction ID 1216: Rp 5.000.000 (cash_account_id = 2 = owner) → <strong style='color: red;'>SHOULD BE EXCLUDED</strong></p>";
    echo "<p>Transaction ID 1218: Rp 500.000 (cash_account_id = 1 = operational) → <strong style='color: green;'>SHOULD BE INCLUDED</strong></p>";
    echo "<p><strong>Expected Total:</strong> Rp 500.000</p>";
    
    if ($monthlyIncome == 500000) {
        echo "<p style='color: green; font-weight: bold; font-size: 1.2em;'>✅ CORRECT! Filter is working!</p>";
    } else if ($monthlyIncome == 5500000) {
        echo "<p style='color: red; font-weight: bold; font-size: 1.2em;'>❌ WRONG! Filter is NOT working - includes owner capital!</p>";
    } else {
        echo "<p style='color: orange; font-weight: bold; font-size: 1.2em;'>⚠️ Unexpected result!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='index.php'>← Back to Dashboard</a></p>";
?>
