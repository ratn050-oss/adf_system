<?php
define('APP_ACCESS', true);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$bizConfig = require __DIR__ . '/../config/businesses/bens-cafe.php';
$db = Database::switchDatabase($bizConfig['database']);
$pdo = $db->getConnection();

// Run the database-payroll.sql (CREATE IF NOT EXISTS — safe for existing)
$sqlFile = __DIR__ . '/../database-payroll.sql';
$sql = file_get_contents($sqlFile);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));
echo "=== Bens Cafe ===\n";
foreach ($statements as $stmt) {
    if (!empty($stmt)) {
        try {
            $pdo->exec($stmt);
            if (preg_match('/(?:CREATE TABLE|INSERT INTO)\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $stmt, $m)) {
                echo "  OK: {$m[1]}\n";
            }
        } catch (PDOException $e) {
            echo "  ERR: " . $e->getMessage() . "\n";
        }
    }
}

// Also init for narayana-hotel
$bizConfig2 = require __DIR__ . '/../config/businesses/narayana-hotel.php';
$db2 = Database::switchDatabase($bizConfig2['database']);
$pdo2 = $db2->getConnection();
echo "\n=== Narayana Hotel ===\n";
foreach ($statements as $stmt) {
    if (!empty($stmt)) {
        try {
            $pdo2->exec($stmt);
            if (preg_match('/(?:CREATE TABLE|INSERT INTO)\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $stmt, $m)) {
                echo "  OK: {$m[1]}\n";
            }
        } catch (PDOException $e) {
            echo "  ERR: " . $e->getMessage() . "\n";
        }
    }
}
echo "\nDone.\n";
