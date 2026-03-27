<?php
define('APP_ACCESS', true);
require_once '../../config/config.php';

// Start session with correct name
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

require_once '../../config/database.php';
require_once '../../includes/auth.php';

echo "<h2>Test Investor Module Access</h2>";
echo "<pre>";

$auth = new Auth();

echo "=== AUTH CHECK ===\n";
echo "Is Logged In: " . ($auth->isLoggedIn() ? "YES" : "NO") . "\n";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NULL') . "\n\n";

echo "=== PERMISSION CHECK ===\n";
$hasInvestor = $auth->hasPermission('investor');
echo "hasPermission('investor'): " . ($hasInvestor ? "✓ YES" : "✗ NO") . "\n\n";

if (!$auth->isLoggedIn()) {
    echo "❌ NOT LOGGED IN - Akan redirect ke login\n";
    echo "Session data:\n";
    print_r($_SESSION);
} else if (!$hasInvestor) {
    echo "❌ NO PERMISSION - Akan tampil 403 Forbidden\n";
} else {
    echo "✅ ALL CHECKS PASSED - Module SEHARUSNYA bisa diakses!\n\n";
    echo "=== TESTING DATABASE CONNECTION ===\n";
    try {
        $db = Database::getInstance()->getConnection();
        echo "Database: ✓ Connected\n";
        
        // Test query
        $stmt = $db->query("SHOW TABLES LIKE 'investors'");
        $tableExists = $stmt->fetch();
        echo "Table 'investors': " . ($tableExists ? "✓ EXISTS" : "✗ NOT FOUND") . "\n";
        
        if (!$tableExists) {
            echo "\n⚠️ WARNING: Table 'investors' tidak ada!\n";
            echo "Module akan error karena table tidak ditemukan.\n";
        }
    } catch (Exception $e) {
        echo "❌ Database Error: " . $e->getMessage() . "\n";
    }
}

echo "</pre>";
echo "<hr>";
echo "<a href='index.php'>Try Access Investor Module</a> | ";
echo "<a href='../../index.php'>Back to Dashboard</a>";
