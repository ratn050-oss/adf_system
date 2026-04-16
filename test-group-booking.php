<?php
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

// Test: get Sunsea booking ID
$test = $conn->prepare('SELECT id, guest_id, check_in_date, check_out_date FROM bookings WHERE booking_code LIKE "BK-20260408-6709" LIMIT 1');
$test->execute();
$row = $test->fetch(PDO::FETCH_ASSOC);
echo "Booking: " . json_encode($row) . "\n";

if($row) {
    $guest_id = $row['guest_id'];
    $check_in = $row['check_in_date'];
    $check_out = $row['check_out_date'];
    
    // Now test group query
    $sql = "SELECT b.id, b.booking_code, b.room_id, r.room_number FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.guest_id = ? AND b.check_in_date = ? AND b.check_out_date = ? AND b.status NOT IN ('cancelled') ORDER BY r.room_number ASC";
    $gStmt = $conn->prepare($sql);
    $gStmt->execute([$guest_id, $check_in, $check_out]);
    $groups = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Group bookings count: " . count($groups) . "\n";
    echo "Group bookings: " . json_encode($groups) . "\n";
}
?>
