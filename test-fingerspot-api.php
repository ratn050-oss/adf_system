<?php
/**
 * Test Fingerspot API - DELETE THIS FILE after testing!
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Fingerspot API Test</h2>";

// Direct connection - no requires
try {
    $host = 'localhost';
    $dbname = 'adfb2574_narayana_hotel';
    $user = 'adfb2574_adfsystem';
    $pass = '@Nnoc2025';
    
    // Detect local vs production
    if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) {
        $dbname = 'adf_narayana_hotel';
        $user = 'root';
        $pass = '';
    }
    
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green;'>✅ DB Connected to: {$dbname}</p>";
} catch (Exception $e) {
    die("<p style='color:red;'>❌ DB Error: " . htmlspecialchars($e->getMessage()) . "</p>");
}

$fpConfig = $pdo->query("SELECT fingerspot_cloud_id, fingerspot_token FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$cloudId = $fpConfig['fingerspot_cloud_id'] ?? '';
$apiToken = $fpConfig['fingerspot_token'] ?? '';

if (!$cloudId || !$apiToken) die("<p style='color:red;'>❌ Cloud ID or Token not configured</p>");

echo "<p>Cloud ID: <code>" . htmlspecialchars($cloudId) . "</code></p>";

// Get real employee PINs from DB
$empPins = $pdo->query("SELECT finger_id, full_name FROM payroll_employees WHERE finger_id IS NOT NULL AND finger_id != '' AND is_active = 1 LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Fingerspot API Test</h2>";
echo "<p>Cloud ID: <code>" . htmlspecialchars($cloudId) . "</code></p>";
echo "<p>Employee PINs in DB: ";
foreach ($empPins as $ep) echo "<code>" . htmlspecialchars($ep['finger_id']) . "</code> (" . htmlspecialchars($ep['full_name']) . ") &nbsp;";
echo "</p><hr>";

function testApi($url, $params, $token) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$http, $resp, $err];
}

$tests = [];

// 1. get_userinfo with real employee PIN
if (!empty($empPins)) {
    $pin1 = $empPins[0]['finger_id'];
    $tests[] = ['label' => "get_userinfo pin={$pin1} (real employee)", 'url' => 'https://developer.fingerspot.io/api/get_userinfo', 'params' => ['trans_id' => uniqid(), 'cloud_id' => $cloudId, 'pin' => $pin1]];
}

// 2-5. get_userinfo with various pin values
foreach (['1', '0', '', '*'] as $p) {
    $tests[] = ['label' => "get_userinfo pin=" . ($p === '' ? '(empty)' : $p), 'url' => 'https://developer.fingerspot.io/api/get_userinfo', 'params' => ['trans_id' => uniqid(), 'cloud_id' => $cloudId, 'pin' => $p]];
}

// 6. get_userinfo without pin param
$tests[] = ['label' => 'get_userinfo (no pin param)', 'url' => 'https://developer.fingerspot.io/api/get_userinfo', 'params' => ['trans_id' => uniqid(), 'cloud_id' => $cloudId]];

// 7. Various other endpoints
foreach (['get_userid_list', 'get_all_userinfo', 'get_user', 'get_users'] as $ep) {
    $tests[] = ['label' => $ep, 'url' => "https://developer.fingerspot.io/api/{$ep}", 'params' => ['trans_id' => uniqid(), 'cloud_id' => $cloudId]];
}

foreach ($tests as $i => $test) {
    $n = $i + 1;
    echo "<h3>Test {$n}: {$test['label']}</h3>";
    echo "<pre style='font-size:11px;'>" . htmlspecialchars(json_encode($test['params'])) . "</pre>";
    
    [$http, $resp, $err] = testApi($test['url'], $test['params'], $apiToken);
    
    $color = ($http == 200 && strpos($resp, 'success') !== false) ? '#d4edda' : (($http == 200) ? '#fff3cd' : '#f8d7da');
    echo "<p>HTTP: <strong>{$http}</strong>" . ($err ? " | " . htmlspecialchars($err) : "") . "</p>";
    echo "<pre style='background:{$color};padding:10px;border:1px solid #ccc;white-space:pre-wrap;word-break:break-all;'>" . htmlspecialchars($resp) . "</pre><hr>";
    
    usleep(500000);
}
echo "<p style='color:red;font-weight:bold;'>⚠️ DELETE this file after testing!</p>";
