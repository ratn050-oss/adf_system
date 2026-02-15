<?php
/**
 * API: Extend Stay
 * Extend check-out date for checked-in guests
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Re-suppress errors AFTER config.php overrides them
error_reporting(0);
ini_set('display_errors', 0);
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

try {
    $bookingId = intval($_POST['booking_id'] ?? 0);
    $extraNights = intval($_POST['extra_nights'] ?? 0);

    if (!$bookingId || $extraNights < 1) {
        throw new Exception('Booking ID and extra nights (min 1) are required');
    }

    // Get current booking
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'checked_in'");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found or not checked-in');
    }

    // Calculate new checkout
    $currentCheckout = new DateTime($booking['check_out_date']);
    $newCheckout = clone $currentCheckout;
    $newCheckout->modify("+{$extraNights} days");
    $newCheckoutStr = $newCheckout->format('Y-m-d');

    // Check room availability for extended period
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM bookings 
        WHERE room_id = ? 
        AND id != ? 
        AND status NOT IN ('cancelled', 'checked_out')
        AND check_in_date < ? 
        AND check_out_date > ?
    ");
    $stmt->execute([$booking['room_id'], $bookingId, $newCheckoutStr, $currentCheckout->format('Y-m-d')]);
    $conflict = $stmt->fetchColumn();

    if ($conflict > 0) {
        throw new Exception('Room is not available for the extended dates. Another booking exists.');
    }

    // Use room_price from booking
    $roomPrice = floatval($booking['room_price']);

    $newTotalNights = $booking['total_nights'] + $extraNights;
    $additionalPrice = $roomPrice * $extraNights;
    $newTotalPrice = floatval($booking['total_price']) + $additionalPrice;
    $discount = floatval($booking['discount'] ?? 0);
    $finalPrice = $newTotalPrice - $discount;

    // Update booking
    $stmt = $db->prepare("
        UPDATE bookings SET 
            check_out_date = ?,
            total_nights = ?,
            total_price = ?,
            final_price = ?,
            payment_status = CASE 
                WHEN paid_amount >= ? THEN 'paid'
                WHEN paid_amount > 0 THEN 'partial'
                ELSE 'unpaid'
            END,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $newCheckoutStr, $newTotalNights, $newTotalPrice,
        $finalPrice, $finalPrice, $bookingId
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Stay extended by {$extraNights} night(s). New checkout: " . $newCheckout->format('d M Y'),
        'data' => [
            'booking_id' => $bookingId,
            'new_checkout' => $newCheckoutStr,
            'total_nights' => $newTotalNights,
            'additional_price' => $additionalPrice,
            'new_total_price' => $newTotalPrice,
            'final_price' => $finalPrice
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
