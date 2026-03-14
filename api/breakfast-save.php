<?php
/**
 * BREAKFAST SAVE API - Clean rewrite
 * Server-side: 1 booking_id = max 1 order per day (prevents duplicates by design)
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

// Validate user
$validUserId = null;
if (!empty($_SESSION['user_id'])) {
    $userCheck = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if ($userCheck) $validUserId = $_SESSION['user_id'];
}
if (!$validUserId) {
    echo json_encode(['success' => false, 'message' => 'User tidak valid']);
    exit;
}

try {
    // Parse & validate menu items
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
        $qty = max(1, (int)($menuQty[$menuId] ?? 1));
        $note = isset($menuNote[$menuId]) ? trim($menuNote[$menuId]) : '';

        $menu = $db->fetchOne("SELECT menu_name, price, is_free FROM breakfast_menus WHERE id = ?", [$menuId]);
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

    if (count($menuItems) === 0) {
        throw new Exception('Tidak ada menu valid yang dipilih');
    }

    // Parse fields
    $guestName = trim($input['guest_name'] ?? '');
    $totalPax = max(1, (int)($input['total_pax'] ?? 1));
    $breakfastTime = $input['breakfast_time'] ?? '';
    $breakfastDate = $input['breakfast_date'] ?? date('Y-m-d');
    $location = $input['location'] ?? 'restaurant';
    $specialRequests = !empty($input['special_requests']) ? trim($input['special_requests']) : null;
    $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
    $roomNumbers = $input['room_number'] ?? [];
    if (!is_array($roomNumbers)) $roomNumbers = [$roomNumbers];

    if (empty($guestName)) throw new Exception('Nama tamu harus diisi');
    if (empty($breakfastTime)) throw new Exception('Waktu sarapan harus diisi');

    // Validate location
    $validLocations = ['restaurant', 'room_service', 'take_away'];
    if (!in_array($location, $validLocations)) $location = 'restaurant';

    $menuJson = json_encode($menuItems);
    $roomJson = json_encode($roomNumbers);

    if ($action === 'create_order') {
        // SERVER-SIDE DUPLICATE PREVENTION: 1 booking per day
        if ($bookingId) {
            $existing = $db->fetchOne(
                "SELECT id FROM breakfast_orders WHERE booking_id = ? AND breakfast_date = ?",
                [$bookingId, $breakfastDate]
            );
            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => "Tamu ini sudah punya order hari ini (ID #{$existing['id']}). Edit order yang ada atau hapus dulu."
                ]);
                exit;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, 
             location, menu_items, special_requests, total_price, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $bookingId, $guestName, $roomJson, $totalPax,
            $breakfastTime, $breakfastDate, $location, $menuJson,
            $specialRequests, $totalPrice, $validUserId
        ]);

        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => "Order #{$newId} untuk {$guestName} tersimpan!", 'id' => $newId]);

    } elseif ($action === 'update_order') {
        $editId = (int)($input['edit_id'] ?? 0);
        if ($editId <= 0) throw new Exception('ID order tidak valid');

        $stmt = $pdo->prepare("UPDATE breakfast_orders SET 
            booking_id=?, guest_name=?, room_number=?, total_pax=?, breakfast_time=?, 
            breakfast_date=?, location=?, menu_items=?, special_requests=?, total_price=?
            WHERE id=?");
        $stmt->execute([
            $bookingId, $guestName, $roomJson, $totalPax,
            $breakfastTime, $breakfastDate, $location, $menuJson,
            $specialRequests, $totalPrice, $editId
        ]);

        echo json_encode(['success' => true, 'message' => "Order #{$editId} berhasil diupdate!", 'id' => $editId]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
} catch (Exception $e) {
    error_log("Breakfast Save Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
