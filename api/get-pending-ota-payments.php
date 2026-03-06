<?php
/**
 * API: GET PENDING OTA PAYMENTS
 * Get list of OTA bookings that are checked-in but payment not yet received from platform
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

$checkinDateFrom = $_GET['from'] ?? date('Y-m-01'); // Default: this month
$checkinDateTo = $_GET['to'] ?? date('Y-m-t');
$platform = $_GET['platform'] ?? ''; // Filter by specific platform

try {
    $sql = "
        SELECT 
            b.id as booking_id,
            b.booking_code,
            b.booking_source,
            b.check_in_date,
            b.check_out_date,
            b.status,
            b.final_price,
            g.guest_name,
            r.room_number,
            bp.id as payment_id,
            bp.amount as pending_amount,
            bp.payment_date as check_in_date_actual,
            bp.notes,
            DATEDIFF(CURDATE(), b.check_in_date) as days_since_checkin
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN booking_payments bp ON b.id = bp.booking_id AND bp.payment_method = 'ota_pending'
        WHERE b.status IN ('checked_in', 'checked_out')
          AND bp.id IS NOT NULL
          AND b.check_in_date >= ?
          AND b.check_in_date <= ?
    ";
    
    $params = [$checkinDateFrom, $checkinDateTo];
    
    if ($platform) {
        $sql .= " AND LOWER(b.booking_source) LIKE ?";
        $params[] = '%' . strtolower($platform) . '%';
    }
    
    $sql .= " ORDER BY b.check_in_date DESC, b.booking_code DESC";
    
    $pendingPayments = $db->fetchAll($sql, $params);
    
    // Calculate totals
    $totalBookings = count($pendingPayments);
    $totalAmount = array_sum(array_column($pendingPayments, 'pending_amount'));
    
    // Group by platform
    $platformSummary = [];
    foreach ($pendingPayments as $payment) {
        $platform = $payment['booking_source'];
        if (!isset($platformSummary[$platform])) {
            $platformSummary[$platform] = ['count' => 0, 'amount' => 0];
        }
        $platformSummary[$platform]['count']++;
        $platformSummary[$platform]['amount'] += floatval($payment['pending_amount']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'pending_payments' => $pendingPayments,
            'summary' => [
                'date_range' => ['from' => $checkinDateFrom, 'to' => $checkinDateTo],
                'total_bookings' => $totalBookings,
                'total_amount' => $totalAmount,
                'platform_breakdown' => $platformSummary
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get pending OTA payments error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>