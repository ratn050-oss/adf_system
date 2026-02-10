<?php
$pdo = new PDO('mysql:host=localhost;dbname=adf_system', 'root', '');
echo "=== user_menu_permissions ===\n";
$stmt = $pdo->query('DESCRIBE user_menu_permissions');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT) . "\n\n";
echo json_encode($pdo->query('SELECT * FROM user_menu_permissions LIMIT 1')->fetch(PDO::FETCH_ASSOC));
