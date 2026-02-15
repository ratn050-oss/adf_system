<?php
// Prevent any output before JSON
ob_start();

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(0);

try {
    // Clear output buffer
    ob_clean();
    
    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';
    
    // Re-suppress errors AFTER config.php overrides them
    error_reporting(0);
    ini_set('display_errors', 0);
    ob_clean();
    
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $bookingId = $_GET['id'] ?? null;
    
    if (!$bookingId) {
        echo json_encode(['success' => false, 'message' => 'Booking ID required']);
        exit;
    }
    
    // Fetch booking with guest and room details
    $query = "
        SELECT 
            b.id,
            b.booking_code,
            b.room_id,
            b.check_in_date,
            b.check_out_date,
            b.total_nights,
            b.room_price,
            b.total_price,
            COALESCE(b.discount, 0) as discount,
            b.final_price,
            b.status,
            b.payment_status,
            b.booking_source,
            COALESCE(b.adults, 1) as adults,
            COALESCE(b.adults, 1) as num_guests,
            COALESCE(b.children, 0) as children,
            COALESCE(b.special_request, '') as special_requests,
            g.guest_name,
            g.phone as guest_phone,
            g.email as guest_email,
            COALESCE(g.id_card_number, '') as guest_id_number,
            r.room_number,
            rt.type_name as room_type,
            rt.base_price,
            COALESCE(SUM(bp.amount), b.paid_amount, 0) as paid_amount
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN booking_payments bp ON b.id = bp.booking_id
        WHERE b.id = ?
        GROUP BY b.id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found with ID: ' . $bookingId]);
        exit;
    }
    
    // Ensure all fields have values
    $booking['guest_phone'] = $booking['guest_phone'] ?? '-';
    $booking['guest_email'] = $booking['guest_email'] ?? '-';
    $booking['guest_id_number'] = $booking['guest_id_number'] ?? '-';
    
    echo json_encode([
        'success' => true,
        'booking' => $booking
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>
