<?php
/**
 * Setup Per-Business Databases
 * Membuat database terpisah untuk setiap bisnis dan copy struktur dari narayana_db
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/business_helper.php';

header('Content-Type: text/html; charset=UTF-8');

// Database credentials
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;

// Businesses and their databases
$businesses = getAvailableBusinesses();
$businessDatabases = [
    'narayana-hotel' => 'adf_narayana',
    'bens-cafe' => 'adf_benscafe',
    'eat-meet' => 'adf_eat_meet',
    'furniture-jepara' => 'adf_furniture',
    'karimunjawa-party-boat' => 'adf_karimunjawa',
    'pabrik-kapal' => 'adf_pabrik'
];

$sourceDb = 'narayana_db'; // Source database to copy from
$results = [];
$errors = [];

// Connect to MySQL
try {
    $pdo = new PDO(
        "mysql:host={$dbHost}",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "<h1>🔧 Setup Per-Business Databases</h1>";
    echo "<p style='color: #666;'>Membuat database terpisah untuk setiap bisnis...</p>";
    echo "<div style='background: #f5f5f5; padding: 1rem; border-radius: 8px; margin: 1rem 0;'>";
    
    foreach ($businessDatabases as $businessId => $targetDb) {
        if (!isset($businesses[$businessId])) {
            $errors[] = "Business {$businessId} tidak ditemukan";
            continue;
        }
        
        echo "<h3>{$businesses[$businessId]['theme']['icon']} {$businesses[$businessId]['name']}</h3>";
        
        try {
            // 1. Drop existing database if exists
            echo "  📍 Cek database yang ada...<br>";
            try {
                $pdo->exec("DROP DATABASE IF EXISTS {$targetDb}");
                echo "    ✓ Database lama dihapus<br>";
            } catch (Exception $e) {
                echo "    ℹ Database baru (belum ada)<br>";
            }
            
            // 2. Create new database
            echo "  📍 Membuat database baru...<br>";
            $pdo->exec("CREATE DATABASE {$targetDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "    ✓ Database '{$targetDb}' berhasil dibuat<br>";
            
            // 3. Get SQL dump from source database
            echo "  📍 Menyalin struktur dari {$sourceDb}...<br>";
            
            // Get all table names from source
            $stmt = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?");
            $stmt->execute([$sourceDb]);
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Copy each table structure and data
            foreach ($tables as $table) {
                try {
                    // Create table in new database
                    // Use fully qualified table name to avoid database selection issues
                    $createStmt = $pdo->query("SHOW CREATE TABLE `{$sourceDb}`.`{$table}`");
                    $createResult = $createStmt->fetch(PDO::FETCH_ASSOC);
                    $createSql = $createResult['Create Table'];
                    
                    // Replace table name to include new database
                    $createSql = preg_replace(
                        '/CREATE TABLE `?[^`]*`?\.`?([^`]*)`?/',
                        'CREATE TABLE IF NOT EXISTS `' . $targetDb . '`.`$1`',
                        $createSql
                    );
                    
                    // Remove AUTO_INCREMENT to avoid conflicts
                    $createSql = preg_replace('/AUTO_INCREMENT=\d+/', '', $createSql);
                    
                    $pdo->exec($createSql);
                    
                    // Copy data from source - use fully qualified names
                    $pdo->exec("INSERT INTO `{$targetDb}`.`{$table}` SELECT * FROM `{$sourceDb}`.`{$table}`");
                    
                    echo "    ✓ Tabel '{$table}' berhasil dikopi<br>";
                } catch (Exception $e) {
                    echo "    ✗ Error tabel '{$table}': " . $e->getMessage() . "<br>";
                }
            }
            
            // 4. Update settings untuk bisnis ini (hapus dari global jika ada)
            try {
                $deleteStmt = $pdo->prepare("DELETE FROM `{$targetDb}`.`settings` WHERE `setting_key` LIKE 'company_logo_%' AND `setting_key` != ?");
                $deleteStmt->execute(['company_logo_' . $businessId]);
            } catch (Exception $e) {
                // Settings table cleanup optional if table doesn't exist
                echo "    ℹ Cleanup settings opsional<br>";
            }
            
            echo "  ✅ {$businesses[$businessId]['name']} setup berhasil!<br><br>";
            $results[] = "{$businesses[$businessId]['name']} ✓";
            
        } catch (Exception $e) {
            echo "  ❌ Error: " . $e->getMessage() . "<br><br>";
            $errors[] = "{$businesses[$businessId]['name']}: " . $e->getMessage();
        }
    }
    
    echo "</div>";
    
    // Summary
    echo "<h2>📊 Summary</h2>";
    echo "<div style='background: #f0f9ff; padding: 1rem; border-radius: 8px; border-left: 4px solid #3b82f6;'>";
    echo "<p><strong>✅ Berhasil dibuat:</strong></p>";
    echo "<ul>";
    foreach ($results as $result) {
        echo "<li>{$result}</li>";
    }
    echo "</ul>";
    
    if (!empty($errors)) {
        echo "<p style='color: #dc2626; margin-top: 1rem;'><strong>❌ Error:</strong></p>";
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li style='color: #dc2626;'>{$error}</li>";
        }
        echo "</ul>";
    }
    echo "</div>";
    
    echo "<hr style='margin: 2rem 0;'>";
    echo "<h2>ℹ️ Informasi</h2>";
    echo "<div style='background: #fef3c7; padding: 1rem; border-radius: 8px; border-left: 4px solid #f59e0b;'>";
    echo "<p><strong>Database yang telah dibuat:</strong></p>";
    echo "<ul>";
    foreach ($businessDatabases as $bid => $db) {
        if (isset($businesses[$bid])) {
            echo "<li><strong>{$businesses[$bid]['name']}</strong>: <code>{$db}</code></li>";
        }
    }
    echo "</ul>";
    echo "<p style='margin-top: 1rem; color: #666;'><strong>Catatan:</strong> Setiap bisnis sekarang memiliki database independen. Data yang diinput di satu bisnis tidak akan mempengaruhi bisnis lain.</p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='background: #fee2e2; padding: 1rem; border-radius: 8px; border-left: 4px solid #dc2626;'>";
    echo "<h2>❌ Connection Error</h2>";
    echo "<p>{$e->getMessage()}</p>";
    echo "</div>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Setup Per-Business Databases</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
            color: #1e293b;
        }
        code {
            background: #f1f5f9;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        a {
            color: #3b82f6;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <p style='margin-top: 2rem;'>
        <a href='javascript:history.back()'>← Kembali</a>
    </p>
</body>
</html>
