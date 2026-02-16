<?php
/**
 * One-time script to trigger cashbook sync for unsynced booking payments
 * Run this once to sync all old payments, then delete this file
 */
define('APP_ACCESS', true);

// Force environment for CLI/direct run
$_SERVER['HTTP_HOST'] = 'localhost:8081';
$_SERVER['REQUEST_URI'] = '/adf_system/trigger-sync.php';

// Force hotel business BEFORE config loads
session_start();
$_SESSION['business_id'] = 1;
$_SESSION['active_business_id'] = 'narayana-hotel';

require_once 'config/config.php';
require_once 'config/database.php';

echo "<h2>Trigger Cashbook Sync</h2><pre>\n";

$db = Database::getInstance();
$pdo = $db->getConnection();

// Detect which DB we're connected to
$dbNameResult = $pdo->query("SELECT DATABASE() as db")->fetch(PDO::FETCH_ASSOC);
echo "Connected to: {$dbNameResult['db']}\n";

// Check if this is the hotel DB
$tables = $pdo->query("SHOW TABLES LIKE 'booking_payments'")->fetchAll();
if (empty($tables)) {
    echo "ERROR: No booking_payments table found. Wrong database!\n";
    echo "</pre>";
    exit;
}

// Get master DB connection
$masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
echo "Master DB: {$masterDbName}\n";
$masterDb = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$businessId = 1;

// Get first valid user from business DB
$firstUser = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cbUserId = $firstUser['id'] ?? 1;
echo "Using user ID: {$cbUserId}\n";

// Check schema
$hasCashAccountId = false;
$colChk = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
$hasCashAccountId = $colChk && $colChk->rowCount() > 0;
echo "Has cash_account_id: " . ($hasCashAccountId ? "YES" : "NO") . "\n";

