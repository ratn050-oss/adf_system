<?php
/**
 * CLI Tool: Business Switcher
 * Usage: php switch-business.php <business-id>
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.");
}

// Get business ID from argument
if ($argc < 2) {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════╗\n";
    echo "║         BUSINESS SWITCHER - Narayana System          ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Usage: php switch-business.php <business-id>\n";
    echo "\n";
    echo "Available businesses:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $files = glob(__DIR__ . '/../config/businesses/*.php');
    foreach ($files as $file) {
        $businessId = basename($file, '.php');
        $config = require $file;
        
        $icon = $config['theme']['icon'] ?? '📦';
        $name = $config['business_name'] ?? $businessId;
        $type = $config['business_type'] ?? 'unknown';
        
        echo "  $icon  $businessId\n";
        echo "      → $name ($type)\n";
        echo "\n";
    }
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "\n";
    echo "Example:\n";
    echo "  php switch-business.php narayana-hotel\n";
    echo "  php switch-business.php warung-pakbudi\n";
    echo "\n";
    exit(1);
}

$businessId = $argv[1];
$businessFile = __DIR__ . '/../config/businesses/' . $businessId . '.php';

if (!file_exists($businessFile)) {
    echo "\n";
    echo "❌ Error: Business '$businessId' not found!\n";
    echo "\n";
    echo "Run 'php switch-business.php' to see available businesses.\n";
    echo "\n";
    exit(1);
}

// Load business config
$config = require $businessFile;

// Update active business file
$activeFile = __DIR__ . '/../config/active-business.php';
$content = "<?php\nreturn '$businessId';\n\n";
$content .= "// Available businesses:\n";

$files = glob(__DIR__ . '/../config/businesses/*.php');
foreach ($files as $file) {
    $id = basename($file, '.php');
    $content .= "// - '$id'\n";
}

file_put_contents($activeFile, $content);

// Display success message
$icon = $config['theme']['icon'] ?? '📦';
$name = $config['name'] ?? $config['business_name'] ?? 'Unknown';
$type = $config['business_type'];
$db = $config['database'];
$modules = count($config['enabled_modules']);

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║              ✓ BUSINESS SWITCHED                      ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  $icon  Business: $name\n";
echo "  📋  Type: $type\n";
echo "  💾  Database: $db\n";
echo "  📦  Modules: $modules enabled\n";
echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";
echo "Next steps:\n";
echo "  1. Refresh browser or restart server\n";
echo "  2. Login to system\n";
echo "  3. Start development for $name\n";
echo "\n";
echo "To switch back later:\n";
echo "  php switch-business.php $businessId\n";
echo "\n";

exit(0);
