<?php
/**
 * Diagnostic + Sync tool
 * URL: https://adfsystem.online/sync.php?token=adf-deploy-2025-secure
 * Add &action=check to check DB values
 * Add &action=sync to sync files from GitHub
 * Add &action=fix_logo&url=CLOUDINARY_URL to fix logo in DB
 */
$token = $_GET['token'] ?? '';
if (!hash_equals('adf-deploy-2025-secure', $token)) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
$action = $_GET['action'] ?? 'check';

if ($action === 'check') {
    echo "=== DB Diagnostic ===\n\n";
    
    // Connect to narayana_hotel DB
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "DB Connected: adfb2574_narayana_hotel\n\n";
        
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('web_logo', 'web_favicon', 'web_hero_background')");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($rows as $row) {
            echo $row['setting_key'] . " = " . $row['setting_value'] . "\n";
        }
        if (empty($rows)) {
            echo "No web_logo/web_favicon settings found in DB!\n";
        }
    } catch (Exception $e) {
        echo "DB Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n--- File Check ---\n";
    $webRoot = '/home/adfb2574/public_html/narayanakarimunjawa.com';
    $checkPaths = [
        $webRoot . '/web_logo.png',
        $webRoot . '/uploads/logo/',
        $webRoot . '/uploads/',
        $webRoot . '/includes/header.php',
    ];
    foreach ($checkPaths as $p) {
        echo $p . " : " . (file_exists($p) ? (is_dir($p) ? 'DIR EXISTS' : 'FILE EXISTS (' . filesize($p) . ' bytes)') : 'NOT FOUND') . "\n";
    }
    
    // List uploads dir if exists
    $uploadsDir = $webRoot . '/uploads/';
    if (is_dir($uploadsDir)) {
        echo "\nContents of $uploadsDir:\n";
        $items = scandir($uploadsDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $uploadsDir . $item;
            echo "  $item " . (is_dir($full) ? '[DIR]' : '(' . filesize($full) . ' bytes)') . "\n";
        }
    }
    exit;
}

if ($action === 'fix_logo') {
    $url = $_GET['url'] ?? '';
    if (empty($url)) {
        die("Provide &url=CLOUDINARY_URL_HERE");
    }
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES ('web_logo', ?, 'text', 'Website Logo') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$url, $url]);
        echo "Logo updated to: $url\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit;
}

// ===== SYNC ACTION =====
$dir = dirname(__FILE__);

echo "=== ADF Sync (GitHub API) ===\n";
echo "Dir: $dir\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$head = @file_get_contents($dir . '/.git/refs/heads/main');
echo "Current commit: " . ($head ? trim($head) : 'unknown') . "\n\n";

// Files to sync from GitHub
$repo = 'ratn050-oss/adf_system';
$branch = 'main';
$filesToSync = [
    '.htaccess',
    'modules/frontdesk/rental-motor.php',
    'website/public/includes/header.php',
    'website/public/assets/css/style.css',
    '.cpanel.yml',
];

// Also deploy website files directly to narayanakarimunjawa.com docroot
$websiteDeploy = [
    'website/public/includes/header.php' => '/home/adfb2574/public_html/narayanakarimunjawa.com/includes/header.php',
    'website/public/assets/css/style.css' => '/home/adfb2574/public_html/narayanakarimunjawa.com/assets/css/style.css',
];

$success = 0;
$failed = 0;
$ctx = stream_context_create(['http' => [
    'timeout' => 30,
    'user_agent' => 'ADF-Sync/1.0',
]]);

foreach ($filesToSync as $file) {
    $url = "https://raw.githubusercontent.com/$repo/$branch/$file";
    echo "Syncing: $file ... ";

    $content = @file_get_contents($url, false, $ctx);
    if ($content === false) {
        echo "FAILED (download error)\n";
        $failed++;
        continue;
    }

    $localPath = $dir . '/' . $file;
    $localDir = dirname($localPath);
    if (!is_dir($localDir)) {
        @mkdir($localDir, 0755, true);
    }

    if (@file_put_contents($localPath, $content) !== false) {
        echo "OK (" . strlen($content) . " bytes)\n";
        $success++;
    } else {
        echo "FAILED (write error)\n";
        $failed++;
    }
}

// Deploy website files directly to narayanakarimunjawa.com
echo "\n--- Deploying to narayanakarimunjawa.com ---\n";
foreach ($websiteDeploy as $srcFile => $destPath) {
    $srcPath = $dir . '/' . $srcFile;
    if (!file_exists($srcPath)) {
        echo "Skip $srcFile (not downloaded)\n";
        continue;
    }
    $destDir = dirname($destPath);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }
    $content = file_get_contents($srcPath);
    if (@file_put_contents($destPath, $content) !== false) {
        echo "Deployed: $destPath (" . strlen($content) . " bytes)\n";
    } else {
        echo "FAILED to deploy: $destPath\n";
    }
}

// Fix git state so cPanel can deploy again
echo "\n--- Fixing git state ---\n";
$gitDir = $dir . '/.git';

// Update git ref to latest
$apiUrl = "https://api.github.com/repos/$repo/git/ref/heads/$branch";
$apiCtx = stream_context_create(['http' => [
    'timeout' => 10,
    'user_agent' => 'ADF-Sync/1.0',
    'header' => "Accept: application/vnd.github.v3+json\r\n",
]]);
$refData = @file_get_contents($apiUrl, false, $apiCtx);
if ($refData) {
    $ref = json_decode($refData, true);
    $latestSha = $ref['object']['sha'] ?? '';
    if ($latestSha) {
        @file_put_contents($gitDir . '/refs/heads/main', $latestSha . "\n");
        echo "Updated git ref: " . substr($latestSha, 0, 7) . "\n";
    }
}

echo "\n=== Result: $success OK, $failed failed ===\n";
echo ($failed === 0) ? "✅ SYNC SUCCESS\n" : "⚠️ SOME FILES FAILED\n";
echo "\n⚠️ HAPUS FILE sync.php SETELAH SELESAI!";
