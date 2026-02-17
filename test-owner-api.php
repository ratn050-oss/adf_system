<?php
/**
 * Test Owner APIs - Debug Script
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Testing Owner APIs</h1>";
echo "<hr>";

// Test 1: owner-branches.php
echo "<h2>1. Testing owner-branches.php</h2>";
$url1 = 'http://localhost:8081/adf_system/api/owner-branches.php';
$response1 = @file_get_contents($url1);
echo "<pre>";
echo "URL: $url1\n";
echo "Response:\n";
echo $response1 ? $response1 : "ERROR: Could not fetch";
echo "</pre>";
echo "<hr>";

// Test 2: owner-stats.php?branch_id=all
echo "<h2>2. Testing owner-stats.php (all)</h2>";
$url2 = 'http://localhost:8081/adf_system/api/owner-stats.php?branch_id=all';
$response2 = @file_get_contents($url2);
echo "<pre>";
echo "URL: $url2\n";
echo "Response:\n";
echo $response2 ? $response2 : "ERROR: Could not fetch";
echo "</pre>";
echo "<hr>";

// Test 3: owner-stats.php?branch_id=1
echo "<h2>3. Testing owner-stats.php (branch_id=1)</h2>";
$url3 = 'http://localhost:8081/adf_system/api/owner-stats.php?branch_id=1';
$response3 = @file_get_contents($url3);
echo "<pre>";
echo "URL: $url3\n";
echo "Response:\n";
echo $response3 ? $response3 : "ERROR: Could not fetch";
echo "</pre>";
echo "<hr>";

// Test 4: owner-occupancy.php?branch_id=1
echo "<h2>4. Testing owner-occupancy.php (branch_id=1)</h2>";
$url4 = 'http://localhost:8081/adf_system/api/owner-occupancy.php?branch_id=1';
$response4 = @file_get_contents($url4);
echo "<pre>";
echo "URL: $url4\n";
echo "Response:\n";
echo $response4 ? $response4 : "ERROR: Could not fetch";
echo "</pre>";
echo "<hr>";

// Test 5: Check businesses table
echo "<h2>5. Checking Businesses Table</h2>";
require_once 'config/config.php';
require_once 'config/database.php';

try {
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=adf_system;charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $mainPdo->query("SELECT id, business_name, database_name, is_active FROM businesses");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<pre>";
    echo "Businesses found: " . count($businesses) . "\n\n";
    print_r($businesses);
    echo "</pre>";
} catch (Exception $e) {
    echo "<pre>ERROR: " . $e->getMessage() . "</pre>";
}
echo "<hr>";

// Test 6: Check cash_book data
echo "<h2>6. Checking Cash Book Data (Today & This Month)</h2>";
try {
    $db = Database::getInstance();
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    
    // Today income
    $todayIncome = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND transaction_date = ?",
        [$today]
    );
    
    // Today expense
    $todayExpense = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND transaction_date = ?",
        [$today]
    );
    
    // Month income
    $monthIncome = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        [$thisMonth]
    );
    
    // Month expense
    $monthExpense = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        [$thisMonth]
    );
    
    echo "<pre>";
    echo "Date: $today\n";
    echo "Month: $thisMonth\n\n";
    echo "Today Income: Rp " . number_format($todayIncome['total'], 0, ',', '.') . "\n";
    echo "Today Expense: Rp " . number_format($todayExpense['total'], 0, ',', '.') . "\n";
    echo "Today Profit: Rp " . number_format($todayIncome['total'] - $todayExpense['total'], 0, ',', '.') . "\n\n";
    echo "Month Income: Rp " . number_format($monthIncome['total'], 0, ',', '.') . "\n";
    echo "Month Expense: Rp " . number_format($monthExpense['total'], 0, ',', '.') . "\n";
    echo "Month Profit: Rp " . number_format($monthIncome['total'] - $monthExpense['total'], 0, ',', '.') . "\n";
    echo "</pre>";
} catch (Exception $e) {
    echo "<pre>ERROR: " . $e->getMessage() . "</pre>";
}

echo "<hr>";
echo "<p><strong>Test completed!</strong></p>";
?>
