<?php
/**
 * Deploy Website Script
 * Copy public/ files from adf_system to narayanakarimunjawa.com
 * 
 * Run this from: https://adfsystem.online/adf_system/deploy-website.php?key=deploy2026
 * Or from cPanel Terminal: php /home/adfb2574/public_html/adf_system/deploy-website.php
 */

// Security key
$secretKey = 'deploy2026';
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    $key = $_GET['key'] ?? '';
    if ($key !== $secretKey) {
        http_response_code(403);
        die('❌ Forbidden. Add ?key=' . $secretKey);
    }
}

// Detect environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                 strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

if ($isProduction || $isCLI) {
    $sourceDir = '/home/adfb2574/public_html/adf_system/public';
    $targetDir = '/home/adfb2574/public_html/narayanakarimunjawa.com';
} else {
    // Local development
    $sourceDir = __DIR__ . '/public';
    $targetDir = dirname(__DIR__) . '/narayanakarimunjawa/public';
}

// Files to deploy
$filesToDeploy = [
    'index.php',
    'booking.php',
    'includes/config.php',
    'includes/database.php',
    'includes/header.php',
    'includes/footer.php',
    'assets/css/homepage.css',
    'assets/css/website.css',
    'assets/css/booking.css',
    'assets/js/main.js',
];

// Start output
if (!$isCLI) echo '<pre>';

echo "========================================================\n";
echo "  DEPLOY WEBSITE - narayanakarimunjawa.com\n";
echo "========================================================\n";
echo "  Source : $sourceDir\n";
echo "  Target : $targetDir\n";
echo "  Time   : " . date('Y-m-d H:i:s') . "\n";
echo "========================================================\n\n";

// Check source directory
if (!is_dir($sourceDir)) {
    echo "❌ ERROR: Source directory not found: $sourceDir\n";
    exit(1);
}

// Check target directory
if (!is_dir($targetDir)) {
    echo "⚠️  Target directory not found, creating: $targetDir\n";
    mkdir($targetDir, 0755, true);
}

$successCount = 0;
$failCount = 0;
$skippedCount = 0;

foreach ($filesToDeploy as $file) {
    $sourcePath = $sourceDir . '/' . $file;
    $targetPath = $targetDir . '/' . $file;
    
    // Check source exists
    if (!file_exists($sourcePath)) {
        echo "  SKIP: $file (source not found)\n";
        $skippedCount++;
        continue;
    }
    
    // Create target directory if needed
    $targetSubDir = dirname($targetPath);
    if (!is_dir($targetSubDir)) {
        mkdir($targetSubDir, 0755, true);
        echo "  DIR : Created $targetSubDir\n";
    }
    
    // Copy file
    if (copy($sourcePath, $targetPath)) {
        $size = round(filesize($sourcePath) / 1024, 1);
        echo "  ✅ OK: $file ($size KB)\n";
        $successCount++;
    } else {
        echo "  ❌ FAIL: $file\n";
        $failCount++;
    }
}

// Also ensure uploads directories exist in target
$uploadDirs = [
    $targetDir . '/uploads',
    $targetDir . '/uploads/hero',
    $targetDir . '/uploads/logo',
    $targetDir . '/uploads/favicon',
    $targetDir . '/uploads/rooms',
    $targetDir . '/uploads/destinations',
];

echo "\n  Creating upload directories...\n";
foreach ($uploadDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "  📁 Created: " . basename($dir) . "/\n";
    }
}

echo "\n========================================================\n";
echo "  DEPLOY COMPLETE!\n";
echo "  ✅ Success : $successCount files\n";
if ($failCount > 0) echo "  ❌ Failed  : $failCount files\n";
if ($skippedCount > 0) echo "  ⏭️  Skipped : $skippedCount files\n";
echo "========================================================\n\n";
echo "  🌐 Website: https://narayanakarimunjawa.com\n";
echo "  🔧 Developer: https://adfsystem.online/adf_system/developer/web-settings.php\n\n";

if (!$isCLI) echo '</pre>';
?>
