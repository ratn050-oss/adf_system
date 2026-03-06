<?php
/**
 * API: RECEIVE OTA PAYMENT 
 * Convert OTA pending payment to actual received payment and sync to cashbook
 * Digunakan ketika OTA benar-benar transfer uang ke hotel
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk') && !$auth->hasPermission('admin') && !$auth->hasPermission('manager')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = $input['booking_id'] ?? null;
$actualAmount = isset($input['actual_amount']) ? floatval($input['actual_amount']) : null;
$receivedDate = $input['received_date'] ?? date('Y-m-d');
$notes = $input['notes'] ?? '';

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

try {
    $db->beginTransaction();
    
    // Get booking info
    $booking = $db->fetchOne("
        SELECT b.*, g.guest_name, r.room_number
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id  
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ", [$bookingId]);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }
    
    // Get pending OTA payment
    $otaPayment = $db->fetchOne("
        SELECT * FROM booking_payments 
        WHERE booking_id = ? AND payment_method = 'ota_pending'
        ORDER BY id DESC LIMIT 1
    ", [$bookingId]);
    
    if (!$otaPayment) {
        echo json_encode(['success' => false, 'message' => 'No pending OTA payment found for this booking']);
        exit;
    }
    
    $pendingAmount = floatval($otaPayment['amount']);
    $finalAmount = $actualAmount !== null ? $actualAmount : $pendingAmount;
    
    // Update payment record to mark as received
    $db->query("
        UPDATE booking_payments 
        SET payment_method = ?, 
            amount = ?,
            payment_date = ?,
            notes = CONCAT(COALESCE(notes, ''), '\n[OTA RECEIVED] ', ?),
            synced_to_cashbook = 0
        WHERE id = ?
    ", [
        strtolower($booking['booking_source']), // agoda, booking, etc
        $finalAmount,
        $receivedDate . ' ' . date('H:i:s'),
        $notes ?: "Transfer diterima dari " . $booking['booking_source'],
        $otaPayment['id']
    ]);
    
    // Sync to cashbook
    $cashbookSynced = false;
    $cashbookMessage = '';
    
    try {
        require_once '../includes/CashbookHelper.php';
        $businessId = $_SESSION['business_id'] ?? $currentUser['business_id'] ?? 1;
        $userId = $currentUser['id'] ?? 1;
        
        $cashbookHelper = new CashbookHelper($db, $businessId, $userId);
        
        $syncResult = $cashbookHelper->syncPaymentToCashbook([
            'payment_id'     => $otaPayment['id'],
            'booking_id'     => $bookingId,
            'amount'         => $finalAmount,
            'payment_method' => strtolower($booking['booking_source']),
            'guest_name'     => $booking['guest_name'],
            'booking_code'   => $booking['booking_code'],
            'room_number'    => $booking['room_number'],
            'booking_source' => $booking['booking_source'],
            'final_price'    => $booking['final_price'],
            'total_paid'     => $finalAmount,
            'is_new_reservation' => false,
            'is_ota_received' => true,
            'received_date' => $receivedDate
        ]);
        
        if ($syncResult['success']) {
            $cashbookSynced = true;
            $cashbookMessage = "Berhasil tercatat di buku kas";
            
            // Update payment record with cashbook ID
            if (!empty($syncResult['transaction_id'])) {
                $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", 
                    [$syncResult['transaction_id'], $otaPayment['id']]);
            }
        } else {
            $cashbookMessage = "Error: " . ($syncResult['message'] ?? 'Unknown error');
        }
        
    } catch (Exception $e) {
        $cashbookMessage = "Cashbook error: " . $e->getMessage();
        error_log("OTA receive cashbook error: " . $e->getMessage());
    }
    
    // Log activity
    $description = "Received OTA payment: {$booking['booking_code']} - {$booking['guest_name']} from {$booking['booking_source']}";
    if ($actualAmount !== null && $actualAmount != $pendingAmount) {
        $description .= " (Adjusted: " . number_format($pendingAmount, 0, ',', '.') . " → " . number_format($actualAmount, 0, ',', '.') . ")";
    }
    
    try {
        $db->query("INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())", [
            $currentUser['id'],
            'receive_ota_payment',
            $description
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the process
        error_log("Activity log error: " . $e->getMessage());
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Pembayaran OTA berhasil diterima!\n\nBooking: {$booking['booking_code']}\nTamu: {$booking['guest_name']}\nPlatform: {$booking['booking_source']}\nJumlah: Rp " . number_format($finalAmount, 0, ',', '.') . "\n\n" . $cashbookMessage,
        'data' => [
            'booking_id' => $bookingId,
            'booking_code' => $booking['booking_code'],
            'guest_name' => $booking['guest_name'],
            'platform' => $booking['booking_source'],
            'pending_amount' => $pendingAmount,
            'received_amount' => $finalAmount,
            'received_date' => $receivedDate,
            'cashbook_synced' => $cashbookSynced,
            'cashbook_message' => $cashbookMessage
        ]
    ]);
    
} catch (Exception $e) {
    $db->rollback();
    error_log("Receive OTA payment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>