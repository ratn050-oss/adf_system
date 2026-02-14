<?php
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$db = Database::getInstance();

// Debug: Check cash accounts
$accounts = $db->fetchAll("SELECT * FROM cash_accounts WHERE is_active = 1");

echo "<h2>DEBUG: Cash Accounts</h2>";
echo "<p>Total: " . count($accounts) . "</p>";
echo "<pre>";
print_r($accounts);
echo "</pre>";

// Check if column exists
$cols = $db->fetchAll("SHOW COLUMNS FROM cash_accounts");
echo "<h2>Cash Accounts Columns:</h2>";
echo "<pre>";
print_r($cols);
echo "</pre>";
?>
