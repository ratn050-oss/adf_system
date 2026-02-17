<?php
/**
 * FIX: Add missing synced_to_cashbook column and sync existing payments
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
session_start();
$_SESSION['business_id'] = 1;
$_SESSION['user_id'] = 1;

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/CashbookHelper.php';

echo "<h1>🔧 Fix Cashbook Sync</h1>";
echo "<pre>";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // STEP 1: Check and add synced_to_cashbook column
    echo "=== STEP 1: Check booking_payments table ===\n";
    
    $columns = $pdo->query("SHOW COLUMNS FROM booking_payments LIKE 'synced_to_cashbook'")->fetchAll();
    if (empty($columns)) {
        echo "❌ Column 'synced_to_cashbook' MISSING! Adding now...\n";
        $pdo->exec("ALTER TABLE booking_payments ADD COLUMN synced_to_cashbook TINYINT(1) NOT NULL DEFAULT 0");
        echo "✅ Column 'synced_to_cashbook' ADDED!\n";
    } else {
        echo "✅ Column 'synced_to_cashbook' exists\n";
    }
    
    $columns = $pdo->query("SHOW COLUMNS FROM booking_payments LIKE 'cashbook_id'")->fetchAll();
    if (empty($columns)) {
        echo "❌ Column 'cashbook_id' MISSING! Adding now...\n";
        $pdo->exec("ALTER TABLE booking_payments ADD COLUMN cashbook_id INT(11) DEFAULT NULL");
        echo "✅ Column 'cashbook_id' ADDED!\n";
    } else {
        echo "✅ Column 'cashbook_id' exists\n";
    }
    
    // STEP 2: Check existing synced status
    echo "\n=== STEP 2: Check existing payments ===\n";
    
    $totalPayments = $pdo->query("SELECT COUNT(*) as cnt FROM booking_payments")->fetch(PDO::FETCH_ASSOC);
    echo "Total payments: " . $totalPayments['cnt'] . "\n";
    
    $unsyncedCount = $pdo->query("SELECT COUNT(*) as cnt FROM booking_payments WHERE synced_to_cashbook = 0")->fetch(PDO::FETCH_ASSOC);
    echo "Unsynced payments: " . $unsyncedCount['cnt'] . "\n";
    
    $syncedCount = $pdo->query("SELECT COUNT(*) as cnt FROM booking_payments WHERE synced_to_cashbook = 1")->fetch(PDO::FETCH_ASSOC);
    echo "Already synced: " . $syncedCount['cnt'] . "\n";
    
    // STEP 3: Mark existing cashbook entries as synced
    echo "\n=== STEP 3: Mark already-synced payments ===\n";
    
    // Find payments that are already in cashbook but not marked as synced
    // Use COLLATE to avoid collation mismatch between tables
    $alreadyInCashbook = $pdo->query("
        SELECT bp.id as payment_id, b.booking_code, cb.id as cashbook_id
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        JOIN cash_book cb ON cb.description COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', b.booking_code COLLATE utf8mb4_unicode_ci, '%')
            AND ABS(cb.amount - bp.amount) < 1
        WHERE bp.synced_to_cashbook = 0
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($alreadyInCashbook) . " payments already in cashbook but not marked:\n";
    
    foreach ($alreadyInCashbook as $item) {
        $pdo->prepare("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?")
            ->execute([$item['cashbook_id'], $item['payment_id']]);
        echo "  - Marked payment #{$item['payment_id']} ({$item['booking_code']}) as synced to cashbook #{$item['cashbook_id']}\n";
    }
    
    // STEP 4: Sync remaining payments
    echo "\n=== STEP 4: Sync remaining unsynced payments ===\n";
    
    if (isset($_GET['sync'])) {
        $helper = new CashbookHelper($db, 1, 1);
        
        $unsynced = $db->fetchAll("
            SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                   b.booking_code, b.booking_source, b.final_price,
                   g.guest_name, r.room_number
            FROM booking_payments bp
            JOIN bookings b ON bp.booking_id = b.id
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE bp.synced_to_cashbook = 0
            ORDER BY bp.id ASC
        ");
        
        echo "Syncing " . count($unsynced) . " payments...\n\n";
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($unsynced as $payment) {
            echo "Processing #{$payment['payment_id']}: {$payment['booking_code']} - Rp " . number_format($payment['amount']) . "... ";
            
            $totalPaid = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id = ?", [$payment['booking_id']]);
            
            $result = $helper->syncPaymentToCashbook([
                'payment_id' => $payment['payment_id'],
                'booking_id' => $payment['booking_id'],
                'amount' => $payment['amount'],
                'payment_method' => $payment['payment_method'] ?? 'cash',
                'guest_name' => $payment['guest_name'] ?? 'Guest',
                'booking_code' => $payment['booking_code'],
                'room_number' => $payment['room_number'] ?? '',
                'booking_source' => $payment['booking_source'] ?? '',
                'final_price' => $payment['final_price'] ?? 0,
                'total_paid' => $totalPaid['total'] ?? $payment['amount'],
                'payment_date' => $payment['payment_date'],
                'is_new_reservation' => false
            ]);
            
            if ($result['success']) {
                echo "✅ OK (cashbook #{$result['transaction_id']})\n";
                $successCount++;
            } else {
                echo "❌ FAILED: {$result['message']}\n";
                $errorCount++;
            }
        }
        
        echo "\n=== SYNC COMPLETE ===\n";
        echo "Success: $successCount\n";
        echo "Errors: $errorCount\n";
        
    } else {
        $stillUnsynced = $pdo->query("SELECT COUNT(*) as cnt FROM booking_payments WHERE synced_to_cashbook = 0")->fetch(PDO::FETCH_ASSOC);
        
        if ($stillUnsynced['cnt'] > 0) {
            echo "\n⚠️ Still have " . $stillUnsynced['cnt'] . " unsynced payments.\n";
            echo "\n<a href='?sync=1' style='background: green; color: white; padding: 15px 30px; text-decoration: none; font-size: 18px; border-radius: 5px;'>🔄 CLICK TO SYNC ALL NOW</a>\n";
        } else {
            echo "\n✅ All payments are synced!\n";
        }
    }
    
    echo "\n=== DONE ===\n";
    
} catch (Throwable $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}

echo "</pre>";
echo "<p><a href='modules/cashbook/index.php'>→ Buku Kas</a> | <a href='modules/frontdesk/calendar.php'>→ Calendar</a></p>";
