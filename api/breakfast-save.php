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
$formToken = $input['_form_token'] ?? '';

// Validate form token
$sessionToken = $_SESSION['bf_form_token'] ?? '';
if (empty($formToken) || $formToken !== $sessionToken) {
    echo json_encode(['success' => false, 'message' => 'Form expired, refresh halaman.']);
    exit;
}
// Clear token IMMEDIATELY
unset($_SESSION['bf_form_token']);

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
        
        // Regenerate content hash for updated order
        $contentHash = hash('sha256', $guestName . '|' . $breakfastDate . '|' . $breakfastTime . '|' . json_encode($roomNumbers) . '|' . $menuJson);
        
        $stmt = $pdo->prepare("UPDATE breakfast_orders SET 
            booking_id=?, guest_name=?, room_number=?, total_pax=?, breakfast_time=?, 
            breakfast_date=?, location=?, menu_items=?, special_requests=?, total_price=?, submit_token=?
            WHERE id=?");
        $stmt->execute([
            $bookingId, $guestName, json_encode($roomNumbers), $totalPax,
            $breakfastTime, $breakfastDate, $location, $menuJson,
            $specialRequests, $totalPrice, $contentHash, $editId
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
        
        // === DUPLICATE PREVENTION ===
        // Generate content hash based on order content
        $contentHash = hash('sha256', $guestName . '|' . $breakfastDate . '|' . $breakfastTime . '|' . json_encode($roomNumbers) . '|' . $menuJson);
        
        // Check 1: Exact content hash match (same guest, date, time, room, menu)
        $dupCheck = $pdo->prepare("SELECT id FROM breakfast_orders WHERE submit_token = ? LIMIT 1");
        $dupCheck->execute([$contentHash]);
        $existingByHash = $dupCheck->fetch(PDO::FETCH_ASSOC);
        if ($existingByHash) {
            echo json_encode(['success' => true, 'message' => "Order sudah tersimpan sebelumnya (ID #{$existingByHash['id']})", 'id' => $existingByHash['id']]);
            exit;
        }
        
        // Check 2: Time-based duplicate (same guest + date + time within last 2 minutes)
        $dupCheck2 = $pdo->prepare("SELECT id FROM breakfast_orders WHERE guest_name = ? AND breakfast_date = ? AND breakfast_time = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1");
        $dupCheck2->execute([$guestName, $breakfastDate, $breakfastTime]);
        $existingByTime = $dupCheck2->fetch(PDO::FETCH_ASSOC);
        if ($existingByTime) {
            echo json_encode(['success' => true, 'message' => "Order serupa baru saja dibuat (ID #{$existingByTime['id']}). Jika ingin order baru, tunggu 2 menit.", 'id' => $existingByTime['id']]);
            exit;
        }
        
        // INSERT with content hash as submit_token for database-level uniqueness
        $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, location, 
             menu_items, special_requests, total_price, created_by, submit_token) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        try {
            $stmt->execute([
                $bookingId, $guestName, json_encode($roomNumbers), $totalPax,
                $breakfastTime, $breakfastDate, $location, $menuJson,
                $specialRequests, $totalPrice, $validUserId, $contentHash
            ]);
            $lastOrderId = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'message' => "Pesanan untuk $guestName tersimpan (ID #$lastOrderId)", 'id' => $lastOrderId]);
        } catch (PDOException $dupEx) {
            // UNIQUE constraint violation = duplicate
            if ($dupEx->getCode() === '23000') {
                $existing = $pdo->prepare("SELECT id FROM breakfast_orders WHERE submit_token = ? LIMIT 1");
                $existing->execute([$contentHash]);
                $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
                $existId = $existingRow ? $existingRow['id'] : '?';
                echo json_encode(['success' => true, 'message' => "Order sudah ada (ID #$existId)", 'id' => $existId]);
            } else {
                throw $dupEx;
            }
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
} catch (Exception $e) {
    error_log("Breakfast Save Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
