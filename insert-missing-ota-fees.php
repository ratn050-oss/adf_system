<?php
require_once 'config/database.php';

// Insert missing OTA fee settings
try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=adf_system;charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Check and insert ota_fee_booking_com
    $check = $db->prepare("SELECT * FROM settings WHERE setting_key = 'ota_fee_booking_com'");
    $check->execute();
    
    if ($check->rowCount() === 0) {
        $insert = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, created_at) 
            VALUES ('ota_fee_booking_com', '12', 'number', NOW())
        ");
        $insert->execute();
        echo "✅ Inserted ota_fee_booking_com = 12%<br>";
    } else {
        echo "⚠️ ota_fee_booking_com already exists<br>";
    }
    
    // Check and insert ota_fee_tiket_com
    $check2 = $db->prepare("SELECT * FROM settings WHERE setting_key = 'ota_fee_tiket_com'");
    $check2->execute();
    
    if ($check2->rowCount() === 0) {
        $insert2 = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, created_at) 
            VALUES ('ota_fee_tiket_com', '10', 'number', NOW())
        ");
        $insert2->execute();
        echo "✅ Inserted ota_fee_tiket_com = 10%<br>";
    } else {
        echo "⚠️ ota_fee_tiket_com already exists<br>";
    }
    
    echo "<br><h3 style='color: green;'>✅ DONE! Missing OTA fees inserted</h3>";
    echo "<p>Default values:</p>";
    echo "<ul>";
    echo "<li>Booking.com: 12%</li>";
    echo "<li>Tiket.com: 10%</li>";
    echo "</ul>";
    echo "<p><a href='check-ota-fee-settings.php'>🔍 Check OTA Fee Settings</a></p>";
    echo "<p><a href='modules/frontdesk/calendar.php'>📅 Test di Calendar</a></p>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
