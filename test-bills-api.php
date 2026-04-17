<?php
/**
 * Test script untuk debug Bills API
 */

session_start();

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

echo "=== BILLS API TEST ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Check session
echo "1. SESSION CHECK:\n";
echo "Session ID: " . session_id() . "\n";
echo "Session data: " . print_r($_SESSION, true) . "\n";

// 2. Check auth
echo "\n2. AUTH CHECK:\n";
$auth = new Auth();
echo "Is Logged In: " . ($auth->isLoggedIn() ? "YES" : "NO") . "\n";
echo "Current User: " . ($auth->isLoggedIn() ? $auth->getUserId() : "N/A") . "\n";

// 3. Check database connection
echo "\n3. DATABASE CHECK:\n";
try {
    $db = Database::getInstance();
    echo "Database Connected: YES\n";
} catch (Exception $e) {
    echo "Database Connected: NO\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit;
}

// 4. Check if monthly_bills table exists
echo "\n4. TABLE CHECK:\n";
try {
    $result = $db->query("SELECT COUNT(*) as count FROM monthly_bills LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "monthly_bills table: EXISTS (" . $result['count'] . " rows)\n";
} catch (Exception $e) {
    echo "monthly_bills table: DOES NOT EXIST\n";
    echo "Error: " . $e->getMessage() . "\n";
}

// 5. Check divisions table
echo "\n5. DIVISIONS TABLE CHECK:\n";
try {
    $result = $db->query("SELECT COUNT(*) as count FROM divisions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "divisions table: EXISTS (" . $result['count'] . " rows)\n";
} catch (Exception $e) {
    echo "divisions table: DOES NOT EXIST\n";
    echo "Error: " . $e->getMessage() . "\n";
}

// 6. Test API call simulation
echo "\n6. TEST API CALL:\n";
if ($auth->isLoggedIn()) {
    try {
        $month = date('Y-m');
        $query = "
            SELECT 
                mb.*,
                d.division_name
            FROM monthly_bills mb
            LEFT JOIN divisions d ON mb.division_id = d.id
            WHERE DATE_FORMAT(mb.bill_month, '%Y-%m') = ?
            LIMIT 5
        ";
        $bills = $db->query($query, [$month])->fetchAll(PDO::FETCH_ASSOC);
        echo "Fetched " . count($bills) . " bills for " . $month . "\n";
        if (count($bills) > 0) {
            echo "Sample bill: " . json_encode($bills[0]) . "\n";
        }
    } catch (Exception $e) {
        echo "Error fetching bills: " . $e->getMessage() . "\n";
    }
} else {
    echo "Cannot test API call - user not logged in\n";
}

echo "\n=== END TEST ===\n";
?>
