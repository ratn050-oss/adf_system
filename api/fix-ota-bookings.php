<?php
/**
 * API: Fix OTA Bookings
 * Auto-updates paid_amount for existing OTA bookings
 * Access: POST /api/fix-ota-bookings.php with ?token=adf_hotfix_2026
 */

header('Content-Type: application/json');

// Verify token
$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token !== 'adf_hotfix_2026') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing token']);
    exit;
}

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

try {
    $db = Database::getInstance();
    
    // List of OTA source keys
    $otaSources = ['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi', 'ota'];
    
    $fixed = 0;
    $errors = [];
    
    foreach ($otaSources as $source) {
        // Find OTA bookings dengan paid_amount = 0 atau NULL
        $bookings = $db->fetchAll(
            "SELECT id, booking_code, final_price, paid_amount, payment_status 
             FROM bookings 
             WHERE booking_source = ? 
             AND (paid_amount = 0 OR paid_amount IS NULL)
             AND status IN ('confirmed', 'pending')",
            [$source]
        );
        
        foreach ($bookings as $bk) {
            $finalPrice = (float)$bk['final_price'];
            
            try {
                $result = $db->query(
                    "UPDATE bookings 
                     SET paid_amount = ?, payment_status = 'paid', updated_at = NOW()
                     WHERE id = ?",
                    [$finalPrice, $bk['id']]
                );
                
                if ($result) {
                    $fixed++;
                } else {
                    $errors[] = "{$bk['booking_code']}: Update failed";
                }
            } catch (\Throwable $e) {
                $errors[] = "{$bk['booking_code']}: " . $e->getMessage();
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'fixed' => $fixed,
        'errors' => $errors,
        'message' => "Fixed $fixed OTA bookings" . (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
    ]);
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
