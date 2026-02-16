<?php
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check projects table structure
    echo "<h2>Projects Table Structure:</h2>";
    $stmt = $db->query('SHOW CREATE TABLE projects');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . htmlspecialchars($result['Create Table']) . "</pre>";
    
    // Check columns info
    echo "<h2>Projects Columns:</h2>";
    $stmt = $db->query('DESCRIBE projects');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "<p><strong>{$col['Field']}</strong>: {$col['Type']} - {$col['Key']} - Default: {$col['Default']}</p>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>