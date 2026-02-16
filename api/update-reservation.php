<?php
/**
 * API: Update Reservation
 * Edit reservation details (dates, room, guest info, price)
 */

// LOG ALL ERRORS
$logFile = __DIR__ . '/../api_debug.log';
ini_set('log_errors', 1);
ini_set('error_log', $logFile);

header('Content-Type: application/json');
if (ob_get_level() === 0) ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('APP_ACCESS', true);

try {
    error_log("=== update-reservation.php START ===");
    
    require_once '../config/config.php';
    error_log("config.php loaded");
    
    require_once '../config/database.php';
    error_log("database.php loaded");
    
    require_once '../includes/auth.php';
    error_log("auth.php loaded");
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    $auth = new Auth();
    error_log("Auth instantiated");
    
    if (!$auth->isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!$auth->hasPermission('frontdesk')) {
        echo json_encode(['success' => false, 'message' => 'No permission']);
        exit;
    }

    $db = Database::getInstance();
    error_log("Database instance obtained");

    $bookingId = intval($_POST['booking_id'] ?? 0);
    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    // Get current booking
    $stmt = $db->prepare("SELECT b.*, g.id as gid FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Only allow editing confirmed/pending bookings
    if (!in_array($booking['status'], ['confirmed', 'pending'])) {
        throw new Exception('Can only edit confirmed or pending reservations');
    }

    // Update guest info in guests table
    if ($booking['gid']) {
        $guestUpdates = [];
        $guestParams = [];

        if (!empty($_POST['guest_name'])) {
            $guestUpdates[] = 'guest_name = ?';
            $guestParams[] = trim($_POST['guest_name']);
        }
        if (isset($_POST['guest_phone'])) {
            $guestUpdates[] = 'phone = ?';
            $guestParams[] = trim($_POST['guest_phone']);
        }
        if (isset($_POST['guest_email'])) {
            $guestUpdates[] = 'email = ?';
            $guestParams[] = trim($_POST['guest_email']);
        }
        if (isset($_POST['guest_id_number'])) {
            $guestUpdates[] = 'id_card_number = ?';
            $guestParams[] = trim($_POST['guest_id_number']);
        }

        if (!empty($guestUpdates)) {
            $guestParams[] = $booking['gid'];
            $sql = "UPDATE guests SET " . implode(', ', $guestUpdates) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($guestParams);
        }
    }

    // Build booking update fields
    $updates = [];
    $params = [];

    if (isset($_POST['special_requests'])) {
        $updates[] = 'special_request = ?';
        $params[] = trim($_POST['special_requests']);
    }
    if (isset($_POST['num_guests'])) {
        $updates[] = 'adults = ?';
        $params[] = intval($_POST['num_guests']);
    }

    // Date changes
    $checkIn = !empty($_POST['check_in_date']) ? trim($_POST['check_in_date']) : $booking['check_in_date'];
    $checkOut = !empty($_POST['check_out_date']) ? trim($_POST['check_out_date']) : $booking['check_out_date'];
    $roomId = $booking['room_id']; // Room change not supported in this form

    $ciDate = new DateTime($checkIn);
    $coDate = new DateTime($checkOut);
    if ($coDate <= $ciDate) {
        throw new Exception('Check-out must be after check-in');
    }
    $nights = $ciDate->diff($coDate)->days;

    // Check availability if dates changed
    if ($checkIn !== $booking['check_in_date'] || $checkOut !== $booking['check_out_date']) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE room_id = ? AND id != ? 
            AND status NOT IN ('cancelled', 'checked_out')
            AND check_in_date < ? AND check_out_date > ?
        ");
        $stmt->execute([$roomId, $bookingId, $checkOut, $checkIn]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Room is not available for selected dates');
        }
    }

    // Get room price
    $roomPrice = floatval($_POST['room_price'] ?? 0);
    if (!$roomPrice) {
        $roomPrice = floatval($booking['room_price']);
    }
    if (!$roomPrice) {
        // Fallback to room_types base_price
        $stmt = $db->prepare("SELECT rt.base_price FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.id = ?");
        $stmt->execute([$roomId]);
        $rtRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $roomPrice = $rtRow ? $rtRow['base_price'] : 0;
    }

    $totalPrice = $roomPrice * $nights;
    $discount = floatval($booking['discount'] ?? 0);
    $finalPrice = $totalPrice - $discount;

    // Add date/price fields to booking update
    $updates[] = 'check_in_date = ?';
    $params[] = $checkIn;
    $updates[] = 'check_out_date = ?';
    $params[] = $checkOut;
    $updates[] = 'total_nights = ?';
    $params[] = $nights;
    $updates[] = 'room_price = ?';
    $params[] = $roomPrice;
    $updates[] = 'total_price = ?';
    $params[] = $totalPrice;
    $updates[] = 'final_price = ?';
    $params[] = $finalPrice;
    $updates[] = 'updated_at = NOW()';

    // Execute booking update
    $params[] = $bookingId;
    $sql = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Reservation updated successfully',
        'data' => [
            'booking_id' => $bookingId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'total_price' => $totalPrice,
            'final_price' => $finalPrice
        ]
    ]);

} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    error_log("Stack: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $t) {
    error_log("FATAL: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine());
    error_log("Stack: " . $t->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $t->getMessage()]);
}
error_log("=== update-reservation.php END ===");
ob_end_flush();