// Pre-fetch division and category
$divStmt = $pdo->prepare("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%frontdesk%' ORDER BY id ASC LIMIT 1");
$divStmt->execute();
$division = $divStmt->fetch(PDO::FETCH_ASSOC);
if (!$division) {
    $divStmt = $pdo->prepare("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
    $divStmt->execute();
    $division = $divStmt->fetch(PDO::FETCH_ASSOC);
}
$divisionId = $division['id'] ?? 1;

$catStmt = $pdo->prepare("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id ASC LIMIT 1");
$catStmt->execute();
$category = $catStmt->fetch(PDO::FETCH_ASSOC);
if (!$category) {
    $catStmt = $pdo->prepare("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
    $catStmt->execute();
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);
}
$categoryId = $category['id'] ?? 1;

echo "Division ID: {$divisionId}, Category ID: {$categoryId}\n\n";

// Get unsynced payments
$stmt = $pdo->prepare("
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
$stmt->execute();
$unsyncedPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($unsyncedPayments) . " unsynced payments\n\n";

$syncCount = 0;
foreach ($unsyncedPayments as $payment) {
    echo "Processing payment #{$payment['payment_id']}: {$payment['booking_code']} - Rp " . number_format($payment['amount']) . " ({$payment['payment_method']})... ";
    
    try {
        $netAmount = (float)$payment['amount'];
        $otaFeePercent = 0;
        if (in_array(strtolower($payment['payment_method']), ['ota', 'agoda', 'booking'])) {
            $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ota_fee_other_ota'");
            $feeStmt->execute();
            $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
            if ($feeQuery) {
                $otaFeePercent = (float)($feeQuery['setting_value'] ?? 0);
                if ($otaFeePercent > 0) {
                    $netAmount = $payment['amount'] - ($payment['amount'] * $otaFeePercent / 100);
                    echo "[OTA fee {$otaFeePercent}%, net=" . number_format($netAmount) . "] ";
                }
            }
        }

        $accountType = ($payment['payment_method'] === 'cash') ? 'cash' : 'bank';
        $accountStmt = $masterDb->prepare("SELECT id, account_name, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
        $accountStmt->execute([$businessId, $accountType]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            echo "SKIP (no {$accountType} account)\n";
            continue;
        }

        $guestName = $payment['guest_name'] ?? 'Guest';
        $roomNum = $payment['room_number'] ?? '';
        $desc = "Pembayaran Reservasi - {$guestName}";
        if ($roomNum) $desc .= " (Room {$roomNum})";
        $desc .= " - {$payment['booking_code']}";

        $totalPaidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id = ?");
        $totalPaidStmt->execute([$payment['booking_id']]);
        $totalPaid = $totalPaidStmt->fetch(PDO::FETCH_ASSOC);
        $desc .= ((float)$totalPaid['total'] >= (float)$payment['final_price']) ? ' [LUNAS]' : ' [CICILAN]';

        $pmMap = ['bank_transfer'=>'transfer','credit_card'=>'debit','credit'=>'debit'];
        $cbMethod = strtolower($payment['payment_method'] ?? 'cash');
        $cbMethod = $pmMap[$cbMethod] ?? $cbMethod;
        $validMethods = ['cash','debit','transfer','qr','bank_transfer','ota','agoda','booking','other'];
        if (!in_array($cbMethod, $validMethods)) $cbMethod = 'other';

        if ($hasCashAccountId) {
            $cashBookInsert = $pdo->prepare("
                INSERT INTO cash_book (
                    transaction_date, transaction_time, division_id, category_id,
                    description, transaction_type, amount, payment_method,
                    cash_account_id, created_by, created_at
                ) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())
            ");
            $cashBookInsert->execute([
                $payment['payment_date'], $payment['payment_date'],
                $divisionId, $categoryId, $desc, $netAmount,
                $cbMethod, $account['id'], $cbUserId
            ]);
        } else {
            $cashBookInsert = $pdo->prepare("
                INSERT INTO cash_book (
                    transaction_date, transaction_time, division_id, category_id,
                    description, transaction_type, amount, payment_method,
                    created_by, created_at
                ) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, NOW())
            ");
            $cashBookInsert->execute([
                $payment['payment_date'], $payment['payment_date'],
                $divisionId, $categoryId, $desc, $netAmount,
                $cbMethod, $cbUserId
            ]);
        }

        $transactionId = $pdo->lastInsertId();

        // Mark as synced
        $markStmt = $pdo->prepare("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?");
        $markStmt->execute([$transactionId, $payment['payment_id']]);

        // Master transaction
        $masterTransInsert = $masterDb->prepare("
            INSERT INTO cash_account_transactions (
                cash_account_id, transaction_id, transaction_date,
                description, amount, transaction_type,
                reference_number, created_by, created_at
            ) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())
        ");
        $masterTransInsert->execute([
            $account['id'], $transactionId, $payment['payment_date'],
            $desc, $netAmount, $payment['booking_code'], $cbUserId
        ]);

        // Update balance
        $updateBal = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
        $updateBal->execute([$netAmount, $account['id']]);

        echo "OK → cashbook #{$transactionId} ({$account['account_name']})\n";
        $syncCount++;
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n========================================\n";
echo "Synced: {$syncCount} / " . count($unsyncedPayments) . " payments\n";

// Show final state
echo "\n--- Final State ---\n";
$finalPayments = $pdo->query("SELECT id, synced_to_cashbook, cashbook_id FROM booking_payments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($finalPayments as $fp) {
    echo "Payment #{$fp['id']}: synced={$fp['synced_to_cashbook']}, cashbook_id={$fp['cashbook_id']}\n";
}

$cashbookCount = $pdo->query("SELECT COUNT(*) as cnt FROM cash_book")->fetch(PDO::FETCH_ASSOC);
$incomeCount = $pdo->query("SELECT COUNT(*) as cnt FROM cash_book WHERE transaction_type='income'")->fetch(PDO::FETCH_ASSOC);
echo "\nCash book: {$cashbookCount['cnt']} total, {$incomeCount['cnt']} income entries\n";

echo "</pre>";
