<?php
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

echo "<h2>Check Table Structure</h2>";

try {
    // Check master database
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=adf_system;charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "<h3>cash_account_transactions Table Structure:</h3>";
    $result = $masterDb->query("SHOW COLUMNS FROM cash_account_transactions");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td><strong>{$row['Field']}</strong></td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
