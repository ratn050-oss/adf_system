<?php
session_start();
require_once '../config/database.php';

// Set session untuk test
$_SESSION['user_id'] = 1;
$_SESSION['business_id'] = 1;
$_SESSION['role'] = 'owner';

header('Content-Type: application/json');

// Test case: Agoda booking dengan Rp 1.450.000
$testData = [
    'guest_name' => 'TEST OTA AGODA',
    'guest_phone' => '08123456789',
    'room_id' => 1,
    'check_in' => '2026-02-18',
    'check_out' => '2026-02-19',
    'room_price' => 1450000,
    'final_price' => 1450000,
    'paid_amount' => 1450000,
    'payment_method' => 'ota',
    'booking_source' => 'agoda',
    'discount' => 0,
    'notes' => 'Test Agoda OTA Fee 18%'
];

// Simulate POST
$_POST = $testData;

// Capture output
ob_start();
include '../api/create-reservation.php';
$response = ob_get_clean();

// Return both raw and parsed
echo json_encode([
    'raw_response' => $response,
    'parsed' => json_decode($response, true),
    'test_data' => $testData
], JSON_PRETTY_PRINT);
?>
