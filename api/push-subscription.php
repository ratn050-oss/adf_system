<?php
/**
 * API: Push Subscription Management
 * Subscribe/unsubscribe browser push notifications
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/config/vapid.php';
require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ GET: Return VAPID public key ═══
if ($method === 'GET' && $action === 'vapid-public-key') {
    echo json_encode([
        'success'   => true,
        'publicKey' => VAPID_PUBLIC_KEY,
    ]);
    exit;
}

// All other actions require POST
if ($method !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$db = Database::getInstance();
$push = new PushNotificationHelper($db);

$action = $input['action'] ?? $action;

// ═══ SUBSCRIBE ═══
if ($action === 'subscribe') {
    $subscription = $input['subscription'] ?? null;
    $userId       = isset($input['user_id']) ? (int)$input['user_id'] : null;
    $employeeId   = isset($input['employee_id']) ? (int)$input['employee_id'] : null;

    // Also check session for user_id
    if (!$userId) {
        session_status() === PHP_SESSION_NONE && session_start();
        $userId = $_SESSION['user_id'] ?? null;
    }

    if (!$subscription || empty($subscription['endpoint'])) {
        echo json_encode(['success' => false, 'message' => 'Subscription data required']);
        exit;
    }

    $result = $push->saveSubscription($subscription, $userId, $employeeId);
    echo json_encode([
        'success' => $result,
        'message' => $result ? 'Push subscription berhasil disimpan' : 'Gagal menyimpan subscription',
    ]);
    exit;
}

// ═══ UNSUBSCRIBE ═══
if ($action === 'unsubscribe') {
    $endpoint = $input['endpoint'] ?? '';
    if (empty($endpoint)) {
        echo json_encode(['success' => false, 'message' => 'Endpoint required']);
        exit;
    }

    $push->removeSubscription($endpoint);
    echo json_encode(['success' => true, 'message' => 'Push subscription dihapus']);
    exit;
}

// ═══ STATUS ═══
if ($action === 'status') {
    session_status() === PHP_SESSION_NONE && session_start();
    $userId     = $_SESSION['user_id'] ?? null;
    $employeeId = isset($input['employee_id']) ? (int)$input['employee_id'] : null;

    $count = $push->getSubscriptionCount($userId, $employeeId);
    echo json_encode([
        'success'    => true,
        'subscribed' => $count > 0,
        'count'      => $count,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
