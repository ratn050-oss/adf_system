<?php

/**
 * Check Database Schema - Verify group_id and other columns
 */

header('Content-Type: text/plain; charset=utf-8');

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

echo "=== CHECKING BOOKINGS TABLE SCHEMA ===\n\n";

// Get all columns
$columns = $conn->query("SHOW COLUMNS FROM bookings")->fetchAll(PDO::FETCH_ASSOC);
echo "Columns in 'bookings' table:\n";
foreach ($columns as $col) {
    echo sprintf(
        "  - %-25s | Type: %-30s | Null: %-3s | Key: %-3s | Default: %s\n",
        $col['Field'],
        $col['Type'],
        $col['Null'],
        $col['Key'] ?? '-',
        $col['Default'] ?? 'NULL'
    );
}

echo "\n=== CHECKING GROUP_ID COLUMN ===\n\n";
$groupIdCol = $conn->query("SHOW COLUMNS FROM bookings LIKE 'group_id'")->fetch(PDO::FETCH_ASSOC);
if ($groupIdCol) {
    echo "✅ group_id column EXISTS\n";
    echo json_encode($groupIdCol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "❌ group_id column DOES NOT EXIST\n";
}

echo "\n=== CHECKING DATA WITH GROUP_ID ===\n\n";

// Find bookings with group_id
$groupBookings = $conn->query("
    SELECT 
        GROUP_CONCAT(DISTINCT group_id) as group_ids,
        COUNT(*) as total_with_group_id
    FROM bookings 
    WHERE group_id IS NOT NULL AND group_id != ''
")->fetch(PDO::FETCH_ASSOC);

if ($groupBookings['total_with_group_id'] > 0) {
    echo "✅ Found " . $groupBookings['total_with_group_id'] . " bookings with group_id\n";
    echo "Group IDs: " . $groupBookings['group_ids'] . "\n\n";

    // Test the specific group_id from the screenshot
    echo "=== TESTING GROUP_ID: GRP-20260411-8792 ===\n\n";
    $testGroup = $conn->query("
        SELECT 
            b.id,
            b.booking_code,
            b.room_id,
            b.guest_id,
            b.group_id,
            b.check_in_date,
            b.check_out_date,
            r.room_number,
            rt.type_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.group_id = 'GRP-20260411-8792'
        ORDER BY b.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "Results for GRP-20260411-8792:\n";
    echo json_encode($testGroup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    echo "Total rooms in this group: " . count($testGroup) . "\n";
} else {
    echo "⚠️ No bookings found with group_id\n";
    echo "Total bookings: " . $conn->query("SELECT COUNT(*) as cnt FROM bookings")->fetch(PDO::FETCH_ASSOC)['cnt'] . "\n";
}

echo "\n=== TESTING DEVA PRANINGTYAS GUEST ===\n\n";
$devaGuest = $conn->query("
    SELECT id, guest_name FROM guests WHERE guest_name LIKE '%Deva%'
")->fetch(PDO::FETCH_ASSOC);

if ($devaGuest) {
    echo "Guest found: " . $devaGuest['guest_name'] . " (ID: " . $devaGuest['id'] . ")\n\n";

    // Check all bookings for this guest
    $devaBookings = $conn->query("
        SELECT 
            b.id,
            b.booking_code,
            b.room_id,
            b.guest_id,
            b.group_id,
            b.check_in_date,
            b.check_out_date,
            b.status,
            r.room_number,
            rt.type_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.guest_id = " . (int)$devaGuest['id'] . "
        ORDER BY b.check_in_date DESC, b.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "All bookings for " . $devaGuest['guest_name'] . " (" . count($devaBookings) . " total):\n";
    echo json_encode($devaBookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "❌ Guest 'Deva' not found\n";
}
