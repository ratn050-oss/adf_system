<?php
session_start();
require_once 'config/database.php';

// Simulate session
$_SESSION['user_id'] = 1;
$_SESSION['business_id'] = 1;
$_SESSION['role'] = 'owner';

// Simulate POST data for OTA booking
$_POST = [
    'guest_name' => 'TEST OTA FEE',
    'guest_phone' => '08123456789',
    'room_id' => 1,
    'check_in' => date('Y-m-d'),
    'check_out' => date('Y-m-d', strtotime('+1 day')),
    'room_price' => 1500000,
    'final_price' => 1500000,
    'paid_amount' => 1500000,
    'payment_method' => 'transfer',
    'booking_source' => 'booking',  // Test with Booking.com
    'discount' => 0,
    'notes' => 'Test OTA Fee - Booking.com 12%'
];

echo "<h2>🧪 TEST API OTA FEE CALCULATION</h2>";
echo "<h3>Test Case: Booking.com - Rp 1.500.000</h3>";
echo "<p><strong>Expected:</strong></p>";
echo "<ul>";
echo "<li>Gross: Rp 1.500.000</li>";
echo "<li>OTA Fee (12%): -Rp 180.000</li>";
echo "<li>Net: Rp 1.320.000 → Bank</li>";
echo "</ul>";

echo "<hr><h3>📤 Calling API...</h3>";

// Capture API output
ob_start();
include 'api/create-reservation.php';
$apiOutput = ob_get_clean();

echo "<h3>📥 API Response:</h3>";
echo "<pre style='background: #f4f4f4; padding: 15px; border: 1px solid #ddd;'>";
echo htmlspecialchars($apiOutput);
echo "</pre>";

echo "<hr><h3>👁️ Formatted Response:</h3>";
$response = json_decode($apiOutput, true);
if ($response) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Key</th><th>Value</th></tr>";
    foreach ($response as $key => $value) {
        if ($key === 'message') {
            echo "<tr><td><strong>{$key}</strong></td><td><pre>" . htmlspecialchars($value) . "</pre></td></tr>";
        } else {
            echo "<tr><td>{$key}</td><td>" . htmlspecialchars(print_r($value, true)) . "</td></tr>";
        }
    }
    echo "</table>";
    
    echo "<hr><h3>🎯 CHECK: Does message contain OTA Fee?</h3>";
    if (strpos($response['message'], 'OTA Fee') !== false) {
        echo "<div style='background: #d4edda; padding: 20px; border: 2px solid green;'>";
        echo "<h2 style='color: green;'>✅ SUCCESS! OTA Fee FOUND in message</h2>";
        echo "<pre>" . htmlspecialchars($response['message']) . "</pre>";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 20px; border: 2px solid red;'>";
        echo "<h2 style='color: red;'>❌ FAILED! OTA Fee NOT in message</h2>";
        echo "<p>Message: " . htmlspecialchars($response['message']) . "</p>";
        echo "</div>";
    }
} else {
    echo "<p style='color: red;'>Failed to parse JSON response</p>";
}
?>
