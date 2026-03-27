<?php
define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));
require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Test Investor</title>";
echo "<style>body{background:#1a1a2e;color:white;padding:2rem;font-family:sans-serif;}</style></head><body>";
echo "<h1>🧪 Test Investor Module</h1>";
echo "<pre>";

$auth = new Auth();
echo "Login: " . ($auth->isLoggedIn() ? "✓ YES" : "✗ NO") . "\n";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NULL') . "\n";
echo "Permission 'investor': " . ($auth->hasPermission('investor') ? "✓ YES" : "✗ NO") . "\n\n";

if (!$auth->hasPermission('investor')) {
    echo "❌ NO PERMISSION!\n";
    echo "</pre></body></html>";
    exit;
}

require_once $base_path . '/includes/InvestorManager.php';
$db = Database::getInstance()->getConnection();
$investor = new InvestorManager($db);

echo "=== DATABASE CHECK ===\n";
try {
    $investors = $investor->getAllInvestors();
    echo "✓ getAllInvestors() berhasil\n";
    echo "Jumlah investor: " . count($investors) . "\n\n";
    
    echo "=== DATA ===\n";
    if (empty($investors)) {
        echo "Belum ada data investor (table kosong)\n";
    } else {
        foreach ($investors as $inv) {
            echo "- {$inv['name']}: Rp " . number_format($inv['total_capital_idr'] ?? 0, 0, ',', '.') . "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<hr>";
echo "<a href='index.php' style='color:#64b5f6;'>Go to Full Investor Page</a> | ";
echo "<a href='../../index.php' style='color:#64b5f6;'>Dashboard</a>";
echo "</body></html>";
