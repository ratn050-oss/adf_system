<?php
/**
 * PUBLIC WEBSITE - Database Connection
 */

if (!defined('PUBLIC_ACCESS')) exit;

class PublicDatabase {
    private static $instance = null;
    private $connection = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        try {
            // Use the final database name determined in config
            $dbName = DB_NAME_FINAL;
            
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=" . DB_CHARSET;
            
            $this->connection = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            return null;
        }
    }
    
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }
    
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetch() : null;
    }
    
    public function insert($table, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = "INSERT INTO " . $table . " (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
        
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(array_values($data));
            return $this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log("Insert error: " . $e->getMessage());
            throw new Exception("Insert failed: " . $e->getMessage());
        }
    }
    
    public function update($table, $data, $where) {
        $setClauses = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setClauses[] = $column . " = ?";
            $values[] = $value;
        }
        
        $whereConditions = [];
        foreach ($where as $column => $value) {
            $whereConditions[] = $column . " = ?";
            $values[] = $value;
        }
        
        $sql = "UPDATE " . $table . " SET " . implode(', ', $setClauses) . " WHERE " . implode(' AND ', $whereConditions);
        
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($values);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Update error: " . $e->getMessage());
            throw new Exception("Update failed: " . $e->getMessage());
        }
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollBack();
    }
}
?>
