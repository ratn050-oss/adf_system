<?php
/**
 * Test Group Booking Detection
 * Simulates the API query to find group bookings
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$output = "";

$db = Database::getInstance();
$conn = $db->getConnection();

// Find guest: Deva Praningtyas
$searchName = '%Deva%';
$guests = $conn->prepare('SELECT id, guest_name, phone FROM guests WHERE guest_name LIKE ? LIMIT 5');
$guests->execute([$searchName]);
$guestResults = $guests->fetchAll(PDO::FETCH_ASSOC);

$output .= "=== GUEST SEARCH ===\n";
$output .= json_encode($guestResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

if (empty($guestResults)) {
    $output .= "No guest found with name like: $searchName\n";
    echo $output;
    exit;
}

$guestId = $guestResults[0]['id'];
$output .= "Using guest_id: $guestId\n\n";

// Find bookings for this guest
$output .= "=== BOOKINGS FOR GUEST ===\n";
$stmt = $conn->prepare('
    SELECT 
        b.id, 
        b.booking_code, 
        b.room_id, 
        b.check_in_date, 
        b.check_out_date,
        b.status,
        r.room_number,
        rt.type_name
    FROM bookings b
    LEFT JOIN rooms r ON b.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    WHERE b.guest_id = ?
    ORDER BY b.check_in_date DESC, b.id
');
$stmt->execute([$guestId]);
$allBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$output .= json_encode($allBookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Find GROUP bookings (multiple rooms on same date)
$output .= "=== GROUP BOOKINGS (MULTIPLE ROOMS, SAME DATE) ===\n";
$groupStmt = $conn->prepare('
    SELECT 
        LEFT(b.check_in_date, 10) as check_in_date,
        LEFT(b.check_out_date, 10) as check_out_date,
        COUNT(DISTINCT b.id) as booking_count,
        GROUP_CONCAT(b.id) as booking_ids,
        GROUP_CONCAT(r.room_number) as room_numbers,
        GROUP_CONCAT(b.booking_code) as booking_codes
    FROM bookings b
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE b.guest_id = ?
    GROUP BY LEFT(b.check_in_date, 10), LEFT(b.check_out_date, 10)
    HAVING COUNT(DISTINCT b.id) > 1
');
$groupStmt->execute([$guestId]);
$groupBookings = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
$output .= json_encode($groupBookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

if (!empty($groupBookings)) {
    // Test the API query for one of these group bookings
    $firstGroup = $groupBookings[0];
    $bookingId = explode(',', $firstGroup['booking_ids'])[0];
    $checkInDate = $firstGroup['check_in_date'];
    $checkOutDate = $firstGroup['check_out_date'];
    
    $output .= "=== SIMULATING API QUERY ===\n";
    $output .= "Testing with booking_id: $bookingId\n";
    $output .= "Guest ID: $guestId\n";
    $output .= "Check-in: $checkInDate\n";
    $output .= "Check-out: $checkOutDate\n\n";
    
    // Execute the exact query from API
    $apiSql = "
        SELECT 
            b.id,
            b.booking_code,
            b.room_id,
            b.room_price,
            COALESCE(b.discount, 0) as discount,
            b.final_price,
            b.status,
            r.room_number,
            rt.type_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.guest_id = ? 
        AND LEFT(b.check_in_date, 10) = ?
        AND LEFT(b.check_out_date, 10) = ?
        AND b.status NOT IN ('cancelled')
        ORDER BY b.id ASC
    ";
    
    $apiStmt = $conn->prepare($apiSql);
    $apiStmt->execute([$guestId, $checkInDate, $checkOutDate]);
    $apiResults = $apiStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $output .= "API Query Results (" . count($apiResults) . " rooms):\n";
    $output .= json_encode($apiResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    $output .= "No group bookings found for this guest.\n";
    $output .= "All bookings are single-room bookings.\n";
}

echo $output;

// Also save to file
@file_put_contents('c:/xampp/htdocs/adf_system/group-booking-test-output.txt', $output);
