<?php
/**
 * DIAGNOSTIC: Test Cashbook Sync Flow
 * Checks WHY payments don't sync to Buku Kas Besar
 */
// Force local environment detection
$_SERVER['HTTP_HOST'] = 'localhost:8081';
$_SERVER['SERVER_PORT'] = '8081';

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

echo "<h2>🔍 Diagnostic: Cashbook Sync Test</h2>";
echo "<pre style='font-size:14px; background:#111; color:#0f0; padding:20px;'>";

$db = Database::getInstance();
$pdo = $db->getConnection();

// Step 1: Check DB connection
echo "=== STEP 1: Database Connection ===\n";
echo "Business DB: " . DB_NAME . "\n";
echo "Master DB: " . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'NOT DEFINED') . "\n";

// Step 2: Connect to master DB
$masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Master DB connection: ✅ OK\n\n";
} catch (Exception $e) {
    echo "Master DB connection: ❌ FAILED - " . $e->getMessage() . "\n\n";
    exit;
}

// Step 3: Check cash_accounts
echo "=== STEP 2: Cash Accounts (master DB) ===\n";
$businessId = 1; // Narayana Hotel
$accounts = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? AND is_active = 1");
$accounts->execute([$businessId]);
$accts = $accounts->fetchAll(PDO::FETCH_ASSOC);
foreach ($accts as $a) {
    echo "  ID={$a['id']} | {$a['account_name']} | type={$a['account_type']} | balance={$a['current_balance']} | default={$a['is_default_account']}\n";
}
echo "\n";

// Step 4: Check divisions & categories
echo "=== STEP 3: Division & Category (hotel DB) ===\n";
$div = $db->fetchOne("SELECT id, division_name FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%frontdesk%' ORDER BY id ASC LIMIT 1");
if (!$div) $div = $db->fetchOne("SELECT id, division_name FROM divisions ORDER BY id ASC LIMIT 1");
echo "  Division: ID={$div['id']} ({$div['division_name']})\n";

$cat = $db->fetchOne("SELECT id, category_name FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id ASC LIMIT 1");
if (!$cat) $cat = $db->fetchOne("SELECT id, category_name FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
echo "  Category: ID={$cat['id']} ({$cat['category_name']})\n\n";

// Step 5: Check all booking payments
echo "=== STEP 4: Booking Payments vs Cash Book ===\n";
$payments = $db->fetchAll("
    SELECT bp.id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
           b.booking_code, b.booking_source, b.final_price,
           g.guest_name, r.room_number
    FROM booking_payments bp
    JOIN bookings b ON bp.booking_id = b.id
    LEFT JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE bp.payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY bp.id ASC
");

$needSync = 0;
foreach ($payments as $p) {
    // Same duplicate check as dashboard auto-sync
    $existing = $db->fetchOne("
        SELECT id, amount, description FROM cash_book 
        WHERE description LIKE ? 
        AND ABS(amount - ?) < 1
        AND transaction_type = 'income'
        LIMIT 1
    ", ['%' . $p['booking_code'] . '%', $p['amount']]);

    $status = '❌ NOT SYNCED';
    $reason = '';
    
    if ($existing) {
        $status = '✅ SYNCED (cb_id=' . $existing['id'] . ')';
    } else {
        // Check if synced with OTA fee (net amount different)
        $anyMatch = $db->fetchOne("
            SELECT id, amount, description FROM cash_book 
            WHERE description LIKE ? 
            AND transaction_type = 'income'
            LIMIT 1
        ", ['%' . $p['booking_code'] . '%']);
        
        if ($anyMatch) {
            $status = "⚠️ SYNCED BUT AMOUNT MISMATCH (cb_id={$anyMatch['id']}, cb_amount={$anyMatch['amount']} vs pmt_amount={$p['amount']})";
        } else {
            $needSync++;
            
            // Try to figure out WHY it's not synced
            // Test the INSERT would work
            $accountType = ($p['payment_method'] === 'cash') ? 'cash' : 'bank';
            $acctStmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
            $acctStmt->execute([$businessId, $accountType]);
            $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$acct) {
                $reason = " → REASON: No cash account for type '{$accountType}'";
            } else {
                $reason = " → account={$acct['account_name']}(id={$acct['id']})";
                
                // Try a test INSERT (will rollback)
                try {
                    $db->beginTransaction();
                    
                    $desc = "TEST - {$p['guest_name']} - {$p['booking_code']}";
                    $testInsert = $pdo->prepare("
                        INSERT INTO cash_book (
                            transaction_date, transaction_time, division_id, category_id,
                            description, transaction_type, amount, payment_method,
                            cash_account_id, created_by, created_at
                        ) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, 8, NOW())
                    ");
                    $testInsert->execute([
                        $p['payment_date'], $p['payment_date'],
                        $div['id'], $cat['id'], $desc, $p['amount'],
                        $p['payment_method'], $acct['id']
                    ]);
                    $reason .= " → TEST INSERT: ✅ OK (rolled back)";
                    $db->rollBack();
                } catch (Exception $e) {
                    $db->rollBack();
                    $reason .= " → TEST INSERT: ❌ FAILED: " . $e->getMessage();
                }
            }
        }
    }
    
    echo sprintf("  Payment#%d | %s | Rp %s | %s | %s | %s%s\n",
        $p['id'], $p['booking_code'], number_format($p['amount'],0,',','.'),
        $p['payment_method'], $p['guest_name'],
        $status, $reason
    );
}

echo "\n  Total: " . count($payments) . " payments, {$needSync} need sync\n\n";

// Step 6: Check cash_book schema for potential issues
echo "=== STEP 5: Cash Book Schema Check ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM cash_book")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'Field');
echo "  Columns: " . implode(', ', $colNames) . "\n";
echo "  Has cash_account_id: " . (in_array('cash_account_id', $colNames) ? '✅' : '❌') . "\n";
echo "  Has source_type: " . (in_array('source_type', $colNames) ? '✅' : '❌') . "\n";

// Check payment_method type
$pmCol = null;
foreach ($cols as $c) {
    if ($c['Field'] === 'payment_method') $pmCol = $c;
}
echo "  payment_method type: " . ($pmCol['Type'] ?? 'UNKNOWN') . "\n";
echo "  payment_method null: " . ($pmCol['Null'] ?? 'UNKNOWN') . "\n\n";

// Step 7: Check FK constraints
echo "=== STEP 6: FK Constraints ===\n";
$fks = $pdo->query("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='cash_book' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
foreach ($fks as $fk) {
    echo "  {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} → {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
}
if (empty($fks)) echo "  (no FK constraints)\n";

// Step 8: Check users
echo "\n=== STEP 7: Users in Hotel DB ===\n";
$users = $db->fetchAll("SELECT id, username, full_name FROM users");
foreach ($users as $u) {
    echo "  ID={$u['id']} | {$u['username']} | {$u['full_name']}\n";
}

echo "\n=== SUMMARY ===\n";
echo "Total booking payments: " . count($payments) . "\n";
echo "Synced to cash_book: " . (count($payments) - $needSync) . "\n";
echo "NOT synced: {$needSync}\n";
echo "Auto-sync runs on: Dashboard page load (modules/frontdesk/dashboard.php)\n";
echo "Direct sync runs on: add-booking-payment.php, create-reservation.php, checkout-guest.php\n";

echo "\n</pre>";
