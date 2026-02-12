<?php
/**
 * API: Get Pending Notifications
 * Called periodically to check for new notifications
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'notifications' => []]);
    exit;
}

$user = $auth->getCurrentUser();
$db = Database::getInstance();

$markRead = isset($_GET['mark_read']) && $_GET['mark_read'] === 'true';
$limit = min((int)($_GET['limit'] ?? 10), 50);

try {
    // Check if notifications table exists
    $tableExists = false;
    try {
        $db->query("SELECT 1 FROM notifications LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {
        // Create table if not exists
        $db->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT,
                data JSON,
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_read (user_id, is_read),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $tableExists = true;
    }
    
    if (!$tableExists) {
        echo json_encode(['success' => true, 'notifications' => [], 'unread_count' => 0]);
        exit;
    }
    
    // Get unread notifications
    $notifications = $db->fetchAll("
        SELECT id, type, title, message, data, is_read, created_at
        FROM notifications 
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC
        LIMIT ?
    ", [$user['id'], $limit]);
    
    // Get unread count
    $countResult = $db->fetchOne("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']]);
    $unreadCount = $countResult['cnt'] ?? 0;
    
    // Mark as read if requested
    if ($markRead && !empty($notifications)) {
        $ids = array_column($notifications, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->query("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders)", $ids);
    }
    
    // Format notifications
    $formattedNotifications = [];
    foreach ($notifications as $notif) {
        $formattedNotifications[] = [
            'id' => $notif['id'],
            'type' => $notif['type'],
            'title' => $notif['title'],
            'message' => $notif['message'],
            'data' => json_decode($notif['data'], true),
            'time' => date('H:i', strtotime($notif['created_at'])),
            'date' => date('d M Y', strtotime($notif['created_at'])),
            'relative' => getRelativeTime($notif['created_at'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $formattedNotifications,
        'unread_count' => $unreadCount
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'notifications' => [],
        'unread_count' => 0
    ]);
}

function getRelativeTime($datetime) {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    
    if ($diff->y > 0) return $diff->y . ' tahun lalu';
    if ($diff->m > 0) return $diff->m . ' bulan lalu';
    if ($diff->d > 0) return $diff->d . ' hari lalu';
    if ($diff->h > 0) return $diff->h . ' jam lalu';
    if ($diff->i > 0) return $diff->i . ' menit lalu';
    return 'Baru saja';
}
