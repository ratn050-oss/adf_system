<?php
/**
 * CQC Projects Database Helper
 * Handles database connections with environment detection
 * Untuk localhost: adf_cqc
 * Untuk hosting: adfb2574_cqc
 */

function getCQCDatabaseConnection() {
    // Detect environment
    $isLocalhost = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false);
    
    $dbHost = 'localhost';
    $dbUser = $isLocalhost ? 'root' : 'adfb2574_adfsystem';
    $dbPass = $isLocalhost ? '' : '@Nnoc2025';
    $dbName = $isLocalhost ? 'adf_cqc' : 'adfb2574_cqc';
    
    try {
        // First connect without database to create it if needed
        $pdo = new PDO(
            "mysql:host=" . $dbHost,
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS " . $dbName . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Now connect to the specific database
        $pdo = new PDO(
            "mysql:host=" . $dbHost . ";dbname=" . $dbName . ";charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (Exception $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

function isLocalhost() {
    return (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false);
}

function getCQCDatabaseName() {
    return isLocalhost() ? 'adf_cqc' : 'adfb2574_cqc';
}
