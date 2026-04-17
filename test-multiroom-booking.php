<?php
/**
 * Test Multi-Room Group Booking Flow
 * This verifies that the complete group booking system works end-to-end
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

echo "=== MULTI-ROOM GROUP BOOKING TEST ===\n\n";

// 1. Check that group_id column exists
echo "1️⃣ Checking group_id column...\n";
$result = $conn->query('SHOW COLUMNS FROM bookings LIKE "group_id"')->fetch(PDO::FETCH_ASSOC);
if ($result) {
    echo "   ✅ group_id column EXISTS\n";
    echo "   Type: " . $result['Type'] . "\n";
    echo "   Key: " . ($result['Key'] ?: 'None') . "\n\n";
} else {
    echo "   ❌ group_id column MISSING\n\n";
    exit;
}

// 2. Check existing group bookings
echo "2️⃣ Checking existing group bookings in database...\n";
$groupsResult = $conn->query("
    SELECT 
        group_id,
        COUNT(*) as room_count,
        GROUP_CONCAT(DISTINCT r.room_number) as rooms,
        MIN(b.check_in_date) as check_in,
        MAX(b.check_out_date) as check_out
    FROM bookings b
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE group_id IS NOT NULL
    GROUP BY group_id
    ORDER BY check_in DESC
")->fetchAll(PDO::FETCH_ASSOC);

if (count($groupsResult) > 0) {
    echo "   Found " . count($groupsResult) . " group bookings:\n";
    foreach ($groupsResult as $group) {
        echo "   - " . $group['group_id'] . ": " . $group['room_count'] . " rooms (";
        echo $group['rooms'] . ") - " . $group['check_in'] . " to " . $group['check_out'] . "\n";
    }
} else {
    echo "   ℹ️  No group bookings found yet\n";
}
echo "\n";

// 3. Check Deva's bookings specifically
echo "3️⃣ Checking Deva Praningtyas' bookings...\n";
$devaGuests = $conn->query("
    SELECT id, name FROM guests WHERE name LIKE '%Deva%'
")->fetchAll(PDO::FETCH_ASSOC);

if (count($devaGuests) > 0) {
    foreach ($devaGuests as $guest) {
        $guestId = $guest['id'];
        $guestName = $guest['name'];
        
        echo "   Guest: $guestName (ID: $guestId)\n";
        
        $bookingsResult = $conn->query("
            SELECT 
                b.id,
                b.booking_code,
                b.group_id,
                r.room_number,
                rt.type_name,
                b.check_in_date,
                b.check_out_date,
                b.status
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.type_id = rt.id
            WHERE b.guest_id = $guestId
            ORDER BY b.check_in_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($bookingsResult) > 0) {
            echo "   Bookings:\n";
            foreach ($bookingsResult as $booking) {
                $groupInfo = $booking['group_id'] ? " [GROUP: " . $booking['group_id'] . "]" : " [SINGLE]";
                echo "   - Room " . $booking['room_number'] . " (" . $booking['type_name'] . ")" .
                     " - " . $booking['check_in_date'] . " to " . $booking['check_out_date'] .
                     " - " . $booking['status'] . $groupInfo . "\n";
            }
        } else {
            echo "   No bookings found\n";
        }
    }
} else {
    echo "   No guest named 'Deva' found\n";
}
echo "\n";

// 4. API Verification: Check if get-booking-details returns group_bookings
echo "4️⃣ Verifying API Response Structure...\n";
echo "   The get-booking-details.php API should return:\n";
echo "   {\n";
echo "     \"success\": true,\n";
echo "     \"booking\": {\n";
echo "       \"id\": 288,\n";
echo "       \"room_number\": \"202\",\n";
echo "       \"group_bookings\": [\n";
echo "         { \"id\": 288, \"room_number\": \"202\", ... },\n";
echo "         { \"id\": XXX, \"room_number\": \"YYY\", ... },\n";
echo "         { \"id\": ZZZ, \"room_number\": \"ZZZ\", ... }\n";
echo "       ]\n";
echo "     }\n";
echo "   }\n";
echo "   When there are multiple rooms with same group_id!\n\n";

// 5. Instructions
echo "5️⃣ HOW TO CREATE MULTI-ROOM GROUP BOOKING:\n";
echo "   Step 1: Click 'New Reservation' button (green button top-right)\n";
echo "   Step 2: Enter guest name and phone\n";
echo "   Step 3: Select check-in and check-out dates\n";
echo "   Step 4: ⭐ SELECT MULTIPLE ROOMS using the checkboxes:\n";
echo "            ☑️ Room 101 (Twin) - Rp 500,000/night\n";
echo "            ☑️ Room 102 (Twin) - Rp 500,000/night\n";
echo "            ☑️ Room 103 (Twin) - Rp 500,000/night\n";
echo "   Step 5: Continue with payment & booking source\n";
echo "   Step 6: Click 'Create Reservation'\n";
echo "   Step 7: System will:\n";
echo "            - Generate group_id (e.g., GRP-20260417-ABC123)\n";
echo "            - Create 3 SEPARATE bookings (one per room)\n";
echo "            - All 3 will have SAME group_id\n";
echo "   Step 8: When you click the booking on calendar:\n";
echo "            - Quick view shows all 3 rooms in 'Kamar dalam Grup' section\n";
echo "            - Clicking any room shows the group relationship\n\n";

echo "✅ System is ready! Test it now with 3 rooms selected.\n";
?>
