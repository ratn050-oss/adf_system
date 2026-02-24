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
        } catch (PDOException $e) {
            die("Database Connection Error to '{$dbName}': " . $e->getMessage());
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