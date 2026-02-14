<?php
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$db = Database::getInstance();

echo "<h2>Check OTA Fee Settings</h2>";

try {
    // Get all OTA fee settings
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'ota_fee_%' ORDER BY setting_key");
    
    echo "<h3>Current OTA Fee Settings:</h3>";
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Setting Key</th><th>Fee Percentage</th></tr>";
    
    if (empty($settings)) {
        echo "<tr><td colspan='2' style='color:red'>No OTA fee settings found!</td></tr>";
    } else {
        foreach ($settings as $setting) {
            echo "<tr>";
            echo "<td>{$setting['setting_key']}</td>";
            echo "<td><strong>{$setting['setting_value']}%</strong></td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    
    echo "<h3>Booking Source Mapping:</h3>";
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Form Value</th><th>Setting Key</th></tr>";
    $mapping = [
        'agoda' => 'ota_fee_agoda',
        'booking' => 'ota_fee_booking_com',
        'tiket' => 'ota_fee_tiket_com',
        'airbnb' => 'ota_fee_airbnb',
        'ota' => 'ota_fee_other_ota'
    ];
    
    foreach ($mapping as $source => $key) {
        $value = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        $fee = $value ? $value['setting_value'] . '%' : '<span style="color:red">NOT SET</span>';
        echo "<tr>";
        echo "<td><strong>{$source}</strong></td>";
        echo "<td>{$key} → {$fee}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<p><a href='modules/frontdesk/settings.php?tab=ota_fees' style='padding:10px 20px; background:#6366f1; color:white; text-decoration:none; border-radius:6px;'>Go to OTA Fee Settings</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
