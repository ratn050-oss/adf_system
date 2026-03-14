<?php
/**
 * AJAX API for saving breakfast orders
 * Replaces HTML form POST to prevent duplicate submissions
 */
define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$action = $input['action'] ?? '';

$db = Database::getInstance();
$pdo = $db->getConnection();

// Get valid user ID
$validUserId = null;
if (!empty($_SESSION['user_id'])) {
    $userCheck = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if ($userCheck) {
        $validUserId = $_SESSION['user_id'];
    } else {
        $adminUser = $db->fetchOne("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1");
        if ($adminUser) $validUserId = $adminUser['id'];
    }
}

if (!$validUserId) {
    echo json_encode(['success' => false, 'message' => 'User tidak valid']);
    exit;
}

try {
    // Parse menu items
    $menuItemIds = $input['menu_items'] ?? [];
    $menuQty = $input['menu_qty'] ?? [];
    $menuNote = $input['menu_note'] ?? [];
    
    if (empty($menuItemIds) || !is_array($menuItemIds)) {
        throw new Exception('Pilih minimal 1 menu item');
    }
    
    $menuItems = [];
    $totalPrice = 0;
    
    foreach ($menuItemIds as $menuId) {
        $menuId = (int)$menuId;
        $qty = (int)($menuQty[$menuId] ?? 1);
        $note = isset($menuNote[$menuId]) ? trim($menuNote[$menuId]) : '';
        if ($qty > 0) {
            $menuStmt = $pdo->prepare("SELECT menu_name, price, is_free FROM breakfast_menus WHERE id = ?");
            $menuStmt->execute([$menuId]);
            $menu = $menuStmt->fetch(PDO::FETCH_ASSOC);
            if ($menu) {
                $item = [
                    'menu_id' => $menuId,
                    'menu_name' => $menu['menu_name'],
                    'quantity' => $qty,
                    'price' => $menu['price'],
                    'is_free' => $menu['is_free']
                ];
                if ($note !== '') $item['note'] = $note;
                $menuItems[] = $item;
                if (!$menu['is_free']) $totalPrice += ($menu['price'] * $qty);
            }
        }
    }
    
    if (count($menuItems) === 0) {
        throw new Exception('No valid menu items selected');
    }
    
    $guestName = trim($input['guest_name'] ?? '');
    $totalPax = (int)($input['total_pax'] ?? 0);
    $breakfastTime = $input['breakfast_time'] ?? '';
    $breakfastDate = $input['breakfast_date'] ?? '';
    $location = $input['location'] ?? 'restaurant';
    $specialRequests = !empty($input['special_requests']) ? trim($input['special_requests']) : null;
    $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
    $roomNumbers = $input['room_number'] ?? [];
    if (!is_array($roomNumbers)) $roomNumbers = [$roomNumbers];
    
    if (empty($guestName)) throw new Exception('Nama tamu harus diisi');
    if ($totalPax < 1) throw new Exception('Total pax minimal 1');
    if (empty($breakfastTime)) throw new Exception('Waktu sarapan harus diisi');
    if (empty($breakfastDate)) throw new Exception('Tanggal harus diisi');
    
    $menuJson = json_encode($menuItems);
    
    if ($action === 'update_order') {
        $editId = (int)($input['edit_id'] ?? 0);
        if ($editId <= 0) throw new Exception('ID order tidak valid');
        
        $stmt = $pdo->prepare("UPDATE breakfast_orders SET 
            booking_id=?, guest_name=?, room_number=?, total_pax=?, breakfast_time=?, 
            breakfast_date=?, location=?, menu_items=?, special_requests=?, total_price=?
            WHERE id=?");
        $stmt->execute([
            $bookingId, $guestName, json_encode($roomNumbers), $totalPax,
            $breakfastTime, $breakfastDate, $location, $menuJson,
            $specialRequests, $totalPrice, $editId
        ]);
        
        echo json_encode(['success' => true, 'message' => "Order #$editId berhasil diupdate!", 'id' => $editId]);
        
    } elseif ($action === 'create_order') {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
            id INT PRIMARY KEY AUTO_INCREMENT,
            booking_id INT NULL,
            guest_name VARCHAR(100) NOT NULL,
            room_number TEXT,
            total_pax INT NOT NULL,
            breakfast_time TIME NOT NULL,
            breakfast_date DATE NOT NULL,
            location ENUM('restaurant', 'room_service', 'take_away') DEFAULT 'restaurant',
            menu_items TEXT,
            special_requests TEXT,
            total_price DECIMAL(10,2) DEFAULT 0.00,
            order_status ENUM('pending', 'preparing', 'served', 'completed', 'cancelled') DEFAULT 'pending',
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_date (breakfast_date),
            INDEX idx_status (order_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        try {
            $pdo->exec("ALTER TABLE breakfast_orders MODIFY COLUMN location ENUM('restaurant', 'room_service', 'take_away') DEFAULT 'restaurant'");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE breakfast_orders MODIFY COLUMN room_number TEXT");
        } catch (Exception $e) {}
        
        // INSERT — straightforward, no aggressive duplicate blocking
        // Double-click protection is handled by frontend (formSubmitting flag)
        $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, location, 
             menu_items, special_requests, total_price, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $bookingId, $guestName, json_encode($roomNumbers), $totalPax,
            $breakfastTime, $breakfastDate, $location, $menuJson,
            $specialRequests, $totalPrice, $validUserId
        ]);
        
        $lastOrderId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => "Pesanan untuk $guestName tersimpan (ID #$lastOrderId)", 'id' => $lastOrderId]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
} catch (Exception $e) {
    error_log("Breakfast Save Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
