<?php
/**
 * ========================================
 * MIGRATION SCRIPT FOR HOSTING DATABASE
 * ========================================
 * 
 * Script ini untuk update database hosting dengan semua schema changes terbaru.
 * Akan update MASTER database dan BUSINESS databases.
 * 
 * CARA PAKAI:
 * 1. Upload file ini ke hosting (sudah via Git)
 * 2. Akses via browser: https://adfsystem.online/migrate-hosting-database.php
 * 3. Klik tombol "JALANKAN MIGRASI"
 * 
 * PERUBAHAN YANG AKAN DILAKUKAN:
 * 
 * Master Database (adf_system):
 * - CREATE TABLE cash_accounts
 * - CREATE TABLE cash_account_transactions 
 * - CREATE TABLE shift_logs
 * - INSERT default cash accounts untuk setiap business
 * 
 * Business Databases (adfb2574_narayana_hotel, dll):
 * - ALTER TABLE cash_book ADD COLUMN cash_account_id
 * - ALTER TABLE cash_book ADD COLUMN payment_method
 * - ALTER TABLE cash_book ADD COLUMN reference_number
 */

define('APP_ACCESS', true);
require_once 'config/config.php';

// Security check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'developer', 'owner'])) {
    die("❌ Akses ditolak. Hanya admin/developer/owner yang bisa menjalankan migrasi.");
}

