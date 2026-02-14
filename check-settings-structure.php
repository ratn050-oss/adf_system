<?php
require_once 'config/database.php';

try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=adf_system;charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "<h2>Settings Table Structure:</h2>";
    $columns = $db->query("DESCRIBE settings")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
    
    echo "<hr>";
    echo "<h2>Current Settings:</h2>";
    $settings = $db->query("SELECT * FROM settings WHERE setting_key LIKE 'ota_fee_%'")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($settings);
    echo "</pre>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
