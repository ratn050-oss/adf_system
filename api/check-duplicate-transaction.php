<?php
/**
 * CHECK DUPLICATE TRANSACTION API
 * Checks if a similar transaction already exists on the same day
 * to prevent accidental double-input by admin
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pdo = $db->getConnection();

$transactionDate = $_GET['date'] ?? date('Y-m-d');
$amount = floatval(str_replace(['.', ','], '', $_GET['amount'] ?? '0'));
$categoryName = trim($_GET['category'] ?? '');
$description = trim($_GET['description'] ?? '');
$transactionType = $_GET['type'] ?? '';

if ($amount <= 0 || empty($categoryName)) {
    echo json_encode(['success' => true, 'duplicates' => []]);
    exit;
}

try {
    $sql = "SELECT cb.id, cb.transaction_date, cb.transaction_time, cb.amount, 
                   cb.description, cb.transaction_type, c.category_name,
                   cb.created_at
            FROM cash_book cb
            LEFT JOIN categories c ON cb.category_id = c.id
            WHERE cb.transaction_date = ?
              AND ABS(cb.amount - ?) < 1
              AND cb.transaction_type = ?";
    $params = [$transactionDate, $amount, $transactionType];

    // Also match category name if provided
    if (!empty($categoryName)) {
        $sql .= " AND (LOWER(c.category_name) = LOWER(?) OR LOWER(cb.description) LIKE LOWER(?))";
        $params[] = $categoryName;
        $params[] = '%' . $categoryName . '%';
    }

    $sql .= " ORDER BY cb.created_at DESC LIMIT 5";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'duplicates' => $duplicates,
        'count' => count($duplicates)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'duplicates' => []]);
}
