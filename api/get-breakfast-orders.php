<?php
/**
 * API - GET BREAKFAST ORDERS
 * Mendapatkan daftar breakfast orders
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

try {
    $bookingId = $_GET['booking_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if ($bookingId) {
        // Get orders for specific booking (include manual orders)
        $query = "SELECT bo.*, 
                         b.booking_code,
                         COALESCE(g.guest_name, bo.guest_name) AS guest_name,
                         COALESCE(r.room_number, bo.room_number) AS room_number
                  FROM breakfast_orders bo
                  LEFT JOIN bookings b ON bo.booking_id = b.id
                  LEFT JOIN guests g ON b.guest_id = g.id
                  LEFT JOIN rooms r ON b.room_id = r.id
                  WHERE (bo.booking_id = ? OR (bo.booking_id IS NULL AND bo.guest_name IS NOT NULL))
                  ORDER BY bo.breakfast_date DESC, bo.breakfast_time DESC";
        $orders = $db->fetchAll($query, [$bookingId]);
    } else {
        // Get all orders for today (include manual orders)
        $query = "SELECT bo.*, 
                         b.booking_code,
                         COALESCE(g.guest_name, bo.guest_name) AS guest_name,
                         COALESCE(r.room_number, bo.room_number) AS room_number
                  FROM breakfast_orders bo
                  LEFT JOIN bookings b ON bo.booking_id = b.id
                  LEFT JOIN guests g ON b.guest_id = g.id
                  LEFT JOIN rooms r ON b.room_id = r.id
                  WHERE bo.breakfast_date = ?
                  ORDER BY bo.breakfast_time ASC, room_number ASC";
        $orders = $db->fetchAll($query, [$date]);
    }
    
    // Decode menu_items JSON
    foreach ($orders as &$order) {
        $order['menu_items'] = json_decode($order['menu_items'], true);
    }
    
    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);
    
} catch (Exception $e) {
    error_log("Get Breakfast Orders Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
