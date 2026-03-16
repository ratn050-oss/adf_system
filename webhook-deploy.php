<?php
/**
 * Simple Deploy Webhook — triggers git pull on hosting
 * Access: https://adfsystem.online/adf_system/webhook-deploy.php?token=SECRET
 */

// Security: require token
$validToken = 'adf-deploy-2025-secure';
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';

if (!hash_equals($validToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

// Determine deploy directory
$deployDir = dirname(__FILE__);
$output = [];
$returnCode = 0;

// Run git pull
chdir($deployDir);
exec('git pull origin main 2>&1', $output, $returnCode);

echo json_encode([
    'status'  => $returnCode === 0 ? 'success' : 'error',
    'output'  => implode("\n", $output),
    'time'    => date('Y-m-d H:i:s'),
    'dir'     => $deployDir,
], JSON_PRETTY_PRINT);
