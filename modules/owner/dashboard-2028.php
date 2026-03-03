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
    
    // Additional: Exclude modal/transfer entries by category or description
    // These are capital/operational funds, not actual business income
    $excludeModalCategories = [];
    try {
        $catStmt = $pdo->query("
            SELECT id FROM categories 
            WHERE LOWER(category_name) LIKE '%modal%' 
            OR LOWER(category_name) LIKE '%transfer%dana%'
            OR LOWER(category_name) LIKE '%petty%cash%'
            OR LOWER(category_name) LIKE '%capital%'
        ");
        $excludeModalCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
    
    $excludeModalClause = "";
    if (!empty($excludeModalCategories)) {
        $excludeModalClause = " AND (category_id IS NULL OR category_id NOT IN (" . implode(',', $excludeModalCategories) . "))";
    }
    
    // Also exclude by description patterns
    $excludeDescClause = " AND (
        LOWER(description) NOT LIKE '%modal operasional%'
        AND LOWER(description) NOT LIKE '%transfer dana%'
        AND LOWER(description) NOT LIKE '%setoran modal%'
    )";
    
    // Combine all exclusions
    $fullExclude = $excludeOwnerCapital . $excludeModalClause . $excludeDescClause;
    
    // Today Income (exclude owner capital and modal entries)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'" . $fullExclude);
    $stmt->execute([$today]);
    $stats['today_income'] = (float)$stmt->fetchColumn();
    
    // Today Expense
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'expense'");
    $stmt->execute([$today]);
    $stats['today_expense'] = (float)$stmt->fetchColumn();
    
    // Month Income (exclude owner capital and modal entries)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $fullExclude);
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
$cqcCategoryExpenses = []; // Expenses per category per project (for pie chart)
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
            
            // CQC: Budget is NOT income. Income only from invoice payments.
            // Budget is just RAB (cost estimate). Don't override month_income with budget.
            // month_income stays from actual cash_book income entries (invoice payments).
            // Only add project expenses if not already counted in cash_book
            // (expenses from detail.php already sync to cash_book)
            
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
            
            // Get expenses grouped by category for pie chart
            $cqcCategoryExpenses[$proj['id']] = [];
            try {
                // First try with category join
                $stmtCat = $cqcPdo->prepare("
                    SELECT 
                        COALESCE(c.category_name, 'Lainnya') as category_name,
                        COALESCE(c.category_icon, '📦') as category_icon,
                        SUM(e.amount) as total_amount
                    FROM cqc_project_expenses e
                    LEFT JOIN cqc_expense_categories c ON e.category_id = c.id
                    WHERE e.project_id = ?
                    GROUP BY COALESCE(c.category_name, 'Lainnya'), COALESCE(c.category_icon, '📦')
                    ORDER BY total_amount DESC
                    LIMIT 6
                ");
                $stmtCat->execute([$proj['id']]);
                $result = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($result)) {
                    $cqcCategoryExpenses[$proj['id']] = $result;
                }
            } catch (Exception $catEx) {
                // If category table doesn't exist, just group as "Lainnya"
                try {
                    $stmtSimple = $cqcPdo->prepare("
                        SELECT 'Lainnya' as category_name, '📦' as category_icon, SUM(amount) as total_amount
                        FROM cqc_project_expenses WHERE project_id = ?
                    ");
                    $stmtSimple->execute([$proj['id']]);
                    $result = $stmtSimple->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($result) && floatval($result[0]['total_amount'] ?? 0) > 0) {
                        $cqcCategoryExpenses[$proj['id']] = $result;
                    }
                } catch (Exception $e2) {
                    // Ignore
                }
            }
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
            color: #fbbf24;
            text-decoration: none;
        }
        
        /* Kas Harian Section - Elegant & Compact */
        .kas-harian-section {
            margin: 16px 0;
            background: linear-gradient(180deg, rgba(15,23,42,0.95) 0%, rgba(30,41,59,0.95) 100%);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        
        .kas-harian-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .kas-harian-title {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .kas-harian-date {
            font-size: 11px;
            color: #9ca3af;
        }
        
        .kas-summary-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }
        
        .kas-summary-box {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 10px 12px;
            text-align: center;
        }
        
        .kas-summary-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        
        .kas-summary-value {
            font-size: 16px;
            font-weight: 700;
        }
        
        .kas-summary-value.saldo { color: #60a5fa; }
        .kas-summary-value.masuk { color: #10b981; }
        .kas-summary-value.keluar { color: #ef4444; }
        
        .kas-table-wrapper {
            max-height: 220px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        
        .kas-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .kas-table th {
            background: rgba(255,255,255,0.05);
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            color: #9ca3af;
            position: sticky;
            top: 0;
        }
        
        .kas-table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            color: #e5e7eb;
        }
        
        .kas-table tr:last-child td { border-bottom: none; }
        
        .kas-table .text-right { text-align: right; }
        
        .kas-badge-masuk, .kas-badge-keluar {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 600;
        }
        
        .kas-badge-masuk { background: rgba(16,185,129,0.2); color: #10b981; }
        .kas-badge-keluar { background: rgba(239,68,68,0.2); color: #ef4444; }
        
        .kas-amount-masuk { color: #10b981; font-weight: 600; }
        .kas-amount-keluar { color: #ef4444; font-weight: 600; }
        
        .kas-empty { text-align: center; padding: 20px; color: #9ca3af; font-style: italic; }
        
        @media (max-width: 480px) {
            .kas-summary-row { grid-template-columns: 1fr; gap: 8px; }
            .kas-summary-value { font-size: 14px; }
            .kas-table { font-size: 11px; }
            .kas-table th, .kas-table td { padding: 6px 8px; }
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
        
        <!-- Kas Harian (Today's Cash Book) -->
        <?php
        // Fetch this month's cash book entries - SAME LOGIC AS index.php
        // Only count owner_capital + petty_cash accounts
        $todayKas = [];
        $startKasHariIni = 0;
        $monthMasuk = 0;
        $monthKeluar = 0;
        $kasAvailable = 0;
        
        try {
            // Connect to master DB to get cash account IDs
            $masterDb = new PDO("mysql:host=" . $dbHost . ";dbname=" . $masterDbName . ";charset=utf8mb4", $dbUser, $dbPass);
            $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $businessId = getMasterBusinessId();
            
            // Get owner_capital account IDs
            $stmtCap = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
            $stmtCap->execute([$businessId]);
            $capitalAccounts = $stmtCap->fetchAll(PDO::FETCH_COLUMN);
            
            // Get petty cash (cash) account IDs
            $stmtPetty = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
            $stmtPetty->execute([$businessId]);
            $pettyCashAccounts = $stmtPetty->fetchAll(PDO::FETCH_COLUMN);
            
            // Merge all operational accounts
            $allAccounts = array_merge($capitalAccounts, $pettyCashAccounts);
            
            if (!empty($allAccounts)) {
                $kasDb = new PDO("mysql:host=" . $dbHost . ";dbname=" . $businessDbName . ";charset=utf8mb4", $dbUser, $dbPass);
                $kasDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $today = date('Y-m-d');
                $thisMonth = date('Y-m');
                $placeholders = implode(',', array_fill(0, count($allAccounts), '?'));
                
                // Get start kas (all balance before today) - filtered by accounts
                $sqlSaldo = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                    FROM cash_book 
                    WHERE cash_account_id IN ($placeholders) AND transaction_date < ?
                ";
                $stmtSaldo = $kasDb->prepare($sqlSaldo);
                $stmtSaldo->execute(array_merge($allAccounts, [$today]));
                $startKasHariIni = (float)($stmtSaldo->fetchColumn() ?: 0);
                
                // Get this month's totals (income & expense) - filtered by accounts
                $sqlMonth = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as masuk,
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as keluar
                    FROM cash_book 
                    WHERE cash_account_id IN ($placeholders) AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                $stmtMonth = $kasDb->prepare($sqlMonth);
                $stmtMonth->execute(array_merge($allAccounts, [$thisMonth]));
                $monthRow = $stmtMonth->fetch(PDO::FETCH_ASSOC);
                $monthMasuk = (float)($monthRow['masuk'] ?? 0);
                $monthKeluar = (float)($monthRow['keluar'] ?? 0);
                
                // Calculate kas available (all time balance) - filtered by accounts
                $sqlAll = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                    FROM cash_book
                    WHERE cash_account_id IN ($placeholders)
                ";
                $stmtAll = $kasDb->prepare($sqlAll);
                $stmtAll->execute($allAccounts);
                $kasAvailable = (float)($stmtAll->fetchColumn() ?: 0);
                
                // Get recent transactions - filtered by accounts
                $sqlKas = "
                    SELECT id, transaction_type, description, amount,
                           TIME_FORMAT(CONCAT(transaction_date, ' ', COALESCE(transaction_time, '00:00:00')), '%H:%i') as jam,
                           transaction_date
                    FROM cash_book 
                    WHERE cash_account_id IN ($placeholders) AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                    ORDER BY transaction_date DESC, id DESC
                    LIMIT 8
                ";
                $stmtKas = $kasDb->prepare($sqlKas);
                $stmtKas->execute(array_merge($allAccounts, [$thisMonth]));
                $todayKas = $stmtKas->fetchAll(PDO::FETCH_ASSOC);
            }
            
        } catch (PDOException $e) {
            // Silent fail - error_log for debugging
            error_log("Kas Harian Error: " . $e->getMessage());
        }
        ?>
        <div class="kas-harian-section">
            <div class="kas-harian-header">
                <div class="kas-harian-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8m-4-4v4"/></svg>
                    Kas Harian
                </div>
                <div class="kas-harian-date"><?= date('M Y') ?></div>
            </div>
            
            <div class="kas-summary-row">
                <div class="kas-summary-box">
                    <div class="kas-summary-label">Saldo Kas</div>
                    <div class="kas-summary-value saldo"><?= number_format($kasAvailable, 0, ',', '.') ?></div>
                </div>
                <div class="kas-summary-box">
                    <div class="kas-summary-label">Masuk</div>
                    <div class="kas-summary-value masuk"><?= number_format($monthMasuk, 0, ',', '.') ?></div>
                </div>
                <div class="kas-summary-box">
                    <div class="kas-summary-label">Keluar</div>
                    <div class="kas-summary-value keluar"><?= number_format($monthKeluar, 0, ',', '.') ?></div>
                </div>
            </div>
            
            <div class="kas-table-wrapper">
                <?php if (empty($todayKas)): ?>
                <div class="kas-empty">Belum ada transaksi bulan ini</div>
                <?php else: ?>
                <table class="kas-table">
                    <thead>
                        <tr>
                            <th>Jam</th>
                            <th>Keterangan</th>
                            <th class="text-right">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayKas as $kas): 
                            $isMasuk = $kas['transaction_type'] === 'income';
                            $amount = (float)$kas['amount'];
                        ?>
                        <tr>
                            <td><?= $kas['jam'] ?></td>
                            <td>
                                <span class="<?= $isMasuk ? 'kas-badge-masuk' : 'kas-badge-keluar' ?>"><?= $isMasuk ? 'IN' : 'OUT' ?></span>
                                <?= htmlspecialchars(mb_substr($kas['description'], 0, 30)) ?>
                            </td>
                            <td class="text-right <?= $isMasuk ? 'kas-amount-masuk' : 'kas-amount-keluar' ?>">
                                <?= $isMasuk ? '+' : '-' ?><?= number_format($amount, 0, ',', '.') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($isCQC): ?>
        <!-- CQC Project Monitoring - Elegant 2026 -->
        <div style="margin: 16px 0; padding: 20px; background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%); border-radius: 20px; box-shadow: 0 4px 24px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);">
            <!-- Header with Icon -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="m12 1v2m0 18v2m4.22-18.36 1.42 1.42M4.93 19.07l1.41 1.42m12.73 0 1.41-1.42M4.93 4.93l1.42 1.42M1 12h2m18 0h2"/></svg>
                    </div>
                    <div>
                        <div style="font-size: 9px; color: #f59e0b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px;">CQC Enjiniring</div>
                        <div style="font-size: 16px; font-weight: 700; color: #1f2937; letter-spacing: -0.4px; margin-top: 1px;">Pencapaian & Keuangan Per Proyek</div>
                    </div>
                </div>
                <a href="<?php echo $basePath; ?>/modules/cqc-projects/" style="padding: 8px 14px; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); color: white; border-radius: 10px; font-size: 11px; font-weight: 600; text-decoration: none; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3); transition: all 0.2s;">Kelola →</a>
            </div>
            
            <?php if (empty($cqcProjects)): ?>
            <div style="text-align: center; padding: 50px 20px; color: #9ca3af; background: #f9fafb; border-radius: 16px;">
                <div style="width: 64px; height: 64px; margin: 0 auto 16px; background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 28px;">☀️</span>
                </div>
                <div style="font-size: 16px; font-weight: 600; color: #6b7280;">Belum ada proyek</div>
                <div style="font-size: 13px; margin-top: 6px; color: #9ca3af;">Tambahkan proyek di menu CQC Projects</div>
            </div>
            <?php else: ?>
            <!-- Summary Stats - Glassmorphism Style -->
            <?php
            $totalBudget = array_sum(array_column($cqcProjects, 'budget_idr'));
            $totalSpent = array_sum(array_column($cqcProjects, 'spent_idr'));
            $totalRemaining = $totalBudget - $totalSpent;
            $avgProgress = count($cqcProjects) > 0 ? round(array_sum(array_column($cqcProjects, 'progress_percentage')) / count($cqcProjects)) : 0;
            $budgetUsedPct = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100) : 0;
            ?>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px;">
                <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.25);">
                    <div style="font-size: 28px; font-weight: 800; color: #fff; font-family: system-ui; line-height: 1;"><?php echo count($cqcProjects); ?></div>
                    <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Total Proyek</div>
                </div>
                <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(14, 165, 233, 0.25);">
                    <div style="font-size: 14px; font-weight: 700; color: #fff; font-family: system-ui;">Rp <?php echo number_format($totalBudget/1000000000, 2); ?>M</div>
                    <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Total Budget</div>
                </div>
                <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(244, 63, 94, 0.25);">
                    <div style="font-size: 14px; font-weight: 700; color: #fff; font-family: system-ui;">Rp <?php echo number_format($totalSpent/1000000, 0); ?>jt</div>
                    <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Terpakai (<?php echo $budgetUsedPct; ?>%)</div>
                </div>
                <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);">
                    <div style="font-size: 28px; font-weight: 800; color: #fff; font-family: system-ui; line-height: 1;"><?php echo $avgProgress; ?>%</div>
                    <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Avg Progress</div>
                </div>
            </div>
            
            <!-- Project Cards Grid - Modern Design -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;">
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
                    $kwp = floatval($proj['solar_capacity_kwp'] ?? 0);
                    $startDate = $proj['start_date'] ?? null;
                    $estCompletion = $proj['estimated_completion'] ?? null;
                    $clientName = $proj['client_name'] ?? '';
                    // Modern color palette per project
                    $projectColorPalette = [
                        ['#10b981', '#34d399', 'rgba(16, 185, 129, 0.1)'], // Emerald
                        ['#f59e0b', '#fbbf24', 'rgba(245, 158, 11, 0.1)'], // Amber
                        ['#3b82f6', '#60a5fa', 'rgba(59, 130, 246, 0.1)'], // Blue
                        ['#8b5cf6', '#a78bfa', 'rgba(139, 92, 246, 0.1)'], // Violet
                        ['#ec4899', '#f472b6', 'rgba(236, 72, 153, 0.1)'], // Pink
                        ['#06b6d4', '#22d3ee', 'rgba(6, 182, 212, 0.1)'], // Cyan
                        ['#84cc16', '#a3e635', 'rgba(132, 204, 22, 0.1)'], // Lime
                        ['#f97316', '#fb923c', 'rgba(249, 115, 22, 0.1)'], // Orange
                    ];
                    $projColorIdx = $idx % count($projectColorPalette);
                    $projColor = $projectColorPalette[$projColorIdx][0];
                    $projColorLight = $projectColorPalette[$projColorIdx][1];
                    $projColorBg = $projectColorPalette[$projColorIdx][2];
                ?>
                <div class="cqc-project-card" onclick="toggleExpenseDetail(<?php echo $idx; ?>)" style="background: #fff; border-radius: 20px; padding: 20px; border: 1px solid #e5e7eb; box-shadow: 0 4px 20px rgba(0,0,0,0.04); cursor: pointer; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 32px rgba(0,0,0,0.1)'; this.style.borderColor='<?php echo $projColor; ?>';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 20px rgba(0,0,0,0.04)'; this.style.borderColor='#e5e7eb';">
                    
                    <!-- Accent Line Top -->
                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, <?php echo $projColor; ?>, <?php echo $projColorLight; ?>);"></div>
                    
                    <!-- Header -->
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px;">
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-size: 10px; color: <?php echo $projColor; ?>; font-weight: 700; letter-spacing: 1.2px; font-family: system-ui; text-transform: uppercase;"><?php echo htmlspecialchars($proj['project_code']); ?></div>
                            <div style="font-size: 15px; font-weight: 700; color: #111827; margin-top: 4px; line-height: 1.3; letter-spacing: -0.3px;"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                            <?php if ($clientName): ?>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php echo htmlspecialchars($clientName); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <span style="padding: 6px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; background: <?php echo $statusColor; ?>15; color: <?php echo $statusColor; ?>; letter-spacing: 0.3px; text-transform: uppercase; white-space: nowrap;"><?php echo $statusLabel; ?></span>
                    </div>
                    
                    <!-- Main Content: Progress Chart + Financial -->
                    <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 16px;">
                        
                        <!-- Progress Donut Chart -->
                        <div style="flex-shrink: 0; text-align: center;">
                            <div style="position: relative; width: 100px; height: 100px;">
                                <canvas id="cqcPie<?php echo $idx; ?>" style="filter: drop-shadow(0 4px 12px <?php echo $projColor; ?>30);"></canvas>
                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                    <div style="font-size: 22px; font-weight: 800; color: <?php echo $projColor; ?>; line-height: 1; font-family: system-ui;"><?php echo $progress; ?>%</div>
                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Progress</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Financial Details -->
                        <div style="flex: 1; min-width: 0;">
                            <!-- Budget -->
                            <div style="margin-bottom: 10px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <span style="font-size: 10px; color: #6b7280; font-weight: 600;">💰 Budget</span>
                                    <span style="font-size: 12px; font-weight: 700; color: #374151; font-family: system-ui;"><?php echo number_format($budget/1000000, 0); ?>jt</span>
                                </div>
                                <div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                    <div style="width: <?php echo min($spentPct, 100); ?>%; height: 100%; background: linear-gradient(90deg, <?php echo $spentPct > 90 ? '#ef4444' : $projColor; ?>, <?php echo $spentPct > 90 ? '#f87171' : $projColorLight; ?>); border-radius: 3px; transition: width 0.5s ease;"></div>
                                </div>
                            </div>
                            
                            <!-- Spent & Remaining -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <div style="background: #fef2f2; padding: 8px 10px; border-radius: 10px;">
                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600;">Terpakai</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #ef4444; font-family: system-ui; margin-top: 2px;"><?php echo number_format($spent/1000000, 1); ?>jt</div>
                                </div>
                                <div style="background: <?php echo $remaining >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 8px 10px; border-radius: 10px;">
                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600;">Sisa</div>
                                    <div style="font-size: 13px; font-weight: 700; color: <?php echo $remaining >= 0 ? '#10b981' : '#ef4444'; ?>; font-family: system-ui; margin-top: 2px;"><?php echo number_format($remaining/1000000, 1); ?>jt</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tags Row: KWP + Timeline -->
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; padding-top: 12px; border-top: 1px solid #f3f4f6;">
                        <?php if ($kwp > 0): ?>
                        <span style="padding: 5px 10px; background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 8px; font-size: 10px; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 4px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            <?php echo number_format($kwp, 1); ?> kWp
                        </span>
                        <?php endif; ?>
                        <?php if ($startDate): ?>
                        <span style="padding: 5px 10px; background: #f0fdf4; border-radius: 8px; font-size: 10px; font-weight: 600; color: #166534; display: flex; align-items: center; gap: 4px;">
                            🚀 <?php echo date('d M Y', strtotime($startDate)); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($estCompletion): ?>
                        <span style="padding: 5px 10px; background: #eff6ff; border-radius: 8px; font-size: 10px; font-weight: 600; color: #1e40af; display: flex; align-items: center; gap: 4px;">
                            🎯 <?php echo date('d M Y', strtotime($estCompletion)); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Expense Detail (hidden by default) -->
                    <div id="expenseDetail<?php echo $idx; ?>" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e5e7eb;">
                        <div style="font-size: 11px; font-weight: 700; color: #374151; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            Pengeluaran Terbaru
                        </div>
                        <?php if (empty($expenses)): ?>
                        <div style="text-align: center; padding: 16px; color: #9ca3af; font-size: 12px; background: #f9fafb; border-radius: 12px;">
                            <div style="font-size: 24px; margin-bottom: 6px;">📭</div>
                            Belum ada pengeluaran
                        </div>
                        <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($expenses as $exp): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f9fafb; border-radius: 10px; border: 1px solid #f3f4f6;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 12px; font-weight: 600; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($exp['description'] ?? 'Pengeluaran'); ?></div>
                                <div style="font-size: 10px; color: #9ca3af; margin-top: 2px;"><?php echo $exp['expense_date'] ? date('d M Y', strtotime($exp['expense_date'])) : '-'; ?></div>
                            </div>
                            <div style="font-size: 12px; font-weight: 700; color: #ef4444; font-family: system-ui; white-space: nowrap; margin-left: 12px; background: #fef2f2; padding: 4px 8px; border-radius: 6px;">-Rp <?php echo number_format(floatval($exp['amount'] ?? 0), 0, ',', '.'); ?></div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Click indicator -->
                    <div style="text-align: center; margin-top: 12px;">
                        <span id="clickHint<?php echo $idx; ?>" style="font-size: 10px; color: #c0c0c0; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 4px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                            tap untuk detail
                        </span>
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
        
        <?php if (!$isCQC): // Only show for non-CQC businesses ?>
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
        <?php endif; // end if (!$isCQC) ?>

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

        // DEBUG: Check if this code is running - VERSION 2026-03-01-D
        console.log('PIE CHART DEBUG: Income=' + income + ', Expense=' + expense);
        console.log('incomeAngle=' + incomeAngle + ', expenseAngle=' + expenseAngle);

        // TEST: Draw FULL circle in GREEN to verify color works
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.arc(cx, cy, innerR, 2 * Math.PI, 0, true);
        ctx.closePath();
        ctx.fillStyle = '#10b981';  // GREEN
        ctx.fill();
        
        // Draw small red slice for expense (if any)
        if (expense > 0 && expenseAngle > 0.01) {
            ctx.beginPath();
            ctx.arc(cx, cy, r, startAngle + incomeAngle, startAngle + incomeAngle + expenseAngle);
            ctx.arc(cx, cy, innerR, startAngle + incomeAngle + expenseAngle, startAngle + incomeAngle, true);
            ctx.closePath();
            ctx.fillStyle = '#ef4444';  // RED
            ctx.fill();
        }

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
    // CQC PROJECT PIE CHARTS - Modern Elegant with Shadows
    <?php 
    $projectColors = [
        ['#10b981', '#34d399'], // Emerald
        ['#f59e0b', '#fbbf24'], // Amber
        ['#3b82f6', '#60a5fa'], // Blue
        ['#8b5cf6', '#a78bfa'], // Violet
        ['#ec4899', '#f472b6'], // Pink
        ['#06b6d4', '#22d3ee'], // Cyan
        ['#84cc16', '#a3e635'], // Lime
        ['#f97316', '#fb923c'], // Orange
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
        
        // Create elegant gradient
        const chartCtx = ctx.getContext('2d');
        const gradient = chartCtx.createLinearGradient(0, 0, 100, 100);
        gradient.addColorStop(0, '<?php echo $color1; ?>');
        gradient.addColorStop(1, '<?php echo $color2; ?>');
        
        new Chart(chartCtx, {
            type: 'doughnut',
            data: {
                labels: ['Progress', 'Remaining'],
                datasets: [{
                    data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                    backgroundColor: [gradient, '#f3f4f6'],
                    borderWidth: 0,
                    borderRadius: 8,
                    hoverBackgroundColor: ['<?php echo $color1; ?>', '#e5e7eb'],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '70%',
                rotation: -90,
                circumference: 360,
                plugins: { 
                    legend: { display: false }, 
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(17, 24, 39, 0.95)', 
                        titleColor: '<?php echo $color2; ?>', 
                        bodyColor: '#e5e7eb',
                        cornerRadius: 12, 
                        padding: 14,
                        displayColors: false,
                        titleFont: { size: 13, weight: '700' },
                        bodyFont: { size: 12 },
                        callbacks: {
                            label: function(ctx) { return ctx.label + ': ' + ctx.parsed + '%'; }
                        }
                    }
                },
                animation: { 
                    animateRotate: true, 
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });
        
        // Category Expense Pie Chart
        <?php 
        $catExpenses = $cqcCategoryExpenses[$proj['id']] ?? [];
        if (!empty($catExpenses)):
            $catColors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4'];
            $catLabels = array_map(function($c) { return $c['category_name']; }, $catExpenses);
            $catValues = array_map(function($c) { return floatval($c['total_amount']); }, $catExpenses);
            $catColorsJson = array_slice($catColors, 0, count($catExpenses));
        ?>
        const catCtx<?php echo $idx; ?> = document.getElementById('cqcCatPie<?php echo $idx; ?>');
        if (catCtx<?php echo $idx; ?>) {
            new Chart(catCtx<?php echo $idx; ?>.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($catLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($catValues); ?>,
                        backgroundColor: <?php echo json_encode($catColorsJson); ?>,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '55%',
                    plugins: { 
                        legend: { display: false }, 
                        tooltip: {
                            backgroundColor: '#374151', 
                            bodyColor: '#e5e7eb',
                            cornerRadius: 8, 
                            padding: 8,
                            displayColors: true,
                            callbacks: {
                                label: function(ctx) { 
                                    return ctx.label + ': Rp ' + ctx.parsed.toLocaleString(); 
                                }
                            }
                        }
                    },
                    animation: { animateRotate: true, duration: 600 }
                }
            });
        }
        <?php endif; ?>
    })();
    <?php endforeach; ?>
    <?php endif; ?>
    
    // Toggle expense detail with smooth animation
    function toggleExpenseDetail(idx) {
        const detail = document.getElementById('expenseDetail' + idx);
        const hint = document.getElementById('clickHint' + idx);
        if (detail.style.display === 'none') {
            detail.style.display = 'block';
            detail.style.animation = 'fadeIn 0.3s ease';
            hint.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg> tutup detail';
        } else {
            detail.style.display = 'none';
            hint.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg> tap untuk detail';
        }
    }
    </script>
    <style>
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    </style>
</body>
</html>
