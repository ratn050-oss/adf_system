<?php
/**
 * Agent API Auth Helper
 * Validasi X-Agent-Key header sebelum semua endpoint AI agent dijalankan.
 * Key disimpan di hotel DB settings: key = 'agent_api_key'
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

/**
 * Buat koneksi PDO ke hotel DB (sama dengan yang dipakai developer panel).
 */
function agent_get_hotel_db() {
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
                     strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    $dbName = $isProduction ? 'adfb2574_narayana_hotel' : 'adf_narayana_hotel';
    $dbUser = $isProduction ? 'adfb2574_adfsystem' : 'root';
    $dbPass = $isProduction ? '@Nnoc2025' : '';
    $pdo = new PDO('mysql:host=localhost;dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Validasi API key dari header X-Agent-Key.
 * Mengembalikan PDO koneksi hotel DB untuk dipakai endpoint.
 */
function agent_auth_check() {
    $key = $_SERVER['HTTP_X_AGENT_KEY'] ?? ($_GET['agent_key'] ?? '');

    if (empty($key)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Missing X-Agent-Key header']);
        exit;
    }

    try {
        $pdo = agent_get_hotel_db();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'agent_api_key' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stored = $row['setting_value'] ?? '';
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB connection failed']);
        exit;
    }

    if (empty($stored) || !hash_equals($stored, $key)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid API key']);
        exit;
    }

    return $pdo; // kembalikan koneksi hotel DB untuk dipakai endpoint
}