$execute = isset($_GET['execute']) && $_GET['execute'] === 'yes';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - Hosting</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        .content {
            padding: 40px;
        }
        .section {
            margin-bottom: 30px;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }
        .section h2 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 22px;
        }
        .section h3 {
            color: #333;
            margin: 20px 0 10px 0;
            font-size: 18px;
        }
        .section ul {
            margin-left: 20px;
            line-height: 1.8;
        }
        .section li {
            margin-bottom: 8px;
            color: #555;
        }
        .code {
            background: #2d3748;
            color: #68d391;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .warning {
            background: #fff3cd;
            border-left-color: #ffc107;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .warning h4 {
            color: #856404;
            margin-bottom: 10px;
        }
        .warning p {
            color: #856404;
            line-height: 1.6;
        }
        .success {
            background: #d4edda;
            border-left-color: #28a745;
            border: 1px solid #28a745;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border-left-color: #dc3545;
            border: 1px solid #dc3545;
            color: #721c24;
        }
        .btn {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            font-size: 18px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            transition: all 0.3s;
            margin-top: 20px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        .btn-danger {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .info-box p {
            color: #0c5460;
            margin-bottom: 8px;
        }
        .log {
            background: #2d3748;
            color: #a0aec0;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            max-height: 500px;
            overflow-y: auto;
        }
        .log p {
            margin: 5px 0;
            line-height: 1.6;
        }
        .log .success-log { color: #68d391; }
        .log .error-log { color: #fc8181; }
        .log .warning-log { color: #f6e05e; }
        .log .info-log { color: #63b3ed; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ Database Migration</h1>
            <p>Update Database Hosting ke Schema Terbaru</p>
        </div>
        
        <div class="content">
            <?php if (!$execute): ?>
            
            <!-- PREVIEW MODE -->
            <div class="section">
                <h2>📋 Preview Perubahan Database</h2>
                <p style="margin-bottom: 20px;">Berikut adalah semua perubahan yang akan dilakukan:</p>
                
                <h3>🏢 Master Database (adf_system)</h3>
                <ul>
                    <li>CREATE TABLE <span class="code">cash_accounts</span> - Master akun kas untuk semua business</li>
                    <li>CREATE TABLE <span class="code">cash_account_transactions</span> - Tracking transaksi kas</li>
                    <li>CREATE TABLE <span class="code">shift_logs</span> - Log untuk End Shift feature</li>
                    <li>INSERT default accounts (3 akun per business):
                        <ul style="margin-top: 8px;">
                            <li>💵 Kas Operasional (cash, default)</li>
                            <li>🏦 Rekening Bank (bank)</li>
                            <li>💰 Kas Modal Owner (owner_capital)</li>
                        </ul>
                    </li>
                </ul>
                
                <h3>🏪 Business Databases (per business)</h3>
                <ul>
                    <li>ALTER TABLE <span class="code">cash_book</span> ADD COLUMN <span class="code">cash_account_id</span> INT(11)</li>
                    <li>ALTER TABLE <span class="code">cash_book</span> ADD COLUMN <span class="code">payment_method</span> ENUM</li>
                    <li>ALTER TABLE <span class="code">cash_book</span> ADD COLUMN <span class="code">reference_number</span> VARCHAR(100)</li>
                </ul>
            </div>
            
            <div class="warning">
                <h4>⚠️ PENTING - Baca Sebelum Menjalankan</h4>
                <p><strong>1. BACKUP DATABASE:</strong> Pastikan Anda sudah backup database sebelum menjalankan migrasi ini.</p>
                <p><strong>2. TIDAK BISA ROLLBACK:</strong> Perubahan schema tidak bisa di-undo. Pastikan backup sudah dibuat.</p>
                <p><strong>3. AMAN DIJALANKAN ULANG:</strong> Script ini aman dijalankan berkali-kali (tidak akan duplicate data).</p>
                <p><strong>4. WAKTU EKSEKUSI:</strong> Proses akan memakan waktu 5-15 detik tergantung ukuran database.</p>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="?execute=yes" class="btn">✅ JALANKAN MIGRASI</a>
            </div>
            
            <?php else: ?>
            
            <!-- EXECUTION MODE -->
            <div class="section">
                <h2>⚙️ Menjalankan Migrasi Database...</h2>
            </div>
            
            <div class="log">
                <?php
                try {
                    // Connect to master database
                    $masterDb = new PDO(
                        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                        DB_USER,
                        DB_PASS,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    
                    echo '<p class="success-log">✅ Connected to Master Database: ' . DB_NAME . '</p>';
                    
                    // ===========================================
                    // STEP 1: CREATE CASH_ACCOUNTS TABLE
                    // ===========================================
                    echo '<p class="info-log">📦 Step 1: Creating cash_accounts table...</p>';
                    
                    $sql = "CREATE TABLE IF NOT EXISTS `cash_accounts` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `business_id` int(11) NOT NULL COMMENT 'Link to businesses table',
                        `account_name` varchar(100) NOT NULL,
                        `account_type` enum('cash','bank','owner_capital') NOT NULL DEFAULT 'cash',
                        `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
                        `is_default_account` tinyint(1) NOT NULL DEFAULT 0,
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        KEY `business_id` (`business_id`),
                        KEY `account_type` (`account_type`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                    
                    $masterDb->exec($sql);
                    echo '<p class="success-log">   ✅ Table cash_accounts created/verified</p>';
                    
                    // ===========================================
                    // STEP 2: CREATE CASH_ACCOUNT_TRANSACTIONS TABLE
                    // ===========================================
                    echo '<p class="info-log">📦 Step 2: Creating cash_account_transactions table...</p>';
                    
                    $sql = "CREATE TABLE IF NOT EXISTS `cash_account_transactions` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `cash_account_id` int(11) NOT NULL,
                        `transaction_date` date NOT NULL,
                        `description` varchar(255) NOT NULL,
                        `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                        `transaction_type` enum('income','expense','transfer','capital_injection') NOT NULL,
                        `reference_number` varchar(50) DEFAULT NULL,
                        `created_by` int(11) DEFAULT NULL,
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`id`),
                        KEY `cash_account_id` (`cash_account_id`),
                        KEY `transaction_date` (`transaction_date`),
                        KEY `transaction_type` (`transaction_type`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                    
                    $masterDb->exec($sql);
                    echo '<p class="success-log">   ✅ Table cash_account_transactions created/verified</p>';
                    
                    // ===========================================
                    // STEP 3: CREATE SHIFT_LOGS TABLE
                    // ===========================================
                    echo '<p class="info-log">📦 Step 3: Creating shift_logs table...</p>';
                    
                    $sql = "CREATE TABLE IF NOT EXISTS `shift_logs` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `user_id` int(11) NOT NULL,
                        `action` varchar(50) NOT NULL,
                        `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        KEY `user_id` (`user_id`),
                        KEY `idx_created_at` (`created_at`),
                        KEY `idx_action` (`action`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                    
                    $masterDb->exec($sql);
                    echo '<p class="success-log">   ✅ Table shift_logs created/verified</p>';
                    
                    // ===========================================
                    // STEP 4: INSERT DEFAULT CASH ACCOUNTS
                    // ===========================================
                    echo '<p class="info-log">📦 Step 4: Inserting default cash accounts...</p>';
                    
                    // Get all active businesses
                    $stmt = $masterDb->query("SELECT * FROM businesses WHERE is_active = 1 ORDER BY id");
                    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $accountsCreated = 0;
                    
                    foreach ($businesses as $biz) {
                        echo '<p class="info-log">   Processing: ' . $biz['business_name'] . '</p>';
                        
                        // Check if accounts already exist
                        $stmt = $masterDb->prepare("SELECT COUNT(*) as count FROM cash_accounts WHERE business_id = ?");
                        $stmt->execute([$biz['id']]);
                        $existingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                        
                        if ($existingCount > 0) {
                            echo '<p class="warning-log">      ⚠️  Already has ' . $existingCount . ' account(s), skipping...</p>';
                            continue;
                        }
                        
                        // Create 3 default accounts
                        $accounts = [
                            ['Kas Operasional', 'cash', 1],
                            ['Rekening Bank', 'bank', 0],
                            ['Kas Modal Owner', 'owner_capital', 0]
                        ];
                        
                        foreach ($accounts as $acc) {
                            $stmt = $masterDb->prepare("INSERT INTO cash_accounts 
                                (business_id, account_name, account_type, current_balance, is_default_account) 
                                VALUES (?, ?, ?, 0, ?)");
                            $stmt->execute([$biz['id'], $acc[0], $acc[1], $acc[2]]);
                            echo '<p class="success-log">      ✅ Created: ' . $acc[0] . '</p>';
                            $accountsCreated++;
                        }
                    }
                    
                    echo '<p class="success-log">   ✅ Total ' . $accountsCreated . ' accounts created</p>';
                    
                    // ===========================================
                    // STEP 5: UPDATE BUSINESS DATABASES
                    // ===========================================
                    echo '<p class="info-log">📦 Step 5: Updating business databases...</p>';
                    
                    // First, get all actual databases from server
                    $allDbs = $masterDb->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
                    $actualDatabases = array_filter($allDbs, function($db) {
                        return stripos($db, 'narayana') !== false || 
                               stripos($db, 'bens') !== false || 
                               stripos($db, 'cafe') !== false ||
                               stripos($db, 'hotel') !== false;
                    });
                    
                    echo '<p class="info-log">   Detected databases: ' . implode(', ', $actualDatabases) . '</p>';
                    
                    foreach ($businesses as $biz) {
                        try {
                            // Auto-detect correct database name
                            $dbName = $biz['database_name'];
                            
                            // Check if database exists as-is
                            $stmt = $masterDb->query("SHOW DATABASES LIKE '" . $dbName . "'");
                            $exists = $stmt->fetch();
                            
                            if (!$exists) {
                                // Extract keywords from business identifier
                                $identifier = $biz['business_identifier'] ?? str_replace('adf_', '', $dbName);
                                $keywords = preg_split('/[-_]/', strtolower($identifier));
                                
                                // Try to find matching database
                                $bestMatch = null;
                                $maxMatchCount = 0;
                                
                                foreach ($actualDatabases as $actualDb) {
                                    $matchCount = 0;
                                    $dbLower = strtolower($actualDb);
                                    
                                    // Count how many keywords match
                                    foreach ($keywords as $keyword) {
                                        if (!empty($keyword) && stripos($dbLower, $keyword) !== false) {
                                            $matchCount++;
                                        }
                                    }
                                    
                                    // Keep track of best match
                                    if ($matchCount > $maxMatchCount) {
                                        $maxMatchCount = $matchCount;
                                        $bestMatch = $actualDb;
                                    }
                                }
                                
                                if ($bestMatch && $maxMatchCount > 0) {
                                    $dbName = $bestMatch;
                                    echo '<p class="warning-log">      Auto-mapped: ' . $biz['database_name'] . ' → ' . $bestMatch . '</p>';
                                }
                            }
                            
                            echo '<p class="info-log">   Processing: ' . $biz['business_name'] . ' → <code>' . $dbName . '</code></p>';
                            
                            $bizDb = new PDO(
                                "mysql:host=" . DB_HOST . ";dbname={$dbName};charset=utf8mb4",
                                DB_USER,
                                DB_PASS,
                                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                            );
                            
                            // Check if cash_book table exists
                            $tables = $bizDb->query("SHOW TABLES LIKE 'cash_book'")->fetchAll();
                            if (empty($tables)) {
                                echo '<p class="warning-log">      ⚠️  Table cash_book not found, skipping...</p>';
                                continue;
                            }
                            
                            // Check and add cash_account_id column
                            $cols = $bizDb->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'")->fetchAll();
                            if (empty($cols)) {
                                $bizDb->exec("ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` INT(11) DEFAULT NULL AFTER `category_id`");
                                echo '<p class="success-log">      ✅ Added column: cash_account_id</p>';
                            } else {
                                echo '<p class="warning-log">      ⚠️  Column cash_account_id already exists</p>';
                            }
                            
                            // Check and add payment_method column
                            $cols = $bizDb->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetchAll();
                            if (empty($cols)) {
                                $bizDb->exec("ALTER TABLE `cash_book` ADD COLUMN `payment_method` ENUM('cash','card','bank_transfer','qris','other') DEFAULT 'cash' AFTER `amount`");
                                echo '<p class="success-log">      ✅ Added column: payment_method</p>';
                            } else {
                                echo '<p class="warning-log">      ⚠️  Column payment_method already exists</p>';
                            }
                            
                            // Check and add reference_number column
                            $cols = $bizDb->query("SHOW COLUMNS FROM cash_book LIKE 'reference_number'")->fetchAll();
                            if (empty($cols)) {
                                $bizDb->exec("ALTER TABLE `cash_book` ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `payment_method`");
                                echo '<p class="success-log">      ✅ Added column: reference_number</p>';
                            } else {
                                echo '<p class="warning-log">      ⚠️  Column reference_number already exists</p>';
                            }
                            
                            echo '<p class="success-log">   ✅ Business database updated successfully</p>';
                            
                        } catch (Exception $e) {
                            echo '<p class="error-log">   ❌ Error: ' . $e->getMessage() . '</p>';
                        }
                    }
                    
                    echo '<p style="margin-top: 20px;"></p>';
                    echo '<p class="success-log">========================================</p>';
                    echo '<p class="success-log">🎉 MIGRASI DATABASE SELESAI!</p>';
                    echo '<p class="success-log">========================================</p>';
                    echo '<p class="info-log">Semua perubahan telah berhasil diterapkan.</p>';
                    echo '<p class="info-log">Silakan test fitur-fitur yang menggunakan cash accounts.</p>';
                    
                } catch (Exception $e) {
                    echo '<p class="error-log">❌ FATAL ERROR: ' . $e->getMessage() . '</p>';
                    echo '<p class="error-log">Stack trace:</p>';
                    echo '<p class="error-log" style="font-size: 12px;">' . nl2br($e->getTraceAsString()) . '</p>';
                }
                ?>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="?" class="btn">🔄 Kembali ke Preview</a>
                <a href="debug-cash-accounts.php" class="btn" style="background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);">🔍 Cek Hasil Migrasi</a>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
