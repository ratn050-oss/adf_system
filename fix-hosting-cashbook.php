<?php
/**
 * FIX HOSTING: Add missing columns to cash_book table
 * Run this ONCE on hosting to fix schema mismatches
 * 
 * URL: https://yourdomain.com/adf_system/fix-hosting-cashbook.php
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

echo "<h2>🔧 Fix Hosting Cash Book Schema</h2>";
echo "<pre>";

$db = Database::getInstance();
$pdo = $db->getConnection();

$fixes = [];

// ==========================================
// 1. Add cash_account_id column if missing
// ==========================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cash_book ADD COLUMN cash_account_id INT(11) DEFAULT NULL AFTER payment_method");
        $fixes[] = "✅ Added 'cash_account_id' column to cash_book";
    } else {
        $fixes[] = "⏭️ 'cash_account_id' already exists";
    }
} catch (Exception $e) {
    $fixes[] = "❌ Error adding cash_account_id: " . $e->getMessage();
}

// ==========================================
// 2. Expand payment_method ENUM to include all types
// ==========================================
try {
    $pdo->exec("ALTER TABLE cash_book MODIFY COLUMN payment_method ENUM('cash','debit','transfer','qr','bank_transfer','ota','agoda','booking','other') DEFAULT 'cash'");
    $fixes[] = "✅ Expanded 'payment_method' ENUM (added: bank_transfer, ota, agoda, booking)";
} catch (Exception $e) {
    $fixes[] = "❌ Error expanding payment_method: " . $e->getMessage();
}

// ==========================================
// 3. Check bookings table for auto-checkout columns
// ==========================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'actual_checkout_time'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN actual_checkout_time DATETIME DEFAULT NULL");
        $fixes[] = "✅ Added 'actual_checkout_time' to bookings";
    } else {
        $fixes[] = "⏭️ 'actual_checkout_time' already exists in bookings";
    }
} catch (Exception $e) {
    $fixes[] = "❌ Error adding actual_checkout_time: " . $e->getMessage();
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'checked_out_by'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN checked_out_by INT(11) DEFAULT NULL");
        $fixes[] = "✅ Added 'checked_out_by' to bookings";
    } else {
        $fixes[] = "⏭️ 'checked_out_by' already exists in bookings";
    }
} catch (Exception $e) {
    $fixes[] = "❌ Error adding checked_out_by: " . $e->getMessage();
}

// ==========================================
// 4. Check activity_logs table exists
// ==========================================
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
    if (empty($tables)) {
        $pdo->exec("CREATE TABLE activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $fixes[] = "✅ Created 'activity_logs' table";
    } else {
        $fixes[] = "⏭️ 'activity_logs' table already exists";
    }
} catch (Exception $e) {
    $fixes[] = "❌ Error with activity_logs: " . $e->getMessage();
}

// ==========================================
// 5. Verify created_by FK - check if business users table has the user
// ==========================================
try {
    $userCount = $pdo->query("SELECT COUNT(*) as cnt FROM users")->fetch(PDO::FETCH_ASSOC);
    $fixes[] = "ℹ️ Business DB has {$userCount['cnt']} users";
    
    if ((int)$userCount['cnt'] === 0) {
        $fixes[] = "⚠️ WARNING: No users in business DB! Cashbook INSERT will fail on created_by FK";
        $fixes[] = "   → Run: INSERT INTO users (id, username, password, full_name, role_id) SELECT id, username, password, full_name, role_id FROM [master_db].users";
    }
} catch (Exception $e) {
    $fixes[] = "❌ Error checking users: " . $e->getMessage();
}

// ==========================================
// 6. Show final cash_book schema
// ==========================================
echo "\n📋 Results:\n";
foreach ($fixes as $fix) {
    echo $fix . "\n";
}

echo "\n\n📊 Current cash_book schema:\n";
try {
    $schema = $pdo->query("SHOW COLUMNS FROM cash_book")->fetchAll(PDO::FETCH_ASSOC);
    echo str_pad("Column", 25) . str_pad("Type", 60) . str_pad("Null", 6) . str_pad("Default", 20) . "\n";
    echo str_repeat("-", 111) . "\n";
    foreach ($schema as $col) {
        echo str_pad($col['Field'], 25) . str_pad($col['Type'], 60) . str_pad($col['Null'], 6) . str_pad($col['Default'] ?? 'NULL', 20) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "\n\n📊 Current bookings schema (key columns):\n";
try {
    $schema = $pdo->query("SHOW COLUMNS FROM bookings WHERE Field IN ('actual_checkout_time', 'checked_out_by', 'status', 'check_out_date')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($schema as $col) {
        echo str_pad($col['Field'], 25) . str_pad($col['Type'], 40) . str_pad($col['Default'] ?? 'NULL', 20) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "\n</pre>";
echo "<p><strong>Selesai!</strong> Sekarang buka Dashboard Frontdesk dan coba lagi.</p>";
