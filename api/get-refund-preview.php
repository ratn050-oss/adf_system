<?php
/**
 * GET REFUND PREVIEW API
 * Calculate refund amount based on cancellation policy
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pdo = $db->getConnection();

$bookingId = $_GET['booking_id'] ?? null;

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

try {
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
    
    // Calculate days until check-in
    $today = new DateTime('today');
    $checkInDate = new DateTime($booking['check_in_date']);
    $interval = $today->diff($checkInDate);
    $daysUntilCheckin = $interval->invert ? -$interval->days : $interval->days;
    
    // Determine refund percentage based on policy
    $refundPercentage = 0;
    $refundPolicy = '';
    $policyColor = '';
    
    if ($daysUntilCheckin > 7) {
        // More than 7 days before check-in: 100% refund
        $refundPercentage = 100;
        $refundPolicy = 'H+7 (> 7 hari sebelum check-in)';
        $policyColor = '#10b981'; // green
    } elseif ($daysUntilCheckin >= 2 && $daysUntilCheckin <= 7) {
        // 2-7 days before check-in: 50% refund
        $refundPercentage = 50;
        $refundPolicy = 'H-7 (2-7 hari sebelum check-in)';
        $policyColor = '#f59e0b'; // orange
    } else {
        // 0-1 day or same day: 0% refund
        $refundPercentage = 0;
        $refundPolicy = 'H-1/No Show (≤ 1 hari sebelum check-in)';
        $policyColor = '#ef4444'; // red
    }
    
    // Calculate refund amount
    $paidAmount = floatval($booking['paid_amount'] ?? 0);
    $refundAmount = ($paidAmount * $refundPercentage) / 100;
    $forfeitAmount = $paidAmount - $refundAmount;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'booking_id' => $booking['id'],
            'booking_code' => $booking['booking_code'],
            'guest_name' => $booking['guest_name'],
            'check_in_date' => $booking['check_in_date'],
            'check_out_date' => $booking['check_out_date'],
            'final_price' => floatval($booking['final_price']),
            'paid_amount' => $paidAmount,
            'days_until_checkin' => $daysUntilCheckin,
            'refund_policy' => $refundPolicy,
            'refund_percentage' => $refundPercentage,
            'refund_amount' => $refundAmount,
            'forfeit_amount' => $forfeitAmount,
            'policy_color' => $policyColor
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get Refund Preview Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
