<?php
/**
 * Database Connection Class
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);
require_once 'config.php';

class Database {
    private static $instance = null;
    private $connection;
    private static $currentDatabase = null;

    /**
     * Private constructor - Singleton Pattern
     */
    private function __construct($dbName = null) {
        try {
            // Get database name from business config if not provided
            if ($dbName === null) {
                if (defined('ACTIVE_BUSINESS_ID')) {
                    // Use business-specific database based on ACTIVE_BUSINESS_ID
                    require_once __DIR__ . '/../includes/business_helper.php';
                    $businessConfig = getActiveBusinessConfig();
                    $dbName = $businessConfig['database'];
                } else {
                    $dbName = DB_NAME;
                }
            }

            // Convert database naming for hosting
            $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                            strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
            if ($isProduction) {
                // Map local database names to hosting database names
                $dbMapping = [
                    'adf_system' => 'adfb2574_adf',
                    'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
                    'adf_benscafe' => 'adfb2574_Adf_Bens',
                    'adf_demo' => 'adfb2574_demo'
                ];
                
                if (isset($dbMapping[$dbName])) {
                    $dbName = $dbMapping[$dbName];
                }
            }

            self::$currentDatabase = $dbName;

            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Auto-sync schema once per session for business databases
            $this->autoSyncSchema($dbName);
        } catch (PDOException $e) {
            die("Database Connection Error to '{$dbName}': " . $e->getMessage());
        }
    }

    /**
     * Auto-sync database schema: ensure all required tables and columns exist.
     * Runs ONCE per session per database to avoid repeated queries.
     */
    private function autoSyncSchema($dbName) {
        // Skip for master database — handle it separately
        $masterNames = ['adf_system', 'adfb2574_adf'];
        $isMaster = in_array($dbName, $masterNames);
        
        // Only run once per session per database (version bump forces re-check)
        $schemaVersion = 2; // Bump this when adding new columns
        $sessionKey = '_schema_synced_v' . $schemaVersion . '_' . md5($dbName);
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[$sessionKey])) return;
        
        try {
            if ($isMaster) {
                // ---- MASTER DB: fix cash_accounts columns ----
                $existingCols = [];
                try {
                    $existingCols = $this->connection->query("SHOW COLUMNS FROM cash_accounts")->fetchAll(PDO::FETCH_COLUMN);
                } catch (PDOException $e) { /* table may not exist */ }
                
                if (!empty($existingCols)) {
                    $masterCols = [
                        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
                        'description' => "TEXT NULL",
                        'is_default_account' => "TINYINT(1) NOT NULL DEFAULT 0",
                        'current_balance' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
                        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ];
                    foreach ($masterCols as $col => $def) {
                        if (!in_array($col, $existingCols)) {
                            try { $this->connection->exec("ALTER TABLE cash_accounts ADD COLUMN `$col` $def"); }
                            catch (PDOException $e) {}
                        }
                    }
                }
            } else {
            // Get existing tables
            $tables = $this->connection->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            // ---- Required tables (CREATE IF NOT EXISTS) ----
            $requiredTables = [
                'settings' => "CREATE TABLE IF NOT EXISTS settings (
                    id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT,
                    setting_type VARCHAR(20) DEFAULT 'string', description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                'divisions' => "CREATE TABLE IF NOT EXISTS divisions (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_code VARCHAR(20), division_name VARCHAR(100) NOT NULL,
                    description TEXT, division_type ENUM('income','expense','both') DEFAULT 'both', is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                'categories' => "CREATE TABLE IF NOT EXISTS categories (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_id INT, category_name VARCHAR(100) NOT NULL,
                    category_type ENUM('income','expense') DEFAULT 'income', description TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                'cash_book' => "CREATE TABLE IF NOT EXISTS cash_book (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), transaction_date DATE NOT NULL, transaction_time TIME,
                    division_id INT, category_id INT, category_name VARCHAR(100), description TEXT,
                    transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                    payment_method VARCHAR(30) DEFAULT 'cash', cash_account_id INT, notes TEXT,
                    attachment VARCHAR(255), created_by INT, shift VARCHAR(20),
                    source_type VARCHAR(30) DEFAULT 'manual', source_id INT NULL, reference_no VARCHAR(50) NULL,
                    is_editable TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                'users' => "CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL,
                    full_name VARCHAR(100), email VARCHAR(100), phone VARCHAR(20),
                    role ENUM('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff',
                    role_id INT, business_access TEXT, is_active TINYINT(1) DEFAULT 1,
                    last_login DATETIME, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                'roles' => "CREATE TABLE IF NOT EXISTS roles (
                    id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL, role_code VARCHAR(30),
                    description TEXT, is_system_role TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                'rooms' => "CREATE TABLE IF NOT EXISTS rooms (
                    id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(10) NOT NULL, room_type_id INT,
                    floor INT DEFAULT 1, status ENUM('available','occupied','maintenance','blocked') DEFAULT 'available',
                    price_per_night DECIMAL(15,2) DEFAULT 0, description TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                'room_types' => "CREATE TABLE IF NOT EXISTS room_types (
                    id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(50) NOT NULL, base_price DECIMAL(15,2) DEFAULT 0,
                    max_occupancy INT DEFAULT 2, description TEXT, amenities TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                'bookings' => "CREATE TABLE IF NOT EXISTS bookings (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_code VARCHAR(20) UNIQUE, guest_name VARCHAR(100) NOT NULL,
                    guest_phone VARCHAR(20), guest_email VARCHAR(100), room_id INT, room_type_id INT,
                    check_in DATE NOT NULL, check_out DATE NOT NULL, nights INT DEFAULT 1,
                    adults INT DEFAULT 1, children INT DEFAULT 0, rate_per_night DECIMAL(15,2) DEFAULT 0,
                    total_amount DECIMAL(15,2) DEFAULT 0, paid_amount DECIMAL(15,2) DEFAULT 0,
                    status ENUM('confirmed','checked_in','checked_out','cancelled','no_show') DEFAULT 'confirmed',
                    source VARCHAR(50) DEFAULT 'walk-in', notes TEXT, created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                'suppliers' => "CREATE TABLE IF NOT EXISTS suppliers (
                    id INT AUTO_INCREMENT PRIMARY KEY, supplier_name VARCHAR(100) NOT NULL, contact_person VARCHAR(100),
                    phone VARCHAR(20), email VARCHAR(100), address TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
            ];
            
            foreach ($requiredTables as $tbl => $sql) {
                if (!in_array($tbl, $tables)) {
                    $this->connection->exec($sql);
                }
            }
            
            // ---- Required columns on existing tables (ALTER TABLE ADD) ----
            $requiredColumns = [
                'settings' => [
                    'setting_type' => "VARCHAR(20) DEFAULT 'string'",
                    'description' => "TEXT NULL",
                    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                ],
                'cash_book' => [
                    'cash_account_id' => "INT NULL",
                    'shift' => "VARCHAR(20) NULL",
                    'branch_id' => "VARCHAR(50) NULL",
                    'transaction_time' => "TIME NULL",
                    'notes' => "TEXT NULL",
                    'attachment' => "VARCHAR(255) NULL",
                    'source_type' => "VARCHAR(30) DEFAULT 'manual'",
                    'source_id' => "INT NULL",
                    'reference_no' => "VARCHAR(50) NULL",
                    'is_editable' => "TINYINT(1) DEFAULT 1",
                    'category_name' => "VARCHAR(100) NULL",
                ],
                'divisions' => [
                    'branch_id' => "VARCHAR(50) NULL",
                    'division_code' => "VARCHAR(20) NULL",
                    'division_type' => "ENUM('income','expense','both') DEFAULT 'both'",
                ],
                'categories' => [
                    'branch_id' => "VARCHAR(50) NULL",
                ],
                'users' => [
                    'role_id' => "INT NULL",
                    'business_access' => "TEXT NULL",
                ],
            ];
            
            foreach ($requiredColumns as $tbl => $cols) {
                if (!in_array($tbl, $tables)) continue; // newly created tables already have all cols
                
                $existingCols = $this->connection->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($cols as $col => $def) {
                    if (!in_array($col, $existingCols)) {
                        try {
                            $this->connection->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
                        } catch (PDOException $e) {
                            // Column might already exist under race condition, ignore
                        }
                    }
                }
            }
            } // end else (business DB)
            
            // Mark as synced for this session
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$sessionKey] = time();
            }
        } catch (PDOException $e) {
            error_log("autoSyncSchema error for $dbName: " . $e->getMessage());
        }
    }

    /**
     * Get Database Instance
     * @param bool $forceNew Force new connection (for switching databases)
     */
    public static function getInstance($forceNew = false) {
        if (self::$instance === null || $forceNew) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get current database name
     */
    public static function getCurrentDatabase() {
        return self::$currentDatabase;
    }

    /**
     * Switch to different database
     * @param string $dbName Database name to switch to
     */
    public static function switchDatabase($dbName) {
        self::$instance = new self($dbName);
        return self::$instance;
    }

    /**
     * Get PDO Connection
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Execute Query
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch Single Row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }

    /**
     * Fetch All Rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Insert Data
     */
    public function insert($table, $data) {
        $fields = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return $this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log("Insert Error in table {$table}: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Data: " . print_r($data, true));
            throw $e; // Re-throw untuk ditangkap di level atas
        }
    }

    /**
     * Update Data
     */
    public function update($table, $data, $where, $whereParams = []) {
        $fields = [];
        foreach (array_keys($data) as $field) {
            $fields[] = "{$field} = :{$field}";
        }
        $fields = implode(', ', $fields);
        $sql = "UPDATE {$table} SET {$fields} WHERE {$where}";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(array_merge($data, $whereParams));
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Update Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete Data
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Delete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Begin Transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction() {
        return $this->connection->inTransaction();
    }

    /**
     * Commit Transaction
     */
    public function commit() {
        return $this->connection->commit();
    }

    /**
     * Rollback Transaction
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
}