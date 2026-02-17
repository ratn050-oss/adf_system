<?php
/**
 * Test CashbookHelper - WITH ACTUAL SYNC
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

echo "<h2>Testing CashbookHelper</h2><pre>";

try {
    $db = Database::getInstance();
    echo "Database connected: OK\n";
    
    $helper = new CashbookHelper($db, 1, 1);
    echo "CashbookHelper loaded: OK\n\n";
    
    // Test cash account
    echo "=== CASH ACCOUNT TEST ===\n";
    $account = $helper->getCashAccount('cash');
    if ($account) {
        echo "Cash Account Found: " . $account['account_name'] . " (ID: " . $account['id'] . ")\n";
        echo "Current Balance: Rp " . number_format($account['current_balance']) . "\n";
    } else {
        echo "ERROR: No cash account found!\n";
    }
    
    // Test division
    echo "\n=== DIVISION TEST ===\n";
    $divId = $helper->getDivisionId();
    echo "Division ID: " . $divId . "\n";
    
    // Test category
    echo "\n=== CATEGORY TEST ===\n";
    $catId = $helper->getCategoryId();
    echo "Category ID: " . $catId . "\n";
    
    // Check unsynced payments
    echo "\n=== UNSYNCED PAYMENTS ===\n";
    $unsynced = $db->fetchAll("
        SELECT bp.id, bp.amount, bp.synced_to_cashbook, bp.booking_id, b.booking_code, b.booking_source, b.final_price,
               g.guest_name, r.room_number
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE bp.synced_to_cashbook = 0
        ORDER BY bp.id DESC
        LIMIT 10
    ");
    echo "Found " . count($unsynced) . " unsynced payments:\n";
    foreach ($unsynced as $u) {
        echo "  - Payment #{$u['id']}: {$u['booking_code']} - Rp " . number_format($u['amount']) . " (synced={$u['synced_to_cashbook']})\n";
    }
    
    // Check recent cash_book entries
    echo "\n=== RECENT CASHBOOK ENTRIES ===\n";
    $recent = $db->fetchAll("
        SELECT id, transaction_date, description, amount, transaction_type
        FROM cash_book
        WHERE transaction_type = 'income'
        ORDER BY id DESC
        LIMIT 5
    ");
    echo "Last 5 income entries:\n";
    foreach ($recent as $r) {
        echo "  - #{$r['id']} [{$r['transaction_date']}]: {$r['description']} - Rp " . number_format($r['amount']) . "\n";
    }
    
    // TEST ACTUAL SYNC IF THERE ARE UNSYNCED PAYMENTS
    if (!empty($unsynced) && isset($_GET['sync'])) {
        echo "\n=== ACTUAL SYNC TEST ===\n";
        $testPayment = $unsynced[0];
        echo "Syncing payment #{$testPayment['id']}: {$testPayment['booking_code']}...\n";
        
        // Get total paid
        $totalPaid = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id = ?", [$testPayment['booking_id']]);
        
        $syncResult = $helper->syncPaymentToCashbook([
            'payment_id' => $testPayment['id'],
            'booking_id' => $testPayment['booking_id'],
            'amount' => $testPayment['amount'],
            'payment_method' => 'cash',
            'guest_name' => $testPayment['guest_name'] ?? 'Guest',
            'booking_code' => $testPayment['booking_code'],
            'room_number' => $testPayment['room_number'] ?? '',
            'booking_source' => $testPayment['booking_source'] ?? '',
            'final_price' => $testPayment['final_price'] ?? 0,
            'total_paid' => $totalPaid['total'] ?? $testPayment['amount'],
            'is_new_reservation' => false
        ]);
        
        echo "Result:\n";
        echo "  - Success: " . ($syncResult['success'] ? 'YES' : 'NO') . "\n";
        echo "  - Transaction ID: " . ($syncResult['transaction_id'] ?? 'N/A') . "\n";
        echo "  - Account: " . ($syncResult['account_name'] ?? 'N/A') . "\n";
        echo "  - Message: " . ($syncResult['message'] ?? 'N/A') . "\n";
        
        if ($syncResult['success']) {
            echo "\n✅ SYNC SUCCESSFUL!\n";
        } else {
            echo "\n❌ SYNC FAILED!\n";
        }
    } elseif (!empty($unsynced)) {
        echo "\n<a href='?sync=1' style='background: green; color: white; padding: 10px 20px; text-decoration: none;'>🔄 CLICK TO TEST SYNC ONE PAYMENT</a>\n";
    }
    
    echo "\n✅ All tests completed!\n";
    
} catch (Throwable $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<p><a href='force-sync-hosting.php'>→ Manual Sync (Working)</a> | <a href='modules/cashbook/index.php'>→ Buku Kas</a></p>";
