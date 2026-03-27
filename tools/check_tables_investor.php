<?php
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'adf_narayana_hotel';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    
    $tables = ['investor_transactions', 'capital_inflow', 'project_funding', 'project_investments'];
    
    foreach($tables as $table) {
        try {
            echo "Checking TABLE: $table\n";
            $stmt = $pdo->query("DESCRIBE $table");
            print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
            echo "FOUND!\n";
        } catch (Exception $e) {
            echo "Not found.\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
