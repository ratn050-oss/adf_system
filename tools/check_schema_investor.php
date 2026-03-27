<?php
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'adf_narayana_hotel';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    
    echo "TABLE: investors\n";
    $stmt = $pdo->query("DESCRIBE investors");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
    
    echo "\nTABLE: projects\n";
    $stmt = $pdo->query("DESCRIBE projects");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
    
    echo "\nTABLE: project_expenses\n";
    $stmt = $pdo->query("DESCRIBE project_expenses");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
    
    echo "\nTABLE: adf_system.users\n";
    // Check if capital_inflow table exists (often used for dana masuk per project)
    $stmt = $pdo->query("SHOW TABLES LIKE 'project_capital'");
    if ($stmt->rowCount() > 0) {
        echo "\nTABLE: project_capital\n";
        $stmt = $pdo->query("DESCRIBE project_capital");
        print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
