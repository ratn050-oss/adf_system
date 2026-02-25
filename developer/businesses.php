<?php
/**
 * Developer Panel - Business Management
 * Create, Edit businesses with automatic database creation
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/DatabaseManager.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Business Management';

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$error = '';
$success = '';

// Detect hosting environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                 strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

// Auto-detect hosting DB prefix from DB_USER (e.g., 'adfb2574_adfsystem' -> 'adfb2574_')
$dbPrefix = '';
if ($isProduction && defined('DB_USER')) {
    $parts = explode('_', DB_USER);
    if (count($parts) >= 2) {
        $dbPrefix = $parts[0] . '_';
    }
}

// Auto-fix: Ensure businesses table has 'description' column
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'description'");
    if ($colCheck->rowCount() === 0) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN description TEXT AFTER owner_id");
    }
} catch (Exception $e) {}

// Auto-fix: Ensure businesses table has 'logo_url' column
try {
    $colCheck2 = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'logo_url'");
    if ($colCheck2->rowCount() === 0) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN logo_url VARCHAR(255) AFTER description");
    }
} catch (Exception $e) {}

// Business types
$businessTypes = ['hotel', 'restaurant', 'retail', 'manufacture', 'tourism', 'other'];

// Get owners (users with owner or developer role)
$owners = [];
try {
    $owners = $pdo->query("
        SELECT u.* FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.role_code IN ('developer', 'owner') AND u.is_active = 1
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get menus for assignment
$menus = [];
try {
    $menus = $pdo->query("SELECT * FROM menu_items WHERE is_active = 1 ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    
    if ($formAction === 'create' || $formAction === 'update') {
        $businessCode = strtoupper(trim($_POST['business_code'] ?? ''));
        $businessName = trim($_POST['business_name'] ?? '');
        $businessType = $_POST['business_type'] ?? 'other';
        $ownerId = (int)($_POST['owner_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $selectedMenus = $_POST['menus'] ?? [];
        
        // Generate database name
        $dbName = 'adf_' . strtolower(preg_replace('/[^a-z0-9]/i', '_', $businessCode));
        
        // Validation
        if (empty($businessCode) || empty($businessName) || $ownerId === 0) {
            $error = 'Please fill all required fields';
        } else {
            try {
                if ($formAction === 'create') {
                    // Check duplicate
                    $check = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE business_code = ? OR database_name = ?");
                    $check->execute([$businessCode, $dbName]);
                    if ($check->fetchColumn() > 0) {
                        $error = 'Business code or database already exists';
                    } else {
                        // Create database automatically (handles hosting prefix + cPanel)
                        $dbCreated = false;
                        $actualDbName = $dbName;
                        $dbError = '';
                        try {
                            $dbMgr = new DatabaseManager();
                            $actualDbName = $dbMgr->resolveDbName($dbName);
                            $result = $dbMgr->createBusinessDatabase($dbName);
                            $dbCreated = true;
                            $actualDbName = $result['database'] ?? $actualDbName;
                        } catch (Exception $e) {
                            $dbError = $e->getMessage();
                            // Still continue to register the business
                        }
                        
                        // Store the actual DB name (with hosting prefix if applicable)
                        $storedDbName = $actualDbName;
                        
                        // Insert business record
                        $stmt = $pdo->prepare("
                            INSERT INTO businesses (business_code, business_name, business_type, database_name, owner_id, description, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$businessCode, $businessName, $businessType, $storedDbName, $ownerId, $description, $isActive]);
                        $businessId = $pdo->lastInsertId();
                        
                        // =============================================
                        // AUTO-SETUP: Create cash_accounts for new business
                        // =============================================
                        try {
                            $pdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, 'Petty Cash', 'cash', 0, 1, 'Uang cash dari tamu / operasional', 1)")->execute([$businessId]);
                            $pdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, 'Bank', 'bank', 0, 0, 'Rekening bank utama bisnis', 1)")->execute([$businessId]);
                            $pdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, 'Kas Modal Owner', 'owner_capital', 0, 0, 'Modal operasional dari owner', 1)")->execute([$businessId]);
                        } catch (Exception $e) {
                            // cash_accounts table might not exist, skip
                        }
                        
                        // =============================================
                        // AUTO-SETUP: Seed business database with essential data
                        // =============================================
                        $seedSuccess = false;
                        try {
                            $bizDbName = $storedDbName;
                            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
                            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            
                            // Create essential tables
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS divisions (
                                id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_code VARCHAR(20), division_name VARCHAR(100) NOT NULL,
                                description TEXT, division_type ENUM('income','expense','both') DEFAULT 'both', is_active TINYINT(1) DEFAULT 1,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS categories (
                                id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_id INT, category_name VARCHAR(100) NOT NULL,
                                category_type ENUM('income','expense') DEFAULT 'income', description TEXT, is_active TINYINT(1) DEFAULT 1,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS settings (
                                id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT,
                                setting_type VARCHAR(20) DEFAULT 'string', description TEXT,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS cash_book (
                                id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), transaction_date DATE NOT NULL, transaction_time TIME,
                                division_id INT, category_id INT, category_name VARCHAR(100), description TEXT,
                                transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                                payment_method VARCHAR(30) DEFAULT 'cash', cash_account_id INT, notes TEXT,
                                attachment VARCHAR(255), created_by INT, shift VARCHAR(20),
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS users (
                                id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL,
                                full_name VARCHAR(100), email VARCHAR(100), phone VARCHAR(20),
                                role ENUM('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff',
                                role_id INT, business_access TEXT, is_active TINYINT(1) DEFAULT 1,
                                last_login DATETIME, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS roles (
                                id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL, role_code VARCHAR(30),
                                description TEXT, is_system_role TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS user_preferences (
                                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, branch_id VARCHAR(50),
                                theme VARCHAR(20) DEFAULT 'dark', language VARCHAR(5) DEFAULT 'id', sidebar_collapsed TINYINT(1) DEFAULT 0,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
                                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, permission VARCHAR(50) NOT NULL,
                                can_view TINYINT(1) DEFAULT 1, can_create TINYINT(1) DEFAULT 0, can_edit TINYINT(1) DEFAULT 0, can_delete TINYINT(1) DEFAULT 0,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action_type VARCHAR(50),
                                table_name VARCHAR(50), record_id INT, old_values TEXT, new_values TEXT,
                                ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
                                id INT AUTO_INCREMENT PRIMARY KEY, supplier_name VARCHAR(100) NOT NULL, contact_person VARCHAR(100),
                                phone VARCHAR(20), email VARCHAR(100), address TEXT, is_active TINYINT(1) DEFAULT 1,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders_header (
                                id INT AUTO_INCREMENT PRIMARY KEY, po_number VARCHAR(30) UNIQUE, supplier_id INT,
                                po_date DATE NOT NULL, delivery_date DATE, status ENUM('draft','sent','received','cancelled') DEFAULT 'draft',
                                total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders_detail (
                                id INT AUTO_INCREMENT PRIMARY KEY, po_header_id INT, item_name VARCHAR(200),
                                quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0,
                                total_price DECIMAL(15,2) DEFAULT 0, notes TEXT
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS sales_invoices_header (
                                id INT AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(30) UNIQUE, customer_name VARCHAR(100),
                                invoice_date DATE NOT NULL, due_date DATE, status ENUM('draft','sent','paid','cancelled') DEFAULT 'draft',
                                total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS sales_invoices_detail (
                                id INT AUTO_INCREMENT PRIMARY KEY, invoice_header_id INT, item_name VARCHAR(200),
                                quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0,
                                total_price DECIMAL(15,2) DEFAULT 0, notes TEXT
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS bill_templates (
                                id INT AUTO_INCREMENT PRIMARY KEY, template_name VARCHAR(100), description TEXT,
                                amount DECIMAL(15,2) DEFAULT 0, frequency ENUM('monthly','weekly','yearly','once') DEFAULT 'monthly',
                                is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS bill_records (
                                id INT AUTO_INCREMENT PRIMARY KEY, template_id INT, bill_date DATE, amount DECIMAL(15,2),
                                status ENUM('pending','paid','overdue') DEFAULT 'pending', paid_date DATE, notes TEXT,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS transaction_attachments (
                                id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT, transaction_type VARCHAR(20),
                                file_name VARCHAR(255), file_path VARCHAR(500), file_type VARCHAR(50), file_size INT,
                                uploaded_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            // Frontdesk tables
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS rooms (
                                id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(10) NOT NULL, room_type_id INT,
                                floor INT DEFAULT 1, status ENUM('available','occupied','maintenance','blocked') DEFAULT 'available',
                                price_per_night DECIMAL(15,2) DEFAULT 0, description TEXT, is_active TINYINT(1) DEFAULT 1,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS room_types (
                                id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(50) NOT NULL, base_price DECIMAL(15,2) DEFAULT 0,
                                max_occupancy INT DEFAULT 2, description TEXT, amenities TEXT,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS bookings (
                                id INT AUTO_INCREMENT PRIMARY KEY, booking_code VARCHAR(20) UNIQUE, guest_name VARCHAR(100) NOT NULL,
                                guest_phone VARCHAR(20), guest_email VARCHAR(100), room_id INT, room_type_id INT,
                                check_in DATE NOT NULL, check_out DATE NOT NULL, nights INT DEFAULT 1,
                                adults INT DEFAULT 1, children INT DEFAULT 0, rate_per_night DECIMAL(15,2) DEFAULT 0,
                                total_amount DECIMAL(15,2) DEFAULT 0, paid_amount DECIMAL(15,2) DEFAULT 0,
                                status ENUM('confirmed','checked_in','checked_out','cancelled','no_show') DEFAULT 'confirmed',
                                source VARCHAR(50) DEFAULT 'walk-in', notes TEXT, created_by INT,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS booking_payments (
                                id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, amount DECIMAL(15,2) NOT NULL,
                                payment_method VARCHAR(30) DEFAULT 'cash', payment_date DATETIME, notes TEXT,
                                created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS frontdesk_rooms (
                                id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(10), room_type VARCHAR(50),
                                floor INT DEFAULT 1, price DECIMAL(15,2) DEFAULT 0, status VARCHAR(20) DEFAULT 'available',
                                guest_name VARCHAR(100), check_in DATE, check_out DATE,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS guests (
                                id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(100) NOT NULL, phone VARCHAR(20),
                                email VARCHAR(100), id_type VARCHAR(30), id_number VARCHAR(50), address TEXT, nationality VARCHAR(50),
                                notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS breakfast_menus (
                                id INT AUTO_INCREMENT PRIMARY KEY, menu_name VARCHAR(100), description TEXT,
                                is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
                                id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, guest_name VARCHAR(100),
                                room_number VARCHAR(10), order_date DATE, menu_id INT, quantity INT DEFAULT 1,
                                notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS breakfast_log (
                                id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, room_number VARCHAR(10),
                                guest_name VARCHAR(100), breakfast_date DATE, pax INT DEFAULT 1,
                                notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            // Investor tables
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS investors (
                                id INT AUTO_INCREMENT PRIMARY KEY, investor_name VARCHAR(100) NOT NULL, phone VARCHAR(20),
                                email VARCHAR(100), address TEXT, notes TEXT, is_active TINYINT(1) DEFAULT 1,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS investor_transactions (
                                id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, transaction_type ENUM('investment','return','dividend'),
                                amount DECIMAL(15,2), transaction_date DATE, notes TEXT,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS investor_balances (
                                id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, balance DECIMAL(15,2) DEFAULT 0,
                                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )");
                            $bizPdo->exec("CREATE TABLE IF NOT EXISTS investor_bills (
                                id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, bill_name VARCHAR(100),
                                amount DECIMAL(15,2), due_date DATE, status ENUM('pending','paid','overdue') DEFAULT 'pending',
                                paid_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )");
                            
                            // Seed default data - using business_code as identifier
                            $bizId = strtolower(str_replace(' ', '-', $businessName));
                            
                            // Seed roles
                            $bizPdo->exec("INSERT IGNORE INTO roles (id, role_name, role_code, description, is_system_role) VALUES
                                (1, 'Admin', 'admin', 'System administrator', 1),
                                (2, 'Manager', 'manager', 'Business manager', 1),
                                (3, 'Staff', 'staff', 'Regular staff', 1),
                                (4, 'Developer', 'developer', 'System developer', 1),
                                (5, 'Owner', 'owner', 'Business owner', 1)");
                            
                            // Seed divisions (generic for any business)
                            $bizPdo->exec("INSERT IGNORE INTO divisions (id, branch_id, division_code, division_name, division_type) VALUES
                                (1, '{$bizId}', 'KITCHEN', 'Kitchen', 'both'),
                                (2, '{$bizId}', 'BAR', 'Bar', 'both'),
                                (3, '{$bizId}', 'RESTO', 'Resto', 'both'),
                                (4, '{$bizId}', 'HOUSEKEEPING', 'Housekeeping', 'expense'),
                                (5, '{$bizId}', 'HOTEL', 'Hotel', 'income'),
                                (6, '{$bizId}', 'GARDENER', 'Gardener', 'expense'),
                                (7, '{$bizId}', 'OTHERS', 'Lain-lain', 'both'),
                                (8, '{$bizId}', 'PC', 'Petty Cash', 'both')");
                            
                            // Seed basic categories
                            $bizPdo->exec("INSERT IGNORE INTO categories (branch_id, division_id, category_name, category_type, description) VALUES
                                ('{$bizId}', 1, 'Food Sales', 'income', 'Revenue from food sales'),
                                ('{$bizId}', 1, 'Food Supplies', 'expense', 'Purchase of food ingredients'),
                                ('{$bizId}', 2, 'Beverage Sales', 'income', 'Revenue from beverage sales'),
                                ('{$bizId}', 2, 'Beverage Inventory', 'expense', 'Purchase of beverages'),
                                ('{$bizId}', 3, 'Room Rental', 'income', 'Room rental income'),
                                ('{$bizId}', 3, 'Staff Salary', 'expense', 'Employee salaries'),
                                ('{$bizId}', 4, 'Housekeeping Service', 'income', 'Cleaning services'),
                                ('{$bizId}', 4, 'Room Supplies', 'expense', 'Room cleaning supplies'),
                                ('{$bizId}', 5, 'Hotel Income', 'income', 'Hotel revenue'),
                                ('{$bizId}', 5, 'Hotel Expense', 'expense', 'Hotel operational expenses'),
                                ('{$bizId}', 7, 'Other Income', 'income', 'Miscellaneous income'),
                                ('{$bizId}', 7, 'Other Expense', 'expense', 'Miscellaneous expenses')");
                            
                            // Seed essential settings
                            $bizPdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
                                ('business_name', '{$businessName}', 'string', 'Business name'),
                                ('business_type', '{$businessType}', 'string', 'Business type'),
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
                                ('print_receipt', '1', 'boolean', 'Enable print receipt'),
                                ('demo_password', 'admin', 'string', 'Demo password')");
                            
                            // Seed room types for hotel
                            if ($businessType === 'hotel') {
                                $bizPdo->exec("INSERT IGNORE INTO room_types (id, type_name, base_price, max_occupancy, description) VALUES
                                    (1, 'Standard', 500000, 2, 'Standard room'),
                                    (2, 'Deluxe', 750000, 2, 'Deluxe room'),
                                    (3, 'Suite', 1200000, 4, 'Suite room')");
                            }
                            
                            $seedSuccess = true;
                        } catch (Exception $seedErr) {
                            // DB seeding failed but business was created
                            error_log("Business DB seed error: " . $seedErr->getMessage());
                        }
                        
                        // Assign menus to business
                        try {
                            $menuStmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
                            foreach ($selectedMenus as $menuId) {
                                $menuStmt->execute([$businessId, $menuId]);
                            }
                        } catch (Exception $e) {
                            // business_menu_config might not exist yet, ignore
                        }
                        
                        $auth->logAction('create_business', 'businesses', $businessId, null, ['name' => $businessName, 'database' => $storedDbName]);
                        
                        if ($dbCreated) {
                            $seedMsg = $seedSuccess ? ' Database seeded with divisions, categories, settings & accounts!' : '';
                            $_SESSION['success_message'] = "Business '{$businessName}' created with database '{$storedDbName}'!{$seedMsg}";
                        } else {
                            $_SESSION['success_message'] = "Business '{$businessName}' registered with cash accounts! ⚠️ Database '{$storedDbName}' could not be auto-created. Please create it manually in cPanel → MySQL Databases, then run quick-db-setup.php. Error: {$dbError}";
                        }
                        header('Location: businesses.php');
                        exit;
                    }
                } else {
                    // Update
                    $updateId = (int)$_POST['business_id'];
                    
                    $stmt = $pdo->prepare("
                        UPDATE businesses SET business_code = ?, business_name = ?, business_type = ?, owner_id = ?, description = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$businessCode, $businessName, $businessType, $ownerId, $description, $isActive, $updateId]);
                    
                    // Update menu assignments
                    $pdo->prepare("DELETE FROM business_menu_config WHERE business_id = ?")->execute([$updateId]);
                    $menuStmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
                    foreach ($selectedMenus as $menuId) {
                        $menuStmt->execute([$updateId, $menuId]);
                    }
                    
                    $auth->logAction('update_business', 'businesses', $updateId, null, ['name' => $businessName]);
                    $_SESSION['success_message'] = 'Business updated successfully!';
                    header('Location: businesses.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle delete
if ($action === 'delete' && $editId) {
    try {
        // Get business info first
        $bizStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
        $bizStmt->execute([$editId]);
        $bizToDelete = $bizStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($bizToDelete) {
            // Delete from adf_system (menu config will cascade)
            $pdo->prepare("DELETE FROM businesses WHERE id = ?")->execute([$editId]);
            
            // Optionally delete the business database (commented for safety)
            // $dbMgr = new DatabaseManager();
            // $dbMgr->deleteDatabase($bizToDelete['database_name'], true);
            
            $auth->logAction('delete_business', 'businesses', $editId, $bizToDelete);
            $_SESSION['success_message'] = 'Business deleted! Note: Database was NOT deleted for safety.';
        }
        header('Location: businesses.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Failed to delete: ' . $e->getMessage();
    }
}

// Get business for editing
$editBusiness = null;
$editMenus = [];
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
    $stmt->execute([$editId]);
    $editBusiness = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get assigned menus
    $menuStmt = $pdo->prepare("SELECT menu_id FROM business_menu_config WHERE business_id = ? AND is_enabled = 1");
    $menuStmt->execute([$editId]);
    $editMenus = $menuStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get all businesses
$businesses = [];
try {
    $stmt = $pdo->query("
        SELECT b.*, u.full_name as owner_name,
               (SELECT COUNT(*) FROM business_menu_config WHERE business_id = b.id AND is_enabled = 1) as menu_count,
               (SELECT COUNT(*) FROM user_business_assignment WHERE business_id = b.id) as user_count
        FROM businesses b
        LEFT JOIN users u ON b.owner_id = u.id
        ORDER BY b.created_at DESC
    ");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <?php if ($action === 'add' || $action === 'edit'): ?>
    <!-- Add/Edit Form -->
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-building-<?php echo $action === 'add' ? 'add' : 'gear'; ?> me-2"></i>
                        <?php echo $action === 'add' ? 'Add New Business' : 'Edit Business'; ?>
                    </h5>
                    <a href="businesses.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to List
                    </a>
                </div>
                
                <div class="p-4">
                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($action === 'add'): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Auto Database Creation:</strong> System will automatically create a new database named <code>adf_[business_code]</code> when you create the business.
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="form_action" value="<?php echo $action === 'add' ? 'create' : 'update'; ?>">
                        <?php if ($editBusiness): ?>
                        <input type="hidden" name="business_id" value="<?php echo $editBusiness['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control text-uppercase" name="business_code" required
                                       placeholder="e.g., HOTEL_01, CAFE_BENS"
                                       value="<?php echo htmlspecialchars($editBusiness['business_code'] ?? ''); ?>"
                                       <?php echo $action === 'edit' ? 'readonly' : ''; ?>>
                                <small class="text-muted">Unique identifier, will be used for database name</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="business_name" required
                                       placeholder="e.g., Narayana Hotel, Ben's Cafe"
                                       value="<?php echo htmlspecialchars($editBusiness['business_name'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="business_type" required>
                                    <?php foreach ($businessTypes as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($editBusiness['business_type'] ?? '') === $type ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Owner <span class="text-danger">*</span></label>
                                <select class="form-select" name="owner_id" required>
                                    <option value="">Select Owner</option>
                                    <?php foreach ($owners as $owner): ?>
                                    <option value="<?php echo $owner['id']; ?>" <?php echo ($editBusiness['owner_id'] ?? '') == $owner['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($owner['full_name']); ?> (@<?php echo $owner['username']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?php echo htmlspecialchars($editBusiness['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <?php if ($editBusiness): ?>
                        <div class="mb-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($editBusiness['database_name']); ?>" readonly>
                            <small class="text-muted">Database name cannot be changed after creation</small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Enable Menus for this Business</label>
                            <div class="row">
                                <?php foreach ($menus as $menu): ?>
                                <div class="col-md-4 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="menus[]" value="<?php echo $menu['id']; ?>"
                                               id="menu_<?php echo $menu['id']; ?>"
                                               <?php echo in_array($menu['id'], $editMenus) || $action === 'add' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="menu_<?php echo $menu['id']; ?>">
                                            <i class="<?php echo $menu['menu_icon']; ?> me-1"></i>
                                            <?php echo htmlspecialchars($menu['menu_name']); ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                       <?php echo ($editBusiness['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active Business</label>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i><?php echo $action === 'add' ? 'Create Business & Database' : 'Update Business'; ?>
                            </button>
                            <a href="businesses.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Businesses List -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Business Management</h4>
                <a href="?action=add" class="btn btn-primary">
                    <i class="bi bi-building-add me-1"></i>Add New Business
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Type</th>
                        <th>Database</th>
                        <th>Owner</th>
                        <th>Menus</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th>Staff Login Link</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($businesses)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-building fs-1 d-block mb-2"></i>
                            No businesses found. <a href="?action=add">Create one</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($businesses as $biz): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($biz['business_name']); ?></strong>
                            <br><small class="text-muted"><?php echo htmlspecialchars($biz['business_code']); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo ucfirst($biz['business_type']); ?></span>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars($biz['database_name']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($biz['owner_name'] ?? '-'); ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo $biz['menu_count']; ?> menus</span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $biz['user_count']; ?> users</span>
                        </td>
                        <td>
                            <?php if ($biz['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            // Map business_code to URL slug
                            $codeToSlug = [
                                'BENSCAFE' => 'bens-cafe',
                                'NARAYANAHOTEL' => 'narayana-hotel'
                            ];
                            $businessSlug = $codeToSlug[$biz['business_code']] ?? strtolower(str_replace('_', '-', $biz['business_code']));
                            $staffLoginUrl = BASE_URL . '/login.php?biz=' . $businessSlug;
                            ?>
                            <div class="input-group input-group-sm" style="max-width: 350px;">
                                <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($staffLoginUrl); ?>" readonly id="bizLoginLink<?php echo $biz['id']; ?>" style="font-size: 0.75rem;">
                                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyBizLoginLink(<?php echo $biz['id']; ?>)" title="Copy Link">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </td>
                        <td>
                            <a href="../developer-access.php?dev_access=<?php echo base64_encode($biz['database_name']); ?>" 
                               class="btn btn-sm btn-success" title="Open Business (Developer Access)" target="_blank">
                                <i class="bi bi-box-arrow-up-right"></i> Open
                            </a>
                            <a href="?action=edit&id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="permissions.php?business_id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-info" title="User Permissions">
                                <i class="bi bi-shield-lock"></i>
                            </a>
                            <button onclick="confirmDelete('?action=delete&id=<?php echo $biz['id']; ?>', '<?php echo addslashes($biz['business_name']); ?>')" 
                                    class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function copyBizLoginLink(bizId) {
    const input = document.getElementById('bizLoginLink' + bizId);
    input.select();
    input.setSelectionRange(0, 99999); // For mobile devices
    
    // Copy to clipboard
    navigator.clipboard.writeText(input.value).then(function() {
        // Show success feedback
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    }).catch(function(err) {
        alert('Failed to copy: ' + err);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
