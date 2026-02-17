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
    $bizId = isset($_GET['biz_id']) ? (int)$_GET['biz_id'] : null;
    
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    $lastMonth = date('Y-m', strtotime('-1 month'));
    
    // Master DB connection for cash_accounts
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;
    
    if ($bizDb) {
        // SINGLE BUSINESS MODE - query specific database
        $actualDbName = function_exists('getDbName') ? getDbName($bizDb) : $bizDb;
        
        $bizPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $actualDbName, DB_USER, DB_PASS);
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
        $activeDbName = $actualDbName;
        
        // Exclude owner capital
        $excludeOwnerCapital = '';
        try {
            $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $masterDbName, DB_USER, DB_PASS);
            $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if ($bizId) {
                $ocStmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE account_type = 'owner_capital' AND business_id = ?");
                $ocStmt->execute([$bizId]);
            } else {
                $ocStmt = $masterPdo->query("SELECT id FROM cash_accounts WHERE account_type = 'owner_capital'");
            }
            $ownerCapitalIds = $ocStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($ownerCapitalIds)) {
                $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalIds) . "))";
            }
        } catch (Exception $e) {}
        
        // Query single business
        $todayIncome = (float)($db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND transaction_date = ?" . $excludeOwnerCapital, [$today])['total'] ?? 0);
        $todayExpense = (float)($db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'expense' AND transaction_date = ?", [$today])['total'] ?? 0);
        $monthIncome = (float)($db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeOwnerCapital, [$thisMonth])['total'] ?? 0);
        $monthExpense = (float)($db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$thisMonth])['total'] ?? 0);
        $lastMonthIncome = (float)($db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeOwnerCapital, [$lastMonth])['total'] ?? 0);
        $lastMonthExpense = (float)($db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$lastMonth])['total'] ?? 0);
        
    } else {
        // ALL BUSINESSES MODE - aggregate from all business databases
        $activeDbName = 'ALL';
        $todayIncome = 0; $todayExpense = 0;
        $monthIncome = 0; $monthExpense = 0;
        $lastMonthIncome = 0; $lastMonthExpense = 0;
        
        // Get all businesses
        $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $masterDbName, DB_USER, DB_PASS);
        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $allBiz = $masterPdo->query("SELECT id, database_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all owner capital IDs
        $allOcIds = $masterPdo->query("SELECT id FROM cash_accounts WHERE account_type = 'owner_capital'")->fetchAll(PDO::FETCH_COLUMN);
        $excludeOC = '';
        if (!empty($allOcIds)) {
            $excludeOC = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $allOcIds) . "))";
        }
        
        foreach ($allBiz as $biz) {
            try {
                $dbName = function_exists('getDbName') ? getDbName($biz['database_name']) : $biz['database_name'];
                $bPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName, DB_USER, DB_PASS);
                $bPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $r = $bPdo->query("SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type='income' AND transaction_date='$today' THEN amount ELSE 0 END),0) as ti,
                    COALESCE(SUM(CASE WHEN transaction_type='expense' AND transaction_date='$today' THEN amount ELSE 0 END),0) as te,
                    COALESCE(SUM(CASE WHEN transaction_type='income' AND DATE_FORMAT(transaction_date,'%Y-%m')='$thisMonth' THEN amount ELSE 0 END),0) as mi,
                    COALESCE(SUM(CASE WHEN transaction_type='expense' AND DATE_FORMAT(transaction_date,'%Y-%m')='$thisMonth' THEN amount ELSE 0 END),0) as me,
                    COALESCE(SUM(CASE WHEN transaction_type='income' AND DATE_FORMAT(transaction_date,'%Y-%m')='$lastMonth' THEN amount ELSE 0 END),0) as li,
                    COALESCE(SUM(CASE WHEN transaction_type='expense' AND DATE_FORMAT(transaction_date,'%Y-%m')='$lastMonth' THEN amount ELSE 0 END),0) as le
                    FROM cash_book WHERE 1=1" . $excludeOC)->fetch(PDO::FETCH_ASSOC);
                
                // Income uses excludeOC, but expense should NOT exclude. Re-query expense separately
                $re = $bPdo->query("SELECT 
                    COALESCE(SUM(CASE WHEN transaction_date='$today' THEN amount ELSE 0 END),0) as te,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(transaction_date,'%Y-%m')='$thisMonth' THEN amount ELSE 0 END),0) as me,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(transaction_date,'%Y-%m')='$lastMonth' THEN amount ELSE 0 END),0) as le
                    FROM cash_book WHERE transaction_type='expense'")->fetch(PDO::FETCH_ASSOC);
                
                $todayIncome += (float)($r['ti'] ?? 0);
                $todayExpense += (float)($re['te'] ?? 0);
                $monthIncome += (float)($r['mi'] ?? 0);
                $monthExpense += (float)($re['me'] ?? 0);
                $lastMonthIncome += (float)($r['li'] ?? 0);
                $lastMonthExpense += (float)($re['le'] ?? 0);
            } catch (Exception $e) {
                // Skip errored database
            }
        }
    }
    
    // CASH ACCOUNTS BALANCES - calculated dynamically from cash_book (same as system dashboard)
    $pettyCash = 0;
    $bankBalance = 0;
    $ownerCapital = 0;
    $cashAccounts = [];
    
    try {
        if (!isset($masterPdo)) {
            $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $masterDbName, DB_USER, DB_PASS);
            $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        // Function to calculate cash balance from cash_book transactions (like system dashboard)
        $calcCashBalance = function($bizPdoConn, $accountIds, $month) {
            if (empty($accountIds)) return ['received' => 0, 'used' => 0, 'balance' => 0];
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $stmt = $bizPdoConn->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as received,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as used,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                     SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
                FROM cash_book 
                WHERE cash_account_id IN ($placeholders)
                AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
            ");
            $params = array_merge($accountIds, [$month]);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        };
        
        if ($bizId) {
            // SINGLE BUSINESS - get account IDs by type
            $getIds = function($type) use ($masterPdo, $bizId) {
                $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = ? AND is_active = 1");
                $stmt->execute([$bizId, $type]);
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            };
            
            $capitalIds = $getIds('owner_capital');
            $cashIds = $getIds('cash');
            $bankIds = $getIds('bank');
            
            // Connect to the business DB for cash_book queries
            if ($bizDb) {
                $calcDbName = function_exists('getDbName') ? getDbName($bizDb) : $bizDb;
            } else {
                // Fallback: get database_name from businesses table
                $bizStmt = $masterPdo->prepare("SELECT database_name FROM businesses WHERE id = ?");
                $bizStmt->execute([$bizId]);
                $bizRow = $bizStmt->fetch(PDO::FETCH_ASSOC);
                $calcDbName = $bizRow ? (function_exists('getDbName') ? getDbName($bizRow['database_name']) : $bizRow['database_name']) : $masterDbName;
            }
            $calcPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $calcDbName, DB_USER, DB_PASS);
            $calcPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $capitalStats = $calcCashBalance($calcPdo, $capitalIds, $thisMonth);
            $cashStats = $calcCashBalance($calcPdo, $cashIds, $thisMonth);
            $bankStats = $calcCashBalance($calcPdo, $bankIds, $thisMonth);
            
            $ownerCapital = (float)($capitalStats['balance'] ?? 0);
            $pettyCash = (float)($cashStats['balance'] ?? 0);
            $bankBalance = (float)($bankStats['balance'] ?? 0);
            
            $cashAccounts = [
                ['account_type' => 'cash', 'account_name' => 'Petty Cash', 'current_balance' => $pettyCash, 'received' => (float)($cashStats['received'] ?? 0), 'used' => (float)($cashStats['used'] ?? 0)],
                ['account_type' => 'bank', 'account_name' => 'Bank', 'current_balance' => $bankBalance, 'received' => (float)($bankStats['received'] ?? 0), 'used' => (float)($bankStats['used'] ?? 0)],
                ['account_type' => 'owner_capital', 'account_name' => 'Owner Capital', 'current_balance' => $ownerCapital, 'received' => (float)($capitalStats['received'] ?? 0), 'used' => (float)($capitalStats['used'] ?? 0)]
            ];
        } else {
            // ALL BUSINESSES - aggregate from all
            $allBizList = $masterPdo->query("SELECT id, database_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($allBizList as $b) {
                try {
                    $bDbName = function_exists('getDbName') ? getDbName($b['database_name']) : $b['database_name'];
                    $bPdoCalc = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $bDbName, DB_USER, DB_PASS);
                    $bPdoCalc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Get account IDs for this business
                    $capIds = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital' AND is_active = 1");
                    $capIds->execute([$b['id']]);
                    $capitalStats = $calcCashBalance($bPdoCalc, $capIds->fetchAll(PDO::FETCH_COLUMN), $thisMonth);
                    
                    $cIds = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' AND is_active = 1");
                    $cIds->execute([$b['id']]);
                    $cashStats = $calcCashBalance($bPdoCalc, $cIds->fetchAll(PDO::FETCH_COLUMN), $thisMonth);
                    
                    $bIds = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' AND is_active = 1");
                    $bIds->execute([$b['id']]);
                    $bankStats = $calcCashBalance($bPdoCalc, $bIds->fetchAll(PDO::FETCH_COLUMN), $thisMonth);
                    
                    $ownerCapital += (float)($capitalStats['balance'] ?? 0);
                    $pettyCash += (float)($cashStats['balance'] ?? 0);
                    $bankBalance += (float)($bankStats['balance'] ?? 0);
                } catch (Exception $e) {
                    // Skip errored business
                }
            }
            
            $cashAccounts = [
                ['account_type' => 'cash', 'account_name' => 'Petty Cash', 'current_balance' => $pettyCash],
                ['account_type' => 'bank', 'account_name' => 'Bank', 'current_balance' => $bankBalance],
                ['account_type' => 'owner_capital', 'account_name' => 'Owner Capital', 'current_balance' => $ownerCapital]
            ];
        }
    } catch (Exception $e) {
        // Skip cash accounts on error
    }
    
    // Total operational cash = Petty Cash + Owner Capital balance
    $totalCash = $pettyCash + $ownerCapital;
    
    echo json_encode([
        'success' => true,
        'todayIncome' => (float)$todayIncome,
        'todayExpense' => (float)$todayExpense,
        'monthIncome' => (float)$monthIncome,
        'monthExpense' => (float)$monthExpense,
        'pettyCash' => $pettyCash,
        'bankBalance' => $bankBalance,
        'ownerCapital' => $ownerCapital,
        'totalCash' => $totalCash,
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
