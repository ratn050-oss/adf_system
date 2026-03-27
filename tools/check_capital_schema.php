<?php
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'adf_narayana_hotel';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    
    echo "TABLE: investor_capital_transactions\n";
    $stmt = $pdo->query("DESCRIBE investor_capital_transactions");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
