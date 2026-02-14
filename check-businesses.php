<?php
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$pdo = Database::getInstance()->getConnection();

echo "📋 TABLE STRUCTURE DEBUG\n";
echo "=======================\n\n";

// Check businesses table
echo "Businesses Table Columns:\n";
$result = $pdo->query("SHOW COLUMNS FROM businesses");
$cols = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "  - {$col['Field']}\n";
}

// Try to get businesses (without is_active filter)
echo "\nBusinesses Data:\n";
$biz = $pdo->query("SELECT * FROM businesses LIMIT 3");
$data = $biz->fetchAll(PDO::FETCH_ASSOC);
foreach ($data as $row) {
    echo "  ID: {$row['id']} | Name: " . (isset($row['business_name']) ? $row['business_name'] : 'N/A') . "\n";
}
?>
