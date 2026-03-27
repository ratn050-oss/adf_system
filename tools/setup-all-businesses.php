<?php
/**
 * Setup All Businesses - Create Databases & Initialize
 * Usage: php setup-all-businesses.php
 */

require_once __DIR__ . '/../config/database.php';

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.");
}

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║        SETUP ALL BUSINESSES - Narayana System         ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";

// Get all business configs
$businesses = [];
$files = glob(__DIR__ . '/../config/businesses/*.php');

echo "Found " . count($files) . " businesses to setup:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

foreach ($files as $file) {
    $businessId = basename($file, '.php');
    $config = require $file;
    $businesses[$businessId] = $config;
    
    $icon = $config['theme']['icon'] ?? '📦';
    $name = $config['business_name'];
    $db = $config['database'];
    
    echo "  $icon  $name\n";
    echo "      Database: $db\n";
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";
echo "This will:\n";
echo "  1. Create separate database for each business\n";
echo "  2. Initialize schema (tables, structure)\n";
echo "  3. Setup basic data (categories, settings)\n";
echo "\n";
echo "⚠️  WARNING: Existing databases will be DROPPED!\n";
echo "\n";

// Confirmation
echo "Continue? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if ($line !== 'yes') {
    echo "\nSetup cancelled.\n\n";
    exit(0);
}

echo "\n";
echo "Starting setup...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

// Connect to MySQL (without database)
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✓ Connected to MySQL\n\n";
    
} catch (PDOException $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Read base schema
$schemaFile = __DIR__ . '/../database.sql';
if (!file_exists($schemaFile)) {
    echo "✗ Schema file not found: $schemaFile\n\n";
    exit(1);
}

$baseSchema = file_get_contents($schemaFile);

// Setup each business
$successCount = 0;
$failCount = 0;

foreach ($businesses as $businessId => $config) {
    $icon = $config['theme']['icon'];
    $name = $config['business_name'];
    $dbName = $config['database'];
    
    echo "Setting up: $icon $name\n";
    echo "  Database: $dbName\n";
    
    try {
        // Drop if exists
        echo "  → Dropping old database (if exists)...\n";
        $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
        
        // Create database
        echo "  → Creating database...\n";
        $pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Select database
        $pdo->exec("USE `$dbName`");
        
        // Execute schema
        echo "  → Creating tables...\n";
        
        // Split by semicolon and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $baseSchema)),
            function($stmt) {
                return !empty($stmt) && 
                       stripos($stmt, 'CREATE DATABASE') === false &&
                       stripos($stmt, 'USE ') !== 0;
            }
        );
        
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // Ignore duplicate table errors
                    if ($e->getCode() != '42S01') {
                        throw $e;
                    }
                }
            }
        }
        
        // Insert default data
        echo "  → Inserting default data...\n";
        
        // Check if admin already exists
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE username = 'admin'");
        $exists = $stmt->fetch()['count'] > 0;
        
        if (!$exists) {
            // Default admin user (password: admin123)
            $pdo->exec("
                INSERT INTO users (username, password, full_name, email, role, is_active, created_at)
                VALUES ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@example.com', 'admin', 1, NOW())
            ");
        }
        
        // Default branch
        $pdo->exec("
            INSERT INTO branches (branch_name, address, phone, is_active, created_at)
            VALUES ('{$config['business_name']} - Main Branch', 'Address TBD', '0000000000', 1, NOW())
        ");
        
        // Default categories
        $categories = [
            ['Sales Revenue', 'income', 'Revenue from sales'],
            ['Service Revenue', 'income', 'Revenue from services'],
            ['Other Income', 'income', 'Other income sources'],
            ['Operational Expense', 'expense', 'Daily operational costs'],
            ['Salary & Wages', 'expense', 'Employee salaries'],
            ['Utilities', 'expense', 'Electricity, water, internet'],
            ['Supplies', 'expense', 'Office and operational supplies'],
            ['Maintenance', 'expense', 'Maintenance and repairs'],
            ['Other Expense', 'expense', 'Other expenses']
        ];
        
        foreach ($categories as $cat) {
            $pdo->prepare("
                INSERT INTO expense_categories (category_name, category_type, description, created_at)
                VALUES (?, ?, ?, NOW())
            ")->execute($cat);
        }
        
        // Default division
        $pdo->exec("
            INSERT INTO divisions (division_name, description, created_at)
            VALUES ('General', 'General division', NOW())
        ");
        
        echo "  ✓ Success!\n";
        echo "\n";
        $successCount++;
        
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        echo "\n";
        $failCount++;
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";
echo "Setup Complete!\n";
echo "  ✓ Success: $successCount\n";

if ($failCount > 0) {
    echo "  ✗ Failed: $failCount\n";
}

echo "\n";
echo "Default Login Credentials:\n";
echo "  Username: admin\n";
echo "  Password: admin123\n";
echo "\n";
echo "Next steps:\n";
echo "  1. Switch to a business: php tools/switch-business.php <business-id>\n";
echo "  2. Open browser: http://localhost:8080/narayana/\n";
echo "  3. Login and start developing!\n";
echo "\n";
