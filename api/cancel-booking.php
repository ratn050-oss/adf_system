<?php
/**
 * CANCEL BOOKING API
 * Change booking status to 'cancelled' with refund calculation
 * 
 * Refund Policy:
 * - H+7 (> 7 days before check-in): 100% refund
 * - H-7 (1-7 days before check-in): 50% refund  
 * - H-1/No show (0-1 day before or same day): 0% refund
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();

// Get business_id from user or session
$businessId = $currentUser['business_id'] ?? $_SESSION['business_id'] ?? 1;

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = $input['booking_id'] ?? null;
$refundAmount = isset($input['refund_amount']) ? floatval($input['refund_amount']) : null;
$refundReason = $input['refund_reason'] ?? 'Pembatalan reservasi';

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Get booking info with guest name
    $stmt = $pdo->prepare("
        SELECT b.*, g.guest_name 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }
    
    // Cannot cancel if already checked in or checked out
    if (in_array($booking['status'], ['checked_in', 'checked_out'])) {
        echo json_encode(['success' => false, 'message' => 'Cannot cancel booking that is already checked in or checked out']);
        exit;
    }
    
    // Cannot cancel if already cancelled
    if ($booking['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'Booking is already cancelled']);
        exit;
    }
    
    // Calculate days until check-in
    $today = new DateTime('today');
    $checkInDate = new DateTime($booking['check_in_date']);
    $interval = $today->diff($checkInDate);
    $daysUntilCheckin = $interval->invert ? -$interval->days : $interval->days;
    
    // Determine refund percentage based on policy
    $refundPercentage = 0;
    $refundPolicy = '';
    
    if ($daysUntilCheckin > 7) {
        // More than 7 days before check-in: 100% refund
        $refundPercentage = 100;
        $refundPolicy = 'H+7 (> 7 hari sebelum check-in): Refund 100%';
    } elseif ($daysUntilCheckin >= 2 && $daysUntilCheckin <= 7) {
        // 2-7 days before check-in: 50% refund
        $refundPercentage = 50;
        $refundPolicy = 'H-7 (2-7 hari sebelum check-in): Refund 50%';
    } else {
        // 0-1 day or same day: 0% refund
        $refundPercentage = 0;
        $refundPolicy = 'H-1/No Show (≤1 hari): Tidak ada refund';
    }
    
    // Calculate refund amount based on paid amount
    $paidAmount = floatval($booking['paid_amount'] ?? 0);
    $calculatedRefund = ($paidAmount * $refundPercentage) / 100;
    
    // Use provided refund amount if valid, otherwise use calculated
    if ($refundAmount !== null && $refundAmount >= 0 && $refundAmount <= $paidAmount) {
        $finalRefundAmount = $refundAmount;
    } else {
        $finalRefundAmount = $calculatedRefund;
    }
    
    // Update booking status to cancelled with refund info
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'cancelled', 
            updated_at = NOW(),
            special_request = CONCAT(COALESCE(special_request, ''), '\n[CANCELLED] ', ?, ' - Refund: Rp ', ?)
        WHERE id = ?
    ");
    $stmt->execute([$refundPolicy, number_format($finalRefundAmount, 0, '', ''), $bookingId]);
    
    // Record refund in cash_book if there's refund amount
    $refundRecorded = false;
    if ($finalRefundAmount > 0) {
        // Get master database name (handles hosting vs local)
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
        
        // Get default cash account (petty cash or owner capital)
        $accountStmt = $pdo->prepare("
            SELECT id, account_name, current_balance 
            FROM {$masterDbName}.cash_accounts 
            WHERE business_id = ? AND account_type IN ('cash', 'owner_capital')
            ORDER BY current_balance DESC
            LIMIT 1
        ");
        $accountStmt->execute([$businessId]);
        $cashAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cashAccount) {
            // Insert expense record for refund
            $refundDesc = "Refund pembatalan {$booking['booking_code']} - {$booking['guest_name']} ({$refundPolicy})";
            
            $insertStmt = $pdo->prepare("
                INSERT INTO cash_book (
                    transaction_date, transaction_time, transaction_type, 
                    category, amount, description, 
                    cash_account_id, reference_id, reference_type,
                    created_by, created_at
                ) VALUES (
                    CURDATE(), CURTIME(), 'expense',
                    'refund', ?, ?,
                    ?, ?, 'booking_refund',
                    ?, NOW()
                )
            ");
            $insertStmt->execute([
                $finalRefundAmount,
                $refundDesc,
                $cashAccount['id'],
                $bookingId,
                $currentUser['id']
            ]);
            
            // Update cash account balance in master database
            $updateBalanceStmt = $pdo->prepare("
                UPDATE {$masterDbName}.cash_accounts 
                SET current_balance = current_balance - ?
                WHERE id = ?
            ");
            $updateBalanceStmt->execute([$finalRefundAmount, $cashAccount['id']]);
            
            $refundRecorded = true;
            
            error_log("REFUND RECORDED: Booking {$booking['booking_code']}, Amount: {$finalRefundAmount}, Account ID: {$cashAccount['id']}, DB: {$masterDbName}");
        } else {
            error_log("REFUND WARNING: No cash account found for business_id {$businessId}");
        }
    }
    
    // Log activity (optional - wrapped in try-catch to handle FK constraint issues)
    $logDesc = "Cancelled booking {$booking['booking_code']} - {$booking['guest_name']}. {$refundPolicy}";
    if ($finalRefundAmount > 0) {
        $logDesc .= " Refund: Rp " . number_format($finalRefundAmount, 0, ',', '.');
    }
    
    try {
        // Check if activity_logs table exists and user is valid
        $checkUser = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $checkUser->execute([$currentUser['id']]);
        
        if ($checkUser->fetch()) {
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, description, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $currentUser['id'],
                'cancel_booking',
                $logDesc
            ]);
        }
    } catch (Exception $logError) {
        // Activity logging failed but don't fail the whole transaction
        error_log("Activity log insert failed: " . $logError->getMessage());
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Booking cancelled successfully',
        'data' => [
            'booking_code' => $booking['booking_code'],
            'guest_name' => $booking['guest_name'],
            'check_in_date' => $booking['check_in_date'],
            'days_until_checkin' => $daysUntilCheckin,
            'paid_amount' => $paidAmount,
            'refund_percentage' => $refundPercentage,
            'refund_policy' => $refundPolicy,
            'refund_amount' => $finalRefundAmount,
            'refund_recorded' => $refundRecorded
        ]
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Cancel Booking Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
