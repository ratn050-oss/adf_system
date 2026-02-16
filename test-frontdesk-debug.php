<?php
// Debug script for Front Desk Cashbook sync
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Set default business ID for testing if not set
if (!isset($_SESSION['business_id'])) {
    $_SESSION['business_id'] = 1;
}

echo '<style>body{font-family:sans-serif;padding:20px;background:#f5f5f5} .box{background:white;padding:15px;margin-bottom:15px;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,0.1)} h3{margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px} table{width:100%;border-collapse:collapse} th,td{padding:8px;border:1px solid #ddd;text-align:left} th{background:#f9f9f9} .success{color:green;font-weight:bold} .error{color:red;font-weight:bold}</style>';

echo "<h1>🔍 DEBUG: Front Desk -> Cashbook Sync</h1>";

require_once 'config/config.php';
require_once 'config/database.php';
// require_once 'includes/auth.php'; // Skip auth for quick debug

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    echo "<div class='box'><h3>✅ Database Connected</h3>";
    echo "Host: " . DB_HOST . "<br>";
    echo "Current DB Name (from config): " . DB_NAME . "<br>";
    echo "Master DB Name (from config): " . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'Not defined') . "</div>";
    
    // 1. Check Tables
    echo "<div class='box'><h3>1. Table Structure Check</h3>";
    $tables = ['bookings', 'booking_payments', 'cash_book', 'cash_accounts'];
    echo "<ul>";
    foreach ($tables as $t) {
        $exists = $conn->query("SHOW TABLES LIKE '$t'")->rowCount() > 0;
        $status = $exists ? "<span class='success'>✅ Exists</span>" : "<span class='error'>❌ MISSING</span>";
        echo "<li>{$t}: {$status}</li>";
    }
    echo "</ul></div>";

    // 2. Master DB Connection Logic Simulation
    echo "<div class='box'><h3>2. Master DB Connection Simulation</h3>";
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
    $masterDb = null;
    $connectionMode = "UNKNOWN";

    try {
        echo "Attempting to connect to Master DB: <strong>{$masterDbName}</strong>... ";
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "<span class='success'>✅ SUCCESS</span><br>";
        $connectionMode = "MASTER_SEPARATE";
    } catch (\Throwable $e) {
        echo "<span class='error'>❌ FAILED</span> ({$e->getMessage()})<br>";
        
        echo "Attempting FALLBACK to Current DB: <strong>" . DB_NAME . "</strong>... ";
        try {
            $masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            echo "<span class='success'>✅ SUCCESS (Fallback Mode)</span><br>";
            $connectionMode = "FALLBACK_CURRENT";
        } catch (\Throwable $e2) {
            echo "<span class='error'>❌ FALLBACK FAILED</span> ({$e2->getMessage()})<br>";
            $masterDb = $conn; // Last resort
             $connectionMode = "LAST_RESORT";
        }
    }
    echo "<strong>Final Connection Mode:</strong> {$connectionMode}</div>";


    // 3. Check Cash Accounts
    echo "<div class='box'><h3>3. Cash Accounts Check (Business ID: {$_SESSION['business_id']})</h3>";
    if ($masterDb) {
        try {
            $sql = "SELECT * FROM cash_accounts WHERE business_id = ?";
            $stmt = $masterDb->prepare($sql);
            $stmt->execute([$_SESSION['business_id']]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($accounts)) {
                echo "<p class='error'>❌ No cash accounts found! This is why sync fails.</p>";
                echo "<p>Please create a cash account (Cash/Bank) for this business.</p>";
            } else {
                echo "<table><thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Balance</th><th>Is Default?</th></tr></thead><tbody>";
                foreach ($accounts as $acc) {
                    echo "<tr>
                        <td>{$acc['id']}</td>
                        <td>{$acc['account_name']}</td>
                        <td>{$acc['account_type']}</td>
                        <td>" . number_format($acc['current_balance']) . "</td>
                        <td>" . ($acc['is_default_account'] ? 'Yes' : 'No') . "</td>
                    </tr>";
                }
                echo "</tbody></table>";
            }
        } catch (\Throwable $e) {
             echo "<p class='error'>❌ Error querying cash_accounts: " . $e->getMessage() . "</p>";
        }
    }
    echo "</div>";
    
    // 4. Trace Recent Payment
    echo "<div class='box'><h3>4. Trace Last 5 Bookings & Payments</h3>";
    $sql = "
        SELECT 
            b.id as booking_id, 
            b.booking_code, 
            b.created_at,
            bp.id as payment_id,
            bp.amount,
            bp.payment_method,
            bp.synced_to_cashbook
        FROM bookings b
        LEFT JOIN booking_payments bp ON b.id = bp.booking_id
        ORDER BY b.id DESC LIMIT 5
    ";
    
    try {
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo "<table><thead><tr><th>Booking</th><th>Date</th><th>Payment ID</th><th>Amount</th><th>Synced Flag</th><th>Cashbook Check</th></tr></thead><tbody>";
        
        foreach ($rows as $row) {
            $cashbookStatus = "N/A";
            if ($row['payment_id']) {
                // Check if it exists in cash_book
                // Try literal match on amount + booking code in description
                $cbCheck = $conn->prepare("SELECT id FROM cash_book WHERE description LIKE ? AND amount = ?");
                $cbCheck->execute(['%' . $row['booking_code'] . '%', $row['amount']]);
                $cbEntry = $cbCheck->fetch();
                
                if ($cbEntry) {
                    $cashbookStatus = "<span class='success'>✅ Found (ID: {$cbEntry['id']})</span>";
                } else {
                    $cashbookStatus = "<span class='error'>❌ MISSING</span>";
                }
            }
            
            echo "<tr>
                <td>{$row['booking_code']}</td>
                <td>{$row['created_at']}</td>
                <td>{$row['payment_id']}</td>
                <td>" . ($row['amount'] ? number_format($row['amount']) : '-') . "</td>
                <td>{$row['synced_to_cashbook']}</td>
                <td>{$cashbookStatus}</td>
            </tr>";
        }
        echo "</tbody></table>";
        
    } catch (\Throwable $e) {
        echo "<p class='error'>❌ Error querying bookings: " . $e->getMessage() . "</p>";
    }
    echo "</div>";


} catch (\Throwable $e) {
    echo "<div class='box error'><h1>CRITICAL ERROR</h1>" . $e->getMessage() . "</div>";
}
?>
