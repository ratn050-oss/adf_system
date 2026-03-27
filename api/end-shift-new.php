<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();

try {
    $today = date('Y-m-d');
    $userId = $currentUser['id'];
    
    // Get daily transactions
    $transactions = $db->fetchAll(
        "SELECT id, amount, transaction_type 
         FROM cash_book 
         WHERE DATE(transaction_date) = ?",
        [$today]
    );
    
    $totalIncome = 0;
    $totalExpense = 0;
    
    foreach ($transactions as $trans) {
        $amt = (float)$trans['amount'];
        if ($trans['transaction_type'] === 'income') {
            $totalIncome += $amt;
        } else {
            $totalExpense += $amt;
        }
    }
    
    // Get purchase orders for today
    $pos = $db->fetchAll(
        "SELECT h.id, h.po_number, h.total_amount, h.attachment_path as image_path, h.supplier_name
         FROM purchase_orders_header h 
         WHERE DATE(h.created_at) = ?
         ORDER BY h.created_at DESC",
        [$today]
    );
    
    // Get business info
    $businessInfo = [
        'name' => BUSINESS_NAME,
        'database' => Database::getCurrentDatabase()
    ];
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'user' => [
                'name' => $currentUser['full_name'] ?? $currentUser['username'] ?? 'User',
                'role' => $currentUser['role'] ?? 'staff'
            ],
            'business' => $businessInfo,
            'daily_report' => [
                'date' => $today,
                'total_income' => (int)$totalIncome,
                'total_expense' => (int)$totalExpense,
                'net_balance' => (int)($totalIncome - $totalExpense),
                'transaction_count' => count($transactions)
            ],
            'pos_data' => [
                'count' => count($pos),
                'list' => $pos
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
exit;
