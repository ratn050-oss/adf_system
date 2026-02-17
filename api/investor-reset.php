<?php
/**
 * API: Reset Investor Data
 * Menghapus semua data investor dan transaksinya
 */

define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json');

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate confirmation
$confirm = isset($_POST['confirm']) ? strtoupper(trim($_POST['confirm'])) : '';
if ($confirm !== 'RESET') {
    echo json_encode(['success' => false, 'message' => 'Konfirmasi tidak valid. Ketik RESET untuk melanjutkan.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Start transaction
    $db->beginTransaction();
    
    // Count data before deletion for reporting
    $investorCount = $db->query("SELECT COUNT(*) FROM investors")->fetchColumn();
    $transactionCount = 0;
    
    // Try to count transactions
    try {
        $transactionCount = $db->query("SELECT COUNT(*) FROM investor_transactions")->fetchColumn();
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Delete investor transactions first (foreign key dependency)
    try {
        $db->exec("DELETE FROM investor_transactions");
    } catch (Exception $e) {
        // Table might not exist or has different structure
        error_log("Reset: investor_transactions delete failed - " . $e->getMessage());
    }
    
    // Delete investors
    $db->exec("DELETE FROM investors");
    
    // Reset auto increment
    try {
        $db->exec("ALTER TABLE investors AUTO_INCREMENT = 1");
    } catch (Exception $e) {
        // Ignore if fails
    }
    
    try {
        $db->exec("ALTER TABLE investor_transactions AUTO_INCREMENT = 1");
    } catch (Exception $e) {
        // Ignore if fails
    }
    
    // Commit transaction
    $db->commit();
    
    // Log the action
    $currentUser = $auth->getCurrentUser();
    error_log(sprintf(
        "INVESTOR RESET: User %s reset all investor data. Deleted %d investors, %d transactions.",
        $currentUser['username'] ?? 'unknown',
        $investorCount,
        $transactionCount
    ));
    
    echo json_encode([
        'success' => true,
        'message' => sprintf(
            'Berhasil menghapus %d investor dan %d transaksi.',
            $investorCount,
            $transactionCount
        ),
        'deleted' => [
            'investors' => $investorCount,
            'transactions' => $transactionCount
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("INVESTOR RESET ERROR: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Gagal mereset data: ' . $e->getMessage()
    ]);
}
