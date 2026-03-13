<?php
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

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$id = (int)($input['id'] ?? 0);

$db = Database::getInstance();
$pdo = $db->getConnection();

// Cleanup duplicates action (does not require ID)
if ($action === 'cleanup_duplicates') {
    $targetDate = $input['date'] ?? date('Y-m-d');
    
    // Find and delete duplicates: keep only the first entry for each unique combination
    $stmt = $pdo->prepare("
        DELETE bo FROM breakfast_orders bo
        INNER JOIN (
            SELECT guest_name, breakfast_date, breakfast_time, menu_items, MIN(id) as keep_id
            FROM breakfast_orders 
            WHERE breakfast_date = ?
            GROUP BY guest_name, breakfast_date, breakfast_time, menu_items
            HAVING COUNT(*) > 1
        ) dups ON bo.guest_name = dups.guest_name 
             AND bo.breakfast_date = dups.breakfast_date 
             AND bo.breakfast_time = dups.breakfast_time 
             AND bo.menu_items = dups.menu_items
             AND bo.id != dups.keep_id
    ");
    $stmt->execute([$targetDate]);
    
    $deleted = $stmt->rowCount();
    echo json_encode([
        'success' => true, 
        'message' => $deleted > 0 ? "Berhasil menghapus $deleted duplikat" : 'Tidak ada duplikat ditemukan'
    ]);
    exit;
}

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

if ($action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM breakfast_orders WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
