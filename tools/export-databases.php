<?php
/**
 * DATABASE EXPORT TOOL
 * Export databases untuk di-import ke hosting
 * Auto-fix nama database ke format hosting (adfb2574_prefix)
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance();

// List all local databases
$stmt = $db->getConnection()->query("SHOW DATABASES");
$databases = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Filter hanya yang ada "adf_" atau "narayana_"
$localDatabases = array_filter($databases, function($name) {
    return strpos($name, 'adf_') === 0 || strpos($name, 'narayana_') === 0;
});

// Handle export request
if (isset($_GET['export'])) {
    $dbName = $_GET['export'];
    
    // Validate database exists
    if (!in_array($dbName, $localDatabases)) {
        die('Database tidak ditemukan!');
    }
    
    // Generate hosting-compatible name
    $hostingName = 'adfb2574_narayana_' . str_replace(['adf_', 'narayana_'], '', $dbName);
    
    // Export SQL
    $tables = $db->getConnection()->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbName'")->fetchAll(PDO::FETCH_COLUMN);
    
    $sql = "-- Database: $hostingName\n";
    $sql .= "-- Exported from: $dbName (Local)\n";
    $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- IMPORTANT: Database harus sudah ada di hosting!\n";
    $sql .= "-- Jangan jalankan di database yang berbeda!\n\n";
    
    // DO NOT CREATE DATABASE - user di hosting tidak punya permission!
    // $sql .= "DROP DATABASE IF EXISTS `$hostingName`;\n";
    // $sql .= "CREATE DATABASE `$hostingName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    $sql .= "USE `$hostingName`;\n\n";
    
    // Export each table structure + data
    foreach ($tables as $table) {
        // Get CREATE TABLE statement
        $createTable = $db->getConnection()->query("SHOW CREATE TABLE `$dbName`.`$table`")->fetch(PDO::FETCH_ASSOC);
        $sql .= $createTable['Create Table'] . ";\n\n";
        
        // Get data
        $data = $db->getConnection()->query("SELECT * FROM `$dbName`.`$table`")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($data)) {
            foreach ($data as $row) {
                $values = array_map(function($val) {
                    return $val === null ? 'NULL' : "'" . str_replace("'", "''", $val) . "'";
                }, $row);
                
                $cols = implode('`, `', array_keys($row));
                $sql .= "INSERT INTO `$table` (`$cols`) VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
    }
    
    // Send as download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $hostingName . '_' . date('Y-m-d_His') . '.sql"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Export Tool</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        h1 {
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 2rem;
            font-size: 14px;
        }
        
        .info-box {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 1rem;
            margin-bottom: 2rem;
            border-radius: 8px;
        }
        
        .info-box strong {
            color: #667eea;
        }
        
        .database-list {
            display: grid;
            gap: 1rem;
        }
        
        .db-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .db-item:hover {
            border-color: #667eea;
            background: #f9fafb;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }
        
        .db-info {
            flex: 1;
        }
        
        .db-name {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        
        .db-note {
            font-size: 12px;
            color: #999;
            margin-top: 0.5rem;
        }
        
        .db-hosting {
            font-size: 12px;
            color: #667eea;
            font-weight: 500;
        }
        
        .btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .table-count {
            background: #f0f4ff;
            color: #667eea;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .status.ok {
            background: #4caf50;
        }
        
        .status.empty {
            background: #ff9800;
        }
        
        .footer {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            color: #999;
            font-size: 13px;
        }
        
        .step-guide {
            background: #fafafa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .step-guide h3 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 16px;
        }
        
        .step-guide ol {
            margin-left: 1.5rem;
            color: #666;
            line-height: 1.8;
        }
        
        .step-guide li {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Database Export Tool</h1>
        <p class="subtitle">Export databases lokal untuk di-import ke hosting</p>
        
        <div class="step-guide">
            <h3>📋 Cara Pakai:</h3>
            <ol>
                <li>Pilih database lokal yang ingin di-export</li>
                <li>Klik tombol "Export" → File SQL akan di-download</li>
                <li>Di Hosting PhpMyAdmin: Tab "Import" → Pilih file SQL → "Go"</li>
                <li>Selesai! Database di hosting akan punya struktur + data</li>
            </ol>
        </div>
        
        <div class="info-box">
            <strong>ℹ️ Catatan:</strong> Nama database akan otomatis diubah ke format hosting (adfb2574_*)
            <br>Contoh: <code>adf_benscafe</code> → <code>adfb2574_narayana_benscafe</code>
        </div>
        
        <div class="database-list">
            <?php foreach ($localDatabases as $dbName): ?>
                <?php
                // Count tables
                $tableCount = count($db->getConnection()->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbName'")->fetchAll(PDO::FETCH_COLUMN));
                
                // Generate hosting name
                $hostingName = 'adfb2574_' . str_replace('adf_', '', str_replace('narayana_', '', $dbName));
                
                // Check if has data
                $hasData = $tableCount > 0;
                $status = $hasData ? 'ok' : 'empty';
                $statusText = $hasData ? "$tableCount tables" : "No tables";
                ?>
                
                <div class="db-item">
                    <div class="db-info">
                        <div class="db-name">
                            <span class="status <?php echo $status; ?>"></span>
                            <?php echo htmlspecialchars($dbName); ?>
                        </div>
                        <div class="db-note">
                            <?php echo $statusText; ?> 
                            <span style="margin-left: 1rem;">→</span>
                            <span class="db-hosting" style="margin-left: 0.5rem;"><?php echo htmlspecialchars($hostingName); ?></span>
                        </div>
                    </div>
                    <div>
                        <span class="table-count"><?php echo $tableCount; ?> tabel</span>
                        <a href="?export=<?php echo urlencode($dbName); ?>" class="btn" style="margin-left: 1rem;">
                            ⬇️ Export
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="footer">
            <p>💡 Tip: Setelah export-import pertama kali berhasil, untuk update selanjutnya tinggal pakai fitur "Export" di PhpMyAdmin</p>
        </div>
    </div>
</body>
</html>
