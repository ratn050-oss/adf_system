<?php
/**
 * Fix Business Setup - Ensure business exists in master DB with cash_accounts
 * Usage: fix-business-setup.php?biz=demo
 * 
 * This ensures:
 * 1. Business record exists in businesses table
 * 2. Cash accounts (Petty Cash, Bank, Kas Modal Owner) exist
 * 3. Business database has all required tables + seed data
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

$bizCode = strtoupper($_GET['biz'] ?? 'DEMO');
$run = isset($_GET['run']);

// Business presets
$businessPresets = [
    'DEMO' => [
        'name' => 'Demo Business',
        'type' => 'general',
        'db_local' => 'adf_demo',
        'business_id_slug' => 'demo'
    ],
    'NARAYANAHOTEL' => [
        'name' => 'Narayana Hotel',
        'type' => 'hotel',
        'db_local' => 'adf_narayana_hotel',
        'business_id_slug' => 'narayana-hotel'
    ],
    'BENSCAFE' => [
        'name' => 'Bens Cafe',
        'type' => 'restaurant',
        'db_local' => 'adf_benscafe',
        'business_id_slug' => 'bens-cafe'
    ]
];

$preset = $businessPresets[$bizCode] ?? null;
if (!$preset) {
    die("Unknown business code: $bizCode. Available: " . implode(', ', array_keys($businessPresets)));
}

$results = [];

try {
    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check businesses table
    $stmt = $masterPdo->prepare("SELECT id, business_name FROM businesses WHERE business_code = ?");
    $stmt->execute([$bizCode]);
    $existingBiz = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingBiz) {
        $businessId = $existingBiz['id'];
        $results[] = "✅ Business '{$existingBiz['business_name']}' exists (ID: {$businessId})";
    } else {
        $results[] = "❌ Business '{$bizCode}' NOT found in businesses table";
    }
    
    // Check cash_accounts
    if ($existingBiz) {
        $stmt = $masterPdo->prepare("SELECT id, account_name, account_type, current_balance FROM cash_accounts WHERE business_id = ?");
        $stmt->execute([$businessId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($accounts)) {
            $results[] = "❌ No cash_accounts for business ID {$businessId}";
        } else {
            foreach ($accounts as $acc) {
                $results[] = "✅ Account: {$acc['account_name']} ({$acc['account_type']}) = Rp " . number_format($acc['current_balance']);
            }
        }
    }
    
    // Check business database
    $bizDbName = getDbName($preset['db_local']);
    try {
        $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
        $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $bizPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $results[] = "✅ Database '{$bizDbName}' exists with " . count($tables) . " tables";
        
        // Check critical tables
        $criticalTables = ['divisions', 'categories', 'cash_book', 'settings', 'users', 'roles'];
        foreach ($criticalTables as $t) {
            if (in_array($t, $tables)) {
                $count = $bizPdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                $status = $count > 0 ? "✅" : "⚠️ EMPTY";
                $results[] = "  $status $t: $count rows";
            } else {
                $results[] = "  ❌ $t: TABLE MISSING";
            }
        }
    } catch (Exception $e) {
        $results[] = "❌ Database '{$bizDbName}' not accessible: " . $e->getMessage();
    }
    
} catch (Exception $e) {
    $results[] = "❌ Master DB error: " . $e->getMessage();
}

// RUN MODE - Fix everything
if ($run) {
    $results[] = "\n--- RUNNING FIXES ---";
    
    try {
        $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $bizDbName = getDbName($preset['db_local']);
        
        // 1. Ensure business exists
        $stmt = $masterPdo->prepare("SELECT id FROM businesses WHERE business_code = ?");
        $stmt->execute([$bizCode]);
        $biz = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$biz) {
            $masterPdo->prepare("INSERT INTO businesses (business_code, business_name, business_type, database_name, owner_id, is_active) VALUES (?, ?, ?, ?, 1, 1)")
                ->execute([$bizCode, $preset['name'], $preset['type'], $bizDbName]);
            $businessId = $masterPdo->lastInsertId();
            $results[] = "✅ Created business record (ID: {$businessId})";
        } else {
            $businessId = $biz['id'];
            $results[] = "✅ Business exists (ID: {$businessId})";
        }
        
        // 2. Ensure cash_accounts exist
        $requiredAccounts = [
            ['Petty Cash', 'cash', 1, 'Uang cash dari tamu / operasional'],
            ['Bank', 'bank', 0, 'Rekening bank utama bisnis'],
            ['Kas Modal Owner', 'owner_capital', 0, 'Modal operasional dari owner']
        ];
        
        foreach ($requiredAccounts as $acc) {
            $check = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = ?");
            $check->execute([$businessId, $acc[1]]);
            if (!$check->fetch()) {
                $masterPdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, ?, ?, 0, ?, ?, 1)")
                    ->execute([$businessId, $acc[0], $acc[1], $acc[2], $acc[3]]);
                $results[] = "✅ Created cash account: {$acc[0]} ({$acc[1]})";
            } else {
                $results[] = "✅ Cash account exists: {$acc[0]}";
            }
        }
        
        // 3. Seed business database
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $bizId = $preset['business_id_slug'];
            
            // Create tables (IF NOT EXISTS = safe to re-run)
            $tableSQL = [
                "CREATE TABLE IF NOT EXISTS divisions (id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_code VARCHAR(20), division_name VARCHAR(100) NOT NULL, description TEXT, division_type ENUM('income','expense','both') DEFAULT 'both', is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_id INT, category_name VARCHAR(100) NOT NULL, category_type ENUM('income','expense') DEFAULT 'income', description TEXT, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT, setting_type VARCHAR(20) DEFAULT 'string', description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS cash_book (id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), transaction_date DATE NOT NULL, transaction_time TIME, division_id INT, category_id INT, category_name VARCHAR(100), description TEXT, transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0, payment_method VARCHAR(30) DEFAULT 'cash', cash_account_id INT, notes TEXT, attachment VARCHAR(255), created_by INT, shift VARCHAR(20), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, full_name VARCHAR(100), email VARCHAR(100), phone VARCHAR(20), role ENUM('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff', role_id INT, business_access TEXT, is_active TINYINT(1) DEFAULT 1, last_login DATETIME, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS roles (id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL, role_code VARCHAR(30), description TEXT, is_system_role TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS user_preferences (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, branch_id VARCHAR(50), theme VARCHAR(20) DEFAULT 'dark', language VARCHAR(5) DEFAULT 'id', sidebar_collapsed TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS user_permissions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, permission VARCHAR(50) NOT NULL, can_view TINYINT(1) DEFAULT 1, can_create TINYINT(1) DEFAULT 0, can_edit TINYINT(1) DEFAULT 0, can_delete TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS audit_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action_type VARCHAR(50), table_name VARCHAR(50), record_id INT, old_values TEXT, new_values TEXT, ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS suppliers (id INT AUTO_INCREMENT PRIMARY KEY, supplier_name VARCHAR(100) NOT NULL, contact_person VARCHAR(100), phone VARCHAR(20), email VARCHAR(100), address TEXT, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS purchase_orders_header (id INT AUTO_INCREMENT PRIMARY KEY, po_number VARCHAR(30) UNIQUE, supplier_id INT, po_date DATE NOT NULL, delivery_date DATE, status ENUM('draft','sent','received','cancelled') DEFAULT 'draft', total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS purchase_orders_detail (id INT AUTO_INCREMENT PRIMARY KEY, po_header_id INT, item_name VARCHAR(200), quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0, total_price DECIMAL(15,2) DEFAULT 0, notes TEXT)",
                "CREATE TABLE IF NOT EXISTS sales_invoices_header (id INT AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(30) UNIQUE, customer_name VARCHAR(100), invoice_date DATE NOT NULL, due_date DATE, status ENUM('draft','sent','paid','cancelled') DEFAULT 'draft', total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS sales_invoices_detail (id INT AUTO_INCREMENT PRIMARY KEY, invoice_header_id INT, item_name VARCHAR(200), quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0, total_price DECIMAL(15,2) DEFAULT 0, notes TEXT)",
                "CREATE TABLE IF NOT EXISTS bill_templates (id INT AUTO_INCREMENT PRIMARY KEY, template_name VARCHAR(100), description TEXT, amount DECIMAL(15,2) DEFAULT 0, frequency ENUM('monthly','weekly','yearly','once') DEFAULT 'monthly', is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS bill_records (id INT AUTO_INCREMENT PRIMARY KEY, template_id INT, bill_date DATE, amount DECIMAL(15,2), status ENUM('pending','paid','overdue') DEFAULT 'pending', paid_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS transaction_attachments (id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT, transaction_type VARCHAR(20), file_name VARCHAR(255), file_path VARCHAR(500), file_type VARCHAR(50), file_size INT, uploaded_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS rooms (id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(10) NOT NULL, room_type_id INT, floor INT DEFAULT 1, status ENUM('available','occupied','maintenance','blocked') DEFAULT 'available', price_per_night DECIMAL(15,2) DEFAULT 0, description TEXT, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS room_types (id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(50) NOT NULL, base_price DECIMAL(15,2) DEFAULT 0, max_occupancy INT DEFAULT 2, description TEXT, amenities TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS bookings (id INT AUTO_INCREMENT PRIMARY KEY, booking_code VARCHAR(20) UNIQUE, guest_name VARCHAR(100) NOT NULL, guest_phone VARCHAR(20), guest_email VARCHAR(100), room_id INT, room_type_id INT, check_in DATE NOT NULL, check_out DATE NOT NULL, nights INT DEFAULT 1, adults INT DEFAULT 1, children INT DEFAULT 0, rate_per_night DECIMAL(15,2) DEFAULT 0, total_amount DECIMAL(15,2) DEFAULT 0, paid_amount DECIMAL(15,2) DEFAULT 0, status ENUM('confirmed','checked_in','checked_out','cancelled','no_show') DEFAULT 'confirmed', source VARCHAR(50) DEFAULT 'walk-in', notes TEXT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS booking_payments (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, amount DECIMAL(15,2) NOT NULL, payment_method VARCHAR(30) DEFAULT 'cash', payment_date DATETIME, notes TEXT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS frontdesk_rooms (id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(10), room_type VARCHAR(50), floor INT DEFAULT 1, price DECIMAL(15,2) DEFAULT 0, status VARCHAR(20) DEFAULT 'available', guest_name VARCHAR(100), check_in DATE, check_out DATE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS guests (id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(100) NOT NULL, phone VARCHAR(20), email VARCHAR(100), id_type VARCHAR(30), id_number VARCHAR(50), address TEXT, nationality VARCHAR(50), notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS breakfast_menus (id INT AUTO_INCREMENT PRIMARY KEY, menu_name VARCHAR(100), description TEXT, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS breakfast_orders (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, guest_name VARCHAR(100), room_number VARCHAR(10), order_date DATE, menu_id INT, quantity INT DEFAULT 1, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS breakfast_log (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, room_number VARCHAR(10), guest_name VARCHAR(100), breakfast_date DATE, pax INT DEFAULT 1, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS investors (id INT AUTO_INCREMENT PRIMARY KEY, investor_name VARCHAR(100) NOT NULL, phone VARCHAR(20), email VARCHAR(100), address TEXT, notes TEXT, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS investor_transactions (id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, transaction_type ENUM('investment','return','dividend'), amount DECIMAL(15,2), transaction_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS investor_balances (id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, balance DECIMAL(15,2) DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS investor_bills (id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, bill_name VARCHAR(100), amount DECIMAL(15,2), due_date DATE, status ENUM('pending','paid','overdue') DEFAULT 'pending', paid_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            ];
            
            $created = 0;
            foreach ($tableSQL as $sql) {
                try { $bizPdo->exec($sql); $created++; } catch (Exception $e) { $results[] = "⚠️ Table error: " . $e->getMessage(); }
            }
            $results[] = "✅ Created/verified {$created} tables in business DB";
            
            // Seed data (IGNORE = skip if exists)
            $bizPdo->exec("INSERT IGNORE INTO roles (id, role_name, role_code, description, is_system_role) VALUES
                (1, 'Admin', 'admin', 'System administrator', 1),
                (2, 'Manager', 'manager', 'Business manager', 1),
                (3, 'Staff', 'staff', 'Regular staff', 1),
                (4, 'Developer', 'developer', 'System developer', 1),
                (5, 'Owner', 'owner', 'Business owner', 1)");
            $results[] = "✅ Roles seeded";
            
            // Check if divisions have data
            $divCount = $bizPdo->query("SELECT COUNT(*) FROM divisions")->fetchColumn();
            if ($divCount == 0) {
                $bizPdo->exec("INSERT INTO divisions (branch_id, division_code, division_name, division_type) VALUES
                    ('{$bizId}', 'KITCHEN', 'Kitchen', 'both'),
                    ('{$bizId}', 'BAR', 'Bar', 'both'),
                    ('{$bizId}', 'RESTO', 'Resto', 'both'),
                    ('{$bizId}', 'HOUSEKEEPING', 'Housekeeping', 'expense'),
                    ('{$bizId}', 'HOTEL', 'Hotel', 'income'),
                    ('{$bizId}', 'GARDENER', 'Gardener', 'expense'),
                    ('{$bizId}', 'OTHERS', 'Lain-lain', 'both'),
                    ('{$bizId}', 'PC', 'Petty Cash', 'both')");
                $results[] = "✅ Divisions seeded (8 divisions)";
            } else {
                $results[] = "✅ Divisions exist ({$divCount} rows)";
            }
            
            // Check if categories have data
            $catCount = $bizPdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
            if ($catCount == 0) {
                $bizPdo->exec("INSERT INTO categories (branch_id, division_id, category_name, category_type, description) VALUES
                    ('{$bizId}', 1, 'Food Sales', 'income', 'Revenue from food sales'),
                    ('{$bizId}', 1, 'Food Supplies', 'expense', 'Purchase of food ingredients'),
                    ('{$bizId}', 2, 'Beverage Sales', 'income', 'Revenue from beverages'),
                    ('{$bizId}', 2, 'Beverage Inventory', 'expense', 'Purchase of beverages'),
                    ('{$bizId}', 3, 'Room Rental', 'income', 'Room rental income'),
                    ('{$bizId}', 3, 'Staff Salary', 'expense', 'Employee salaries'),
                    ('{$bizId}', 4, 'Housekeeping Service', 'income', 'Cleaning services'),
                    ('{$bizId}', 4, 'Room Supplies', 'expense', 'Room cleaning supplies'),
                    ('{$bizId}', 5, 'Hotel Income', 'income', 'Hotel revenue'),
                    ('{$bizId}', 5, 'Hotel Expense', 'expense', 'Hotel operational expenses'),
                    ('{$bizId}', 7, 'Other Income', 'income', 'Miscellaneous income'),
                    ('{$bizId}', 7, 'Other Expense', 'expense', 'Miscellaneous expenses')");
                $results[] = "✅ Categories seeded (12 categories)";
            } else {
                $results[] = "✅ Categories exist ({$catCount} rows)";
            }
            
            // Seed settings
            $settingsCount = $bizPdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
            if ($settingsCount == 0) {
                $bizPdo->exec("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
                    ('business_name', '{$preset['name']}', 'string', 'Business name'),
                    ('business_type', '{$preset['type']}', 'string', 'Business type'),
                    ('currency', 'IDR', 'string', 'Currency'),
                    ('timezone', 'Asia/Jakarta', 'string', 'Timezone'),
                    ('date_format', 'd/m/Y', 'string', 'Date format'),
                    ('fiscal_year_start', '01', 'string', 'Fiscal year start month'),
                    ('show_running_balance', '1', 'boolean', 'Show running balance'),
                    ('show_daily_total', '1', 'boolean', 'Show daily total'),
                    ('enable_shift', '1', 'boolean', 'Enable shift'),
                    ('enable_approval', '0', 'boolean', 'Require approval'),
                    ('min_kas_awal', '0', 'string', 'Minimum kas awal'),
                    ('kas_awal_default', '0', 'string', 'Default kas awal'),
                    ('print_receipt', '1', 'boolean', 'Enable receipt printing'),
                    ('demo_password', 'admin', 'string', 'Demo password')");
                $results[] = "✅ Settings seeded (14 settings)";
            } else {
                $results[] = "✅ Settings exist ({$settingsCount} rows)";
            }
            
            // Verify final state
            $finalTables = $bizPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $results[] = "\n✅ DONE! Business DB now has " . count($finalTables) . " tables.";
            
        } catch (Exception $e) {
            $results[] = "❌ Business DB error: " . $e->getMessage();
        }
    } catch (Exception $e) {
        $results[] = "❌ Master DB error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Fix Business Setup</title></head>
<body style="font-family: monospace; padding: 20px; background: #1a1a2e; color: #e0e0e0;">
<h2>🔧 Fix Business Setup: <?php echo htmlspecialchars($bizCode); ?></h2>

<div style="background: #16213e; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
<?php foreach ($results as $r): ?>
<div style="padding: 3px 0;"><?php echo htmlspecialchars($r); ?></div>
<?php endforeach; ?>
</div>

<?php if (!$run): ?>
<a href="?biz=<?php echo urlencode($bizCode); ?>&run" 
   style="display: inline-block; padding: 12px 24px; background: #4CAF50; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">
   🚀 RUN FIX NOW
</a>
<?php else: ?>
<p style="color: #4CAF50; font-weight: bold;">✅ Fix complete! <a href="?biz=<?php echo urlencode($bizCode); ?>" style="color: #2196F3;">Run check again</a></p>
<?php endif; ?>

<div style="margin-top: 20px;">
    <strong>Quick Links:</strong>
    <a href="?biz=DEMO" style="color: #2196F3; margin: 0 10px;">Demo</a>
    <a href="?biz=NARAYANAHOTEL" style="color: #2196F3; margin: 0 10px;">Narayana Hotel</a>
    <a href="?biz=BENSCAFE" style="color: #2196F3; margin: 0 10px;">Bens Cafe</a>
</div>
</body>
</html>
