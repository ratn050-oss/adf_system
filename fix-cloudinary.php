<?php
/**
 * Fix Cloudinary URLs di database hosting
 * Akses via: https://adf.narayanakarimunjawa.com/fix-cloudinary.php
 * HAPUS FILE INI SETELAH SELESAI!
 */

// Security - simple token
$token = $_GET['token'] ?? '';
if ($token !== 'fix2026') {
    die('Add ?token=fix2026 to URL');
}

// Database connection - hosting
$host = 'localhost';
$user = 'adfb2574_adfsystem';
$pass = '@Nnoc2025';
$dbname = 'adfb2574_narayana_hotel';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "<h2>✓ Database connected: $dbname</h2>";
} catch (PDOException $e) {
    die("<h2>✗ Database error: " . $e->getMessage() . "</h2>");
}

// Cloudinary URLs
$updates = [
    'web_hero_background' => 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772739188/adf_system/website/hero/ombs61riq165vcwenxy1.png',
    'web_room_gallery_king' => '["https://res.cloudinary.com/dpdmut9ls/image/upload/v1772739325/adf_system/website/rooms/king/tnqqgezxjthz4ylbpmg2.jpg","https://res.cloudinary.com/dpdmut9ls/image/upload/v1772739334/adf_system/website/rooms/king/ojiw1yv6n6qbjgc5zjo8.jpg"]',
    'web_room_primary_king' => 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772739342/adf_system/website/rooms/king/c5klzqjq3aedbya2gphd.jpg'
];

echo "<h3>Current values:</h3><pre>";
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('web_hero_background', 'web_room_gallery_king', 'web_room_primary_king')");
$current = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($current as $row) {
    $val = strlen($row['setting_value']) > 80 ? substr($row['setting_value'], 0, 80) . '...' : $row['setting_value'];
    echo htmlspecialchars($row['setting_key']) . ": " . htmlspecialchars($val) . "\n";
}
echo "</pre>";

// Check if update needed
$needUpdate = false;
foreach ($current as $row) {
    if (strpos($row['setting_value'], 'cloudinary.com') === false && !empty($row['setting_value'])) {
        $needUpdate = true;
        break;
    }
}

if (isset($_GET['update'])) {
    echo "<h3>Updating to Cloudinary URLs...</h3>";
    foreach ($updates as $key => $value) {
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$value, $key]);
        echo "✓ Updated $key<br>";
    }
    echo "<h3>Done! <a href='https://narayanakarimunjawa.com'>Check website</a></h3>";
} else {
    if ($needUpdate) {
        echo "<h3 style='color:red'>URLs need update!</h3>";
        echo "<a href='?token=fix2026&update=1' style='background:green;color:white;padding:10px 20px;text-decoration:none;font-size:18px'>Click to Update to Cloudinary URLs</a>";
    } else {
        echo "<h3 style='color:green'>URLs already pointing to Cloudinary!</h3>";
    }
}

echo "<hr><h3>Test image:</h3>";
echo "<img src='https://res.cloudinary.com/dpdmut9ls/image/upload/v1772739188/adf_system/website/hero/ombs61riq165vcwenxy1.png' style='max-width:400px'>";
echo "<p><small>If image shows above, Cloudinary is working.</small></p>";

echo "<hr><p style='color:red'><strong>HAPUS FILE INI SETELAH SELESAI!</strong></p>";
