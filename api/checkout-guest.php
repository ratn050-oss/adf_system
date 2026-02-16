<?php
/**
 * API: Check-out Guest
 * Update booking status to 'checked_out' and record check-out time
 */

// Suppress all errors and warnings to prevent non-JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any unexpected output
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Clean any output that might have been generated
ob_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

try {
    // Get booking ID from request
    $bookingId = $_POST['booking_id'] ?? null;
    
    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }
    
    // Get booking details
    $booking = $db->fetchOne("
        SELECT b.*, g.guest_name, r.room_number 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ", [$bookingId]);
    
    if (!$booking) {
        throw new Exception('Booking not found');
    }
    
    // Check if checked in
    if ($booking['status'] !== 'checked_in') {
        throw new Exception('Guest belum check-in, tidak bisa check-out');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Update booking status to checked_out
    $db->query("
        UPDATE bookings 
        SET status = 'checked_out',
            actual_checkout_time = NOW(),
            checked_out_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ", [$currentUser['id'], $bookingId]);
    
    // Update room status to available
    $db->query("
        UPDATE rooms 
        SET status = 'available',
            current_guest_id = NULL,
            updated_at = NOW()
        WHERE id = ?
    ", [$booking['room_id']]);
    
    // Log activity
    $db->query("
        INSERT INTO activity_logs (user_id, action, description, created_at)
        VALUES (?, ?, ?, NOW())
    ", [
        $currentUser['id'],
        'check_out',
        "Check-out guest: {$booking['guest_name']} - Room {$booking['room_number']} - Booking #{$booking['booking_code']}"
    ]);
    
    $db->commit();

    // ==========================================
    // AUTO-SYNC UNSYNC'D PAYMENTS TO CASHBOOK
    // ==========================================
    $cashbookMsg = '';
    try {
        // Get master database name (handles hosting vs local)
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $businessId = $_SESSION['business_id'] ?? 1;

        // Get all payments for this booking
        $payments = $db->fetchAll("
            SELECT id, amount, payment_method, payment_date 
            FROM booking_payments WHERE booking_id = ? ORDER BY id
        ", [$bookingId]);

        $syncCount = 0;
        foreach ($payments as $pmt) {
            // Check if already in cashbook
            $exists = $db->fetchOne("
                SELECT id FROM cash_book 
                WHERE description LIKE ? AND ABS(amount - ?) < 1 AND transaction_type = 'income'
                LIMIT 1
            ", ['%' . $booking['booking_code'] . '%', $pmt['amount']]);

            if ($exists) continue;

            // Calculate net amount (OTA fee)
            $netAmt = (float)$pmt['amount'];
            if (in_array(strtolower($pmt['payment_method']), ['ota', 'agoda', 'booking'])) {
                $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ota_fee_other_ota'");
                $feeStmt->execute();
                $feeQ = $feeStmt->fetch(PDO::FETCH_ASSOC);
                if ($feeQ && (float)$feeQ['setting_value'] > 0) {
                    $netAmt = $pmt['amount'] - ($pmt['amount'] * (float)$feeQ['setting_value'] / 100);
                }
            }

            // Find cash account
            $acctType = ($pmt['payment_method'] === 'cash') ? 'cash' : 'bank';
            $acctStmt = $masterDb->prepare("SELECT id, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
            $acctStmt->execute([$businessId, $acctType]);
            $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);
            if (!$acct) continue;

            // Division & Category
            $div = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' ORDER BY id LIMIT 1");
            if (!$div) $div = $db->fetchOne("SELECT id FROM divisions ORDER BY id LIMIT 1");
            $cat = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id LIMIT 1");
            if (!$cat) $cat = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id LIMIT 1");

            $desc = "Pembayaran Reservasi - {$booking['guest_name']} (Room {$booking['room_number']}) - {$booking['booking_code']} [CHECKOUT-SYNC]";

            $cashBookInsert = $db->getConnection()->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, cash_account_id, created_by, created_at) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())");
            $cashBookInsert->execute([
                $pmt['payment_date'], $pmt['payment_date'],
                $div['id'] ?? 1, $cat['id'] ?? 1, $desc, $netAmt,
                $pmt['payment_method'], $acct['id'], $currentUser['id']
            ]);

            $txId = $db->getConnection()->lastInsertId();
            $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                $acct['id'], $txId, $pmt['payment_date'], $desc, $netAmt, $booking['booking_code'], $currentUser['id']
            ]);
            $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$netAmt, $acct['id']]);
            $syncCount++;
        }
        if ($syncCount > 0) {
            $cashbookMsg = " | {$syncCount} pembayaran di-sync ke Buku Kas";
        }
    } catch (Exception $cbErr) {
        error_log("Checkout cashbook sync error: " . $cbErr->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Check-out berhasil! {$booking['guest_name']} - Room {$booking['room_number']}" . $cashbookMsg,
        'booking_id' => $bookingId,
        'guest_name' => $booking['guest_name'],
        'room_number' => $booking['room_number']
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Check-out Error: " . $e->getMessage());
    
    // Clean output buffer before sending JSON
    ob_clean();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Flush output buffer
ob_end_flush();
