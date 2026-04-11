<?php
/**
 * One-time script to copy Fingerspot config from Narayana to Bens Cafe
 * Same fingerprint device is shared between both businesses
 * DELETE after running!
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$user = 'root';
$pass = '';
$narayanaDb = 'adf_narayana_hotel';
$bensDb = 'adf_benscafe';

// Detect production
if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false) {
    $user = 'adfb2574_adfsystem';
    $pass = '@Nnoc2025';
    $narayanaDb = 'adfb2574_narayana_hotel';
    $bensDb = 'adfb2574_Adf_Bens';
}

try {
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get Narayana fingerspot config
    $src = $pdo->query("SELECT fingerspot_cloud_id, fingerspot_token, fingerspot_enabled FROM {$narayanaDb}.payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    
    if (!$src || !$src['fingerspot_cloud_id']) {
        die("❌ Narayana belum ada Fingerspot config");
    }

    echo "<p>Source (Narayana): Cloud ID = <code>{$src['fingerspot_cloud_id']}</code>, Enabled = {$src['fingerspot_enabled']}</p>";

    // Ensure fingerspot_token column exists in Bens
    try {
        $pdo->query("SELECT fingerspot_token FROM {$bensDb}.payroll_attendance_config LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE {$bensDb}.payroll_attendance_config ADD COLUMN `fingerspot_token` VARCHAR(100) DEFAULT NULL AFTER fingerspot_enabled");
        echo "<p>✅ Added fingerspot_token column to Bens Cafe</p>";
    }

    // Update Bens Cafe with same config
    $stmt = $pdo->prepare("UPDATE {$bensDb}.payroll_attendance_config SET fingerspot_cloud_id = ?, fingerspot_token = ?, fingerspot_enabled = ? WHERE id = 1");
    $stmt->execute([$src['fingerspot_cloud_id'], $src['fingerspot_token'], $src['fingerspot_enabled']]);

    // Verify
    $dst = $pdo->query("SELECT fingerspot_cloud_id, fingerspot_token, fingerspot_enabled FROM {$bensDb}.payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    echo "<p>✅ <strong>Bens Cafe updated!</strong></p>";
    echo "<p>Cloud ID: <code>{$dst['fingerspot_cloud_id']}</code>, Enabled: {$dst['fingerspot_enabled']}</p>";
    echo "<p style='color:red;font-weight:bold;'>⚠️ DELETE this file now!</p>";

} catch (Exception $e) {
    echo "❌ Error: " . htmlspecialchars($e->getMessage());
}
