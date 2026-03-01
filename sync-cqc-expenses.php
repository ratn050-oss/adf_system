<?php
/**
 * Sync CQC Project Expenses to Main Cashbook
 * This script syncs all expenses from cqc_project_expenses to cash_book table
 * Both tables are in the CQC database (adf_cqc)
 * Run via browser or CLI
 */

// Detect localhost
$isLocalhost = true;
if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false) {
    $isLocalhost = false;
}

// Database credentials
$dbHost = 'localhost';
$dbUser = $isLocalhost ? 'root' : 'adfb2574_adfsystem';
$dbPass = $isLocalhost ? '' : '@Nnoc2025';
$cqcDbName = $isLocalhost ? 'adf_cqc' : 'adfb2574_cqc';

echo "<h2>Syncing CQC Expenses to Cashbook</h2>";
echo "<p>CQC Database: <strong>{$cqcDbName}</strong></p>";
echo "<p><em>All data is within the CQC database</em></p>";

try {
    // Connect to CQC database (contains both cqc_project_expenses AND cash_book)
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$cqcDbName};charset=utf8mb4",
        $dbUser,
        $dbPass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if cash_book table exists, if not create it
    $hasCashBook = $pdo->query("SHOW TABLES LIKE 'cash_book'")->rowCount() > 0;
    if (!$hasCashBook) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cash_book (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_date DATE NOT NULL,
                transaction_time TIME NOT NULL,
                division_id INT NOT NULL DEFAULT 1,
                category_id INT NOT NULL DEFAULT 1,
                transaction_type ENUM('income', 'expense') NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                description TEXT,
                reference_no VARCHAR(50),
                receipt_number VARCHAR(50),
                payment_method ENUM('cash','debit','transfer','qr','other') DEFAULT 'cash',
                source_type VARCHAR(50) DEFAULT 'manual',
                is_editable TINYINT(1) DEFAULT 1,
                created_by INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                cash_account_id INT DEFAULT NULL,
                INDEX idx_date (transaction_date),
                INDEX idx_type (transaction_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "<p>✅ Created cash_book table</p>";
    }
    
    // Check if divisions table exists, if not create it
    $hasDivisions = $pdo->query("SHOW TABLES LIKE 'divisions'")->rowCount() > 0;
    if (!$hasDivisions) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS divisions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                division_name VARCHAR(100) NOT NULL,
                division_code VARCHAR(20),
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "<p>✅ Created divisions table</p>";
    }
    
    // Check if categories table exists, if not create it
    $hasCategories = $pdo->query("SHOW TABLES LIKE 'categories'")->rowCount() > 0;
    if (!$hasCategories) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_name VARCHAR(100) NOT NULL,
                category_type ENUM('income','expense') DEFAULT 'expense',
                division_id INT DEFAULT 1,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "<p>✅ Created categories table</p>";
    }
    
    // Get all CQC projects
    $projects = $pdo->query("SELECT id, project_code, project_name FROM cqc_projects ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Found <strong>" . count($projects) . "</strong> projects</p>";
    
    // Get all expense categories from CQC
    $categories = $pdo->query("SELECT id, category_name FROM cqc_expense_categories")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get or create CQC division
    $stmt = $pdo->query("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%cqc%' OR LOWER(division_name) LIKE '%proyek%' LIMIT 1");
    $cqcDivision = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cqcDivision) {
        $pdo->exec("INSERT INTO divisions (division_name, division_code, is_active) VALUES ('CQC Projects', 'CQC', 1)");
        $divisionId = $pdo->lastInsertId();
        echo "<p>✅ Created CQC division (ID: {$divisionId})</p>";
    } else {
        $divisionId = $cqcDivision['id'];
        echo "<p>✓ Using existing CQC division (ID: {$divisionId})</p>";
    }
    
    // Get all expenses from CQC database
    $expenses = $pdo->query("
        SELECT e.*, p.project_code 
        FROM cqc_project_expenses e
        JOIN cqc_projects p ON e.project_id = p.id
        ORDER BY e.expense_date, e.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found <strong>" . count($expenses) . "</strong> expenses to check</p>";
    
    $synced = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($expenses as $expense) {
        $projectCode = $expense['project_code'];
        $projectId = $expense['project_id'];
        $catName = $categories[$expense['category_id']] ?? 'CQC Expense';
        
        // Check if already synced (look for CQC_PROJECT marker in description)
        $marker = "[CQC_PROJECT:{$projectId}]";
        $stmtCheck = $pdo->prepare("SELECT id FROM cash_book WHERE description LIKE :marker AND transaction_date = :date AND amount = :amount LIMIT 1");
        $stmtCheck->execute([
            'marker' => "%{$marker}%",
            'date' => $expense['expense_date'],
            'amount' => $expense['amount']
        ]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $skipped++;
            continue;
        }
        
        // Get or create category
        $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE LOWER(category_name) = LOWER(:name) AND category_type = 'expense' LIMIT 1");
        $stmtCat->execute(['name' => $catName]);
        $mainCat = $stmtCat->fetch(PDO::FETCH_ASSOC);
        if (!$mainCat) {
            $pdo->exec("INSERT INTO categories (category_name, category_type, division_id, is_active) VALUES ('" . addslashes($catName) . "', 'expense', {$divisionId}, 1)");
            $catId = $pdo->lastInsertId();
        } else {
            $catId = $mainCat['id'];
        }
        
        // Build description
        $fullDesc = "[CQC_PROJECT:{$projectId}] [{$projectCode}] " . ($expense['description'] ?? '');
        
        try {
            $pdo->exec("
                INSERT INTO cash_book 
                (transaction_date, transaction_time, division_id, category_id, transaction_type, amount, description, payment_method, source_type, is_editable, created_by)
                VALUES (
                    '{$expense['expense_date']}',
                    '" . date('H:i:s', strtotime($expense['created_at'] ?? 'now')) . "',
                    {$divisionId},
                    {$catId},
                    'expense',
                    {$expense['amount']},
                    '" . addslashes($fullDesc) . "',
                    'cash',
                    'cqc_project',
                    1,
                    " . ($expense['created_by'] ?? 1) . "
                )
            ");
            $synced++;
        } catch (Exception $e) {
            $errors++;
            echo "<p style='color:red'>Error syncing expense ID {$expense['id']}: {$e->getMessage()}</p>";
        }
    }
    
    echo "<hr>";
    echo "<h3>Sync Results</h3>";
    echo "<ul>";
    echo "<li><strong style='color:green'>Synced:</strong> {$synced} expenses</li>";
    echo "<li><strong>Skipped (already synced):</strong> {$skipped} expenses</li>";
    echo "<li><strong style='color:red'>Errors:</strong> {$errors}</li>";
    echo "</ul>";
    
    if ($synced > 0) {
        echo "<p style='color:green; font-weight:bold'>✅ Successfully synced {$synced} CQC expenses to main cashbook!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
