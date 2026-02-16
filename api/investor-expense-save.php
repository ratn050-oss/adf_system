<?php
/**
 * API: Simpan Pengeluaran Investor
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $project_id = intval($_POST['project_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $category_id = intval($_POST['category_id'] ?? 0) ?: null;

    // Validate
    if (!$project_id) {
        echo json_encode(['success' => false, 'message' => 'Project tidak ditemukan']);
        exit;
    }
    
    if (empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Deskripsi pengeluaran harus diisi']);
        exit;
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah harus lebih dari 0']);
        exit;
    }

    // Verify project exists
    $stmt = $db->prepare("SELECT id FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Projek tidak ditemukan']);
        exit;
    }

    // Insert expense
    $stmt = $db->prepare("
        INSERT INTO project_expenses (project_id, category_id, description, amount, expense_date, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $project_id,
        $category_id,
        $description,
        $amount,
        $expense_date,
        $_SESSION['user_id'] ?? 1
    ]);

    $expense_id = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Pengeluaran berhasil dicatat',
        'expense_id' => $expense_id
    ]);

} catch (PDOException $e) {
    error_log('Expense save error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
