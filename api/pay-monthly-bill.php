<?php

/**
 * API: PAY MONTHLY BILL
 * POST /api/pay-monthly-bill.php
 * 
 * Record payment for monthly bill + auto-sync to cashbook
 * 
 * POST data:
 * - bill_id: ID tagihan
 * - amount: Jumlah yang dibayar
 * - payment_method: cash, transfer, card, other
 * - cash_account_id: Dari rekening mana (FK cash_accounts.id)
 * - reference_number: Nomor bukti (opsional)
 * - notes: Catatan (opsional)
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CashbookHelper.php';

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('finance')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

try {
    // Validate
    $billId = (int)$_POST['bill_id'];
    $amount = (float)$_POST['amount'];
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $cashAccountId = (int)($_POST['cash_account_id'] ?? 0);
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!$billId || !$amount || $amount <= 0) {
        throw new Exception('Bill ID dan amount harus valid');
    }

    // Get bill details
    $bill = $db->fetchOne(
        "SELECT * FROM monthly_bills WHERE id = ? LIMIT 1",
        [$billId]
    );

    if (!$bill) {
        throw new Exception('Tagihan tidak ditemukan');
    }

    $billCode = $bill['bill_code'];
    $billName = $bill['bill_name'];
    $billMonth = $bill['bill_month'];
    $finalAmount = (float)$bill['amount'];
    $currentPaid = (float)$bill['paid_amount'];
    $newTotal = $currentPaid + $amount;

    // Validate: tidak boleh lebih dari jumlah tagihan
    if ($newTotal > $finalAmount) {
        throw new Exception("Pembayaran melebihi jumlah tagihan. Sisa: Rp " . number_format($finalAmount - $currentPaid, 0, ',', '.'));
    }

    // Determine new status
    $newStatus = 'partial';
    if ($newTotal >= $finalAmount) {
        $newStatus = 'paid';
    }

    // Insert payment record
    $paymentId = null;
    $insertPayment = $db->query(
        "INSERT INTO bill_payments 
        (bill_id, payment_date, amount, payment_method, cash_account_id, reference_number, notes, created_by)
        VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)",
        [
            $billId,
            $amount,
            $paymentMethod,
            $cashAccountId ?: null,
            $referenceNumber,
            $notes,
            $currentUser['id']
        ]
    );

    if (!$insertPayment) {
        throw new Exception('Failed to record payment');
    }

    $paymentId = $db->getConnection()->lastInsertId();

    // Update monthly_bills
    $db->query(
        "UPDATE monthly_bills SET paid_amount = paid_amount + ?, status = ? WHERE id = ?",
        [$amount, $newStatus, $billId]
    );

    // ======================================
    // AUTO-SYNC TO CASHBOOK
    // ======================================
    
    $cbHelper = new CashbookHelper($db, $currentUser['id']);
    
    // Get division & category
    $divisionId = $bill['division_id'];
    $categoryId = $bill['category_id'];
    
    if (!$divisionId) {
        $divisionId = $cbHelper->getDivisionId('Biaya Operasional');
    }
    if (!$categoryId) {
        $categoryId = $cbHelper->getCategoryId($divisionId, 'Biaya Operasional');
    }

    // Get cash account
    $accountId = $cashAccountId;
    if (!$accountId) {
        $account = $cbHelper->getCashAccount($paymentMethod);
        $accountId = $account['id'] ?? 1;
    }

    // Create cashbook entry
    $cbDescription = "{$billName} ({$billCode}) - " . ($newStatus === 'paid' ? '[LUNAS]' : '[CICILAN]');
    
    $cbResult = $db->query(
        "INSERT INTO cash_book 
        (division_id, category_id, transaction_type, transaction_date, transaction_time, amount, description, payment_method, cash_account_id, is_editable, created_by)
        VALUES (?, ?, 'expense', DATE(?), TIME(NOW()), ?, ?, ?, ?, 1, ?)",
        [
            $divisionId,
            $categoryId,
            date('Y-m-d'),
            $amount,
            $cbDescription,
            $paymentMethod,
            $accountId,
            $currentUser['id']
        ]
    );

    if (!$cbResult) {
        throw new Exception('Failed to sync to cashbook');
    }

    $cashbookId = $db->getConnection()->lastInsertId();

    // Mark as synced
    $db->query(
        "UPDATE bill_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?",
        [$cashbookId, $paymentId]
    );

    echo json_encode([
        'success' => true,
        'message' => "Pembayaran Rp " . number_format($amount, 0, ',', '.') . " berhasil dicatat",
        'bill_status' => $newStatus,
        'total_paid' => $newTotal,
        'remaining' => $finalAmount - $newTotal,
        'cashbook_id' => $cashbookId
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
