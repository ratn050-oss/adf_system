<?php
// Test API raw output
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/database.php';

// Set session
$_SESSION['user_id'] = 1;
$_SESSION['business_id'] = 1;
$_SESSION['role'] = 'owner';

echo "<h2>🔍 TEST API RAW OUTPUT</h2>";
echo "<p>Testing create-reservation.php with Agoda booking...</p>";
echo "<hr>";

// Set POST data
$_POST = [
    'guest_name' => 'TEST AGODA',
    'guest_phone' => '08123456789',
    'guest_email' => '',
    'room_id' => '1',
    'check_in_date' => '2026-02-18',  // FIX: change from check_in
    'check_out_date' => '2026-02-19',  // FIX: change from check_out
    'room_price' => '1450000',
    'final_price' => '1450000',
    'paid_amount' => '1450000',
    'payment_method' => 'ota',
    'booking_source' => 'agoda',
    'discount' => '0',
    'notes' => 'Test OTA Fee',
    'guests' => '1',
    'adult_count' => '1',
    'children_count' => '0',
    'total_nights' => '1',
    'total_price' => '1450000',
    'special_request' => ''
];

echo "<h3>📤 Input POST Data:</h3>";
echo "<pre>"; print_r($_POST); echo "</pre>";
echo "<hr>";

echo "<h3>📥 API Output:</h3>";
echo "<div style='background: #f4f4f4; padding: 15px; border: 2px solid #333;'>";

// Capture API output
ob_start();
try {
    include 'api/create-reservation.php';
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage();
}
$output = ob_get_clean();

echo "<pre>";
echo htmlspecialchars($output);
echo "</pre>";
echo "</div>";

echo "<hr>";
echo "<h3>🧪 Try to parse as JSON:</h3>";
$json = json_decode($output, true);
if ($json) {
    echo "<div style='background: #d4edda; padding: 15px;'>";
    echo "<h4>✅ Valid JSON!</h4>";
    echo "<pre>" . print_r($json, true) . "</pre>";
    
    if (isset($json['message'])) {
        echo "<hr><h4>💬 Message Field:</h4>";
        echo "<pre>" . htmlspecialchars($json['message']) . "</pre>";
    }
    
    if (isset($json['debug'])) {
        echo "<hr><h4>🔍 Debug Field:</h4>";
        echo "<pre>" . print_r($json['debug'], true) . "</pre>";
    }
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; padding: 15px;'>";
    echo "<h4>❌ NOT Valid JSON!</h4>";
    echo "<p>JSON Error: " . json_last_error_msg() . "</p>";
    echo "<p><strong>This is why fetch() fails!</strong></p>";
    echo "</div>";
}
?>
