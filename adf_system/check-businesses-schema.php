<?php
$pdo = new PDO('mysql:host=localhost;dbname=adf_system', 'root', '');
$stmt = $pdo->query('DESCRIBE businesses');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
