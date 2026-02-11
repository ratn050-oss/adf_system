<?php
/**
 * API: Owner Statistics
 * Get today and monthly statistics
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();

// Check if user is owner, admin, manager, or developer
if (!in_array($currentUser['role'], ['owner', 'admin', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get selected business
$branchId = isset($_GET['branch_id']) ? $_GET['branch_id'] : 'all';

try {
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    $lastMonth = date('Y-m', strtotime('-1 month'));
    
    // Get businesses list with their database names
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($branchId === 'all' || $branchId === '') {
        $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $mainPdo->prepare("SELECT id, business_name, database_name FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$branchId]);
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Initialize variables
    $todayIncome = 0;
    $todayExpense = 0;
    $todayIncomeCount = 0;
    $todayExpenseCount = 0;
    $monthIncome = 0;
    $monthExpense = 0;
    $lastMonthIncome = 0;
    $lastMonthExpense = 0;
    
    // Loop through each business database and aggregate
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // TODAY STATS
            $stmt = $bizPdo->prepare(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                    COUNT(CASE WHEN transaction_type = 'income' THEN 1 END) as income_count,
                    COUNT(CASE WHEN transaction_type = 'expense' THEN 1 END) as expense_count
                 FROM cash_book 
                 WHERE transaction_date = ?"
            );
            $stmt->execute([$today]);
            $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($todayStats) {
                $todayIncome += (float)$todayStats['income'];
                $todayExpense += (float)$todayStats['expense'];
                $todayIncomeCount += (int)$todayStats['income_count'];
                $todayExpenseCount += (int)$todayStats['expense_count'];
            }
            
            // THIS MONTH STATS
            $stmt = $bizPdo->prepare(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
                 FROM cash_book 
                 WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?"
            );
            $stmt->execute([$thisMonth]);
            $monthStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($monthStats) {
                $monthIncome += (float)$monthStats['income'];
                $monthExpense += (float)$monthStats['expense'];
            }
            
            // LAST MONTH STATS
            $stmt = $bizPdo->prepare(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
                 FROM cash_book 
                 WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?"
            );
            $stmt->execute([$lastMonth]);
            $lastMonthStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastMonthStats) {
                $lastMonthIncome += (float)$lastMonthStats['income'];
                $lastMonthExpense += (float)$lastMonthStats['expense'];
            }
        } catch (Exception $e) {
            // Skip this business if database doesn't exist or table missing
            continue;
        }
    }
    
    // Calculate change percentages
    $incomeChange = 0;
    $expenseChange = 0;
    
    if ($lastMonthIncome > 0) {
        $incomeChange = (($monthIncome - $lastMonthIncome) / $lastMonthIncome) * 100;
    }
    
    if ($lastMonthExpense > 0) {
        $expenseChange = (($monthExpense - $lastMonthExpense) / $lastMonthExpense) * 100;
    }
    
    echo json_encode([
        'success' => true,
        'today' => [
            'income' => $todayIncome,
            'expense' => $todayExpense,
            'income_count' => $todayIncomeCount,
            'expense_count' => $todayExpenseCount,
            'net' => $todayIncome - $todayExpense
        ],
        'month' => [
            'income' => $monthIncome,
            'expense' => $monthExpense,
            'net' => $monthIncome - $monthExpense,
            'income_change' => round($incomeChange, 1),
            'expense_change' => round($expenseChange, 1)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
