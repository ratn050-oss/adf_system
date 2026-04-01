<?php
/**
 * Debug Website Image Loading
 * Akses: https://narayanakarimunjawa.com/debug-images.php?token=debug2026
 */

$token = $_GET['token'] ?? '';
if ($token !== 'debug2026') {
    die('Add ?token=debug2026');
}

// Config sama dengan website
$isProduction = true;
$pdo = new PDO(
    'mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4',
    'adfb2574_adfsystem', '@Nnoc2026',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "<h2>Debug: Website Image Settings</h2>";
echo "<p>Database: adfb2574_narayana_hotel</p>";

// Get all image related settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero%' OR setting_key LIKE 'web_room%' ORDER BY setting_key");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>All web_ settings:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
echo "<tr><th>Key</th><th>Value</th><th>Preview</th></tr>";

foreach ($settings as $s) {
    $key = htmlspecialchars($s['setting_key']);
    $val = $s['setting_value'];
    $preview = '';
    
    // Check if it's an image URL
    if (strpos($val, 'http') === 0 && preg_match('/\.(jpg|jpeg|png|webp|gif)/i', $val)) {
        $preview = "<img src='" . htmlspecialchars($val) . "' style='max-width:150px;max-height:100px'>";
    } elseif (strpos($val, '[') === 0) {
        // JSON array
        $arr = json_decode($val, true);
        if (is_array($arr) && count($arr) > 0) {
            $preview = "<img src='" . htmlspecialchars($arr[0]) . "' style='max-width:150px;max-height:100px'>";
        }
    }
    
    $displayVal = strlen($val) > 80 ? substr($val, 0, 80) . '...' : $val;
    echo "<tr><td>$key</td><td style='font-size:11px'>" . htmlspecialchars($displayVal) . "</td><td>$preview</td></tr>";
}
echo "</table>";

// Specific check for hero
echo "<h3>Hero Background Check:</h3>";
$hero = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'web_hero_background'")->fetchColumn();
echo "<p><strong>URL:</strong> " . htmlspecialchars($hero) . "</p>";

if (!empty($hero)) {
    echo "<p><strong>Full size preview:</strong></p>";
    echo "<div style='background-image:url(" . htmlspecialchars($hero) . ");width:600px;height:300px;background-size:cover;background-position:center;border:2px solid #333'></div>";
}

echo "<hr>";
echo "<h3>How website uses it:</h3>";
echo "<pre>";
echo 'In index.php:
$heroBg = $hero["web_hero_background"] ?? "";

In HTML:
&lt;div class="hero-bg" style="background-image: url(\'' . htmlspecialchars($hero) . '\');"&gt;&lt;/div&gt;';
echo "</pre>";

echo "<hr><p style='color:red'><strong>HAPUS FILE INI SETELAH DEBUG!</strong></p>";
