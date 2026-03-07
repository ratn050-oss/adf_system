<?php
/**
 * Agent API Auth Helper
 * Validasi X-Agent-Key header sebelum semua endpoint AI agent dijalankan.
 * Key disimpan di tabel settings: key = 'agent_api_key'
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

function agent_auth_check($db) {
    $key = $_SERVER['HTTP_X_AGENT_KEY'] ?? ($_GET['agent_key'] ?? '');

    if (empty($key)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Missing X-Agent-Key header']);
        exit;
    }

    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'agent_api_key' LIMIT 1", []);
    $stored = $row['setting_value'] ?? '';

    if (empty($stored) || !hash_equals($stored, $key)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid API key']);
        exit;
    }
}
