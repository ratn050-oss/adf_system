<?php
/**
 * EMERGENCY SYNC FOR HOSTING
 * Forces all un-synced booking payments into the Cashbook
 * Run this URL directly!
 */

// Show ALL errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';
require_once 'config/database.php';

echo "<h1>🚑 EMERGENCY SYNC TOOL</h1>";
echo "<p>Checking database connections and missing transactions...</p>";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // 1. DATABASE CONNECTION STRATEGY
    $masterDb = null;
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
    
    echo "<ul>";
    try {
        $masterDb = new PDO("mysql:host=".DB_HOST.";dbname=".$masterDbName.";charset=".DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "<li style='color:green'>✅ Connected to Master DB: $masterDbName</li>";
    } catch (Exception $e) {
        echo "<li style='color:orange'>⚠️ Master DB connection failed. Using Local/Current DB as fallback.</li>";
        $masterDb = $conn; // Fallback
    }
    
    // 2. CHECK CASH ACCOUNT
    $businessId = $_SESSION['business_id'] ?? 1;
    if ($businessId < 1) $businessId = 1;
    
    echo "<li>Business ID: $businessId</li>";
    
    // Find ANY valid cash account to dump money into
    $account = $masterDb->query("SELECT * FROM cash_accounts WHERE business_id = $businessId LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        // CREATE ONE IF MISSING
        echo "<li style='color:red'>❌ No Cash Account Found! Creating one...</li>";
        $masterDb->query("INSERT INTO cash_accounts (business_id, account_name, account_type, currency, current_balance, is_active, is_default_account, created_at) VALUES ($businessId, 'KAS TUNAI (AUTO)', 'cash', 'IDR', 0, 1, 1, NOW())");
        $account = $masterDb->query("SELECT * FROM cash_accounts WHERE business_id = $businessId ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    
    echo "<li style='color:green'>✅ Target Wallet: <strong>{$account['account_name']}</strong> (ID: {$account['id']})</li>";
    
    // 3. CHECK MISSING TRANSACTIONS
    // Setup columns if missing
    try {
        $conn->exec("ALTER TABLE booking_payments ADD COLUMN synced_to_cashbook TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {} // Ignore if exists
    
    // Find payments not synced
    $payments = $conn->query("
        SELECT bp.*, b.booking_code, g.guest_name 
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE bp.synced_to_cashbook = 0
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<li>Found <strong>" . count($payments) . "</strong> payments waiting to sync.</li>";
    echo "</ul>";
    
    echo "<hr><h3>Syncing Now...</h3>";
    
    $successCount = 0;
    
    foreach ($payments as $p) {
        echo "Processing {$p['booking_code']} (Rp ".number_format($p['amount']).")... ";
        
        try {
            // Check if already in cashbook (double check)
            $chk = $conn->query("SELECT id FROM cash_book WHERE description LIKE '%{$p['booking_code']}%' AND amount = {$p['amount']}")->fetch();
            
            if ($chk) {
                // Mark as synced only
                $conn->query("UPDATE booking_payments SET synced_to_cashbook = 1 WHERE id = {$p['id']}");
                echo "<span style='color:blue'>Already in Cashbook. Marked as synced.</span><br>";
                continue;
            }
            
            // PREPARE DATA
            $desc = "Pembayaran Reservasi - {$p['guest_name']} - {$p['booking_code']} (AUTO-SYNC)";
            $amount = $p['amount'];
            $method = $p['payment_method'];
            $divisionId = 1; // Default
            $categoryId = 1; // Default
            
            // Get proper IDs
            $div = $conn->query("SELECT id FROM divisions ORDER BY id ASC LIMIT 1")->fetch();
            if ($div) $divisionId = $div['id'];
            
            $cat = $conn->query("SELECT id FROM categories WHERE category_type='income' ORDER BY id ASC LIMIT 1")->fetch();
            if ($cat) $categoryId = $cat['id'];
            
            // INSERT TO CASHBOOK
            // Detect schema
            $hasAccId = false;
            try { $conn->query("SELECT cash_account_id FROM cash_book LIMIT 1"); $hasAccId = true; } catch(Exception $e){}
            
            if ($hasAccId) {
                $stmt = $conn->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, cash_account_id, created_by, created_at) VALUES (NOW(), NOW(), ?, ?, ?, 'income', ?, ?, ?, 1, NOW())");
                $stmt->execute([$divisionId, $categoryId, $desc, $amount, 'cash', $account['id']]);
            } else {
                $stmt = $conn->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, created_by, created_at) VALUES (NOW(), NOW(), ?, ?, ?, 'income', ?, ?, 1, NOW())");
                $stmt->execute([$divisionId, $categoryId, $desc, $amount, 'cash']);
            }
            
            $transId = $conn->lastInsertId();
            
            // INSERT TO MASTER TRANSACTION for Balance
            $stmt2 = $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, created_by, created_at) VALUES (?, ?, NOW(), ?, ?, 'income', 1, NOW())");
            $stmt2->execute([$account['id'], $transId, $desc, $amount]);
            
            // UPDATE BALANCE
            $masterDb->query("UPDATE cash_accounts SET current_balance = current_balance + $amount WHERE id = {$account['id']}");
            
            // MARK SYNCED
            $conn->query("UPDATE booking_payments SET synced_to_cashbook = 1 WHERE id = {$p['id']}");
            
            echo "<span style='color:green'><strong>SUCCESS!</strong></span><br>";
            $successCount++;
            
        } catch (Exception $e) {
            echo "<span style='color:red'>ERROR: " . $e->getMessage() . "</span><br>";
        }
    }
    
    echo "<h3>DONE. Synced $successCount transactions.</h3>";
    echo "<p><a href='modules/cashbook/index.php'>&laquo; Kembali ke Buku Kas</a></p>";

} catch (Exception $e) {
    echo "<h1>FATAL ERROR</h1>" . $e->getMessage();
}
