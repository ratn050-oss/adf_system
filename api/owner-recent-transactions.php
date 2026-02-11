<?php
/**
 * API: Owner Recent Transactions
 * Get recent transactions
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();

// Check if user is owner, admin, manager, or developer
if (!in_array($currentUser['role'], ['owner', 'admin', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    // Switch to hotel database
    $db = Database::switchDatabase(getDbName('adf_narayana_hotel'));
    
    // Get recent transactions
    $transactions = $db->fetchAll(
        "SELECT 
            cb.*,
            d.division_name,
            c.category_name,
            u.full_name as user_name
         FROM cash_book cb
         LEFT JOIN divisions d ON cb.division_id = d.id
         LEFT JOIN categories c ON cb.category_id = c.id
         LEFT JOIN users u ON cb.created_by = u.id
         WHERE DATE(cb.transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
         LIMIT ?",
        [$limit]
    );
    
    // Format transactions for display
    foreach ($transactions as &$tx) {
        $tx['formatted_date'] = date('d M Y', strtotime($tx['transaction_date']));
        $tx['formatted_amount'] = number_format($tx['amount'], 0, ',', '.');
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'count' => count($transactions),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
