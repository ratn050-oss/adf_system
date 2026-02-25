<?php
/**
 * Quick DB Setup - Complete database setup for any business database
 * Creates ALL required tables and seed data
 * Safe to re-run (uses CREATE TABLE IF NOT EXISTS)
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

$targetDb = $_GET['db'] ?? 'adfb2574_demo';
$targetDb = preg_replace('/[^a-zA-Z0-9_]/', '', $targetDb);
$run = isset($_GET['run']);
$results = [];

try {
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $check = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$targetDb}'");
    if ($check->rowCount() === 0) {
        die("Database '{$targetDb}' does not exist!");
    }
    
    $dbPdo = new PDO("mysql:host=" . DB_HOST . ";dbname={$targetDb}", DB_USER, DB_PASS);
    $dbPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $tables = $dbPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $results[] = ['info', "Database '{$targetDb}' — " . count($tables) . " existing tables."];
    
    if ($run) {
        $errors = [];
        
        // ============================================================
        // STEP 1: Run business_template.sql (base tables)
        // ============================================================
        $templatePath = __DIR__ . '/database/business_template.sql';
        if (file_exists($templatePath)) {
            $sql = file_get_contents($templatePath);
            $lines = explode("\n", $sql);
            $cleanLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
                $cleanLines[] = $line;
            }
            $cleanSql = implode("\n", $cleanLines);
            $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
            
            $executed = 0;
            foreach ($statements as $stmt) {
                if (!empty($stmt)) {
                    try { $dbPdo->exec($stmt); $executed++; } 
                    catch (PDOException $e) { $errors[] = substr($stmt, 0, 60) . ': ' . $e->getMessage(); }
                }
            }
            $results[] = ['success', "Template: {$executed} statements executed."];
        } else {
            $results[] = ['warning', "Template file not found, skipping."];
        }

        // ============================================================
        // STEP 2: Create ALL system tables (IF NOT EXISTS = safe)
        // ============================================================
        $allTables = [
            // --- Core System Tables ---
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100),
                phone VARCHAR(20),
                role ENUM('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff',
                role_id INT DEFAULT NULL,
                business_access TEXT,
                is_active TINYINT(1) DEFAULT 1,
                last_login DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_username (username),
                INDEX idx_role (role),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_name VARCHAR(100) NOT NULL,
                role_code VARCHAR(30) UNIQUE NOT NULL,
                description VARCHAR(255),
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                branch_id VARCHAR(50) NOT NULL DEFAULT '',
                theme VARCHAR(50) DEFAULT 'dark',
                language VARCHAR(20) DEFAULT 'id',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_branch (user_id, branch_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS user_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                permission VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_permission (user_id, permission)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS user_menu_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                business_id INT NOT NULL,
                menu_key VARCHAR(100) NOT NULL,
                can_view TINYINT(1) DEFAULT 1,
                can_create TINYINT(1) DEFAULT 0,
                can_edit TINYINT(1) DEFAULT 0,
                can_delete TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_permission (user_id, business_id, menu_key),
                INDEX idx_user (user_id),
                INDEX idx_business (business_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS login_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                business_id VARCHAR(100),
                user_id INT,
                username VARCHAR(100) NOT NULL,
                full_name VARCHAR(255),
                role VARCHAR(50),
                status ENUM('success','failed') DEFAULT 'success',
                ip_address VARCHAR(45),
                user_agent VARCHAR(500),
                login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                logout_time TIMESTAMP NULL,
                session_duration INT,
                INDEX idx_login_time (login_time),
                INDEX idx_business_id (business_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                action_type VARCHAR(50),
                table_name VARCHAR(100),
                record_id INT,
                old_data LONGTEXT,
                new_data LONGTEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_action (action_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Accounting Tables ---
            "CREATE TABLE IF NOT EXISTS divisions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(20),
                description TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                type ENUM('income','expense') NOT NULL,
                parent_id INT DEFAULT NULL,
                code VARCHAR(20),
                description TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_code VARCHAR(20) NOT NULL,
                account_name VARCHAR(100) NOT NULL,
                account_type ENUM('asset','liability','equity','income','expense') NOT NULL,
                parent_id INT DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                balance DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Cash & Finance ---
            "CREATE TABLE IF NOT EXISTS cash_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_name VARCHAR(100) NOT NULL,
                account_type ENUM('cash','bank','e-wallet','petty_cash') DEFAULT 'cash',
                balance DECIMAL(15,2) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (account_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS cash_book (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL,
                division_id INT,
                category_id INT,
                type ENUM('income','expense') NOT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                payment_method ENUM('cash','transfer','qr','debit','ota','other') DEFAULT 'cash',
                reference_number VARCHAR(50),
                notes TEXT,
                attachment VARCHAR(255),
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_date (transaction_date),
                INDEX idx_type (type),
                INDEX idx_division (division_id),
                INDEX idx_category (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cash_balance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                balance_date DATE NOT NULL,
                opening_balance DECIMAL(15,2) DEFAULT 0,
                total_income DECIMAL(15,2) DEFAULT 0,
                total_expense DECIMAL(15,2) DEFAULT 0,
                closing_balance DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY balance_date (balance_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS cash_account_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cash_account_id INT NOT NULL,
                transaction_id INT,
                transaction_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL,
                debit DECIMAL(15,2) DEFAULT 0,
                credit DECIMAL(15,2) DEFAULT 0,
                transaction_type ENUM('income','expense','transfer','opening_balance','capital_injection') NOT NULL,
                reference_number VARCHAR(50),
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_account (cash_account_id),
                INDEX idx_date (transaction_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Suppliers & Customers ---
            "CREATE TABLE IF NOT EXISTS suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                contact_person VARCHAR(100),
                phone VARCHAR(20),
                email VARCHAR(100),
                address TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(20),
                email VARCHAR(100),
                address TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Purchase Orders ---
            "CREATE TABLE IF NOT EXISTS purchase_orders_header (
                id INT AUTO_INCREMENT PRIMARY KEY,
                po_number VARCHAR(50) NOT NULL UNIQUE,
                supplier_id INT NOT NULL,
                po_date DATE NOT NULL,
                expected_delivery_date DATE,
                status ENUM('draft','submitted','approved','rejected','partially_received','completed','cancelled') DEFAULT 'draft',
                total_amount DECIMAL(15,2) DEFAULT 0,
                discount_amount DECIMAL(15,2) DEFAULT 0,
                tax_amount DECIMAL(15,2) DEFAULT 0,
                grand_total DECIMAL(15,2) DEFAULT 0,
                notes TEXT,
                approved_by INT,
                approved_at TIMESTAMP NULL,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_po_date (po_date),
                INDEX idx_status (status),
                INDEX idx_supplier (supplier_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS purchase_orders_detail (
                id INT AUTO_INCREMENT PRIMARY KEY,
                po_header_id INT NOT NULL,
                line_number INT NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                item_description TEXT,
                unit_of_measure VARCHAR(50) DEFAULT 'pcs',
                quantity DECIMAL(15,2) NOT NULL,
                unit_price DECIMAL(15,2) NOT NULL,
                subtotal DECIMAL(15,2) NOT NULL,
                division_id INT NOT NULL,
                received_quantity DECIMAL(15,2) DEFAULT 0,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_po_header (po_header_id),
                INDEX idx_division (division_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Sales Invoices ---
            "CREATE TABLE IF NOT EXISTS sales_invoices_header (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(50) NOT NULL UNIQUE,
                invoice_date DATE NOT NULL,
                customer_name VARCHAR(255) NOT NULL,
                customer_phone VARCHAR(50),
                customer_email VARCHAR(255),
                customer_address TEXT,
                division_id INT NOT NULL,
                payment_method ENUM('cash','debit','transfer','qr','ota','other') DEFAULT 'cash',
                payment_status ENUM('unpaid','paid','partial') DEFAULT 'unpaid',
                subtotal DECIMAL(15,2) DEFAULT 0,
                discount_amount DECIMAL(15,2) DEFAULT 0,
                tax_amount DECIMAL(15,2) DEFAULT 0,
                total_amount DECIMAL(15,2) DEFAULT 0,
                paid_amount DECIMAL(15,2) DEFAULT 0,
                notes TEXT,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                cash_book_id INT,
                print_count INT DEFAULT 0,
                last_printed_at TIMESTAMP NULL,
                INDEX idx_date (invoice_date),
                INDEX idx_status (payment_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS sales_invoices_detail (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_header_id INT NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                item_description TEXT,
                category VARCHAR(100),
                quantity DECIMAL(10,2) DEFAULT 1,
                unit_price DECIMAL(15,2) NOT NULL,
                total_price DECIMAL(15,2) NOT NULL,
                notes TEXT,
                INDEX idx_header (invoice_header_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Bills ---
            "CREATE TABLE IF NOT EXISTS bill_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bill_name VARCHAR(150) NOT NULL,
                bill_category ENUM('electricity','tax','wifi','vehicle','po','receivable','other') DEFAULT 'other',
                vendor_name VARCHAR(150),
                vendor_contact VARCHAR(100),
                account_number VARCHAR(100),
                default_amount DECIMAL(15,2) DEFAULT 0,
                is_fixed_amount TINYINT(1) DEFAULT 0,
                recurrence ENUM('monthly','quarterly','yearly','one-time') DEFAULT 'monthly',
                due_day INT DEFAULT 1,
                reminder_days INT DEFAULT 3,
                division_id INT,
                category_id INT,
                payment_method ENUM('cash','transfer','qr','debit','other') DEFAULT 'transfer',
                notes TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_category (bill_category),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS bill_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                bill_period VARCHAR(7) NOT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                due_date DATE NOT NULL,
                status ENUM('pending','paid','overdue','cancelled') DEFAULT 'pending',
                paid_date DATE,
                paid_amount DECIMAL(15,2),
                payment_method ENUM('cash','transfer','qr','debit','other'),
                cashbook_id INT,
                proof_file VARCHAR(255),
                notes TEXT,
                paid_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_bill_period (template_id, bill_period),
                INDEX idx_template (template_id),
                INDEX idx_period (bill_period),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            // --- Business ---
            "CREATE TABLE IF NOT EXISTS businesses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                business_name VARCHAR(255) NOT NULL,
                business_code VARCHAR(50),
                database_name VARCHAR(255) NOT NULL,
                owner_id INT,
                status VARCHAR(50) DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS business_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                business_id INT NOT NULL,
                whatsapp_number VARCHAR(20),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business (business_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Inventory ---
            "CREATE TABLE IF NOT EXISTS inventory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_name VARCHAR(200) NOT NULL,
                sku VARCHAR(50),
                category_id INT,
                unit VARCHAR(30) DEFAULT 'pcs',
                quantity DECIMAL(15,2) DEFAULT 0,
                min_stock DECIMAL(15,2) DEFAULT 0,
                unit_price DECIMAL(15,2) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS inventory_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                inventory_id INT NOT NULL,
                movement_type ENUM('in','out','adjustment') NOT NULL,
                quantity DECIMAL(15,2) NOT NULL,
                reference_type VARCHAR(50),
                reference_id INT,
                notes TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Daily Operations ---
            "CREATE TABLE IF NOT EXISTS daily_shifts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shift_date DATE NOT NULL,
                user_id INT NOT NULL,
                start_time DATETIME,
                end_time DATETIME,
                opening_cash DECIMAL(15,2) DEFAULT 0,
                closing_cash DECIMAL(15,2) DEFAULT 0,
                notes TEXT,
                status ENUM('open','closed') DEFAULT 'open',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS system_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                log_type VARCHAR(50),
                message TEXT,
                details LONGTEXT,
                user_id INT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS bank_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bank_name VARCHAR(100) NOT NULL,
                account_number VARCHAR(50),
                account_name VARCHAR(100),
                balance DECIMAL(15,2) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_date DATE NOT NULL,
                customer_id INT,
                total_amount DECIMAL(15,2) DEFAULT 0,
                payment_method VARCHAR(50) DEFAULT 'cash',
                notes TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS purchases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                purchase_date DATE NOT NULL,
                supplier_id INT,
                total_amount DECIMAL(15,2) DEFAULT 0,
                payment_method VARCHAR(50) DEFAULT 'cash',
                notes TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS transaction_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_type VARCHAR(50),
                file_size INT,
                uploaded_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_transaction (transaction_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        
        $tableOk = 0;
        foreach ($allTables as $stmt) {
            try { $dbPdo->exec($stmt); $tableOk++; } 
            catch (PDOException $e) { $errors[] = 'Table: ' . $e->getMessage(); }
        }
        $results[] = ['success', "System tables: {$tableOk}/" . count($allTables) . " created/verified."];

        // ============================================================
        // STEP 3: Seed Data
        // ============================================================
        
        // 3a. Default roles
        try {
            $cnt = $dbPdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
            if ($cnt == 0) {
                $roleStmt = $dbPdo->prepare("INSERT INTO roles (role_name, role_code, description) VALUES (?,?,?)");
                foreach ([
                    ['Owner', 'owner', 'Business owner with full access'],
                    ['Developer', 'developer', 'System developer with full access'],
                    ['Admin', 'admin', 'Administrator'],
                    ['Frontdesk', 'frontdesk', 'Front desk staff'],
                    ['Manager', 'manager', 'Manager access'],
                ] as $r) { $roleStmt->execute($r); }
                $results[] = ['success', "5 default roles inserted."];
            }
        } catch (Exception $e) { $errors[] = 'Roles: ' . $e->getMessage(); }

        // 3b. Default admin user  
        try {
            $cnt = $dbPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($cnt == 0) {
                $hash = password_hash('admin123', PASSWORD_DEFAULT);
                $dbPdo->prepare("INSERT INTO users (username, password, full_name, role, role_id, is_active) VALUES (?,?,?,?,?,?)")
                    ->execute(['admin', $hash, 'Administrator', 'admin', 3, 1]);
                $results[] = ['success', "Default admin created (user: admin / pass: admin123)."];
            }
        } catch (Exception $e) { $errors[] = 'Admin: ' . $e->getMessage(); }

        // 3c. Default settings (insert missing keys only)
        try {
            $existingSettings = $dbPdo->query("SELECT setting_key FROM settings")->fetchAll(PDO::FETCH_COLUMN);
            $defaultSettings = [
                'company_name' => 'Demo Business',
                'company_email' => '',
                'company_phone' => '',
                'company_address' => '',
                'company_logo' => '',
                'company_tagline' => '',
                'company_website' => '',
                'developer_whatsapp' => '',
                'invoice_logo' => '',
                'login_background' => '',
                'currency_symbol' => 'Rp',
                'currency_position' => 'before',
                'date_format' => 'd/m/Y',
                'timezone' => 'Asia/Jakarta',
            ];
            $insSettings = $dbPdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)");
            $inserted = 0;
            foreach ($defaultSettings as $key => $val) {
                if (!in_array($key, $existingSettings)) {
                    $insSettings->execute([$key, $val]);
                    $inserted++;
                }
            }
            if ($inserted > 0) $results[] = ['success', "{$inserted} default settings inserted."];
        } catch (Exception $e) { $errors[] = 'Settings: ' . $e->getMessage(); }

        // 3d. Default permissions for admin user
        try {
            $cnt = $dbPdo->query("SELECT COUNT(*) FROM user_permissions")->fetchColumn();
            if ($cnt == 0) {
                $permStmt = $dbPdo->prepare("INSERT INTO user_permissions (user_id, permission) VALUES (?,?)");
                foreach (['dashboard','cashbook','divisions','categories','reports','settings',
                          'procurement','sales','bills','inventory','developer'] as $perm) {
                    $permStmt->execute([1, $perm]);
                }
                $results[] = ['success', "11 default permissions for admin user inserted."];
            }
        } catch (Exception $e) { $errors[] = 'Permissions: ' . $e->getMessage(); }

        // 3e. Default divisions if empty
        try {
            $cnt = $dbPdo->query("SELECT COUNT(*) FROM divisions")->fetchColumn();
            if ($cnt == 0) {
                $divStmt = $dbPdo->prepare("INSERT INTO divisions (name, code, description) VALUES (?,?,?)");
                $divStmt->execute(['Umum', 'GEN', 'Divisi Umum']);
                $divStmt->execute(['Operasional', 'OPS', 'Divisi Operasional']);
                $divStmt->execute(['Administrasi', 'ADM', 'Divisi Administrasi']);
                $results[] = ['success', "3 default divisions inserted."];
            }
        } catch (Exception $e) { $errors[] = 'Divisions: ' . $e->getMessage(); }

        // 3f. Default categories if empty
        try {
            $cnt = $dbPdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
            if ($cnt == 0) {
                $catStmt = $dbPdo->prepare("INSERT INTO categories (name, type, code) VALUES (?,?,?)");
                $catStmt->execute(['Pendapatan Umum', 'income', 'INC01']);
                $catStmt->execute(['Pendapatan Jasa', 'income', 'INC02']);
                $catStmt->execute(['Biaya Operasional', 'expense', 'EXP01']);
                $catStmt->execute(['Biaya Gaji', 'expense', 'EXP02']);
                $catStmt->execute(['Biaya Utilitas', 'expense', 'EXP03']);
                $catStmt->execute(['Biaya Supplies', 'expense', 'EXP04']);
                $results[] = ['success', "6 default categories inserted."];
            }
        } catch (Exception $e) { $errors[] = 'Categories: ' . $e->getMessage(); }

        // Show errors
        if (!empty($errors)) {
            $results[] = ['warning', "Warnings (" . count($errors) . "):<br>" . implode('<br>', array_slice($errors, 0, 10))];
        }
        
        // Final table count with row counts
        $tablesAfter = $dbPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $tableRows = [];
        foreach ($tablesAfter as $t) {
            try {
                $cnt = $dbPdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                $color = $cnt > 0 ? '#16a34a' : '#94a3b8';
                $tableRows[] = "<span style='color:{$color}'>{$t}({$cnt})</span>";
            } catch (Exception $e) {
                $tableRows[] = "<span style='color:#ef4444'>{$t}(err)</span>";
            }
        }
        $results[] = ['success', "<strong>" . count($tablesAfter) . " tables:</strong><br>" . implode(' &bull; ', $tableRows)];
        $results[] = ['success', "<strong>✅ DONE!</strong> Database '{$targetDb}' is fully set up."];
        
    } else {
        // Show current state
        if (count($tables) > 0) {
            $tableInfo = [];
            foreach ($tables as $t) {
                try {
                    $cnt = $dbPdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                    $tableInfo[] = "{$t}({$cnt})";
                } catch (Exception $e) {
                    $tableInfo[] = "{$t}(err)";
                }
            }
            $results[] = ['info', "Tables: " . implode(', ', $tableInfo)];
        }
        
        // Check required tables
        $required = ['users','roles','settings','user_preferences','user_permissions','login_history',
            'cash_book','cash_balance','cash_accounts','cash_account_transactions','categories','divisions',
            'accounts','suppliers','customers','purchase_orders_header','purchase_orders_detail',
            'sales_invoices_header','sales_invoices_detail','bill_templates','bill_records',
            'businesses','business_settings','audit_logs','inventory','daily_shifts','transaction_attachments'];
        $missing = array_diff($required, $tables);
        if (!empty($missing)) {
            $results[] = ['warning', "<strong>" . count($missing) . " missing tables:</strong> " . implode(', ', $missing)];
        } else {
            $results[] = ['success', "All " . count($required) . " required tables exist!"];
        }
        
        $results[] = ['action', "<a href='?db={$targetDb}&run=1' style='display:inline-block;background:#16a34a;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:1.1rem;'>▶ RUN FULL SETUP on {$targetDb}</a><br><small style='color:#64748b;margin-top:8px;display:block;'>Safe to re-run — uses IF NOT EXISTS + inserts only if empty</small>"];
    }
    
} catch (PDOException $e) {
    $results[] = ['error', "ERROR: " . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html><head><title>Quick DB Setup</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:2rem;max-width:850px;margin:auto;background:#f1f5f9;color:#1e293b;}
h2{margin-bottom:1rem;}
.r{padding:0.75rem 1rem;margin:0.4rem 0;border-radius:8px;font-size:0.88rem;line-height:1.6;}
.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.warning{background:#fef9c3;color:#854d0e;border:1px solid #fde68a;}
.info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
.action{background:#fff;border:1px solid #e2e8f0;text-align:center;padding:1.5rem;}
hr{border:none;border-top:1px solid #e2e8f0;margin:1rem 0;}
a.db{color:#3b82f6;}
</style>
</head><body>
<h2>⚡ Database Setup: <?= htmlspecialchars($targetDb) ?></h2>
<?php foreach($results as $r): ?>
<div class="r <?= $r[0] ?>"><?= $r[1] ?></div>
<?php endforeach; ?>
<hr>
<p><small>Databases: 
<a class="db" href="?db=adfb2574_demo">adfb2574_demo</a> | 
<a class="db" href="?db=adfb2574_Adf_Bens">adfb2574_Adf_Bens</a> |
<a class="db" href="?db=adfb2574_narayana_hotel">adfb2574_narayana_hotel</a>
</small></p>
</body></html>
