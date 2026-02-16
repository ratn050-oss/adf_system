<?php
require_once 'config/database.php';
$db = Database::getInstance();

try {
    // Check Room 105
    $room = $db->fetchOne("SELECT * FROM rooms WHERE room_number = ?", ["105"]);
    if ($room) {
        echo "Room 105 exists: " . json_encode($room) . "\n";
        
        // Check for existing bookings for Room 105
        $bookings = $db->fetchAll("
            SELECT * FROM bookings 
            WHERE room_id = ? 
            AND status != 'cancelled' 
            ORDER BY id DESC 
            LIMIT 5
        ", [$room['id']]);
        
        echo "Recent bookings for Room 105: " . json_encode($bookings) . "\n";
    } else {
        echo "Room 105 NOT FOUND!\n";
        
        // Show available rooms
        $rooms = $db->fetchAll("SELECT id, room_number FROM rooms ORDER BY room_number");
        echo "Available rooms: " . json_encode($rooms) . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>