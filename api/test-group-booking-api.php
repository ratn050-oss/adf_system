<?php
// TEST: Group booking API response
header('Content-Type: application/json');

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Find a group booking (where same guest has multiple rooms on same date)
    $sql = "
        SELECT 
            b.guest_id, 
            b.check_in_date, 
            b.check_out_date,
            COUNT(DISTINCT b.room_id) as room_count,
            GROUP_CONCAT(DISTINCT b.id) as booking_ids,
            GROUP_CONCAT(DISTINCT r.room_number) as room_numbers
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status NOT IN ('cancelled')
        GROUP BY b.guest_id, LEFT(b.check_in_date, 10), LEFT(b.check_out_date, 10)
        HAVING room_count > 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $groupBooking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$groupBooking) {
        echo json_encode([
            'error' => 'No group bookings found',
            'suggestion' => 'Create a booking with multiple rooms for same guest, same date'
        ]);
        exit;
    }

    error_log("Found group booking: " . json_encode($groupBooking));

    // Get first booking ID to test
    $bookingIds = explode(',', $groupBooking['booking_ids']);
    $testBookingId = $bookingIds[0];

    echo json_encode([
        'test_setup' => $groupBooking,
        'test_booking_id' => $testBookingId,
        'calling_api_with' => "/api/get-booking-details.php?id=$testBookingId"
    ]);
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
