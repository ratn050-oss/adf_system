<?php
/**
 * Pure API - No dependencies
 * Direct DB connection only
 */

// STOP ANY OUTPUT IMMEDIATELY
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Database credentials (hardcoded - adjust if needed)
$dbConfig = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'adf2574_narayana_hotel'
];

$response = ['success' => false, 'message' => 'Unknown error'];
$httpCode = 500;

try {
    // Try .env file first
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') && !strpos($line, '#')) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                if ($key === 'DB_HOST') $dbConfig['host'] = $val;
                if ($key === 'DB_USER') $dbConfig['user'] = $val;
                if ($key === 'DB_PASSWORD') $dbConfig['pass'] = $val;
                if ($key === 'DB_NAME') $dbConfig['name'] = $val;
            }
        }
    }

    // Connect
    $pdo = new PDO(
        'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['name'] . ';charset=utf8mb4',
        $dbConfig['user'],
        $dbConfig['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_THROW]
    );

    $action = $_GET['action'] ?? 'check';

    if ($action === 'check') {
        $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'ota_source_detail' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$dbConfig['name']]);
        $exists = $stmt->rowCount() > 0;
        
        $response = [
            'success' => true,
            'exists' => $exists,
            'message' => $exists ? 'Column exists' : 'Column not found'
        ];
        $httpCode = 200;

    } elseif ($action === 'run') {
        // Check exists first
        $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'ota_source_detail' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$dbConfig['name']]);
        
        if ($stmt->rowCount() > 0) {
            $response = ['success' => true, 'message' => 'Column already exists'];
            $httpCode = 200;
        } else {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN ota_source_detail VARCHAR(50) DEFAULT NULL AFTER booking_source");
            $response = ['success' => true, 'message' => 'Column added successfully'];
            $httpCode = 200;
        }

    } else {
        $response = ['success' => false, 'message' => 'Invalid action'];
        $httpCode = 400;
    }

} catch (PDOException $e) {
    $response = ['success' => false, 'message' => 'DB Error: ' . $e->getMessage()];
    $httpCode = 400;
} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    $httpCode = 400;
}

http_response_code($httpCode);
echo json_encode($response);
exit;
?>
