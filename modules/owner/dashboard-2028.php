<?php
/**
 * OWNER DASHBOARD 2028
 * Data langsung dari PHP - Same logic as System Dashboard (index.php)
 * Multi-business aware via business_helper.php
 * @version 2.1.0 - 2026-03-01 - Pie: Income=Green, Expense=Red, Profit=Yellow
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/business_helper.php';

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

// Auth check
$role = $_SESSION['role'] ?? null;
if (!$role && isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        $authDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $roleStmt = $authDb->prepare("SELECT r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $roleStmt->execute([$_SESSION['user_id'] ?? 0]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $role = $roleRow['role_code'];
            $_SESSION['role'] = $role;
        }
    } catch (Exception $e) {}
}

if (!$role || !in_array($role, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}
$userName = $_SESSION['username'] ?? 'Owner';
$isDev = ($role === 'developer');

// BUSINESS SWITCHER - handle switch request
if (isset($_GET['business']) && !empty($_GET['business'])) {
    setActiveBusinessId($_GET['business']);
    header('Location: ' . $basePath . '/modules/owner/dashboard-2028.php');
    exit;
}

// Get all available businesses & active config
require_once __DIR__ . '/../../includes/business_access.php';
$allBusinesses = getUserAvailableBusinesses();
$activeBusinessId = getActiveBusinessId();

// If current active business is not in user's allowed list, auto-switch to first allowed
if (!empty($allBusinesses) && !isset($allBusinesses[$activeBusinessId])) {
    $firstAllowed = array_key_first($allBusinesses);
    setActiveBusinessId($firstAllowed);
    $activeBusinessId = $firstAllowed;
}

$activeConfig = getActiveBusinessConfig();

// DATABASE CONFIG - dynamic from business config
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
$businessDbName = getDbName($activeConfig['database'] ?? 'adf_narayana_hotel');
$businessName = $activeConfig['name'] ?? 'Unknown Business';
$businessType = $activeConfig['business_type'] ?? 'other';
$businessIcon = $activeConfig['theme']['icon'] ?? '🏢';
$enabledModules = $activeConfig['enabled_modules'] ?? [];
$hasLogo = !empty($activeConfig['logo']);
$logoFile = $activeBusinessId . '_logo.png';

// Get stats - SAME LOGIC AS SYSTEM DASHBOARD (index.php)
$stats = [
    'today_income' => 0,
    'today_expense' => 0,
    'month_income' => 0,
    'month_expense' => 0,
    'total_transactions' => 0
];

$capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
$pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];
$totalOperationalCash = 0;
$totalOperationalExpense = 0;

$transactions = [];
$error = null;

try {
    // Connect to business database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Connect to master database for cash_accounts
    $masterPdo = new PDO("mysql:host=$dbHost;dbname=$masterDbName;charset=utf8mb4", $dbUser, $dbPass);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    
    // Get numeric business ID from master DB
    $businessId = null;
    $stmt = $masterPdo->prepare("SELECT id FROM businesses WHERE database_name = ? LIMIT 1");
    $stmt->execute([$activeConfig['database'] ?? '']);
    $bizRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $businessId = $bizRow ? (int)$bizRow['id'] : 1;
    
    // Check if cash_account_id column exists in this business's cash_book
    $hasCashAccountId = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
        $hasCashAccountId = ($colCheck && $colCheck->rowCount() > 0);
    } catch (Exception $e) { /* column doesn't exist */ }

    // Get owner_capital account IDs from master DB
    $capitalAccounts = [];
    $pettyCashAccounts = [];
    if ($hasCashAccountId) {
        $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
        $stmt->execute([$businessId]);
        $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get cash (Petty Cash) account IDs from master DB
        $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
        $stmt->execute([$businessId]);
        $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Build exclude owner capital condition for income/expense totals
    $excludeOwnerCapital = '';
    if ($hasCashAccountId && !empty($capitalAccounts)) {
        $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $capitalAccounts) . "))";
    }
    
    // Today Income (exclude owner capital)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$today]);
    $stats['today_income'] = (float)$stmt->fetchColumn();
    
    // Today Expense
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'expense'");
    $stmt->execute([$today]);
    $stats['today_expense'] = (float)$stmt->fetchColumn();
    
    // Month Income (exclude owner capital)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$thisMonth]);
    $stats['month_income'] = (float)$stmt->fetchColumn();
    
    // Month Expense
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'");
    $stmt->execute([$thisMonth]);
    $stats['month_expense'] = (float)$stmt->fetchColumn();
    
    // Query Modal Owner stats (from cash_book with cash_account_id filter)
    if ($hasCashAccountId && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as used,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";
        $params = array_merge($capitalAccounts, [$thisMonth]);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $capitalStats['received'] = (float)($result['received'] ?? 0);
        $capitalStats['used'] = (float)($result['used'] ?? 0);
        $capitalStats['balance'] = (float)($result['balance'] ?? 0);
    } else {
        $capitalStats['received'] = 0;
        $capitalStats['used'] = 0;
        $capitalStats['balance'] = 0;
    }

    // Query Petty Cash stats (Only cash payment method - same as system dashboard)
    if ($hasCashAccountId && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as used,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
            AND payment_method = 'cash'
        ";
        $params = array_merge($pettyCashAccounts, [$thisMonth]);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pettyCashStats['received'] = (float)($result['received'] ?? 0);
        $pettyCashStats['used'] = (float)($result['used'] ?? 0);
        $pettyCashStats['balance'] = (float)($result['balance'] ?? 0);
    } else {
        $pettyCashStats['received'] = 0;
        $pettyCashStats['used'] = 0;
        $pettyCashStats['balance'] = 0;
    }
    
    // TOTAL KAS OPERASIONAL = Petty Cash balance + Modal Owner balance
    $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];
    
    // TOTAL PENGELUARAN OPERASIONAL = Combined expense
    $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];
    
    // ============================================
    // CHART DATA - Expense per Division (for pie chart)
    // ============================================
    $expenseDivisionData = [];
    $stmt = $pdo->prepare("
        SELECT 
            d.division_name,
            d.division_code,
            COALESCE(SUM(cb.amount), 0) as total
        FROM divisions d
        LEFT JOIN cash_book cb ON d.id = cb.division_id 
            AND cb.transaction_type = 'expense'
            AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
        WHERE d.is_active = 1
        GROUP BY d.id, d.division_name, d.division_code
        HAVING total > 0
        ORDER BY total DESC
    ");
    $stmt->execute([$thisMonth]);
    $expenseDivisionData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total transactions
    $stmt = $pdo->query("SELECT COUNT(*) FROM cash_book");
    $stats['total_transactions'] = (int)$stmt->fetchColumn();
    
    // Recent transactions - SAME AS SYSTEM DASHBOARD (include division_name, category_name)
    $stmt = $pdo->query("
        SELECT 
            cb.id, cb.transaction_date, cb.description, cb.transaction_type, cb.amount, cb.payment_method,
            d.division_name,
            c.category_name
        FROM cash_book cb
        LEFT JOIN divisions d ON cb.division_id = d.id
        LEFT JOIN categories c ON cb.category_id = c.id
        ORDER BY cb.transaction_date DESC, cb.id DESC 
        LIMIT 10
    ");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Check if CQC business
$isCQC = (strtolower($activeBusinessId) === 'cqc') || 
         (stripos($activeBusinessId, 'cqc') !== false) ||
         (stripos($businessName ?? '', 'cqc') !== false);

// CQC PROJECT DATA
$cqcProjects = [];
$cqcExpenses = []; // Recent expenses per project
if ($isCQC) {
    try {
        require_once __DIR__ . '/../cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();
        
        // ====== GET CQC FINANCIAL STATS ======
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');
        
        // Try to get income/expense from cash_book if exists
        $hasCashBook = false;
        try {
            $tableCheck = $cqcPdo->query("SHOW TABLES LIKE 'cash_book'");
            $hasCashBook = ($tableCheck && $tableCheck->rowCount() > 0);
        } catch (Exception $e) {}
        
        if ($hasCashBook) {
            // Get stats from cash_book
            try {
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'");
                $stmt->execute([$today]);
                $stats['today_income'] = (float)$stmt->fetchColumn();
                
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'expense'");
                $stmt->execute([$today]);
                $stats['today_expense'] = (float)$stmt->fetchColumn();
                
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'");
                $stmt->execute([$thisMonth]);
                $stats['month_income'] = (float)$stmt->fetchColumn();
                
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'");
                $stmt->execute([$thisMonth]);
                $stats['month_expense'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                // cash_book query failed
            }
        }
        
        // Also add project expenses to stats - use spent_idr directly from projects
        try {
            // Get total spent from all projects for this month (using spent_idr directly)
            $stmt = $cqcPdo->query("SELECT COALESCE(SUM(spent_idr), 0) as total_spent, COALESCE(SUM(budget_idr), 0) as total_budget FROM cqc_projects WHERE status != 'completed'");
            $projTotals = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalProjectSpent = (float)($projTotals['total_spent'] ?? 0);
            $totalProjectBudget = (float)($projTotals['total_budget'] ?? 0);
            
            // For CQC: Income = Total Budget, Expense = Total Spent
            if ($stats['month_income'] == 0) {
                $stats['month_income'] = $totalProjectBudget;
            }
            $stats['month_expense'] += $totalProjectSpent;
            
            // Also try from cqc_project_expenses table
            try {
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cqc_project_expenses WHERE expense_date = ?");
                $stmt->execute([$today]);
                $stats['today_expense'] += (float)$stmt->fetchColumn();
            } catch (Exception $e) {}
        } catch (Exception $e) {
            error_log('CQC stats error: ' . $e->getMessage());
        }
        
        // Simple query - just get projects
        $stmt = $cqcPdo->query("
            SELECT id, project_name, project_code, status, 
                   progress_percentage, budget_idr, spent_idr,
                   client_name, location, solar_capacity_kwp,
                   start_date, estimated_completion, end_date
            FROM cqc_projects 
            ORDER BY status ASC, progress_percentage DESC
        ");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate actual spent from expenses for each project
        foreach ($cqcProjects as &$proj) {
            try {
                // Try amount_idr first, then amount
                $stmtSum = $cqcPdo->prepare("SELECT COALESCE(SUM(amount_idr), 0) as total FROM cqc_project_expenses WHERE project_id = ?");
                $stmtSum->execute([$proj['id']]);
                $sumResult = $stmtSum->fetch(PDO::FETCH_ASSOC);
                if ($sumResult && $sumResult['total'] > 0) {
                    $proj['spent_idr'] = $sumResult['total'];
                }
            } catch (Exception $e) {
                // Try with 'amount' column if 'amount_idr' doesn't exist
                try {
                    $stmtSum = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cqc_project_expenses WHERE project_id = ?");
                    $stmtSum->execute([$proj['id']]);
                    $sumResult = $stmtSum->fetch(PDO::FETCH_ASSOC);
                    if ($sumResult && $sumResult['total'] > 0) {
                        $proj['spent_idr'] = $sumResult['total'];
                    }
                } catch (Exception $e2) {
                    // Keep original spent_idr
                }
            }
        }
        unset($proj);
        
        // Get recent expenses per project
        foreach ($cqcProjects as $proj) {
            $expenses = [];
            try {
                // Try amount_idr column first
                $stmt = $cqcPdo->prepare("
                    SELECT description, amount_idr as amount, expense_date
                    FROM cqc_project_expenses 
                    WHERE project_id = ? 
                    ORDER BY expense_date DESC, id DESC 
                    LIMIT 5
                ");
                $stmt->execute([$proj['id']]);
                $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Try 'amount' column
                try {
                    $stmt = $cqcPdo->prepare("
                        SELECT description, amount, expense_date
                        FROM cqc_project_expenses 
                        WHERE project_id = ? 
                        ORDER BY expense_date DESC, id DESC 
                        LIMIT 5
                    ");
                    $stmt->execute([$proj['id']]);
                    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e2) {
                    // No expenses
                }
            }
            
            $cqcExpenses[$proj['id']] = $expenses;
        }
    } catch (Exception $e) {
        error_log('CQC project data error: ' . $e->getMessage());
    }
}

// Format rupiah
function rp($num) {
    return 'Rp ' . number_format($num, 0, ',', '.');
}

$netProfit = $stats['month_income'] - $stats['month_expense'];
$netToday = $stats['today_income'] - $stats['today_expense'];
$expenseRatio = $stats['month_income'] > 0 ? ($stats['month_expense'] / $stats['month_income']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Owner Dashboard - <?= htmlspecialchars($businessName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --text-primary: #334155;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --accent: #6366f1;
            --accent-light: #818cf8;
            --success: #10b981;
            --success-light: #34d399;
            --danger: #f43f5e;
            --danger-light: #fb7185;
            --warning: #f59e0b;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: 80px;
        }
        
        .container {
            max-width: 100%;
            padding: 16px;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            margin-bottom: 16px;
        }
        
        .brand {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            padding: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .brand-subtext {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        
        .user-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50px;
            font-size: 12px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 6px;
            padding-right: 6px;
        }
        .dev-badge {
            background-color: var(--danger);
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 20px;
        }
        
        .avatar {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Info Card → Business Switcher */
        .info-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .info-card.error {
            background-color: #fff1f2;
            color: var(--danger);
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .info-card-icon {
            font-size: 24px;
        }
        .info-card-content {
            flex: 1;
        }
        .info-card-title {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .info-card-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        /* Business Switcher */
        .biz-switcher {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 2px 0;
        }
        .biz-switcher::-webkit-scrollbar { display: none; }
        .biz-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 12px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
            flex-shrink: 0;
            cursor: pointer;
        }
        .biz-pill:active { transform: scale(0.97); }
        .biz-pill.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            border-color: var(--accent);
            box-shadow: 0 4px 12px rgba(99,102,241,0.25);
        }
        .biz-pill-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }
        .biz-pill.active .biz-pill-icon {
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .biz-pill-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .biz-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .biz-pill-name {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .biz-pill.active .biz-pill-name {
            color: white;
        }
        .biz-pill-type {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: capitalize;
        }
        .biz-pill.active .biz-pill-type {
            color: rgba(255,255,255,0.7);
        }
        }
        
        /* DB Info */
        .db-info {
            background: #f0fdf4;
            color: #166534;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 11px;
            margin-bottom: 16px;
        }
        
        .db-info.error {
            background: #fee2e2;
            color: #dc2626;
        }
        
        /* Hero Section - Digital Premium 2027 */
        .hero {
            background: linear-gradient(160deg, #0f0c29 0%, #302b63 40%, #24243e 100%);
            border-radius: 20px;
            padding: 18px 16px 14px;
            margin-bottom: 16px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 40px rgba(15, 12, 41, 0.5), inset 0 1px 0 rgba(255,255,255,0.08);
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: -80px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(99,102,241,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero::after {
            content: '';
            position: absolute;
            bottom: -40px;
            left: -30px;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(139,92,246,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-title {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.2px;
            margin-bottom: 1px;
        }
        
        .hero-subtitle {
            font-size: 10px;
            opacity: 0.55;
            font-weight: 400;
            letter-spacing: 0.3px;
        }
        
        .hero-date {
            font-size: 10px;
            opacity: 0.5;
        }
        
        /* Chart Container - Compact */
        .chart-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            margin: 14px 0 0 0;
        }
        
        .pie-wrapper {
            position: relative;
            width: 160px;
            height: 160px;
            flex-shrink: 0;
        }
        
        #pieChart {
            filter: drop-shadow(0 0 24px rgba(16, 185, 129, 0.3)) drop-shadow(0 8px 16px rgba(0,0,0,0.4));
        }
        
        .pie-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 2px solid rgba(255,255,255,0.08);
            border-radius: 50%;
            width: 80px;
            height: 80px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.1);
        }
        
        .pie-center-label {
            font-size: 8px;
            opacity: 0.5;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .pie-center-value {
            font-size: 22px;
            font-weight: 800;
            font-family: 'Inter', system-ui, sans-serif;
            letter-spacing: -1px;
        }
        .pie-center-value.positive {
            color: #10b981;
            text-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
        }
        .pie-center-value.negative {
            color: #ef4444;
            text-shadow: 0 0 20px rgba(239, 68, 68, 0.5);
        }
        .pie-center-value.zero {
            color: #9ca3af;
        }
        
        /* Legend - Minimal Compact */
        .legend {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 0;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 6px 10px;
        }
        
        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .legend-dot.income { background: #10b981; }
        .legend-dot.expense { background: #ef4444; }
        .legend-dot.profit { background: #f59e0b; }
        
        .legend-text {
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }
        
        .legend-label {
            font-size: 10px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }
        
        .legend-value {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: -0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #1f2937;
            text-decoration: none;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }
        
        .stat-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .stat-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 700;
        }
        
        .stat-value.income { color: var(--success); }
        .stat-value.expense { color: var(--danger); }
        
        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        /* Operational Section */
        .operational-section {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.8) 0%, rgba(240, 249, 255, 0.5) 100%);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 113, 227, 0.1);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }
        
        .operational-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.3px;
        }
        
        .operational-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .op-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .op-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--gradient-start), var(--gradient-end));
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }
        
        .op-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.08);
            border-color: rgba(0, 113, 227, 0.2);
        }
        
        .op-card:hover::before {
            opacity: 1;
        }
        
        .op-card.modal-owner { --gradient-start: #10b981; --gradient-end: #34d399; }
        .op-card.petty-cash { --gradient-start: #f59e0b; --gradient-end: #fbbf24; }
        .op-card.digunakan { --gradient-start: #f43f5e; --gradient-end: #fb7185; }
        .op-card.total-kas { --gradient-start: #0071e3; --gradient-end: #0055b8; }
        
        .op-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            color: #6c757d;
            margin-bottom: 6px;
        }
        
        .op-value {
            font-size: 16px;
            font-weight: 800;
            color: #1a1a1a;
            font-family: 'Monaco', 'Courier New', monospace;
            line-height: 1.2;
        }
        
        .op-detail-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--primary) 0%, #0055b8 100%);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 113, 227, 0.25);
            cursor: pointer;
            letter-spacing: -0.2px;
        }
        
        .op-detail-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 113, 227, 0.35);
        }
        
        .op-detail-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0, 113, 227, 0.25);
        }
        
        /* AI Health Section */
        .ai-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .ai-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .ai-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .ai-badge {
            background: #f59e0b;
            color: white;
            font-size: 9px;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .ai-title {
            font-size: 14px;
            font-weight: 600;
            color: #92400e;
        }
        
        .ai-score {
            background: white;
            padding: 8px 14px;
            border-radius: 12px;
            text-align: center;
        }
        
        .ai-score-value {
            font-size: 20px;
            font-weight: 700;
            color: <?= $expenseRatio < 50 ? '#10b981' : ($expenseRatio < 70 ? '#f59e0b' : '#f43f5e') ?>;
        }
        
        .ai-score-label {
            font-size: 9px;
            color: #78350f;
            text-transform: uppercase;
        }
        
        .ai-content {
            font-size: 13px;
            color: #78350f;
            line-height: 1.6;
        }
        
        /* Summary Card */
        .summary-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .summary-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .summary-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, var(--accent), var(--accent-light));
            border-radius: 2px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }
        
        .summary-row:last-child { border-bottom: none; }
        
        .summary-row.total {
            font-weight: 700;
            font-size: 15px;
            padding-top: 16px;
            margin-top: 8px;
            border-top: 2px solid var(--border);
            border-bottom: none;
        }
        
        /* Transactions */
        .tx-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .tx-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tx-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, var(--warning), #fbbf24);
            border-radius: 2px;
        }
        
        .tx-list { list-style: none; }
        
        .tx-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
            gap: 10px;
        }
        
        .tx-item:last-child { border-bottom: none; }
        
        .tx-desc {
            font-size: 12.5px;
            color: var(--text-primary);
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            line-height: 1.4;
        }
        
        .tx-date {
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .tx-amount {
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            letter-spacing: -0.2px;
        }
        
        .tx-amount.income { color: var(--success); }
        .tx-amount.expense { color: var(--danger); }
        
        .tx-method {
            display: inline-flex;
            align-items: center;
            font-size: 7.5px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            vertical-align: middle;
            flex-shrink: 0;
        }
        .tx-method.cash { background: #dcfce7; color: #16a34a; }
        .tx-method.transfer, .tx-method.tf { background: #dbeafe; color: #2563eb; }
        .tx-method.qr { background: #fef3c7; color: #d97706; }
        .tx-method.debit, .tx-method.edc { background: #f3e8ff; color: #9333ea; }
        .tx-method.other { background: #f1f5f9; color: #64748b; }
        
        /* Footer Nav */
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 12px rgba(0,0,0,0.05);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 10px;
            color: var(--text-muted);
            transition: color 0.2s;
        }
        
        .nav-item.active { color: var(--accent); }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 2px;
        }
        
        /* Hero Today Row - Clean */
        .hero-today-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 14px;
            gap: 8px;
        }
        .hero-today-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }
        .hero-today-label {
            font-size: 9px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            font-weight: 500;
        }
        .hero-today-value {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #e5e7eb;
        }
        .hero-today-value.income { color: #10b981; }
        .hero-today-value.expense { color: #ef4444; }
        .hero-today-divider {
            width: 1px;
            height: 28px;
            background: rgba(255,255,255,0.1);
        }

        /* Dev Badge */
        .dev-badge {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #f43f5e;
            color: white;
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            z-index: 1000;
        }
        
        /* Expense Division Pie Chart */
        .expense-division-card {
            background: var(--surface);
            border-radius: 14px;
            margin-top: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .expense-division-header {
            padding: 12px 14px 10px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .expense-division-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .expense-division-title .icon-circle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .expense-month-input {
            font-size: 11px;
            padding: 4px 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text-primary);
            outline: none;
            height: 28px;
        }
        .expense-month-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(99,102,241,0.1);
        }
        .expense-division-body {
            position: relative;
            height: 220px;
            padding: 10px 14px 6px;
        }
        .expense-division-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-muted);
            font-size: 12px;
        }
        .expense-division-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            padding: 6px 14px 12px;
            justify-content: center;
        }
        .edl-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            color: var(--text-secondary);
        }
        .edl-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Mobile Optimizations */
        @media (max-width: 380px) {
            .pie-wrapper {
                width: 130px;
                height: 130px;
            }
            .pie-center {
                width: 65px;
                height: 65px;
            }
            .pie-center-value { font-size: 18px; }
            .legend-item { padding: 5px 8px; }
            .legend-value { font-size: 11px; }
            .hero-today-value { font-size: 11px; }
        }
        @media (max-width: 340px) {
            .chart-container {
                flex-direction: column;
                gap: 12px;
            }
            .legend {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 6px;
            }
            .operational-grid {
                grid-template-columns: 1fr;
            }
            .expense-division-body {
                height: 200px;
            }
        }
        
        /* CQC Status Badges */
        .cqc-status-planning { background: #eef2ff; color: #4a6cf7; }
        .cqc-status-procurement { background: #fef3c7; color: #d97706; }
        .cqc-status-installation { background: #dbeafe; color: #2563eb; }
        .cqc-status-testing { background: #fce7f3; color: #db2777; }
        .cqc-status-completed { background: #d1fae5; color: #059669; }
        .cqc-status-on_hold { background: #f3f4f6; color: #6b7280; }
    </style>
</head>
<body>
    <?php if ($isDev): ?>
    <div class="dev-badge">DEV</div>
    <?php endif; ?>
    
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="brand">
                <div class="brand-icon">
                    <?php if (file_exists(__DIR__ . '/../../uploads/logos/' . $logoFile)): ?>
                        <img src="<?= $basePath ?>/uploads/logos/<?= $logoFile ?>" alt="Logo">
                    <?php else: ?>
                        <span style="font-size:22px;display:flex;align-items:center;justify-content:center;width:100%;height:100%"><?= $businessIcon ?></span>
                    <?php endif; ?>
                </div>
                <div class="brand-text">
                    <?= htmlspecialchars($businessName) ?>
                    <span class="brand-subtext">Owner Dashboard</span>
                </div>
            </div>
            <div class="user-badge">
                <div class="avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                <div class="user-info">
                    <?= htmlspecialchars($userName) ?>
                    <?php if($isDev): ?><span class="dev-badge">DEV</span><?php endif; ?>
                </div>
            </div>
        </header>

            <?php if (count($allBusinesses) > 1): ?>
            <!-- Business Switcher -->
            <div class="info-card">
                <div style="font-size:10px;color:var(--text-muted);margin-bottom:6px;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;">Switch Business</div>
                <div class="biz-switcher">
                    <?php foreach ($allBusinesses as $bizId => $biz): 
                        $isActive = ($bizId === $activeBusinessId);
                        $bizLogoFile = $bizId . '_logo.png';
                        $bizLogoExists = file_exists(__DIR__ . '/../../uploads/logos/' . $bizLogoFile);
                    ?>
                    <a href="<?= $basePath ?>/modules/owner/dashboard-2028.php?business=<?= urlencode($bizId) ?>" class="biz-pill <?= $isActive ? 'active' : '' ?>">
                        <div class="biz-pill-icon">
                            <?php if ($bizLogoExists): ?>
                                <img src="<?= $basePath ?>/uploads/logos/<?= $bizLogoFile ?>" alt="">
                            <?php else: ?>
                                <?= $biz['theme']['icon'] ?? '🏢' ?>
                            <?php endif; ?>
                        </div>
                        <div class="biz-pill-text">
                            <span class="biz-pill-name"><?= htmlspecialchars($biz['name']) ?></span>
                            <span class="biz-pill-type"><?= $biz['business_type'] ?? 'business' ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        <?php if ($error): ?>
            <div class="info-card error">
                <div class="info-card-icon">❌</div>
                <div class="info-card-content">
                    <div class="info-card-title">Connection Error</div>
                    <div class="info-card-value"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!$error): ?>
        <!-- Hero with Pie Chart -->
        <div class="hero">
            <div class="hero-content">
                <div class="hero-title">Financial Performance</div>
                <div class="hero-subtitle"><?= date('F Y') ?> &nbsp;·&nbsp; <?= date('d M Y') ?></div>

                <div class="chart-container">
                    <!-- Pie Chart -->
                    <div class="pie-wrapper">
                        <canvas id="pieChart" width="160" height="160"></canvas>
                        <?php 
                        $profitMargin = $stats['month_income'] > 0 
                            ? round((($stats['month_income'] - $stats['month_expense']) / $stats['month_income']) * 100) 
                            : 0;
                        $profitClass = $profitMargin > 0 ? 'positive' : ($profitMargin < 0 ? 'negative' : 'zero');
                        ?>
                        <div class="pie-center">
                            <div class="pie-center-label">Margin</div>
                            <div class="pie-center-value <?= $profitClass ?>"><?= $profitMargin ?>%</div>
                        </div>
                    </div>
                    <!-- Legend -->
                    <div class="legend">
                        <div class="legend-item">
                            <span class="legend-dot income"></span>
                            <div class="legend-text">
                                <span class="legend-label">Income</span>
                                <span class="legend-value"><?= rp($stats['month_income']) ?></span>
                            </div>
                        </div>
                        <div class="legend-item">
                            <span class="legend-dot expense"></span>
                            <div class="legend-text">
                                <span class="legend-label">Expense</span>
                                <span class="legend-value"><?= rp($stats['month_expense']) ?></span>
                            </div>
                        </div>
                        <div class="legend-item">
                            <span class="legend-dot profit"></span>
                            <div class="legend-text">
                                <span class="legend-label">Profit</span>
                                <span class="legend-value"><?= $netProfit >= 0 ? '+' : '' ?><?= rp($netProfit) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today Quick Stats -->
                <div class="hero-today-row">
                    <div class="hero-today-item">
                        <span class="hero-today-label">Today In</span>
                        <span class="hero-today-value income"><?= rp($stats['today_income']) ?></span>
                    </div>
                    <div class="hero-today-divider"></div>
                    <div class="hero-today-item">
                        <span class="hero-today-label">Today Out</span>
                        <span class="hero-today-value expense"><?= rp($stats['today_expense']) ?></span>
                    </div>
                    <div class="hero-today-divider"></div>
                    <div class="hero-today-item">
                        <span class="hero-today-label">Expense Ratio</span>
                        <span class="hero-today-value"><?= number_format($expenseRatio, 1) ?>%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($isCQC): ?>
        <!-- CQC Project Monitoring - Modern 2027 Design -->
        <div style="margin: 14px 0; padding: 18px; background: #ffffff; border-radius: 16px; box-shadow: 0 1px 12px rgba(0,0,0,0.04);">
            <!-- Header -->
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(245, 158, 11, 0.3);">
                    <span style="font-size: 18px;">☀️</span>
                </div>
                <div>
                    <div style="font-size: 9px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px;">CQC Enjiniring</div>
                    <div style="font-size: 15px; font-weight: 600; color: #374151; letter-spacing: -0.3px;">Project Monitoring</div>
                </div>
            </div>
            
            <?php if (empty($cqcProjects)): ?>
            <div style="text-align: center; padding: 30px; color: #9ca3af;">
                <div style="font-size: 32px; margin-bottom: 10px;">📋</div>
                <div style="font-size: 14px; font-weight: 500;">Belum ada proyek</div>
                <div style="font-size: 11px; margin-top: 4px;">Tambahkan proyek di menu CQC Projects</div>
            </div>
            <?php else: ?>
            <!-- Summary Row - Modern 2027 Typography -->
            <?php
            $totalBudget = array_sum(array_column($cqcProjects, 'budget_idr'));
            $totalSpent = array_sum(array_column($cqcProjects, 'spent_idr'));
            $totalRemaining = $totalBudget - $totalSpent;
            $avgProgress = count($cqcProjects) > 0 ? round(array_sum(array_column($cqcProjects, 'progress_percentage')) / count($cqcProjects)) : 0;
            ?>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px;">
                <div style="text-align: center; padding: 14px 10px; background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <div style="font-size: 24px; font-weight: 800; color: #111827;"><?php echo count($cqcProjects); ?></div>
                    <div style="font-size: 10px; color: #9ca3af; font-weight: 500; margin-top: 4px;">Proyek</div>
                </div>
                <div style="text-align: center; padding: 14px 10px; background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <div style="font-size: 13px; font-weight: 700; color: #374151; font-family: 'Inter', system-ui;">Rp <?php echo number_format($totalBudget, 0, ',', '.'); ?></div>
                    <div style="font-size: 10px; color: #9ca3af; font-weight: 500; margin-top: 4px;">Total Budget</div>
                </div>
                <div style="text-align: center; padding: 14px 10px; background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <div style="font-size: 13px; font-weight: 700; color: #f59e0b; font-family: 'Inter', system-ui;">Rp <?php echo number_format($totalSpent, 0, ',', '.'); ?></div>
                    <div style="font-size: 10px; color: #9ca3af; font-weight: 500; margin-top: 4px;">Terpakai</div>
                </div>
                <div style="text-align: center; padding: 14px 10px; background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <div style="font-size: 24px; font-weight: 800; color: #10b981;"><?php echo $avgProgress; ?>%</div>
                    <div style="font-size: 10px; color: #9ca3af; font-weight: 500; margin-top: 4px;">Progress</div>
                </div>
            </div>
            
            <!-- Project Cards Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                <?php foreach ($cqcProjects as $idx => $proj): 
                    $budget = floatval($proj['budget_idr'] ?? 0);
                    $spent = floatval($proj['spent_idr'] ?? 0);
                    $remaining = $budget - $spent;
                    $progress = intval($proj['progress_percentage'] ?? 0);
                    $spentPct = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
                    $statusLabels = ['planning'=>'Planning','procurement'=>'Procurement','installation'=>'Instalasi','testing'=>'Testing','completed'=>'Selesai','on_hold'=>'Ditunda'];
                    $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
                    $statusColors = ['planning'=>'#6366f1','procurement'=>'#f59e0b','installation'=>'#3b82f6','testing'=>'#ec4899','completed'=>'#10b981','on_hold'=>'#6b7280'];
                    $statusColor = $statusColors[$proj['status']] ?? '#6b7280';
                    $expenses = $cqcExpenses[$proj['id']] ?? [];
                ?>
                <?php 
                    $kwp = floatval($proj['solar_capacity_kwp'] ?? 0);
                    $startDate = $proj['start_date'] ?? null;
                    $estCompletion = $proj['estimated_completion'] ?? null;
                    // Varied colors per project
                    $projectColorPalette = [
                        ['#10b981', '#6ee7b7'], // Green
                        ['#f59e0b', '#fcd34d'], // Yellow/Orange
                        ['#3b82f6', '#93c5fd'], // Blue
                        ['#8b5cf6', '#c4b5fd'], // Purple
                        ['#ec4899', '#f9a8d4'], // Pink
                        ['#06b6d4', '#67e8f9'], // Cyan
                        ['#84cc16', '#bef264'], // Lime
                        ['#f97316', '#fdba74'], // Orange
                    ];
                    $projColorIdx = $idx % count($projectColorPalette);
                    $projColor = $projectColorPalette[$projColorIdx][0];
                ?>
                <div class="cqc-project-card" onclick="toggleExpenseDetail(<?php echo $idx; ?>)" style="background: #fff; border-radius: 14px; padding: 14px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); cursor: pointer; transition: all 0.25s cubic-bezier(0.4,0,0.2,1);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.05)';">
                    <!-- Header -->
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                        <div>
                            <div style="font-size: 8px; color: #9ca3af; font-weight: 600; letter-spacing: 0.8px; font-family: system-ui;"><?php echo htmlspecialchars($proj['project_code']); ?></div>
                            <div style="font-size: 12px; font-weight: 600; color: #374151; margin-top: 2px; line-height: 1.25;"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                        </div>
                        <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:8px; font-weight:600; background: <?php echo $statusColor; ?>12; color: <?php echo $statusColor; ?>; letter-spacing: 0.2px;"><?php echo $statusLabel; ?></span>
                    </div>
                    
                    <!-- Project Info - KVA, Dates -->
                    <div style="display: flex; gap: 5px; margin-bottom: 10px; flex-wrap: wrap;">
                        <?php if ($kwp > 0): ?>
                        <div style="display: inline-flex; align-items: center; gap: 3px; padding: 3px 7px; background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 6px;">
                            <span style="font-size: 9px;">⚡</span>
                            <span style="font-size: 9px; font-weight: 600; color: #92400e;"><?php echo number_format($kwp, 1); ?> kWp</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($startDate): ?>
                        <div style="display: inline-flex; align-items: center; gap: 3px; padding: 3px 7px; background: #f0fdf4; border-radius: 6px;">
                            <span style="font-size: 8px;">🚀</span>
                            <span style="font-size: 8px; font-weight: 500; color: #166534;"><?php echo date('d M Y', strtotime($startDate)); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($estCompletion): ?>
                        <div style="display: inline-flex; align-items: center; gap: 3px; padding: 3px 7px; background: #eff6ff; border-radius: 6px;">
                            <span style="font-size: 8px;">🎯</span>
                            <span style="font-size: 8px; font-weight: 500; color: #1e40af;"><?php echo date('d M Y', strtotime($estCompletion)); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pie Chart - Compact -->
                    <div style="position: relative; width: 80px; height: 80px; margin: 0 auto 10px;">
                        <canvas id="cqcPie<?php echo $idx; ?>"></canvas>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                            <div style="font-size: 16px; font-weight: 700; color: <?php echo $projColor; ?>; line-height: 1; letter-spacing: -0.5px;"><?php echo $progress; ?>%</div>
                        </div>
                    </div>
                    
                    <!-- Financial Stats - Elegant minimal -->
                    <div style="background: #f9fafb; border-radius: 10px; padding: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 5px 4px;">
                            <span style="font-size: 9px; color: #6b7280; font-weight: 500;">Budget</span>
                            <span style="font-size: 10px; font-weight: 600; color: #374151; font-family: 'Inter', system-ui;">Rp <?php echo number_format($budget, 0, ',', '.'); ?></span>
                        </div>
                        <div style="height: 1px; background: #e5e7eb; margin: 0 8px;"></div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 5px 4px;">
                            <span style="font-size: 9px; color: #6b7280; font-weight: 500;">Terpakai</span>
                            <span style="font-size: 10px; font-weight: 600; color: #ef4444; font-family: 'Inter', system-ui;">Rp <?php echo number_format($spent, 0, ',', '.'); ?></span>
                        </div>
                        <div style="height: 1px; background: #e5e7eb; margin: 0 8px;"></div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 5px 4px;">
                            <span style="font-size: 9px; color: #6b7280; font-weight: 500;">Sisa</span>
                            <span style="font-size: 10px; font-weight: 600; color: <?php echo $remaining >= 0 ? '#10b981' : '#ef4444'; ?>; font-family: 'Inter', system-ui;">Rp <?php echo number_format($remaining, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                    
                    <!-- Expense Detail (hidden by default) -->
                    <div id="expenseDetail<?php echo $idx; ?>" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                        <div style="font-size: 8px; font-weight: 600; color: #6b7280; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Pengeluaran Terbaru</div>
                        <?php if (empty($expenses)): ?>
                        <div style="text-align: center; padding: 10px; color: #9ca3af; font-size: 9px; background: #fafafa; border-radius: 8px;">
                            Belum ada pengeluaran
                        </div>
                        <?php else: ?>
                        <?php foreach ($expenses as $exp): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 8px; margin-bottom: 4px; background: #fff; border-radius: 6px; border: 1px solid #f0f0f0;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 9px; font-weight: 500; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($exp['description'] ?? 'Pengeluaran'); ?></div>
                                <div style="font-size: 8px; color: #9ca3af; margin-top: 1px;"><?php echo $exp['expense_date'] ? date('d M Y', strtotime($exp['expense_date'])) : '-'; ?></div>
                            </div>
                            <div style="font-size: 9px; font-weight: 600; color: #ef4444; font-family: system-ui; white-space: nowrap; margin-left: 8px;">-Rp <?php echo number_format(floatval($exp['amount'] ?? 0), 0, ',', '.'); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Click indicator -->
                    <div style="text-align: center; margin-top: 8px;">
                        <span id="clickHint<?php echo $idx; ?>" style="font-size: 8px; color: #c0c0c0; font-weight: 500;">tap untuk detail</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; // end of else (has projects) ?>
        </div>
        <?php endif; // end of isCQC ?>
        
        <!-- AI Health -->
        <div class="ai-card">
            <div class="ai-header">
                <div class="ai-title-wrap">
                    <span class="ai-badge">✨ AI</span>
                    <span class="ai-title">Business Health</span>
                </div>
                <div class="ai-score">
                    <div class="ai-score-value"><?= number_format(100 - $expenseRatio, 0) ?></div>
                    <div class="ai-score-label">Score</div>
                </div>
            </div>
            <div class="ai-content">
                <?php
                if ($expenseRatio < 50) {
                    echo "🟢 <strong>Excellent!</strong> Expense ratio " . number_format($expenseRatio, 1) . "% of income. Very healthy finances with high profit margin.";
                } elseif ($expenseRatio < 70) {
                    echo "🟡 <strong>Good.</strong> Expense ratio " . number_format($expenseRatio, 1) . "% of income. Maintain operational efficiency.";
                } elseif ($expenseRatio < 90) {
                    echo "🟠 <strong>Warning.</strong> Expense ratio " . number_format($expenseRatio, 1) . "% of income. Need to optimize expenses to improve margin.";
                } else {
                    echo "🔴 <strong>Critical!</strong> Expense ratio " . number_format($expenseRatio, 1) . "% of income. Immediately evaluate expenses and revenue strategy.";
                }
                ?>
            </div>
        </div>
        
        <!-- Summary -->
        <div class="summary-card">
            <div class="summary-title">Monthly Performance</div>
            <div class="summary-row">
                <span>Total Revenue</span>
                <span style="color:var(--success)"><?= rp($stats['month_income']) ?></span>
            </div>
            <div class="summary-row">
                <span>Total Expense</span>
                <span style="color:var(--danger)"><?= rp($stats['month_expense']) ?></span>
            </div>
            <div class="summary-row total">
                <span>Net Profit</span>
                <span style="color:<?= $netProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                    <?= $netProfit >= 0 ? '+' : '' ?><?= rp($netProfit) ?>
                </span>
            </div>
        </div>
        
        <!-- Recent Transactions -->
        <div class="tx-card">
            <div class="tx-title">⚡ Recent Transactions</div>
            <ul class="tx-list">
                <?php foreach ($transactions as $tx): 
                    $method = strtolower(trim($tx['payment_method'] ?? 'other'));
                    $methodClass = in_array($method, ['cash','transfer','tf','qr','debit','edc']) ? $method : 'other';
                    $methodLabel = strtoupper($method === 'transfer' ? 'TF' : $method);
                ?>
                <li class="tx-item">
                    <div style="min-width:0;flex:1;">
                        <div class="tx-desc">
                            <?= htmlspecialchars(($tx['division_name'] ?? 'Umum') . ' - ' . ($tx['category_name'] ?? $tx['description'] ?? '-')) ?>
                            <span class="tx-method <?= $methodClass ?>"><?= $methodLabel ?></span>
                        </div>
                        <div class="tx-date"><?= date('d/m/Y', strtotime($tx['transaction_date'])) ?></div>
                    </div>
                    <div class="tx-amount <?= $tx['transaction_type'] ?>">
                        <?= $tx['transaction_type'] === 'income' ? '+' : '-' ?><?= rp($tx['amount']) ?>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <li class="tx-item" style="justify-content:center;color:var(--text-muted)">No transactions yet</li>
                <?php endif; ?>
            </ul>
        </div>

        <?php endif; // end if (!$error) ?>

    </div><!-- end .container -->

    <!-- Footer Nav -->
    <?php
    require_once __DIR__ . '/../../includes/owner_footer_nav.php';
    renderOwnerFooterNav('home', $basePath, $enabledModules);
    ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ============================================
        // DOUGHNUT CHART - Expense per Division
        // ============================================
        var expDivCanvas = document.getElementById('expenseDivisionChart');
        var expenseDivisionChart = null;
        var divChartColors = [
            'rgba(239,68,68,0.85)', 'rgba(251,146,60,0.85)', 'rgba(245,158,11,0.85)',
            'rgba(234,179,8,0.85)', 'rgba(132,204,22,0.85)', 'rgba(34,197,94,0.85)',
            'rgba(20,184,166,0.85)', 'rgba(6,182,212,0.85)', 'rgba(59,130,246,0.85)',
            'rgba(99,102,241,0.85)', 'rgba(139,92,246,0.85)', 'rgba(168,85,247,0.85)'
        ];

        if (expDivCanvas) {
            var expDivCtx = expDivCanvas.getContext('2d');
            expenseDivisionChart = new Chart(expDivCtx, {
                type: 'doughnut',
                data: {
                    labels: [<?php foreach ($expenseDivisionData as $div): ?>'<?= addslashes($div['division_name']) ?>',<?php endforeach; ?>],
                    datasets: [{
                        data: [<?php foreach ($expenseDivisionData as $div): ?><?= $div['total'] ?>,<?php endforeach; ?>],
                        backgroundColor: divChartColors.slice(0, <?= count($expenseDivisionData) ?>),
                        borderWidth: 0,
                        hoverOffset: 14
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15,23,42,0.92)',
                            padding: 10,
                            titleFont: { size: 11, weight: '600' },
                            bodyFont: { size: 10 },
                            cornerRadius: 8,
                            callbacks: {
                                label: function(ctx) {
                                    var val = ctx.parsed || 0;
                                    var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                                    var pct = ((val/total)*100).toFixed(1);
                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // AJAX update for month change
        window.updateExpenseDivisionChart = function(month) {
            var basePath = '<?= $basePath ?>';
            var activeBiz = '<?= $activeBusinessId ?>';
            fetch(basePath + '/api/expense-division-data.php?month=' + month + '&business=' + activeBiz)
                .then(function(r){ return r.json(); })
                .then(function(data) {
                    if (data.success && data.divisions.length > 0) {
                        // Show canvas, hide empty
                        var body = document.querySelector('.expense-division-body');
                        body.innerHTML = '<canvas id="expenseDivisionChart"></canvas>';
                        var newCtx = document.getElementById('expenseDivisionChart').getContext('2d');
                        expenseDivisionChart = new Chart(newCtx, {
                            type: 'doughnut',
                            data: {
                                labels: data.divisions,
                                datasets: [{
                                    data: data.amounts,
                                    backgroundColor: divChartColors.slice(0, data.divisions.length),
                                    borderWidth: 0,
                                    hoverOffset: 14
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                cutout: '58%',
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        backgroundColor: 'rgba(15,23,42,0.92)',
                                        padding: 10,
                                        titleFont: { size: 11, weight: '600' },
                                        bodyFont: { size: 10 },
                                        cornerRadius: 8,
                                        callbacks: {
                                            label: function(ctx) {
                                                var val = ctx.parsed || 0;
                                                var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                                                var pct = ((val/total)*100).toFixed(1);
                                                return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                        // Update legend
                        var legendEl = document.getElementById('expenseDivisionLegend');
                        if (legendEl) {
                            var html = '';
                            data.divisions.forEach(function(name, i) {
                                html += '<div class="edl-item"><span class="edl-dot" style="background:' + divChartColors[i % divChartColors.length] + '"></span>' + name + '</div>';
                            });
                            legendEl.innerHTML = html;
                            legendEl.style.display = 'flex';
                        }
                    } else {
                        // Show empty state
                        var body = document.querySelector('.expense-division-body');
                        body.innerHTML = '<div class="expense-division-empty"><span style="font-size:28px;margin-bottom:6px;">📭</span>No expense data available</div>';
                        var legendEl = document.getElementById('expenseDivisionLegend');
                        if (legendEl) legendEl.style.display = 'none';
                    }
                })
                .catch(function(err){ console.error('Error:', err); });
        };

        // ============================================
        // MAIN PIE CHART - Income vs Expense (Digital 2027)
        // ============================================
        var canvas = document.getElementById('pieChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var dpr = window.devicePixelRatio || 1;
        var size = 160;
        canvas.width = size * dpr;
        canvas.height = size * dpr;
        canvas.style.width = size + 'px';
        canvas.style.height = size + 'px';
        ctx.scale(dpr, dpr);

        var income = <?= (float)$stats['month_income'] ?>;
        var expense = <?= (float)$stats['month_expense'] ?>;
        var total = income + expense;
        if (total === 0) { income = 1; expense = 1; total = 2; }

        var cx = 80, cy = 80, r = 72, innerR = 42, gap = 0.03;
        var startAngle = -Math.PI / 2;
        var incomeAngle = (income / total) * 2 * Math.PI;
        var expenseAngle = (expense / total) * 2 * Math.PI;

        // Outer subtle ring
        ctx.beginPath();
        ctx.arc(cx, cy, r + 3, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(255,255,255,0.06)';
        ctx.lineWidth = 1;
        ctx.stroke();

        // Draw donut arc helper
        function drawArc(start, end, color) {
            ctx.beginPath();
            ctx.arc(cx, cy, r, start, end);
            ctx.arc(cx, cy, innerR, end, start, true);
            ctx.closePath();
            ctx.fillStyle = color;
            ctx.fill();
        }

        // DEBUG: Check if this code is running - VERSION 2026-03-01-C
        console.log('PIE CHART DEBUG: Income=' + income + ', Expense=' + expense);
        console.log('PIE CHART COLORS: Income=#10b981 (GREEN), Expense=#ef4444 (RED)');

        // Draw EXPENSE first (red) - small slice
        ctx.fillStyle = '#ef4444';
        drawArc(startAngle + incomeAngle + gap/2, startAngle + incomeAngle + expenseAngle - gap/2, '#ef4444');

        // Draw INCOME second (green) - large slice, will be on top
        ctx.fillStyle = '#10b981';
        drawArc(startAngle + gap/2, startAngle + incomeAngle - gap/2, '#10b981');

        // Inner hole - dark glass
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        var holeFill = ctx.createRadialGradient(cx, cy, 0, cx, cy, innerR);
        holeFill.addColorStop(0, 'rgba(48,43,99,0.9)');
        holeFill.addColorStop(1, 'rgba(15,12,41,0.95)');
        ctx.fillStyle = holeFill;
        ctx.fill();

        // Inner glow ring
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(99,102,241,0.25)';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        // Outer glow ring
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(255,255,255,0.08)';
        ctx.lineWidth = 0.5;
        ctx.stroke();
    });
    
    <?php if ($isCQC && !empty($cqcProjects)): ?>
    // CQC PROJECT PIE CHARTS - Varied gradient colors per project
    <?php 
    $projectColors = [
        ['#10b981', '#6ee7b7'], // Green
        ['#f59e0b', '#fcd34d'], // Yellow/Orange
        ['#3b82f6', '#93c5fd'], // Blue
        ['#8b5cf6', '#c4b5fd'], // Purple
        ['#ec4899', '#f9a8d4'], // Pink
        ['#06b6d4', '#67e8f9'], // Cyan
        ['#84cc16', '#bef264'], // Lime
        ['#f97316', '#fdba74'], // Orange
    ];
    foreach ($cqcProjects as $idx => $proj): 
        $progress = intval($proj['progress_percentage'] ?? 0);
        $colorIdx = $idx % count($projectColors);
        $color1 = $projectColors[$colorIdx][0];
        $color2 = $projectColors[$colorIdx][1];
    ?>
    (function() {
        const ctx = document.getElementById('cqcPie<?php echo $idx; ?>');
        if (!ctx) return;
        
        // Create gradient with varied colors
        const chartCtx = ctx.getContext('2d');
        const gradient = chartCtx.createLinearGradient(0, 0, 0, 110);
        gradient.addColorStop(0, '<?php echo $color1; ?>');
        gradient.addColorStop(1, '<?php echo $color2; ?>');
        
        new Chart(chartCtx, {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Tersisa'],
                datasets: [{
                    data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                    backgroundColor: [
                        gradient,
                        '#e5e7eb'
                    ],
                    borderWidth: 0,
                    hoverBackgroundColor: ['<?php echo $color1; ?>', '#d1d5db']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '72%',
                plugins: { 
                    legend: { display: false }, 
                    tooltip: {
                        backgroundColor: '#374151', 
                        titleColor: '<?php echo $color2; ?>', 
                        bodyColor: '#e5e7eb',
                        cornerRadius: 10, 
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            label: function(ctx) { return ctx.label + ': ' + ctx.parsed + '%'; }
                        }
                    }
                },
                animation: { animateRotate: true, duration: 800 }
            }
        });
    })();
    <?php endforeach; ?>
    <?php endif; ?>
    
    // Toggle expense detail
    function toggleExpenseDetail(idx) {
        const detail = document.getElementById('expenseDetail' + idx);
        const hint = document.getElementById('clickHint' + idx);
        if (detail.style.display === 'none') {
            detail.style.display = 'block';
            hint.innerHTML = '▲ Tutup';
        } else {
            detail.style.display = 'none';
            hint.innerHTML = '▼ Detail pengeluaran';
        }
    }
    </script>
</body>
</html>
