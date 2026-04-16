<?php
// CRITICAL: Set header & prevent output FIRST
header('Content-Type: application/json');
if (ob_get_level() === 0) ob_start();
error_reporting(0);
ini_set('display_errors', 0);

try {
    // Clear any buffered output
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();

    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';

    // Re-suppress errors AFTER config.php
    error_reporting(0);
    ini_set('display_errors', 0);

    $db = Database::getInstance();
    $conn = $db->getConnection();

    $bookingId = intval($_GET['id'] ?? 0);

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
            b.group_id,
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

    // Fetch payment history
    $payments = [];
    try {
        $pStmt = $conn->prepare("SELECT amount, payment_method, payment_date, notes FROM booking_payments WHERE booking_id = ? ORDER BY payment_date DESC");
        $pStmt->execute([$bookingId]);
        $payments = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */
    }
    $booking['payments'] = $payments;

    // Fetch extras (extra bed, laundry, dll)
    $extras = [];
    $totalExtras = 0;
    try {
        $eStmt = $conn->prepare("SELECT id, item_name, quantity, unit_price, total_price, notes, created_at FROM booking_extras WHERE booking_id = ? ORDER BY created_at ASC");
        $eStmt->execute([$bookingId]);
        $extras = $eStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($extras as $ex) {
            $totalExtras += (float)$ex['total_price'];
        }
    } catch (Exception $e) { /* table might not exist yet */
    }
    $booking['extras'] = $extras;
    $booking['total_extras'] = $totalExtras;

    // Fetch created_at
    try {
        $cStmt = $conn->prepare("SELECT created_at FROM bookings WHERE id = ?");
        $cStmt->execute([$bookingId]);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        $booking['created_at'] = $cRow['created_at'] ?? null;
    } catch (Exception $e) { /* ignore */
    }

    // Fetch group bookings (if this booking is part of a group reservation)
    $groupBookings = [];
    $groupId = $booking['group_id'] ?? null;
    if ($groupId) {
        try {
            $gStmt = $conn->prepare("
                SELECT 
                    b.id,
                    b.booking_code,
                    b.room_id,
                    b.room_price,
                    b.discount,
                    b.final_price,
                    b.status,
                    r.room_number,
                    rt.type_name,
                    COALESCE(SUM(bp.amount), 0) as paid_amount
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN room_types rt ON r.room_type_id = rt.id
                LEFT JOIN booking_payments bp ON b.id = bp.booking_id
                WHERE b.group_id = ? AND b.status != 'cancelled'
                GROUP BY b.id
                ORDER BY r.room_number ASC
            ");
            $gStmt->execute([$groupId]);
            $groupBookings = $gStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* ignore */
        }
    }
    $booking['group_bookings'] = $groupBookings;

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
