<?php
/**
 * API: Owner Statistics - Simple Version
 * Direct query to current database (no multi-tenant)
 */

// Use same session name as main app
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Auth check - try session role first, fallback to logged_in user
$role = $_SESSION['role'] ?? null;
if (!$role && isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    // User logged in but role not in session - try to fetch from DB
    try {
        $authDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $roleStmt = $authDb->prepare("SELECT r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $roleStmt->execute([$_SESSION['user_id'] ?? 0]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $role = $roleRow['role_code'];
            $_SESSION['role'] = $role; // cache for next time
        }
    } catch (Exception $e) {}
}

if (!$role || !in_array($role, ['owner', 'admin', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Check if specific business database requested
    $bizDb = isset($_GET['db']) ? $_GET['db'] : null;
    
    if ($bizDb) {
        // Connect to specific business database
        $bizPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $bizDb, DB_USER, DB_PASS);
        $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db = new class($bizPdo) {
            private $pdo;
            public function __construct($pdo) { $this->pdo = $pdo; }
            public function fetchOne($sql, $params = []) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            public function fetchAll($sql, $params = []) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        };
        $activeDbName = $bizDb;
    } else {
        $db = Database::getInstance();
        $activeDbName = DB_NAME;
    }
    
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    $lastMonth = date('Y-m', strtotime('-1 month'));
    
    // Exclude Owner Capital from income calculations (same as system dashboard)
    $excludeOwnerCapital = '';
    try {
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;
        $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $masterDbName, DB_USER, DB_PASS);
        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Find owner capital account IDs for current business
        $ocStmt = $masterPdo->query("SELECT id FROM cash_accounts WHERE account_type = 'owner_capital'");
        $ownerCapitalIds = $ocStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($ownerCapitalIds)) {
            $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalIds) . "))";
        }
    } catch (Exception $e) {
        // Continue without exclusion
    }
    
    // TODAY's transactions
    $todayIncomeResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND transaction_date = ?" . $excludeOwnerCapital,
        [$today]
    );
    $todayIncome = $todayIncomeResult['total'] ?? 0;
    
    $todayExpenseResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND transaction_date = ?",
        [$today]
    );
    $todayExpense = $todayExpenseResult['total'] ?? 0;
    
    // THIS MONTH's transactions
    $monthIncomeResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeOwnerCapital,
        [$thisMonth]
    );
    $monthIncome = $monthIncomeResult['total'] ?? 0;
    
    $monthExpenseResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        [$thisMonth]
    );
    $monthExpense = $monthExpenseResult['total'] ?? 0;
    
    // LAST MONTH's transactions (for comparison)
    $lastMonthIncomeResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeOwnerCapital,
        [$lastMonth]
    );
    $lastMonthIncome = $lastMonthIncomeResult['total'] ?? 0;
    
    $lastMonthExpenseResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        [$lastMonth]
    );
    $lastMonthExpense = $lastMonthExpenseResult['total'] ?? 0;
    
    // CASH ACCOUNTS BALANCES
    $cashAccounts = $db->fetchAll(
        "SELECT id, account_name, account_type, current_balance 
         FROM cash_accounts WHERE is_active = 1 ORDER BY account_type, account_name"
    );
    
    $pettyCash = 0;
    $bankBalance = 0;
    $ownerCapital = 0;
    
    foreach ($cashAccounts as $acc) {
        if ($acc['account_type'] === 'cash') {
            $pettyCash += (float)$acc['current_balance'];
        } elseif ($acc['account_type'] === 'bank') {
            $bankBalance += (float)$acc['current_balance'];
        } elseif ($acc['account_type'] === 'owner_capital') {
            $ownerCapital += (float)$acc['current_balance'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'todayIncome' => (float)$todayIncome,
        'todayExpense' => (float)$todayExpense,
        'monthIncome' => (float)$monthIncome,
        'monthExpense' => (float)$monthExpense,
        'pettyCash' => $pettyCash,
        'bankBalance' => $bankBalance,
        'ownerCapital' => $ownerCapital,
        'cashAccounts' => $cashAccounts,
        'lastMonth' => [
            'income' => (float)$lastMonthIncome,
            'expense' => (float)$lastMonthExpense
        ],
        'debug' => [
            'today' => $today,
            'thisMonth' => $thisMonth,
            'lastMonth' => $lastMonth,
            'database' => $activeDbName
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
