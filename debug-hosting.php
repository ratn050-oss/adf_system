<?php
/**
 * Debug file for hosting - upload to find 500 error cause
 * DELETE AFTER USE!
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>ADF System Debug</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Step 1: Config
echo "<h3>Step 1: Config</h3>";
try {
    define('APP_ACCESS', true);
    require_once 'config/config.php';
    echo "<p style='color:green'>config.php loaded OK</p>";
    echo "<p>DB_NAME: " . DB_NAME . "</p>";
    echo "<p>BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NOT SET') . "</p>";
    echo "<p>ACTIVE_BUSINESS_ID: " . (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'NOT SET') . "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>config error: " . $e->getMessage() . " line " . $e->getLine() . " in " . $e->getFile() . "</p>";
    exit;
}

// Step 2: Database
echo "<h3>Step 2: Database</h3>";
try {
    $testConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    echo "<p style='color:green'>DB connected: " . DB_NAME . "</p>";
    $tables = $testConn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Tables (" . count($tables) . "): " . implode(', ', $tables) . "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>DB error: " . $e->getMessage() . "</p>";
    exit;
}

// Step 3: Database class
echo "<h3>Step 3: Database Class</h3>";
try {
    require_once 'config/database.php';
    $db = Database::getInstance();
    echo "<p style='color:green'>getInstance OK - " . Database::getCurrentDatabase() . "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>DB class error: " . $e->getMessage() . " line " . $e->getLine() . " file " . $e->getFile() . "</p>";
    exit;
}

// Step 4: Includes
echo "<h3>Step 4: Includes</h3>";
foreach (['includes/auth.php','includes/functions.php','includes/trial_check.php'] as $inc) {
    try {
        if (file_exists($inc)) { require_once $inc; echo "<p style='color:green'>{$inc} OK</p>"; }
        else echo "<p style='color:red'>{$inc} NOT FOUND</p>";
    } catch (Throwable $e) {
        echo "<p style='color:red'>{$inc}: " . $e->getMessage() . " line " . $e->getLine() . "</p>";
    }
}

// Step 5: Master DB / cash_accounts
echo "<h3>Step 5: Master DB</h3>";
try {
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $masterDbName, DB_USER, DB_PASS);
    echo "<p style='color:green'>Master DB: {$masterDbName}</p>";
    $mt = $masterDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>cash_accounts: " . (in_array('cash_accounts', $mt) ? 'YES' : 'NO') . "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>Master DB error: " . $e->getMessage() . "</p>";
}

// Step 6: Key functions
echo "<h3>Step 6: Functions</h3>";
echo "<p>formatCurrency: " . (function_exists('formatCurrency') ? 'YES' : 'NO') . "</p>";
echo "<p>formatDate: " . (function_exists('formatDate') ? 'YES' : 'NO') . "</p>";
echo "<p>checkTrialStatus: " . (function_exists('checkTrialStatus') ? 'YES' : 'NO') . "</p>";

echo "<hr><p><b>DELETE THIS FILE after debug!</b></p>";
