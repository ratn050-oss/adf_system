<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>DEBUG BUSINESS-USERS</h1>";
echo "<pre>";

// Check if config file exists
$configPath = __DIR__ . '/config/config.php';
echo "Checking config.php at: $configPath\n";
echo "Exists: " . (file_exists($configPath) ? "YES" : "NO") . "\n\n";

// Check if dev_auth exists
$authPath = __DIR__ . '/developer/includes/dev_auth.php';
echo "Checking dev_auth.php at: $authPath\n";
echo "Exists: " . (file_exists($authPath) ? "YES" : "NO") . "\n\n";

// List developer folder contents
echo "Contents of developer/ folder:\n";
$devFiles = scandir(__DIR__ . '/developer');
foreach ($devFiles as $file) {
    if ($file !== '.' && $file !== '..') {
        echo "  - $file\n";
    }
}

echo "\nContents of developer/includes/ folder:\n";
$includesPath = __DIR__ . '/developer/includes';
if (is_dir($includesPath)) {
    $includeFiles = scandir($includesPath);
    foreach ($includeFiles as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "  - $file\n";
        }
    }
} else {
    echo "  FOLDER NOT FOUND!\n";
}

echo "</pre>";

// Now try to load business-users directly with error handling
echo "<h2>Attempting to load business-users.php</h2>";
echo "<pre>";
try {
    require_once __DIR__ . '/config/config.php';
    echo "✓ Config loaded\n";
} catch (Exception $e) {
    echo "✗ Config ERROR: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/developer/includes/dev_auth.php';
    echo "✓ Dev Auth loaded\n";
} catch (Exception $e) {
    echo "✗ Dev Auth ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
