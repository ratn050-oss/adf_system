<?php
/**
 * API: Add Booking Payment
 * Insert payment record and update booking payment status
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CashbookHelper.php';

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
    $bookingId = $_POST['booking_id'] ?? null;
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }
    if ($amount <= 0) {
        throw new Exception('Amount must be greater than 0');
    }

    $validMethods = ['cash', 'card', 'transfer', 'qris', 'ota', 'bank_transfer', 'other', 'edc'];
    if (!in_array($paymentMethod, $validMethods, true)) {
        $paymentMethod = 'cash';
    }

    $booking = $db->fetchOne("SELECT id, final_price, paid_amount FROM bookings WHERE id = ?", [$bookingId]);
    if (!$booking) {
        throw new Exception('Booking not found');
    }

    $db->beginTransaction();

    $db->query("INSERT INTO booking_payments (booking_id, amount, payment_method, processed_by, payment_date, created_at) VALUES (?, ?, ?, ?, NOW(), NOW())", [
        $bookingId,
        $amount,
        $paymentMethod,
        $currentUser['id']
    ]);
    $newPaymentId = $db->getConnection()->lastInsertId();

    $payment = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as paid FROM booking_payments WHERE booking_id = ?", [$bookingId]);
    $totalPaid = max((float)$payment['paid'], (float)$booking['paid_amount']);
    $remaining = max(0, (float)$booking['final_price'] - $totalPaid);

    if ($totalPaid <= 0) {
        $paymentStatus = 'unpaid';
    } elseif ($remaining <= 0) {
        $paymentStatus = 'paid';
    } else {
        $paymentStatus = 'partial';
    }

    $db->query("UPDATE bookings SET paid_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?", [
        $totalPaid,
        $paymentStatus,
        $bookingId
    ]);

    // ==========================================
    // AUTO-INSERT TO CASHBOOK SYSTEM (via Helper)
    // ONLY for DIRECT BOOKING - OTA akan tercatat saat check-in
    // ==========================================
    $cashbookInserted = false;
    $cashbookMessage = '';
    $cashAccountName = '';
    
    // Initialize OTA fee variables
    $otaFeePercent = 0;
    $otaFeeAmount = 0;
    $netAmount = $amount;
    
    try {
        // Get booking details for description
        $bookingDetails = $db->fetchOne("
            SELECT b.booking_code, b.booking_source, b.final_price, 
                   g.guest_name, r.room_number
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
        ", [$bookingId]);
        
        // Normalize booking source for matching (tiket.com -> tiket, Booking.com -> booking)
        $normalizedSource = strtolower(trim($bookingDetails['booking_source'] ?? ''));
        $normalizedSource = str_replace(['.com', '.co.id', '.id'], '', $normalizedSource);
        $normalizedSource = preg_replace('/[^a-z0-9]/', '', $normalizedSource);
        
        // Check if OTA booking - OTA payments sync at check-in, not at payment time
        $otaSources = ['agoda', 'booking', 'bookingcom', 'tiket', 'tiketcom', 'airbnb', 'ota', 'traveloka', 'pegipegi', 'expedia'];
        $isOTA = false;
        foreach ($otaSources as $ota) {
            if (strpos($normalizedSource, $ota) !== false || $normalizedSource === $ota) {
                $isOTA = true;
                break;
            }
        }
        
        // Semua booking (OTA maupun Direct) TIDAK langsung masuk buku kas
        // Pembayaran dicatat di booking_payments, masuk kas saat guest CHECK-IN setelah konfirmasi
        if ($isOTA) {
            $cashbookMessage = "Booking OTA - akan tercatat di buku kas saat check-in";
        } else {
            $cashbookMessage = "Pembayaran tersimpan - akan tercatat di buku kas saat check-in";
        }
        
    } catch (\Throwable $cashbookError) {
        // Log error but don't fail the payment
        $cashbookMessage = "Error mencatat ke buku kas: " . $cashbookError->getMessage();
        error_log("Cashbook auto-insert error: " . $cashbookError->getMessage());
    }

    $db->commit();
    
    // Prepare success message - semua pembayaran masuk kas saat CHECK-IN
    $statusLabel = $paymentStatus === 'paid' ? 'LUNAS ✅' : 'PARTIAL - Sisa: Rp ' . number_format($remaining, 0, ',', '.');
    $successMessage = "Pembayaran tersimpan ✅";
    $successMessage .= "\nRp " . number_format($amount, 0, ',', '.') . " dicatat untuk booking " . ($bookingDetails['booking_code'] ?? '');
    $successMessage .= "\n\n⏰ Akan masuk Buku Kas saat CHECK-IN";
    $successMessage .= "\nStatus: " . $statusLabel;

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'total_paid' => $totalPaid,
        'remaining' => $remaining,
        'payment_status' => $paymentStatus,
        'cashbook_inserted' => false,
        'cashbook_at_checkin' => true,
        'is_ota' => $isOTA
    ]);

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

ob_end_flush();
