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

if ($action === 'verify') {
    echo "=== Verify Deployed Files ===\n\n";
    
    $webRoot = '/home/adfb2574/public_html/narayanakarimunjawa.com';
    
    // Check header.php content
    $headerFile = $webRoot . '/includes/header.php';
    echo "Header file: $headerFile\n";
    echo "Exists: " . (file_exists($headerFile) ? 'YES' : 'NO') . "\n";
    if (file_exists($headerFile)) {
        echo "Size: " . filesize($headerFile) . " bytes\n";
        echo "Modified: " . date('Y-m-d H:i:s', filemtime($headerFile)) . "\n\n";
        
        $content = file_get_contents($headerFile);
        
        // Check for assetUrl function
        echo "Contains 'function assetUrl': " . (strpos($content, 'function assetUrl') !== false ? 'YES' : 'NO') . "\n";
        echo "Contains 'preg_match.*https': " . (strpos($content, "preg_match('#^https?://#i'") !== false ? 'YES' : 'NO') . "\n";
        echo "Contains 'uploads/logo': " . (strpos($content, 'uploads/logo') !== false ? 'YES' : 'NO') . "\n";
        echo "Contains old 'BASE_URL./. htmlspecialchars(logoPath)': " . (strpos($content, 'BASE_URL ?>/<?= htmlspecialchars($logoPath)') !== false ? 'YES (OLD!)' : 'NO (GOOD)') . "\n\n";
        
        // Extract the img tag line
        foreach (explode("\n", $content) as $i => $line) {
            if (strpos($line, 'brand-img') !== false) {
                echo "Line " . ($i+1) . ": " . trim($line) . "\n";
            }
            if (strpos($line, 'assetUrl') !== false) {
                echo "Line " . ($i+1) . ": " . trim($line) . "\n";
            }
        }
    }
    
    // Check config.php
    echo "\n--- Config Check ---\n";
    $cfgFile = $webRoot . '/config/config.php';
    echo "Config: $cfgFile - " . (file_exists($cfgFile) ? 'EXISTS (' . filesize($cfgFile) . ' bytes)' : 'NOT FOUND') . "\n";
    
    // Try to load config and test the actual logo URL generation
    echo "\n--- Live Test ---\n";
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'web_logo'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $logoPath = $row['setting_value'] ?? '';
        echo "web_logo from DB: '$logoPath'\n";
        
        // Simulate assetUrl
        if (preg_match('#^https?://#i', $logoPath)) {
            echo "Result: Direct URL (Cloudinary) → $logoPath\n";
        } else {
            echo "Result: Local path → /uploads/logo/$logoPath\n";
        }
    } catch (Exception $e) {
        echo "DB Error: " . $e->getMessage() . "\n";
    }
    
    exit;
}

if ($action === 'debug_site') {
    echo "=== Finding Real Document Root ===\n\n";
    
    // Check all possible locations
    $locations = [
        '/home/adfb2574/public_html/narayanakarimunjawa.com',
        '/home/adfb2574/narayanakarimunjawa.com',
        '/home/adfb2574/narayanakarimunjawa',
        '/home/adfb2574/public_html/narayanakarimunjawa',
    ];
    
    foreach ($locations as $loc) {
        echo "$loc:\n";
        if (file_exists($loc)) {
            echo "  EXISTS - " . (is_dir($loc) ? 'DIRECTORY' : 'FILE') . "\n";
            if (is_link($loc)) {
                echo "  SYMLINK → " . readlink($loc) . "\n";
            }
            if (is_dir($loc)) {
                $items = @scandir($loc);
                if ($items) {
                    echo "  Contents: ";
                    $show = [];
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $full = $loc . '/' . $item;
                        $show[] = $item . (is_dir($full) ? '/' : ' (' . filesize($full) . 'b)');
                    }
                    echo implode(', ', $show) . "\n";
                }
                // Check for index.php and includes/header.php
                if (file_exists($loc . '/index.php')) {
                    echo "  index.php: EXISTS (" . filesize($loc . '/index.php') . " bytes)\n";
                }
                if (file_exists($loc . '/includes/header.php')) {
                    $hc = file_get_contents($loc . '/includes/header.php');
                    echo "  includes/header.php: EXISTS (" . strlen($hc) . " bytes)\n";
                    echo "    Has assetUrl: " . (strpos($hc, 'function assetUrl') !== false ? 'YES' : 'NO') . "\n";
                    echo "    Has old BASE_URL pattern: " . (strpos($hc, 'BASE_URL ?>/<?= htmlspecialchars($logoPath)') !== false ? 'YES (OLD!)' : 'NO (GOOD)') . "\n";
                }
                if (file_exists($loc . '/config/config.php')) {
                    echo "  config/config.php: EXISTS (" . filesize($loc . '/config/config.php') . " bytes)\n";
                }
            }
        } else {
            echo "  NOT FOUND\n";
        }
        echo "\n";
    }
    
    // Also create _debug.php in ALL locations that have index.php
    $debugContent = '<?php echo "SERVED FROM: " . __DIR__;';
    foreach ($locations as $loc) {
        if (file_exists($loc . '/index.php')) {
            @file_put_contents($loc . '/_debug.php', $debugContent);
            echo "Created _debug.php in $loc\n";
        }
    }
    
    echo "\nNow try: https://narayanakarimunjawa.com/_debug.php\n";
    exit;
}

