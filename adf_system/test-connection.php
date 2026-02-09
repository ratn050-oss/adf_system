<?php
/**
 * Test Database Connection
 */

echo "<h2>📡 DATABASE CONNECTION TEST</h2>";
echo "<hr>";

// Test 1: Local Database
echo "<h3>1️⃣ Testing LOCAL DATABASE (adf_system)</h3>";
try {
    $localPdo = new PDO(
        'mysql:host=localhost;dbname=adf_system;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $result = $localPdo->query('SELECT VERSION() as version')->fetch();
    echo "<div style='background:#4CAF50; color:white; padding:15px; border-radius:5px;'>";
    echo "✅ LOCAL DATABASE: Connected OK<br>";
    echo "MySQL Version: " . $result['version'] . "";
    echo "</div>";
} catch (Exception $e) {
    echo "<div style='background:#f44336; color:white; padding:15px; border-radius:5px;'>";
    echo "❌ LOCAL DATABASE ERROR: " . $e->getMessage() . "";
    echo "</div>";
}

echo "<br><br>";

// Test 2: Hosting Database
echo "<h3>2️⃣ Testing HOSTING DATABASE (adfb2574_adf)</h3>";
try {
    $hostingPdo = new PDO(
        'mysql:host=localhost;dbname=adfb2574_adf;charset=utf8mb4',
        'adfb2574_adfsystem',
        '@Nnoc2025',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $result = $hostingPdo->query('SELECT VERSION() as version')->fetch();
    echo "<div style='background:#4CAF50; color:white; padding:15px; border-radius:5px;'>";
    echo "✅ HOSTING DATABASE: Connected OK<br>";
    echo "MySQL Version: " . $result['version'] . "";
    echo "</div>";
} catch (Exception $e) {
    echo "<div style='background:#f44336; color:white; padding:15px; border-radius:5px;'>";
    echo "❌ HOSTING DATABASE ERROR: " . $e->getMessage() . "";
    echo "</div>";
}

echo "<br><br>";

// Test 3: Config File
echo "<h3>3️⃣ Testing CONFIG.PHP</h3>";
try {
    require_once 'config/config.php';
    echo "<div style='background:#2196F3; color:white; padding:15px; border-radius:5px;'>";
    echo "✅ CONFIG.PHP loaded<br>";
    echo "App Name: " . APP_NAME . "<br>";
    echo "DB_HOST: " . DB_HOST . "<br>";
    echo "DB_NAME: " . DB_NAME . "<br>";
    echo "DB_USER: " . DB_USER . "";
    echo "</div>";
} catch (Exception $e) {
    echo "<div style='background:#f44336; color:white; padding:15px; border-radius:5px;'>";
    echo "❌ CONFIG.PHP ERROR: " . $e->getMessage() . "";
    echo "</div>";
}

echo "<hr>";
echo "<p><strong>If all tests pass, your system is ready for deployment!</strong></p>";
?>
