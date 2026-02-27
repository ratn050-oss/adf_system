<?php
/**
 * Fix CQC Database Setup
 * Create adfb2574_cqc database with proper schema
 */

header('Content-Type: text/html; charset=utf-8');
echo "<h2>🔧 Fixing CQC Database Setup</h2>\n";

$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$dbHost = $isHosting ? 'localhost' : 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$dbName = 'adfb2574_cqc';

echo "<p><strong>Creating database:</strong> <code>$dbName</code></p>\n";
echo "<hr>\n";

try {
    // Connect to master
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 1: Create database
    echo "<h3>1️⃣ Creating database...</h3>\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ Database created/exists\n\n";
    
    // Step 2: Connect to new database and create schema
    echo "<h3>2️⃣ Creating tables...</h3>\n";
    $bizPdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create users table (same as master)
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
    
    // Create minimal essential tables for ADF system
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
    
    // Create transactions table
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
    
    echo "\n<h3>✅ Success!</h3>\n";
    echo "<p>Database <code>$dbName</code> has been created with essential schema.</p>\n";
    echo "<p><strong>Next steps:</strong></p>\n";
    echo "<ol>\n";
    echo "<li>Update <code>config/businesses.php</code> with:<br><code>'database' => 'adfb2574_cqc'</code></li>\n";
    echo "<li>Test create business again or access the business dashboard</li>\n";
    echo "</ol>\n";
    
    // Check if config exists
    $configFile = '/home/adfb2574/public_html/config/businesses.php';
    if (file_exists($configFile)) {
        echo "\n<p style='color: green;'>ℹ️ Config file found at expected location</p>\n";
    }
    
    echo "\n<p><a href='javascript:history.back()'>← Back</a></p>\n";
    
} catch (Exception $e) {
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
    echo "<p>Could not create database. Please check:<br>";
    echo "1. Database name: <code>$dbName</code><br>";
    echo "2. User: <code>$dbUser</code><br>";
    echo "3. Host: <code>$dbHost</code>";
    echo "</p>\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; line-height: 1.6; }
h2, h3 { color: #333; }
code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
</style>