if ($action === 'clearcache') {
    echo "=== PHP OPcache Clear ===\n\n";
    
    if (function_exists('opcache_reset')) {
        opcache_reset();
        echo "opcache_reset() called successfully!\n";
    } else {
        echo "opcache_reset() not available\n";
    }
    
    // Also invalidate specific files
    $files = [
        '/home/adfb2574/public_html/narayanakarimunjawa.com/includes/header.php',
        '/home/adfb2574/public_html/narayanakarimunjawa.com/index.php',
        '/home/adfb2574/public_html/narayanakarimunjawa.com/config/config.php',
    ];
    foreach ($files as $f) {
        if (function_exists('opcache_invalidate') && file_exists($f)) {
            $result = opcache_invalidate($f, true);
            echo "opcache_invalidate($f): " . ($result ? 'OK' : 'FAILED') . "\n";
        }
    }
    
    echo "\nOPcache status:\n";
    if (function_exists('opcache_get_status')) {
        $status = opcache_get_status(false);
        if ($status) {
            echo "Enabled: " . ($status['opcache_enabled'] ? 'YES' : 'NO') . "\n";
            echo "Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
            echo "Cache hits: " . ($status['opcache_statistics']['hits'] ?? 'N/A') . "\n";
        } else {
            echo "Could not get status (restricted)\n";
        }
    } else {
        echo "opcache_get_status not available\n";
    }
    
    echo "\nDone! Now refresh narayanakarimunjawa.com\n";
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
    'sync.php',
    '.htaccess',
    'modules/frontdesk/rental-motor.php',
    'website/public/index.php',
    'website/public/rooms.php',
    'website/public/activities.php',
    'website/public/includes/header.php',
    'website/public/assets/css/style.css',
    '.cpanel.yml',
];

// Also deploy website files directly to narayanakarimunjawa.com
// The REAL website runs from public/ subdirectory!
$webBase = '/home/adfb2574/public_html/narayanakarimunjawa.com';
$websiteDeploy = [
    // Deploy to public/ (where website actually runs from)
    ['src' => 'website/public/index.php', 'dest' => $webBase . '/public/index.php'],
    ['src' => 'website/public/rooms.php', 'dest' => $webBase . '/public/rooms.php'],
    ['src' => 'website/public/activities.php', 'dest' => $webBase . '/public/activities.php'],
    ['src' => 'website/public/includes/header.php', 'dest' => $webBase . '/public/includes/header.php'],
    ['src' => 'website/public/assets/css/style.css', 'dest' => $webBase . '/public/assets/css/style.css'],
    // Also deploy to root level (for .cpanel.yml compatibility)
    ['src' => 'website/public/index.php', 'dest' => $webBase . '/index.php'],
    ['src' => 'website/public/rooms.php', 'dest' => $webBase . '/rooms.php'],
    ['src' => 'website/public/activities.php', 'dest' => $webBase . '/activities.php'],
    ['src' => 'website/public/includes/header.php', 'dest' => $webBase . '/includes/header.php'],
    ['src' => 'website/public/assets/css/style.css', 'dest' => $webBase . '/assets/css/style.css'],
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
foreach ($websiteDeploy as $entry) {
    $srcFile = $entry['src'];
    $destPath = $entry['dest'];
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
    // Clear PHP OPcache for this file
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($destPath, true);
        echo "  → OPcache cleared for $destPath\n";
    }
}

// Also clear opcache for all PHP files in website
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "\n✅ PHP OPcache fully reset!\n";
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
