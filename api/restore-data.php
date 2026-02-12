<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

// Only admin or developer can restore data
if (!$auth->hasRole('admin') && !$auth->hasRole('developer')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya admin atau developer yang bisa restore data.']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File backup tidak ditemukan atau error saat upload.']);
    exit;
}

$file = $_FILES['backup_file'];

// Validate file extension
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension !== 'sql') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File harus berformat .sql']);
    exit;
}

// Validate file size (max 50MB)
if ($file['size'] > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File terlalu besar. Maksimal 50MB.']);
    exit;
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Read SQL file
    $sql = file_get_contents($file['tmp_name']);
    
    if (empty($sql)) {
        throw new Exception('File SQL kosong atau tidak dapat dibaca.');
    }
    
    // Disable foreign key checks
    $conn->exec("SET FOREIGN_KEY_CHECKS=0");
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($statement) {
            return !empty($statement) && !preg_match('/^--/', $statement);
        }
    );
    
    $executedCount = 0;
    $errors = [];
    
    // Execute each statement
    foreach ($statements as $statement) {
        try {
            $conn->exec($statement);
            $executedCount++;
        } catch (PDOException $e) {
            // Log error but continue
            $errors[] = substr($statement, 0, 100) . '... : ' . $e->getMessage();
        }
    }
    
    // Re-enable foreign key checks
    $conn->exec("SET FOREIGN_KEY_CHECKS=1");
    
    if (empty($errors)) {
        echo json_encode([
            'success' => true,
            'message' => "Restore berhasil! {$executedCount} statement dieksekusi.",
            'executed_count' => $executedCount
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => "Restore selesai dengan {$executedCount} statement berhasil, namun ada " . count($errors) . " error.",
            'executed_count' => $executedCount,
            'errors' => array_slice($errors, 0, 5) // Show first 5 errors only
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saat restore: ' . $e->getMessage()
    ]);
}
