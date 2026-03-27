<?php
/**
 * Create user_permissions table in all business databases
 */

$databases = [
    'adf_narayana',
    'adf_benscafe',
    'adf_narayana',
    'adf_eat_meet',
    'adf_pabrik',
    'adf_furniture',
    'adf_karimunjawa'
];

$createTableSQL = "
CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_key VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_permission (user_id, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

echo "Creating user_permissions tables...\n";
echo str_repeat("=", 50) . "\n";

foreach ($databases as $dbName) {
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=$dbName;charset=utf8mb4",
            "root",
            "",
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $pdo->exec($createTableSQL);
        echo "✅ $dbName: Table created/verified\n";
        
    } catch (PDOException $e) {
        echo "❌ $dbName: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ Done!\n";
