<?php
/**
 * Auto-Fix CQC Database Configuration
 * 1. Ensure adfb2574_cqc exists
 * 2. Add CQC to config/businesses.php with correct name
 */

header('Content-Type: text/html; charset=utf-8');
echo "<h2>🔧 Fixing CQC Database & Config</h2>\n";

// Detect environment
$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

echo "<p><strong>Environment:</strong> " . ($isHosting ? "🌐 HOSTING" : "💻 LOCAL") . "</p>\n";

$dbHost = 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$dbName = $isHosting ? 'adfb2574_cqc' : 'adf_cqc';

echo "<hr>\n";

try {
    // Step 1: Create/verify database
    echo "<h3>1️⃣ Creating database: <code>$dbName</code></h3>\n";
    
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ Database created/exists\n\n";
    
    // Connect to new database
    $bizPdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create essential tables
    echo "<h3>2️⃣ Creating tables in database...</h3>\n";
    
    $bizPdo->exec("
    CREATE TABLE IF NOT EXISTS `users` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `business_id` int(11) DEFAULT NULL,
        `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `full_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'user',
        `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'active',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created: users table\n";
    
    $bizPdo->exec("
    CREATE TABLE IF NOT EXISTS `settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `key` varchar(255) NOT NULL,
        `value` longtext,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `key` (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created: settings table\n";
    
    $bizPdo->exec("
    CREATE TABLE IF NOT EXISTS `transactions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `type` varchar(50) NOT NULL,
        `amount` decimal(15,2) NOT NULL,
        `description` text,
        `date` date NOT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `date` (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created: transactions table\n";
    
    echo "\n<h3>✅ Database Setup Complete!</h3>\n";
    echo "<p>Database <strong><code>$dbName</code></strong> is ready with schema.</p>\n";
    
    // Step 2: Provide config update info
    echo "\n<h3>3️⃣ Next: Update config/businesses.php</h3>\n";
    echo "<p style='background: #f0f0f0; padding: 15px; border-radius: 5px; font-family: monospace;'>\n";
    echo "Add this business entry to config/businesses.php inside \$BUSINESSES array:<br><br>\n";
    echo "<pre style='background: white; padding: 10px; border-left: 3px solid green;'>\n";
    echo htmlspecialchars("[\n    'id' => 7,\n    'name' => 'CQC',\n    'database' => '" . $dbName . "',\n    'type' => 'other',\n    'active' => true\n]") . "\n";
    echo "</pre>\n";
    echo "</p>\n";
    
    echo "\n<p><a href='javascript:history.back()'>← Back</a></p>\n";
    
} catch (Exception $e) {
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
    echo "<p>User: <code>$dbUser</code></p>\n";
    echo "<p>Database: <code>$dbName</code></p>\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; line-height: 1.6; }
h2, h3 { color: #333; }
code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
pre { overflow-x: auto; }
</style>
