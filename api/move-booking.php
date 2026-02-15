<?php
/**
 * API: Move Booking (Drag & Drop)
 * Update booking dates and/or room when dragged on calendar
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
    $newCheckIn = trim($_POST['new_check_in'] ?? '');
    $newCheckOut = trim($_POST['new_check_out'] ?? '');
    $newRoomId = intval($_POST['new_room_id'] ?? 0);

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    // Get current booking
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Cannot move checked_out bookings
    if ($booking['status'] === 'checked_out') {
        throw new Exception('Cannot move checked-out bookings');
    }

    // Determine new dates
    $checkIn = $newCheckIn ?: $booking['check_in_date'];
    $checkOut = $newCheckOut ?: $booking['check_out_date'];
    $roomId = $newRoomId ?: $booking['room_id'];

    // Validate dates
    $ciDate = new DateTime($checkIn);
    $coDate = new DateTime($checkOut);
    if ($coDate <= $ciDate) {
        throw new Exception('Check-out must be after check-in');
    }

    $nights = $ciDate->diff($coDate)->days;

    // Check room availability (exclude current booking)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM bookings 
        WHERE room_id = ? 
        AND id != ? 
        AND status NOT IN ('cancelled', 'checked_out')
        AND check_in_date < ? 
        AND check_out_date > ?
    ");
    $stmt->execute([$roomId, $bookingId, $checkOut, $checkIn]);
    $conflict = $stmt->fetchColumn();

    if ($conflict > 0) {
        throw new Exception('Room is not available for the selected dates');
    }

    // Get room price from room_types via rooms
    $stmt = $db->prepare("
        SELECT rt.base_price 
        FROM rooms r 
        JOIN room_types rt ON r.room_type_id = rt.id 
        WHERE r.id = ?
    ");
    $stmt->execute([$roomId]);
    $roomData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Use existing room_price from booking, or base_price for new room
    $roomPrice = $booking['room_price'];
    if ($roomId != $booking['room_id'] && $roomData) {
        $roomPrice = $roomData['base_price'];
    }

    $totalPrice = $roomPrice * $nights;
    $discount = floatval($booking['discount'] ?? 0);
    $finalPrice = $totalPrice - $discount;

    // Update booking
    $stmt = $db->prepare("
        UPDATE bookings SET 
            check_in_date = ?,
            check_out_date = ?,
            room_id = ?,
            total_nights = ?,
            room_price = ?,
            total_price = ?,
            final_price = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $checkIn, $checkOut, $roomId, $nights,
        $roomPrice, $totalPrice, $finalPrice, $bookingId
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Booking moved successfully',
        'data' => [
            'booking_id' => $bookingId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_id' => $roomId,
            'nights' => $nights,
            'total_price' => $totalPrice,
            'final_price' => $finalPrice
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
