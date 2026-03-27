<?php
header('Content-Type: application/json');

// Simple test
echo json_encode([
    'status' => 'ok',
    'message' => 'End Shift API is accessible',
    'session' => isset($_SESSION) ? 'YES' : 'NO',
    'user_id' => $_SESSION['user_id'] ?? 'NOT SET',
    'php_version' => phpversion(),
    'timestamp' => date('Y-m-d H:i:s')
]);
?>
