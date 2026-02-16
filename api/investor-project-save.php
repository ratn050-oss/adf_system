<?php
/**
 * API: Simpan Projek Investor
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

    $project_name = trim($_POST['project_name'] ?? '');
    $project_code = trim($_POST['project_code'] ?? '') ?: null;
    $budget_idr = floatval($_POST['budget_idr'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'planning';

    // Validate
    if (empty($project_name)) {
        echo json_encode(['success' => false, 'message' => 'Nama projek harus diisi']);
        exit;
    }
    
    if ($budget_idr <= 0) {
        echo json_encode(['success' => false, 'message' => 'Budget harus lebih dari 0']);
        exit;
    }

    // Check if projects table exists, if not create it
    try {
        $db->query("SELECT 1 FROM projects LIMIT 1");
    } catch (Exception $e) {
        // Create projects table if not exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_name VARCHAR(150) NOT NULL,
                project_code VARCHAR(50),
                description TEXT,
                budget_idr DECIMAL(15,2),
                status ENUM('planning', 'ongoing', 'on_hold', 'completed', 'cancelled') DEFAULT 'planning',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by INT,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Check if project_expenses table exists
    try {
        $db->query("SELECT 1 FROM project_expenses LIMIT 1");
    } catch (Exception $e) {
        // Create project_expenses table if not exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS project_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                category_id INT,
                amount DECIMAL(15,2) NOT NULL,
                description TEXT,
                expense_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by INT,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                INDEX idx_project (project_id),
                INDEX idx_date (expense_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Insert project
    $stmt = $db->prepare("
        INSERT INTO projects (project_name, project_code, description, budget_idr, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $project_name,
        $project_code,
        $description,
        $budget_idr,
        $status,
        $_SESSION['user_id'] ?? 1
    ]);

    $project_id = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Projek berhasil ditambahkan',
        'project_id' => $project_id
    ]);

} catch (PDOException $e) {
    error_log('Project save error: ' . $e->getMessage());
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
