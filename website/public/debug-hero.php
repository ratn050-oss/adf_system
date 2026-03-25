<?php
/**
 * Debug hero image
 * https://narayanakarimunjawa.com/debug-hero.php?token=debughero
 */
if (($_GET['token'] ?? '') !== 'debughero') die('Token required');

// Connect to database
$pdo = new PDO(
    'mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4',
    'adfb2574_adfsystem', '@Nnoc2025',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Get hero background
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'web_hero_background'");
$heroBg = $stmt->fetchColumn();

echo "<h2>Hero Background Debug</h2>";
echo "<p><strong>Value in database:</strong></p>";
echo "<pre>" . htmlspecialchars($heroBg) . "</pre>";

echo "<p><strong>Is URL absolute (starts with http)?:</strong> " . (strpos($heroBg, 'http') === 0 ? 'YES' : 'NO') . "</p>";

// Test image
echo "<h3>Direct image test:</h3>";
if (!empty($heroBg)) {
    echo "<img src='" . htmlspecialchars($heroBg) . "' style='max-width:500px;border:2px solid green'>";
    echo "<p>If image shows above, URL is correct.</p>";
}

// Check index.php hero section
echo "<h3>index.php hero-bg line:</h3>";
$indexContent = file_get_contents(__DIR__ . '/index.php');
if (preg_match('/(<div class="hero-bg".*?<\/div>)/s', $indexContent, $matches)) {
    echo "<pre style='background:#eee;padding:10px;overflow-x:auto'>" . htmlspecialchars($matches[1]) . "</pre>";
} else {
    echo "<p style='color:red'>Could not find hero-bg div!</p>";
}

// Show what the CSS would render
echo "<h3>CSS Background test:</h3>";
$testUrl = (strpos($heroBg, 'http') === 0) ? $heroBg : '/' . $heroBg;
echo "<div style='width:600px;height:300px;background-image:url(\"" . htmlspecialchars($testUrl) . "\");background-size:cover;background-position:center;border:2px solid blue'></div>";
echo "<p>URL used: " . htmlspecialchars($testUrl) . "</p>";

echo "<hr><p style='color:red'><b>DELETE THIS FILE!</b></p>";
