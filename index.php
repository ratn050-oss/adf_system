<?php
/**
 * MULTI-BUSINESS MANAGEMENT SYSTEM
 * Dashboard - Main Page
 */

ob_start();

define('APP_ACCESS', true);
require_once 'config/config.php';

// Check if database exists, redirect to installer if not
try {
    $testConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
    // Database not exists, redirect to setup page
    header('Location: setup-required.html');
    exit;
}

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/trial_check.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// Load business configuration (already loaded in config.php, use safe fallback)
$businessConfigFile = __DIR__ . '/config/businesses/' . ACTIVE_BUSINESS_ID . '.php';
$businessConfig = file_exists($businessConfigFile) ? require $businessConfigFile : $BUSINESS_CONFIG;

// Check trial status
$currentUser = $auth->getCurrentUser();
$trialStatus = checkTrialStatus($currentUser);

// Get WhatsApp number from settings
$waSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'developer_whatsapp'");
$developerWA = $waSetting['setting_value'] ?? null;

// Get company name from settings, fallback to BUSINESS_NAME
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : BUSINESS_NAME;

$pageTitle = BUSINESS_ICON . ' ' . $displayCompanyName;
$pageSubtitle = 'Dashboard & Monitoring Real-time';

// ============================================
// CQC-SPECIFIC COLOR PALETTE
// ============================================
$isCQC = (strtolower(ACTIVE_BUSINESS_ID) === 'cqc');
// Primary glow/tint color (replaces purple rgba(99,102,241,...))
$cPrimaryRgb = $isCQC ? '240, 180, 41' : '99, 102, 241';
// Secondary tint (replaces secondary purple rgba(139,92,246,...))
$cSecondaryRgb = $isCQC ? '13, 31, 60' : '139, 92, 246';
// Action button color (replaces blue #0071e3)
$cAccent = $isCQC ? '#0d1f3c' : '#0071e3';
$cAccentDark = $isCQC ? '#122a4e' : '#0055b8';
// Action button rgb (replaces blue rgba(0,113,227,...))
$cAccentRgb = $isCQC ? '13, 31, 60' : '0, 113, 227';
// Kas tersedia highlight color
$cKasColor = $isCQC ? '#f0b429' : '#0071e3';

// Get date range (today, this month, this year)
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear = date('Y');

// ============================================
// EXCLUDE OWNER CAPITAL FROM OPERATIONAL STATS
// ============================================
// First check if cash_account_id column exists in cash_book (may not exist on hosting)
$hasCashAccountIdCol = false;
try {
    $colCheck = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    $hasCashAccountIdCol = $colCheck && $colCheck->rowCount() > 0;
} catch (\Throwable $e) {
    $hasCashAccountIdCol = false;
}

// Also check if transaction_time column exists
$hasTransactionTimeCol = true;
try {
    $db->getConnection()->query("SELECT transaction_time FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasTransactionTimeCol = false;
}

// Get owner capital account IDs to exclude from operational income
$ownerCapitalAccountIds = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessId = getMasterBusinessId();
    
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching owner capital accounts: " . $e->getMessage());
}

// Build exclusion clause - ONLY if cash_account_id column exists in cash_book
$excludeOwnerCapital = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Colors for divisions
$divisionColors = [
    '#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', 
    '#3b82f6', '#ef4444', '#14b8a6', '#f97316', '#06b6d4', '#8b5cf6'
];

// ============================================
// TODAY STATISTICS (Exclude Owner Capital)
// ============================================
$todayIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND transaction_date = :date" . $excludeOwnerCapital,
    ['date' => $today]
);
$todayIncome = ['total' => $todayIncomeResult[0]['total'] ?? 0];

$todayExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND transaction_date = :date",
    ['date' => $today]
);
$todayExpense = ['total' => $todayExpenseResult[0]['total'] ?? 0];

// ============================================
// MONTHLY STATISTICS (Exclude Owner Capital)
// ============================================
$monthlyIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = :month" . $excludeOwnerCapital,
    ['month' => $thisMonth]
);
$monthlyIncome = ['total' => $monthlyIncomeResult[0]['total'] ?? 0];

$monthlyExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = :month",
    ['month' => $thisMonth]
);
$monthlyExpense = ['total' => $monthlyExpenseResult[0]['total'] ?? 0];

// ============================================
// YEARLY STATISTICS (Exclude Owner Capital)
// ============================================
$yearlyIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND YEAR(transaction_date) = :year" . $excludeOwnerCapital,
    ['year' => $thisYear]
);
$yearlyIncome = ['total' => $yearlyIncomeResult[0]['total'] ?? 0];

$yearlyExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND YEAR(transaction_date) = :year",
    ['year' => $thisYear]
);
$yearlyExpense = ['total' => $yearlyExpenseResult[0]['total'] ?? 0];

// ============================================
// CURRENT BALANCE (YEARLY)
// ============================================
$totalBalance = ($yearlyIncome['total'] ?? 0) - ($yearlyExpense['total'] ?? 0);

// ============================================
// ALL TIME CASH (REAL MONEY - Only Cash Payment Method)
// ============================================
$allTimeCashResult = $db->fetchOne(
    "SELECT SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as balance FROM cash_book WHERE payment_method = 'cash'" . $excludeOwnerCapital
);
$totalRealCash = $allTimeCashResult['balance'] ?? 0;

// ============================================
// KAS OPERASIONAL HARIAN (This Month) - From Master DB
// ============================================
try {
    // Get owner capital account from master database
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessId = getMasterBusinessId();
    
    // Get ALL owner_capital account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get ALL cash (Petty Cash) account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $capitalStats = [
        'received' => 0,
        'used' => 0,
        'balance' => 0
    ];
    
    $pettyCashStats = [
        'received' => 0,
        'used' => 0,
        'balance' => 0
    ];
    
    // Query Modal Owner stats - only if cash_account_id column exists
    if ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        
        $query = "
            SELECT 
                SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as received,
                SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as used,
                (SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END)) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";
        
        $params = array_merge($capitalAccounts, [$thisMonth]);
        $result = $db->fetchOne($query, $params);
        
        $capitalStats['received'] = $result['received'] ?? 0;
        $capitalStats['used'] = $result['used'] ?? 0;
        $capitalStats['balance'] = $result['balance'] ?? 0;
    }
    
    // Query Petty Cash stats (Only cash payment method) - only if cash_account_id column exists
    if ($hasCashAccountIdCol && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        
        $query = "
            SELECT 
                SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as received,
                SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as used,
                (SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END)) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
            AND payment_method = 'cash'
        ";
        
        $params = array_merge($pettyCashAccounts, [$thisMonth]);
        $result = $db->fetchOne($query, $params);
        
        $pettyCashStats['received'] = $result['received'] ?? 0;
        $pettyCashStats['used'] = $result['used'] ?? 0;
        $pettyCashStats['balance'] = $result['balance'] ?? 0;
    }
    
    // TOTAL KAS OPERASIONAL = Petty Cash + Modal Owner (physical cash available)
    $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];
    
    // TOTAL PENGELUARAN OPERASIONAL = Petty Cash expense + Modal Owner expense
    $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];
    
    // TOTAL UANG MASUK = Petty Cash received + Modal Owner received
    $totalOperationalIncome = $pettyCashStats['received'] + $capitalStats['received'];
    
    // ============================================
    // START KAS AWAL HARI INI
    // Saldo sebelum hari ini (sisa uang kemarin)
    // ============================================
    $today = date('Y-m-d');
    $startKasOwner = 0;
    $startKasPetty = 0;
    
    // Modal Owner: all transactions before today
    if ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $qStart = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date < ?";
        $pStart = array_merge($capitalAccounts, [$today]);
        $rStart = $db->fetchOne($qStart, $pStart);
        $startKasOwner = $rStart['bal'] ?? 0;
    }
    
    // Petty Cash: all cash transactions before today
    if ($hasCashAccountIdCol && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $qStart = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND payment_method='cash' AND transaction_date < ?";
        $pStart = array_merge($pettyCashAccounts, [$today]);
        $rStart = $db->fetchOne($qStart, $pStart);
        $startKasPetty = $rStart['bal'] ?? 0;
    }
    
    $startKasHariIni = $startKasOwner + $startKasPetty;
    
    // Today's transactions
    $todayIncome = 0;
    $todayExpense = 0;
    if ($hasCashAccountIdCol && (!empty($capitalAccounts) || !empty($pettyCashAccounts))) {
        $allAccIds = array_merge($capitalAccounts, $pettyCashAccounts);
        $placeholders = implode(',', array_fill(0, count($allAccIds), '?'));
        $qToday = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as inc,
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as exp
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date = ?";
        $pToday = array_merge($allAccIds, [$today]);
        $rToday = $db->fetchOne($qToday, $pToday);
        $todayIncome = $rToday['inc'] ?? 0;
        $todayExpense = $rToday['exp'] ?? 0;
    }
    
} catch (Exception $e) {
    error_log("Error fetching operational cash stats: " . $e->getMessage());
    $capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $totalOperationalCash = 0;
    $totalOperationalExpense = 0;
    $totalOperationalIncome = 0;
    $startKasHariIni = 0;
    $todayIncome = 0;
    $todayExpense = 0;
}

// ============================================
// TOP DIVISIONS (This Month)
// ============================================
// Exclude owner capital ONLY from income, not from expense
$divisionOwnerCapitalFilter = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $divisionOwnerCapitalFilter = " AND (cb.transaction_type = 'expense' OR cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$topDivisions = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE 0 END), 0) as income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE -cb.amount END), 0) as net
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $divisionOwnerCapitalFilter . "
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    ORDER BY net DESC
    LIMIT 5",
    ['month' => $thisMonth]
);

// ============================================
// RECENT TRANSACTIONS
// ============================================
$recentTransactions = $db->fetchAll(
    "SELECT 
        cb.*,
        COALESCE(d.division_name, 'Unknown') as division_name,
        COALESCE(c.category_name, 'Unknown') as category_name,
        COALESCE(u.full_name, 'System') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN users u ON cb.created_by = u.id
    ORDER BY cb.transaction_date DESC, cb.id DESC
    LIMIT 10"
);

// ============================================
// CHART DATA - Division Income (Pie Chart)
// ============================================
// Exclude owner capital from income
$divisionIncomeFilter = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $divisionIncomeFilter = " AND (cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$divisionIncomeData = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(cb.amount), 0) as total
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_type = 'income'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $divisionIncomeFilter . "
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $thisMonth]
);

// ============================================
// CHART DATA - Expense per Division (for pie chart)
// ============================================
$expenseDivisionData = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(cb.amount), 0) as total
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_type = 'expense'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $thisMonth]
);

// ============================================
// CHART DATA - Daily Income vs Expense (Monthly View)
// ============================================
$selectedMonth = isset($_GET['chart_month']) ? $_GET['chart_month'] : date('Y-m');

// Get first and last day of selected month
$firstDay = $selectedMonth . '-01';
$lastDay = date('Y-m-t', strtotime($firstDay));
$daysInMonth = date('t', strtotime($firstDay));

// Generate all dates in the month
$dates = [];
for ($i = 1; $i <= $daysInMonth; $i++) {
    $dates[] = $selectedMonth . '-' . sprintf('%02d', $i);
}

// Get actual transaction data for the month
// IMPORTANT: Exclude owner capital ONLY from income (not from expense!)
$transData = $db->fetchAll(
    "SELECT 
        DATE(transaction_date) as date,
        SUM(CASE WHEN transaction_type = 'income'" . $excludeOwnerCapital . " THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as expense
    FROM cash_book
    WHERE DATE_FORMAT(transaction_date, '%Y-%m') = :month
    GROUP BY DATE(transaction_date)
    ORDER BY date ASC",
    ['month' => $selectedMonth]
);

// Map transaction data by date
$transMap = [];
foreach ($transData as $data) {
    $transMap[$data['date']] = $data;
}

// Fill all days in month (missing dates will have 0 values)
$dailyData = [];
foreach ($dates as $date) {
    $dailyData[] = [
        'date' => $date,
        'income' => isset($transMap[$date]) ? $transMap[$date]['income'] : 0,
        'expense' => isset($transMap[$date]) ? $transMap[$date]['expense'] : 0
    ];
}

// ============================================
// CHART DATA - Top Categories This Month
// ============================================
// Exclude owner capital ONLY from income, not from expense
$ownerCapitalFilter = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $ownerCapitalFilter = " AND (cb.transaction_type = 'expense' OR cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$topCategories = $db->fetchAll(
    "SELECT 
        c.category_name,
        d.division_name,
        SUM(cb.amount) as total,
        cb.transaction_type
    FROM cash_book cb
    JOIN categories c ON cb.category_id = c.id
    JOIN divisions d ON cb.division_id = d.id
    WHERE DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $ownerCapitalFilter . "
    GROUP BY c.id, c.category_name, d.division_name, cb.transaction_type
    ORDER BY total DESC
    LIMIT 10",
    ['month' => $thisMonth]
);

// ============================================
// CQC PROJECT DATA (if CQC business)
// ============================================
$cqcProjects = [];
if ($isCQC) {
    try {
        require_once __DIR__ . '/modules/cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();
        
        // Get total budget and spent directly (same as dashboard-2028.php)
        $totalsStmt = $cqcPdo->query("SELECT COALESCE(SUM(spent_idr), 0) as total_spent, COALESCE(SUM(budget_idr), 0) as total_budget FROM cqc_projects WHERE status != 'completed'");
        $projTotals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        $totalCqcBudget = (float)($projTotals['total_budget'] ?? 0);
        $totalCqcSpent = (float)($projTotals['total_spent'] ?? 0);
        
        // Get project list
        $stmt = $cqcPdo->query("
            SELECT p.id, p.project_name, p.project_code, p.status, 
                   p.progress_percentage, p.budget_idr, p.spent_idr,
                   p.client_name, p.location, p.solar_capacity_kwp,
                   COALESCE(SUM(e.amount), 0) as actual_spent
            FROM cqc_projects p
            LEFT JOIN cqc_project_expenses e ON p.id = e.project_id
            GROUP BY p.id
            ORDER BY p.status ASC, p.progress_percentage DESC
        ");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update spent_idr with actual expense totals
        foreach ($cqcProjects as &$proj) {
            if ($proj['actual_spent'] > 0) {
                $proj['spent_idr'] = $proj['actual_spent'];
            }
        }
        unset($proj);
        
        // Override dailyData for CQC
        // Budget on first day, total spent on today
        $today = date('Y-m-d');
        $firstDay = true;
        foreach ($dailyData as &$day) {
            $day['income'] = $firstDay ? $totalCqcBudget : 0;
            $day['expense'] = ($day['date'] == $today) ? $totalCqcSpent : 0;
            $firstDay = false;
        }
        unset($day);
        
    } catch (Exception $e) {
        error_log('CQC project data error: ' . $e->getMessage());
    }
}

include 'includes/header.php';
?>

<?php if ($isCQC): ?>
<style>
/* CQC Theme - Gold accent, navy text */
:root,
body,
body[data-theme="light"],
body[data-theme="dark"] {
    --primary-color: #f0b429 !important;
    --primary-dark: #d4960d !important;
    --primary-light: #f5c842 !important;
    --secondary-color: #0d1f3c !important;
    --accent-color: #f0b429 !important;
}
</style>
<?php endif; ?>

<?php 
// Show trial notification if applicable
if ($trialStatus) {
    echo getTrialNotificationHtml($trialStatus, $developerWA);
}
?>

<!-- PREMIUM TRADING CHART - PALING ATAS -->
<div class="card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(<?php echo $cPrimaryRgb; ?>, 0.08), rgba(<?php echo $cSecondaryRgb; ?>, 0.05)); border: 2px solid rgba(<?php echo $cPrimaryRgb; ?>, 0.2); box-shadow: 0 10px 40px rgba(<?php echo $cPrimaryRgb; ?>, 0.15);">
    <div style="padding: 0.75rem; border-bottom: 1px solid rgba(<?php echo $cPrimaryRgb; ?>, 0.15); background: linear-gradient(90deg, rgba(<?php echo $cPrimaryRgb; ?>, 0.1), transparent);">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 0.95rem; color: var(--text-primary); font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0;">
                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); border-radius: 8px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(<?php echo $cPrimaryRgb; ?>, 0.3);">
                        <i data-feather="trending-up" style="width: 20px; height: 20px; color: white;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em;"><?php echo strtoupper(BUSINESS_NAME); ?></div>
                        <div style="font-size: 0.875rem;">Financial Performance Monitor</div>
                        <div style="font-size: 0.688rem; color: var(--success); font-weight: 600; margin-top: 0.125rem;">💰 Revenue / Pemasukan</div>
                    </div>
                </h3>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div id="liveIndicator" style="display: flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.75rem; background: rgba(16, 185, 129, 0.15); border-radius: 20px; border: 2px solid rgba(16, 185, 129, 0.3);">
                    <span style="width: 6px; height: 6px; background: var(--success); border-radius: 50%; animation: pulse 2s infinite; box-shadow: 0 0 8px var(--success);"></span>
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--success); text-transform: uppercase; letter-spacing: 0.05em;">Live</span>
                </div>
                
                <!-- Toggle View Type -->
                <div style="display: flex; align-items: center; gap: 0.25rem; background: var(--bg-tertiary); padding: 0.25rem; border-radius: 8px;">
                    <button id="btnDaily" onclick="switchView('daily')" class="btn-view-toggle" style="padding: 0.35rem 0.75rem; border: none; background: transparent; color: var(--text-muted); border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        📅 Harian
                    </button>
                    <button id="btnMonthly" onclick="switchView('monthly')" class="btn-view-toggle active" style="padding: 0.35rem 0.75rem; border: none; background: var(--primary-color); color: white; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        📆 Bulanan
                    </button>
                    <button id="btnYearly" onclick="switchView('yearly')" class="btn-view-toggle" style="padding: 0.35rem 0.75rem; border: none; background: transparent; color: var(--text-muted); border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        📊 Tahunan
                    </button>
                    <button id="btnAllTime" onclick="switchView('alltime')" class="btn-view-toggle" style="padding: 0.35rem 0.75rem; border: none; background: transparent; color: var(--text-muted); border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        🌍 All Time
                    </button>
                </div>
                
                <div id="dailyFilter" style="display: none; align-items: center; gap: 0.5rem;">
                    <input type="date" id="chartDateFilter" value="<?php echo date('Y-m-d'); ?>" 
                           class="form-control" style="max-width: 150px; height: 36px; font-size: 0.75rem; font-weight: 600; border: 2px solid var(--bg-tertiary);"
                           onchange="updateChartDate(this.value)">
                </div>
                
                <div id="monthlyFilter" style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="month" name="chart_month" id="chartMonthFilter" value="<?php echo $selectedMonth; ?>" 
                           class="form-control" style="max-width: 150px; height: 36px; font-size: 0.75rem; font-weight: 600; border: 2px solid var(--bg-tertiary);"
                           onchange="updateChartMonth(this.value)">
                </div>
                
                <div id="yearlyFilter" style="display: none; align-items: center; gap: 0.5rem;">
                    <label style="font-size: 0.875rem; color: var(--text-muted); font-weight: 600;">Tahun:</label>
                    <select id="chartYearFilter" class="form-control" style="max-width: 140px; height: 42px; font-size: 0.875rem; font-weight: 600; border: 2px solid var(--bg-tertiary);" onchange="updateChartYear(this.value)">
                        <?php for($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
    <div style="position: relative; height: 320px; padding: 1rem;">
        <canvas id="tradingChart"></canvas>
    </div>
    <div style="padding: 1rem; border-top: 1px solid rgba(<?php echo $cPrimaryRgb; ?>, 0.15); background: var(--bg-secondary);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(<?php echo $cPrimaryRgb; ?>, 0.12), rgba(<?php echo $cSecondaryRgb; ?>, 0.05)); border-radius: 8px; border-left: 4px solid var(--primary-color);">
                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">Periode</div>
                <div id="periodDisplay" style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                    1 - <?php echo date('t', strtotime($firstDay)); ?> <?php echo date('M Y', strtotime($firstDay)); ?>
                </div>
            </div>
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.05)); border-radius: 8px; border-left: 4px solid var(--success);">
                <div style="font-size: 0.75rem; color: var(--success); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo $isCQC ? 'Total Budget' : 'Total Pemasukan'; ?></div>
                <div id="totalIncome" style="font-size: 1.5rem; font-weight: 800; color: var(--success);">
                    <?php 
                    $totalIncome = array_sum(array_column($dailyData, 'income'));
                    echo formatCurrency($totalIncome);
                    ?>
                </div>
            </div>
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.12), rgba(239, 68, 68, 0.05)); border-radius: 8px; border-left: 4px solid var(--danger);">
                <div style="font-size: 0.75rem; color: var(--danger); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo $isCQC ? 'Total Terpakai' : 'Total Pengeluaran'; ?></div>
                <div id="totalExpense" style="font-size: 1.5rem; font-weight: 800; color: var(--danger);">
                    <?php 
                    $totalExpense = array_sum(array_column($dailyData, 'expense'));
                    echo formatCurrency($totalExpense);
                    ?>
                </div>
            </div>
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(<?php echo $cPrimaryRgb; ?>, 0.12), rgba(<?php echo $cSecondaryRgb; ?>, 0.05)); border-radius: 8px; border-left: 4px solid var(--primary-color);">
                <div style="font-size: 0.75rem; color: var(--primary-color); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo $isCQC ? 'Sisa Budget' : 'Net Balance'; ?></div>
                <div id="netBalance" style="font-size: 1.5rem; font-weight: 800; color: <?php echo ($totalIncome - $totalExpense) >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo formatCurrency($totalIncome - $totalExpense); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(1.1); }
}

/* Card hover effects for operational section */
div[style*="grid-template-columns: repeat(4"] > div {
    cursor: pointer;
}

div[style*="grid-template-columns: repeat(4"] > div:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important;
    border-color: rgba(<?php echo $cAccentRgb; ?>, 0.15) !important;
}

div[style*="grid-template-columns: repeat(4"] > div:hover .card-top-bar {
    opacity: 1 !important;
}
</style>

<?php if (!$isCQC): ?>
<!-- KAS OPERASIONAL HARIAN Widget -->
<div class="card fade-in" style="margin-bottom: 1rem; background: #fff; border: 1px solid #e5e7eb;">
    <div style="padding: 1rem 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <h3 style="font-size: 0.95rem; color: #111827; font-weight: 700; margin: 0;">
                Kas Operasional Harian
                <span style="font-size: 0.75rem; color: #9ca3af; font-weight: 400; margin-left: 0.5rem;"><?php echo date('F Y'); ?></span>
            </h3>
            <a href="modules/owner/owner-capital-monitor.php" style="padding: 0.55rem 1rem; background: linear-gradient(135deg, <?php echo $cAccent; ?> 0%, <?php echo $cAccentDark; ?> 100%); color: white; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(<?php echo $cAccentRgb; ?>, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(<?php echo $cAccentRgb; ?>, 0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(<?php echo $cAccentRgb; ?>, 0.3)'">
                📋 Detail Monitor
            </a>
        </div>
        
        <!-- START KAS + KAS TERSEDIA -->
        <div style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.85) 0%, rgba(240, 249, 255, 0.5) 100%); backdrop-filter: blur(10px); padding: 1.25rem 1.5rem; border-radius: 14px; border: 1px solid rgba(<?php echo $cAccentRgb; ?>, 0.1); display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); transition: all 0.3s ease;">
            <div>
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 700; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.5px;">Start Kas Hari Ini (<?php echo date('d M'); ?>)</div>
                <div style="font-size: 1.625rem; font-weight: 800; color: #1a1a1a; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($startKasHariIni); ?></div>
            </div>
            <div style="width: 1.5px; height: 56px; background: linear-gradient(180deg, transparent, rgba(<?php echo $cAccentRgb; ?>, 0.1), transparent);"></div>
            <div style="text-align: right;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 700; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.5px;">Kas Tersedia Sekarang</div>
                <div style="font-size: 1.625rem; font-weight: 800; color: <?php echo $cKasColor; ?>; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($totalOperationalCash); ?></div>
            </div>
        </div>
        
        <!-- Detail breakdown: 4 columns -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.875rem;">
            <!-- Modal Owner -->
            <div style="background: white; padding: 1rem; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.06); box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, #10b981, #34d399); opacity: 0; transition: opacity 0.3s ease;" class="card-top-bar"></div>
                <div style="font-size: 0.7rem; color: #8b8b8f; font-weight: 700; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.4px;">Modal Owner</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #1a1a1a; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($capitalStats['balance']); ?></div>
            </div>
            <!-- Petty Cash -->
            <div style="background: white; padding: 1rem; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.06); box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, #f59e0b, #fbbf24); opacity: 0; transition: opacity 0.3s ease;" class="card-top-bar"></div>
                <div style="font-size: 0.7rem; color: #8b8b8f; font-weight: 700; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.4px;">Petty Cash</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #1a1a1a; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($pettyCashStats['balance']); ?></div>
            </div>
            <!-- Uang Masuk Bulan Ini -->
            <div style="background: white; padding: 1rem; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.06); box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, #34c759, #34d399); opacity: 0; transition: opacity 0.3s ease;" class="card-top-bar"></div>
                <div style="font-size: 0.7rem; color: #8b8b8f; font-weight: 700; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.4px;">Uang Masuk</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #16a34a; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($totalOperationalIncome); ?></div>
                <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.35rem; font-weight: 600;">Uang masuk: Owner + Petty Cash</div>
            </div>
            <!-- Uang Keluar Bulan Ini -->
            <div style="background: white; padding: 1rem; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.06); box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden;">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, #ff3b30, #fb7185); opacity: 0; transition: opacity 0.3s ease;" class="card-top-bar"></div>
                <div style="font-size: 0.7rem; color: #8b8b8f; font-weight: 700; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.4px;">Uang Keluar</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #dc2626; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($totalOperationalExpense); ?></div>
            </div>
        </div>
        
        <?php if ($totalOperationalCash < 0): ?>
        <div style="margin-top: 0.75rem; padding: 0.6rem 1rem; background: #fef2f2; border-left: 3px solid #dc2626; border-radius: 6px;">
            <div style="font-size: 0.8rem; color: #dc2626; font-weight: 600;">
                ⚠️ Kas operasional negatif! Perlu tambah modal.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Charts & Data - 3 Pie Charts -->
<?php endif; // !$isCQC - end kas operasional + charts hide ?>

<?php if ($isCQC): ?>
<!-- ============================================ -->
<!-- CQC PROJECT OVERVIEW - PIE CHARTS -->
<!-- ============================================ -->
<style>
.cqc-project-card { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.25rem; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.cqc-project-card:hover { box-shadow: 0 8px 24px rgba(13,31,60,0.12); transform: translateY(-2px); }
.cqc-chart-container { position: relative; width: 160px; height: 160px; margin: 0 auto 1rem; }
.cqc-center-pct { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
.cqc-center-pct .pct-value { font-size: 1.5rem; font-weight: 800; color: #0d1f3c; }
.cqc-center-pct .pct-label { font-size: 0.65rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
.cqc-stat-row { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #f3f4f6; }
.cqc-stat-row:last-child { border-bottom: none; }
.cqc-stat-label { font-size: 0.75rem; color: #6b7280; display: flex; align-items: center; gap: 0.35rem; }
.cqc-stat-value { font-size: 0.85rem; font-weight: 700; font-family: 'Monaco', 'Courier New', monospace; }
.cqc-status-badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 20px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
.cqc-status-planning { background: #eef2ff; color: #4a6cf7; }
.cqc-status-procurement { background: #fef3c7; color: #d97706; }
.cqc-status-installation { background: #dbeafe; color: #2563eb; }
.cqc-status-testing { background: #fce7f3; color: #db2777; }
.cqc-status-completed { background: #d1fae5; color: #059669; }
.cqc-status-on_hold { background: #f3f4f6; color: #6b7280; }
</style>

<?php if (!empty($cqcProjects)): ?>
<!-- CQC Header with Detail Monitor Button -->
<div class="card fade-in" style="margin-bottom: 1rem; background: linear-gradient(135deg, rgba(13, 31, 60, 0.02), rgba(240, 180, 41, 0.05)); border: 1px solid rgba(240, 180, 41, 0.2);">
    <div style="padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 0.95rem; color: #0d1f3c; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 0.75rem;">
            <span style="width: 36px; height: 36px; background: linear-gradient(135deg, #f0b429, #d4960d); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; box-shadow: 0 4px 12px rgba(240, 180, 41, 0.3);">☀️</span>
            <div>
                <div style="font-size: 0.65rem; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em;">CQC ENJINIRING</div>
                <div style="font-size: 0.95rem;">Project Monitoring Overview</div>
            </div>
        </h3>
        <a href="modules/owner/cqc-dashboard.php" style="padding: 0.6rem 1.25rem; background: linear-gradient(135deg, #0d1f3c 0%, #1a3a5c 100%); color: #f0b429; border-radius: 10px; text-decoration: none; font-size: 0.85rem; font-weight: 700; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(13, 31, 60, 0.3); display: flex; align-items: center; gap: 0.5rem;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(13, 31, 60, 0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(13, 31, 60, 0.3)'">
            📋 Detail Monitor
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <?php
    $totalBudget = array_sum(array_column($cqcProjects, 'budget_idr'));
    $totalSpent = array_sum(array_column($cqcProjects, 'spent_idr'));
    $totalRemaining = $totalBudget - $totalSpent;
    $avgProgress = count($cqcProjects) > 0 ? round(array_sum(array_column($cqcProjects, 'progress_percentage')) / count($cqcProjects)) : 0;
    ?>
    <div class="card fade-in" style="padding: 1rem; border-left: 4px solid #f0b429;">
        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total Proyek</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #0d1f3c;"><?php echo count($cqcProjects); ?></div>
    </div>
    <div class="card fade-in" style="padding: 1rem; border-left: 4px solid #10b981;">
        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total Budget</div>
        <div style="font-size: 1.1rem; font-weight: 800; color: #10b981; font-family: 'Monaco', monospace;">Rp <?php echo number_format($totalBudget, 0, ',', '.'); ?></div>
    </div>
    <div class="card fade-in" style="padding: 1rem; border-left: 4px solid #ef4444;">
        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total Pengeluaran</div>
        <div style="font-size: 1.1rem; font-weight: 800; color: #ef4444; font-family: 'Monaco', monospace;">Rp <?php echo number_format($totalSpent, 0, ',', '.'); ?></div>
    </div>
    <div class="card fade-in" style="padding: 1rem; border-left: 4px solid #3b82f6;">
        <div style="font-size: 0.7rem; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Rata-rata Progress</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #3b82f6;"><?php echo $avgProgress; ?>%</div>
    </div>
</div>

<!-- Project Pie Charts Grid -->
<div class="card fade-in" style="margin-bottom: 1.25rem; padding: 1.25rem;">
    <h3 style="font-size: 1rem; font-weight: 700; color: #0d1f3c; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
        <span style="width: 32px; height: 32px; background: linear-gradient(135deg, #f0b429, #d4960d); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">📊</span>
        Pencapaian & Keuangan Per Proyek
    </h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.25rem;">
        <?php foreach ($cqcProjects as $idx => $proj): 
            $budget = floatval($proj['budget_idr'] ?? 0);
            $spent = floatval($proj['spent_idr'] ?? 0);
            $remaining = $budget - $spent;
            $progress = intval($proj['progress_percentage'] ?? 0);
            $spentPct = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
            $statusClass = 'cqc-status-' . ($proj['status'] ?? 'planning');
            $statusLabels = ['planning'=>'Planning','procurement'=>'Procurement','installation'=>'Instalasi','testing'=>'Testing','completed'=>'Selesai','on_hold'=>'Ditunda'];
            $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
        ?>
        <div class="cqc-project-card">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                <div>
                    <div style="font-size: 0.65rem; color: #9ca3af; font-weight: 600;"><?php echo htmlspecialchars($proj['project_code']); ?></div>
                    <div style="font-size: 0.9rem; font-weight: 700; color: #0d1f3c;"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                    <?php if ($proj['client_name']): ?>
                    <div style="font-size: 0.7rem; color: #6b7280;">👤 <?php echo htmlspecialchars($proj['client_name']); ?></div>
                    <?php endif; ?>
                </div>
                <span class="cqc-status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
            </div>
            
            <!-- Pie Chart -->
            <div class="cqc-chart-container">
                <canvas id="cqcPie<?php echo $idx; ?>"></canvas>
                <div class="cqc-center-pct">
                    <div class="pct-value"><?php echo $progress; ?>%</div>
                    <div class="pct-label">Progress</div>
                </div>
            </div>
            
            <!-- Financial Stats -->
            <div style="margin-top: 0.5rem;">
                <div class="cqc-stat-row">
                    <span class="cqc-stat-label">💰 Budget</span>
                    <span class="cqc-stat-value" style="color: #0d1f3c;">Rp <?php echo number_format($budget, 0, ',', '.'); ?></span>
                </div>
                <div class="cqc-stat-row">
                    <span class="cqc-stat-label">📤 Uang Keluar</span>
                    <span class="cqc-stat-value" style="color: #ef4444;">Rp <?php echo number_format($spent, 0, ',', '.'); ?></span>
                </div>
                <div class="cqc-stat-row">
                    <span class="cqc-stat-label">💵 Sisa Budget</span>
                    <span class="cqc-stat-value" style="color: <?php echo $remaining >= 0 ? '#10b981' : '#ef4444'; ?>;">Rp <?php echo number_format($remaining, 0, ',', '.'); ?></span>
                </div>
                <div class="cqc-stat-row">
                    <span class="cqc-stat-label">📊 Budget Terpakai</span>
                    <span class="cqc-stat-value" style="color: <?php echo $spentPct > 90 ? '#ef4444' : ($spentPct > 70 ? '#f59e0b' : '#10b981'); ?>;"><?php echo $spentPct; ?>%</span>
                </div>
            </div>
            
            <a href="modules/cqc-projects/detail.php?id=<?php echo $proj['id']; ?>" 
               style="display: block; text-align: center; margin-top: 0.75rem; padding: 0.5rem; background: linear-gradient(135deg, #0d1f3c, #1a3a5c); color: #f0b429; border-radius: 8px; text-decoration: none; font-size: 0.75rem; font-weight: 700; transition: all 0.3s ease;"
               onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(13,31,60,0.3)';"
               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                Lihat Detail →
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Overall Budget Pie Chart -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
    <div class="card fade-in" style="padding: 1.25rem;">
        <h3 style="font-size: 0.9rem; font-weight: 700; color: #0d1f3c; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.4rem;">
            💰 Distribusi Budget per Proyek
        </h3>
        <div style="position: relative; height: 280px;">
            <canvas id="cqcBudgetPie"></canvas>
        </div>
    </div>
    <div class="card fade-in" style="padding: 1.25rem;">
        <h3 style="font-size: 0.9rem; font-weight: 700; color: #0d1f3c; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.4rem;">
            📊 Budget vs Pengeluaran Semua Proyek
        </h3>
        <div style="position: relative; height: 280px;">
            <canvas id="cqcBudgetVsSpentChart"></canvas>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card fade-in" style="padding: 2rem; text-align: center;">
    <div style="font-size: 3rem; margin-bottom: 0.75rem;">☀️</div>
    <h3 style="font-size: 1rem; color: #0d1f3c; font-weight: 700; margin-bottom: 0.5rem;">Belum Ada Proyek</h3>
    <p style="color: #6b7280; font-size: 0.85rem; margin-bottom: 1rem;">Tambahkan proyek pertama Anda untuk melihat grafik pencapaian.</p>
    <a href="modules/cqc-projects/add.php" style="padding: 0.6rem 1.5rem; background: linear-gradient(135deg, #0d1f3c, #1a3a5c); color: #f0b429; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.85rem;">+ Tambah Proyek</a>
</div>
<?php endif; ?>
<?php else: // not CQC ?>

<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem;">
    
    <!-- Pie Chart 1 - Income per Division -->
    <div class="card">
        <div style="padding: 0.75rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                <i data-feather="pie-chart" style="width: 16px; height: 16px; color: var(--success);"></i>
                Pemasukan per Divisi
            </h3>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                <input type="month" id="divisionIncomeMonth" value="<?php echo $thisMonth; ?>" 
                       class="form-control" style="font-size: 0.75rem; height: 32px; padding: 0.25rem 0.5rem; flex: 1;"
                       onchange="updateDivisionIncomeChart(this.value)">
            </div>
        </div>
        <div style="position: relative; height: 280px; padding: 1rem;">
            <?php if (empty($divisionIncomeData)): ?>
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);">
                    <i data-feather="inbox" style="width: 40px; height: 40px; margin-bottom: 0.5rem;"></i>
                    <p style="margin: 0; font-size: 0.813rem;">Belum ada data</p>
                </div>
            <?php else: ?>
                <canvas id="divisionPieChart"></canvas>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Pie Chart 2 - Expense per Division (NEW) -->
    <div class="card">
        <div style="padding: 0.75rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                <i data-feather="pie-chart" style="width: 16px; height: 16px; color: var(--danger);"></i>
                Pengeluaran per Divisi
            </h3>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                <input type="month" id="expenseCategoryMonth" value="<?php echo $thisMonth; ?>" 
                       class="form-control" style="font-size: 0.75rem; height: 32px; padding: 0.25rem 0.5rem; flex: 1;"
                       onchange="updateExpenseCategoryChart(this.value)">
            </div>
        </div>
        <div style="position: relative; height: 280px; padding: 1rem;">
            <?php if (empty($expenseDivisionData)): ?>
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);">
                    <i data-feather="inbox" style="width: 40px; height: 40px; margin-bottom: 0.5rem;"></i>
                    <p style="margin: 0; font-size: 0.813rem;">Belum ada data</p>
                </div>
            <?php else: ?>
                <canvas id="expenseCategoryChart"></canvas>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Daily Activity Summary -->
    <div class="card">
        <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                <i data-feather="activity" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                Ringkasan Aktivitas Bulan Ini
            </h3>
        </div>
        <div style="padding: 1rem;">
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: var(--bg-tertiary); border-radius: 8px;">
                    <span style="font-size: 0.875rem; color: var(--text-muted);">Total Hari Transaksi</span>
                    <span style="font-size: 1.25rem; font-weight: 800; color: var(--primary-color);">
                        <?php echo count(array_filter($dailyData, function($d) { return $d['income'] > 0 || $d['expense'] > 0; })); ?> hari
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05)); border-radius: 8px;">
                    <span style="font-size: 0.875rem; color: var(--success);">Rata-rata Pemasukan/Hari</span>
                    <span style="font-size: 1.25rem; font-weight: 800; color: var(--success);">
                        <?php 
                        $activeDays = count(array_filter($dailyData, function($d) { return $d['income'] > 0 || $d['expense'] > 0; }));
                        echo formatCurrency($activeDays > 0 ? array_sum(array_column($dailyData, 'income')) / $activeDays : 0);
                        ?>
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05)); border-radius: 8px;">
                    <span style="font-size: 0.875rem; color: var(--danger);">Rata-rata Pengeluaran/Hari</span>
                    <span style="font-size: 1.25rem; font-weight: 800; color: var(--danger);">
                        <?php 
                        echo formatCurrency($activeDays > 0 ? array_sum(array_column($dailyData, 'expense')) / $activeDays : 0);
                        ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; // else not CQC - end charts section ?>

<?php if (!$isCQC): ?>
<!-- Top Categories & Top Divisions - Compact -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
    
    <!-- Top Categories Chart -->
    <div class="card">
        <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                <i data-feather="trending-up" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                Top 10 Kategori Transaksi
            </h3>
        </div>
        <div style="position: relative; height: 240px; padding: 0.75rem 0.5rem;">
            <?php if (empty($topCategories)): ?>
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);">
                    <i data-feather="inbox" style="width: 40px; height: 40px; margin-bottom: 0.5rem;"></i>
                    <p style="margin: 0; font-size: 0.813rem;">Belum ada data transaksi</p>
                </div>
            <?php else: ?>
                <canvas id="topCategoriesChart"></canvas>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
    
    <!-- Top 5 Divisions -->
    <div class="card">
        <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                <i data-feather="award" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                Top 5 Divisi
            </h3>
        </div>
        
        <?php if (empty($topDivisions)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 1.25rem; font-size: 0.813rem;">Belum ada data</p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.5rem; padding: 0.5rem 0;">
                <?php foreach ($topDivisions as $index => $division): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-md);">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem; font-size: 0.813rem;">
                                #<?php echo $index + 1; ?> <?php echo $division['division_name']; ?>
                            </div>
                            <div style="font-size: 0.688rem; color: var(--text-muted);">
                                <span class="text-success">+<?php echo formatCurrency($division['income']); ?></span>
                                <span style="margin: 0 0.25rem;">•</span>
                                <span class="text-danger">-<?php echo formatCurrency($division['expense']); ?></span>
                            </div>
                        </div>
                        <div style="font-weight: 800; font-size: 0.938rem; color: <?php echo $division['net'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                            <?php echo formatCurrency($division['net']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; // !$isCQC top categories ?>

<?php if (!$isCQC): ?>
<!-- Recent Transactions - Full Width -->
<div class="card">
    <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary); margin-bottom: 0.5rem;">
        <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
            <i data-feather="clock" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
            Transaksi Terakhir
        </h3>
    </div>
    
    <?php if (empty($recentTransactions)): ?>
        <p style="color: var(--text-muted); text-align: center; padding: 1.25rem; font-size: 0.813rem;">Belum ada transaksi</p>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.4rem; padding: 0.5rem 0;">
            <?php foreach ($recentTransactions as $trans): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.65rem; border-bottom: 1px solid var(--bg-tertiary);">
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.1rem; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?php echo $trans['division_name']; ?> - <?php echo $trans['category_name']; ?>
                        </div>
                        <div style="font-size: 0.688rem; color: var(--text-muted);">
                            <?php echo formatDate($trans['transaction_date']); ?>
                        </div>
                    </div>
                    <div style="text-align: right; margin-left: 0.5rem;">
                        <div style="font-weight: 700; font-size: 0.875rem; color: <?php echo $trans['transaction_type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>; white-space: nowrap;">
                            <?php echo $trans['transaction_type'] === 'income' ? '+' : '-'; ?><?php echo formatCurrency($trans['amount']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <div style="margin-top: 0.65rem; text-align: center;">
        <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="btn btn-secondary btn-sm">
            Lihat Semua →
        </a>
    </div>
</div>

<?php endif; // !$isCQC recent transactions ?>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
    // Initialize Feather Icons
    feather.replace();
    
    // ============================================
    // CHART CONFIGURATION
    // ============================================
    Chart.defaults.font.family = "'Inter', sans-serif";
    
    // Dynamic chart colors based on theme
    function getChartTextColor() {
        const isLight = document.body.getAttribute('data-theme') === 'light';
        // Light theme: dark text, Dark theme: light text
        return isLight ? '#475569' : '#94a3b8';
    }
    
    function getLegendTextColor() {
        const isLight = document.body.getAttribute('data-theme') === 'light';
        // Light theme: dark text, Dark theme: light text
        return isLight ? '#1e293b' : '#e2e8f0';
    }
    
    Chart.defaults.color = getChartTextColor();
    
    // Update chart colors when theme changes
    function updateChartColors() {
        Chart.defaults.color = getChartTextColor();
        // Update all chart instances
        Chart.instances.forEach(chart => {
            if (chart.options.plugins && chart.options.plugins.legend) {
                chart.options.plugins.legend.labels.color = getLegendTextColor();
            }
            if (chart.options.scales) {
                if (chart.options.scales.x && chart.options.scales.x.ticks) {
                    chart.options.scales.x.ticks.color = getChartTextColor();
                }
                if (chart.options.scales.y && chart.options.scales.y.ticks) {
                    chart.options.scales.y.ticks.color = getChartTextColor();
                }
            }
            chart.update();
        });
    }
    
    // Listen for theme changes
    const themeObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.attributeName === 'data-theme') {
                updateChartColors();
            }
        });
    });
    themeObserver.observe(document.body, { attributes: true });
    
    // ============================================
    // PIE CHART - Division Income
    // ============================================
    <?php if (!$isCQC && !empty($divisionIncomeData)): ?>
    const divisionPieCtx = document.getElementById('divisionPieChart').getContext('2d');
    let divisionPieChart = new Chart(divisionPieCtx, {
        type: 'doughnut',
        data: {
            labels: [
                <?php foreach ($divisionIncomeData as $index => $div): ?>
                    '<?php echo $div['division_name']; ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Pemasukan',
                data: [
                    <?php foreach ($divisionIncomeData as $div): ?>
                        <?php echo $div['total']; ?>,
                    <?php endforeach; ?>
                ],
                backgroundColor: [
                    <?php foreach ($divisionIncomeData as $index => $div): ?>
                        '<?php echo $divisionColors[$index % count($divisionColors)]; ?>',
                    <?php endforeach; ?>
                ],
                borderWidth: 0,
                hoverOffset: 20
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 12,
                        font: { 
                            size: 10, 
                            weight: '400',
                            family: "'Inter', sans-serif"
                        },
                        color: getLegendTextColor(),
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        boxHeight: 8
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 10,
                    titleFont: { size: 11, weight: '500' },
                    bodyFont: { size: 10, weight: '400' },
                    titleColor: 'rgba(255, 255, 255, 0.9)',
                    bodyColor: 'rgba(255, 255, 255, 0.85)',
                    cornerRadius: 6,
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.parsed || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = ((value / total) * 100).toFixed(1);
                            return label + ': Rp ' + value.toLocaleString('id-ID') + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    // ============================================
    // PIE CHART 2 - Expense per Division (NEW)
    // ============================================
    <?php if (!$isCQC && !empty($expenseDivisionData)): ?>
    const expenseCategoryCtx = document.getElementById('expenseCategoryChart').getContext('2d');
    let expenseCategoryChart = new Chart(expenseCategoryCtx, {
        type: 'doughnut',
        data: {
            labels: [
                <?php foreach ($expenseDivisionData as $index => $div): ?>
                    '<?php echo $div['division_name']; ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Pengeluaran',
                data: [
                    <?php foreach ($expenseDivisionData as $div): ?>
                        <?php echo $div['total']; ?>,
                    <?php endforeach; ?>
                ],
                backgroundColor: [
                    'rgba(239, 68, 68, 0.8)',
                    'rgba(251, 146, 60, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(234, 179, 8, 0.8)',
                    'rgba(132, 204, 22, 0.8)',
                    'rgba(34, 197, 94, 0.8)',
                    'rgba(20, 184, 166, 0.8)',
                    'rgba(6, 182, 212, 0.8)',
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(99, 102, 241, 0.8)',
                    'rgba(139, 92, 246, 0.8)',
                    'rgba(168, 85, 247, 0.8)'
                ],
                borderWidth: 0,
                hoverOffset: 20
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 12,
                        font: { 
                            size: 10, 
                            weight: '400' 
                        },
                        color: getLegendTextColor(),
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        boxHeight: 8
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 10,
                    titleFont: { size: 11, weight: '500' },
                    bodyFont: { size: 10, weight: '400' },
                    titleColor: 'rgba(255, 255, 255, 0.9)',
                    bodyColor: 'rgba(255, 255, 255, 0.85)',
                    cornerRadius: 6,
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.parsed || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = ((value / total) * 100).toFixed(1);
                            return label + ': Rp ' + value.toLocaleString('id-ID') + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    <?php if (!$isCQC): ?>
    // Function to update division income chart
    function updateDivisionIncomeChart(month) {
        fetch(`api/division-income-data.php?month=${month}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.divisions.length > 0) {
                    divisionPieChart.data.labels = data.divisions;
                    divisionPieChart.data.datasets[0].data = data.amounts;
                    divisionPieChart.update();
                } else {
                    // Show empty state
                    divisionPieChart.data.labels = [];
                    divisionPieChart.data.datasets[0].data = [];
                    divisionPieChart.update();
                }
            })
            .catch(error => console.error('Error updating division income chart:', error));
    }
    
    // Function to update expense category chart
    function updateExpenseCategoryChart(month) {
        fetch(`api/expense-category-data.php?month=${month}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.categories.length > 0) {
                    expenseCategoryChart.data.labels = data.categories;
                    expenseCategoryChart.data.datasets[0].data = data.amounts;
                    expenseCategoryChart.update();
                } else {
                    // Show empty state
                    expenseCategoryChart.data.labels = [];
                    expenseCategoryChart.data.datasets[0].data = [];
                    expenseCategoryChart.update();
                }
            })
            .catch(error => console.error('Error updating expense category chart:', error));
    }
    <?php endif; // !$isCQC pie chart functions ?>
    
    // ============================================
    // PREMIUM TRADING LINE CHART
    // ============================================
    <?php if (!empty($dailyData)): ?>
    const tradingCtx = document.getElementById('tradingChart').getContext('2d');
    
    // Calculate cumulative balance for net line
    let cumulativeBalance = [];
    let runningBalance = 0;
    <?php foreach ($dailyData as $data): ?>
        runningBalance += <?php echo $data['income'] - $data['expense']; ?>;
        cumulativeBalance.push(runningBalance);
    <?php endforeach; ?>
    
    // Create gradient for income line
    const incomeGradient = tradingCtx.createLinearGradient(0, 0, 0, 400);
    incomeGradient.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
    incomeGradient.addColorStop(0.5, 'rgba(16, 185, 129, 0.15)');
    incomeGradient.addColorStop(1, 'rgba(16, 185, 129, 0.02)');
    
    // Create gradient for expense line
    const expenseGradient = tradingCtx.createLinearGradient(0, 0, 0, 400);
    expenseGradient.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
    expenseGradient.addColorStop(0.5, 'rgba(239, 68, 68, 0.15)');
    expenseGradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');
    
    // Create gradient for net balance line
    const netGradient = tradingCtx.createLinearGradient(0, 0, 0, 400);
    netGradient.addColorStop(0, 'rgba(<?php echo $cPrimaryRgb; ?>, 0.3)');
    netGradient.addColorStop(0.5, 'rgba(<?php echo $cPrimaryRgb; ?>, 0.15)');
    netGradient.addColorStop(1, 'rgba(<?php echo $cPrimaryRgb; ?>, 0.02)');
    
    let tradingChart = new Chart(tradingCtx, {
        type: 'line',
        data: {
            labels: [
                <?php foreach ($dailyData as $data): ?>
                    <?php echo (int)date('d', strtotime($data['date'])); ?>,
                <?php endforeach; ?>
            ],
            datasets: [
                {
                    label: 'Pemasukan',
                    data: [
                        <?php foreach ($dailyData as $data): ?>
                            <?php echo $data['income']; ?>,
                        <?php endforeach; ?>
                    ],
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: incomeGradient,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointBackgroundColor: 'rgb(16, 185, 129)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgb(16, 185, 129)',
                    pointHoverBorderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    order: 2
                },
                {
                    label: 'Pengeluaran',
                    data: [
                        <?php foreach ($dailyData as $data): ?>
                            <?php echo $data['expense']; ?>,
                        <?php endforeach; ?>
                    ],
                    borderColor: 'rgb(239, 68, 68)',
                    backgroundColor: expenseGradient,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointBackgroundColor: 'rgb(239, 68, 68)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgb(239, 68, 68)',
                    pointHoverBorderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    order: 3
                },
                {
                    label: 'Net Balance (Kumulatif)',
                    data: cumulativeBalance,
                    borderColor: 'rgb(<?php echo $cPrimaryRgb; ?>)',
                    backgroundColor: netGradient,
                    borderWidth: 4,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: 'rgb(<?php echo $cPrimaryRgb; ?>)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 3,
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgb(<?php echo $cPrimaryRgb; ?>)',
                    pointHoverBorderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    borderDash: [0],
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            animation: {
                duration: 1500,
                easing: 'easeInOutQuart'
            },
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        padding: 15,
                        font: { 
                            size: 10, 
                            weight: '400',
                            family: "'Inter', sans-serif"
                        },
                        color: getLegendTextColor(),
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        boxHeight: 8
                    }
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    titleColor: 'rgba(255, 255, 255, 0.9)',
                    bodyColor: 'rgba(255, 255, 255, 0.85)',
                    borderColor: 'rgba(<?php echo $cPrimaryRgb; ?>, 0.3)',
                    borderWidth: 1,
                    padding: 12,
                    titleFont: { 
                        size: 11, 
                        weight: '500',
                        family: "'Inter', sans-serif"
                    },
                    bodyFont: { 
                        size: 10,
                        weight: '400',
                        family: "'Inter', sans-serif"
                    },
                    cornerRadius: 8,
                    displayColors: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    boxPadding: 4,
                    callbacks: {
                        title: function(context) {
                            return 'Tanggal ' + context[0].label + ' <?php echo date('M Y', strtotime($firstDay)); ?>';
                        },
                        label: function(context) {
                            let label = context.dataset.label || '';
                            let value = context.parsed.y || 0;
                            return label + ': Rp ' + value.toLocaleString('id-ID');
                        },
                        footer: function(tooltipItems) {
                            let income = tooltipItems.find(item => item.dataset.label === 'Pemasukan')?.parsed.y || 0;
                            let expense = tooltipItems.find(item => item.dataset.label === 'Pengeluaran')?.parsed.y || 0;
                            let net = income - expense;
                            return '───────────────\nNet Hari Ini: Rp ' + net.toLocaleString('id-ID');
                        },
                        footerColor: function(tooltipItems) {
                            let income = tooltipItems[0].dataset.label === 'Pemasukan' ? tooltipItems[0].parsed.y : (tooltipItems[1]?.parsed.y || 0);
                            let expense = tooltipItems[1]?.dataset.label === 'Pengeluaran' ? tooltipItems[1].parsed.y : (tooltipItems[0]?.parsed.y || 0);
                            let net = income - expense;
                            return net >= 0 ? 'rgb(16, 185, 129)' : 'rgb(239, 68, 68)';
                        }
                    },
                    footerFont: {
                        size: 10,
                        weight: '400'
                    },
                    footerColor: 'rgba(255, 255, 255, 0.7)'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(148, 163, 184, 0.08)',
                        drawBorder: false,
                        lineWidth: 1
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        padding: 12,
                        font: {
                            size: 12,
                            weight: '600'
                        },
                        color: getChartTextColor(),
                        callback: function(value) {
                            if (value >= 1000000) {
                                return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                            } else if (value >= 1000) {
                                return 'Rp ' + (value / 1000).toFixed(0) + 'rb';
                            }
                            return 'Rp ' + value;
                        }
                    }
                },
                x: {
                    grid: {
                        display: true,
                        color: 'rgba(148, 163, 184, 0.05)',
                        drawBorder: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        padding: 10,
                        font: {
                            size: 12,
                            weight: '600'
                        },
                        color: getChartTextColor(),
                        maxRotation: 0,
                        minRotation: 0
                    }
                }
            }
        }
    });
    
    // ============================================
    // LIVE UPDATE - Auto refresh every 30 seconds
    // ============================================
    function updateLiveChart() {
        const selectedMonth = document.getElementById('chartMonthFilter').value;
        fetch(`api/live-chart-data.php?month=${selectedMonth}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Calculate cumulative balance
                    let cumulativeBalance = [];
                    let runningBalance = 0;
                    for (let i = 0; i < data.income.length; i++) {
                        runningBalance += (data.income[i] - data.expense[i]);
                        cumulativeBalance.push(runningBalance);
                    }
                    
                    // Update chart data
                    tradingChart.data.labels = data.labels;
                    tradingChart.data.datasets[0].data = data.income;
                    tradingChart.data.datasets[1].data = data.expense;
                    tradingChart.data.datasets[2].data = cumulativeBalance;
                    tradingChart.update('none'); // Update without animation
                    
                    // Update summary cards
                    const totalIncome = data.income.reduce((a, b) => a + b, 0);
                    const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                    const netBalance = totalIncome - totalExpense;
                    
                    document.getElementById('totalIncome').textContent = formatRupiah(totalIncome);
                    document.getElementById('totalExpense').textContent = formatRupiah(totalExpense);
                    document.getElementById('netBalance').textContent = formatRupiah(netBalance);
                    document.getElementById('netBalance').style.color = netBalance >= 0 ? 'var(--success)' : 'var(--danger)';
                    
                    console.log('Chart updated at:', data.timestamp);
                }
            })
            .catch(error => console.error('Error updating chart:', error));
    }
    
    // Update chart when month filter changes
    function updateChartMonth(month) {
        fetch(`api/live-chart-data.php?month=${month}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Calculate cumulative balance
                    let cumulativeBalance = [];
                    let runningBalance = 0;
                    for (let i = 0; i < data.income.length; i++) {
                        runningBalance += (data.income[i] - data.expense[i]);
                        cumulativeBalance.push(runningBalance);
                    }
                    
                    // Update chart with animation
                    tradingChart.data.labels = data.labels;
                    tradingChart.data.datasets[0].data = data.income;
                    tradingChart.data.datasets[1].data = data.expense;
                    tradingChart.data.datasets[2].data = cumulativeBalance;
                    tradingChart.update();
                    
                    // Update summary cards
                    const totalIncome = data.income.reduce((a, b) => a + b, 0);
                    const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                    const netBalance = totalIncome - totalExpense;
                    
                    document.getElementById('totalIncome').textContent = formatRupiah(totalIncome);
                    document.getElementById('totalExpense').textContent = formatRupiah(totalExpense);
                    document.getElementById('netBalance').textContent = formatRupiah(netBalance);
                    document.getElementById('netBalance').style.color = netBalance >= 0 ? 'var(--success)' : 'var(--danger)';
                    
                    // Update period display
                    const monthObj = new Date(month + '-01');
                    const monthStr = monthObj.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
                    const daysInMonth = new Date(monthObj.getFullYear(), monthObj.getMonth() + 1, 0).getDate();
                    document.getElementById('periodDisplay').textContent = '1 - ' + daysInMonth + ' ' + monthStr;
                    
                    // Update URL without reload
                    const url = new URL(window.location);
                    url.searchParams.set('chart_month', month);
                    window.history.pushState({}, '', url);
                }
            })
            .catch(error => console.error('Error updating chart:', error));
    }
    
    // Helper function to format currency
    function formatRupiah(amount) {
        return 'Rp ' + amount.toLocaleString('id-ID');
    }
    
    // Auto refresh every 30 seconds
    setInterval(updateLiveChart, 30000);
    
    // ============================================
    // SWITCH VIEW - Daily, Monthly, Yearly, All-Time
    // ============================================
    let currentView = 'monthly';
    
    function switchView(view) {
        currentView = view;
        
        // Update button styles
        const btnDaily = document.getElementById('btnDaily');
        const btnMonthly = document.getElementById('btnMonthly');
        const btnYearly = document.getElementById('btnYearly');
        const btnAllTime = document.getElementById('btnAllTime');
        const dailyFilter = document.getElementById('dailyFilter');
        const monthlyFilter = document.getElementById('monthlyFilter');
        const yearlyFilter = document.getElementById('yearlyFilter');
        
        // Reset all buttons
        [btnDaily, btnMonthly, btnYearly, btnAllTime].forEach(btn => {
            btn.style.background = 'transparent';
            btn.style.color = 'var(--text-muted)';
        });
        
        // Hide all filters
        dailyFilter.style.display = 'none';
        monthlyFilter.style.display = 'none';
        yearlyFilter.style.display = 'none';
        
        if (view === 'daily') {
            btnDaily.style.background = 'var(--primary-color)';
            btnDaily.style.color = 'white';
            dailyFilter.style.display = 'flex';
            
            // Load daily data (hourly breakdown)
            const selectedDate = document.getElementById('chartDateFilter').value;
            updateChartDate(selectedDate);
        } else if (view === 'monthly') {
            btnMonthly.style.background = 'var(--primary-color)';
            btnMonthly.style.color = 'white';
            monthlyFilter.style.display = 'flex';
            
            // Load monthly data (daily breakdown)
            const selectedMonth = document.getElementById('chartMonthFilter').value;
            updateChartMonth(selectedMonth);
        } else if (view === 'yearly') {
            btnYearly.style.background = 'var(--primary-color)';
            btnYearly.style.color = 'white';
            yearlyFilter.style.display = 'flex';
            
            // Load yearly data (monthly breakdown)
            const selectedYear = document.getElementById('chartYearFilter').value;
            updateChartYear(selectedYear);
        } else if (view === 'alltime') {
            btnAllTime.style.background = 'var(--primary-color)';
            btnAllTime.style.color = 'white';
            
            // Load all-time data (yearly breakdown)
            updateChartAllTime();
        }
    }
    
    function updateChartDate(date) {
        fetch(`api/daily-chart-data.php?date=${date}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Calculate cumulative balance
                    let cumulativeBalance = [];
                    let runningBalance = 0;
                    for (let i = 0; i < data.income.length; i++) {
                        runningBalance += (data.income[i] - data.expense[i]);
                        cumulativeBalance.push(runningBalance);
                    }
                    
                    // Update chart with animation
                    tradingChart.data.labels = data.labels;
                    tradingChart.data.datasets[0].data = data.income;
                    tradingChart.data.datasets[1].data = data.expense;
                    tradingChart.data.datasets[2].data = cumulativeBalance;
                    tradingChart.update();
                    
                    // Update summary cards
                    const totalIncome = data.income.reduce((a, b) => a + b, 0);
                    const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                    const netBalance = totalIncome - totalExpense;
                    
                    document.getElementById('totalIncome').textContent = formatRupiah(totalIncome);
                    document.getElementById('totalExpense').textContent = formatRupiah(totalExpense);
                    document.getElementById('netBalance').textContent = formatRupiah(netBalance);
                    document.getElementById('netBalance').style.color = netBalance >= 0 ? 'var(--success)' : 'var(--danger)';
                    
                    // Update period display
                    const dateObj = new Date(date);
                    const dateStr = dateObj.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
                    document.getElementById('periodDisplay').textContent = dateStr + ' (24 jam)';
                }
            })
            .catch(error => console.error('Error updating chart:', error));
    }
    
    function updateChartAllTime() {
        fetch(`api/alltime-chart-data.php`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Calculate cumulative balance
                    let cumulativeBalance = [];
                    let runningBalance = 0;
                    for (let i = 0; i < data.income.length; i++) {
                        runningBalance += (data.income[i] - data.expense[i]);
                        cumulativeBalance.push(runningBalance);
                    }
                    
                    // Update chart with animation
                    tradingChart.data.labels = data.labels;
                    tradingChart.data.datasets[0].data = data.income;
                    tradingChart.data.datasets[1].data = data.expense;
                    tradingChart.data.datasets[2].data = cumulativeBalance;
                    tradingChart.update();
                    
                    // Update summary cards
                    const totalIncome = data.income.reduce((a, b) => a + b, 0);
                    const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                    const netBalance = totalIncome - totalExpense;
                    
                    document.getElementById('totalIncome').textContent = formatRupiah(totalIncome);
                    document.getElementById('totalExpense').textContent = formatRupiah(totalExpense);
                    document.getElementById('netBalance').textContent = formatRupiah(netBalance);
                    document.getElementById('netBalance').style.color = netBalance >= 0 ? 'var(--success)' : 'var(--danger)';
                    
                    // Update period display
                    if (data.labels.length > 0) {
                        const firstYear = data.labels[0];
                        const lastYear = data.labels[data.labels.length - 1];
                        document.getElementById('periodDisplay').textContent = firstYear + ' - ' + lastYear + ' (' + data.labels.length + ' tahun)';
                    } else {
                        document.getElementById('periodDisplay').textContent = 'Tidak ada data';
                    }
                }
            })
            .catch(error => console.error('Error updating chart:', error));
    }
    
    function updateChartYear(year) {
        fetch(`api/yearly-chart-data.php?year=${year}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Calculate cumulative balance
                    let cumulativeBalance = [];
                    let runningBalance = 0;
                    for (let i = 0; i < data.income.length; i++) {
                        runningBalance += (data.income[i] - data.expense[i]);
                        cumulativeBalance.push(runningBalance);
                    }
                    
                    // Update chart with animation
                    tradingChart.data.labels = data.labels;
                    tradingChart.data.datasets[0].data = data.income;
                    tradingChart.data.datasets[1].data = data.expense;
                    tradingChart.data.datasets[2].data = cumulativeBalance;
                    tradingChart.update();
                    
                    // Update summary cards
                    const totalIncome = data.income.reduce((a, b) => a + b, 0);
                    const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                    const netBalance = totalIncome - totalExpense;
                    
                    document.getElementById('totalIncome').textContent = formatRupiah(totalIncome);
                    document.getElementById('totalExpense').textContent = formatRupiah(totalExpense);
                    document.getElementById('netBalance').textContent = formatRupiah(netBalance);
                    document.getElementById('netBalance').style.color = netBalance >= 0 ? 'var(--success)' : 'var(--danger)';
                    
                    // Update period display
                    document.getElementById('periodDisplay').textContent = 'Jan - Des ' + year + ' (12 bulan)';
                }
            })
            .catch(error => console.error('Error updating chart:', error));
    }
    <?php endif; ?>
    // ============================================
    // HORIZONTAL BAR CHART - Top Categories
    // ============================================
    // ============================================
    // CQC PROJECT PIE CHARTS
    // ============================================
    <?php if ($isCQC && !empty($cqcProjects)): ?>
    const cqcColors = ['#f0b429', '#0d1f3c', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6', '#f59e0b', '#ec4899', '#14b8a6', '#6366f1'];
    
    // Individual project doughnut charts
    <?php foreach ($cqcProjects as $idx => $proj): 
        $progress = intval($proj['progress_percentage'] ?? 0);
        $budget = floatval($proj['budget_idr'] ?? 0);
        $spent = floatval($proj['spent_idr'] ?? 0);
    ?>
    (function() {
        const ctx = document.getElementById('cqcPie<?php echo $idx; ?>');
        if (!ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Tersisa'],
                datasets: [{
                    data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                    backgroundColor: [
                        '<?php echo $progress >= 80 ? "#10b981" : ($progress >= 50 ? "#f0b429" : ($progress >= 25 ? "#3b82f6" : "#6b7280")); ?>',
                        'rgba(229, 231, 235, 0.5)'
                    ],
                    borderWidth: 0,
                    cutout: '75%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false }, tooltip: {
                    backgroundColor: 'rgba(13,31,60,0.95)', titleColor: '#f0b429', bodyColor: '#fff',
                    cornerRadius: 8, padding: 10,
                    callbacks: {
                        label: function(ctx) {
                            return ctx.label + ': ' + ctx.parsed + '%';
                        }
                    }
                }},
                animation: { animateRotate: true, duration: 1200 }
            }
        });
    })();
    <?php endforeach; ?>
    
    // Budget Distribution Pie
    (function() {
        const ctx = document.getElementById('cqcBudgetPie');
        if (!ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', array_map(function($p) { return "'" . addslashes($p['project_name']) . "'"; }, $cqcProjects)); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($cqcProjects, 'budget_idr')); ?>],
                    backgroundColor: cqcColors.slice(0, <?php echo count($cqcProjects); ?>),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '55%',
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 12, font: { size: 11, weight: '500' }, usePointStyle: true, pointStyle: 'circle', boxWidth: 8 } },
                    tooltip: {
                        backgroundColor: 'rgba(13,31,60,0.95)', titleColor: '#f0b429', bodyColor: '#fff', cornerRadius: 8, padding: 12,
                        callbacks: {
                            label: function(ctx) {
                                let total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                let pct = ((ctx.parsed / total) * 100).toFixed(1);
                                return ctx.label + ': Rp ' + ctx.parsed.toLocaleString('id-ID') + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    })();
    
    // Budget vs Spent Bar Chart
    (function() {
        const ctx = document.getElementById('cqcBudgetVsSpentChart');
        if (!ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(function($p) { return "'" . addslashes($p['project_code']) . "'"; }, $cqcProjects)); ?>],
                datasets: [
                    {
                        label: 'Budget',
                        data: [<?php echo implode(',', array_column($cqcProjects, 'budget_idr')); ?>],
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: '#10b981',
                        borderWidth: 2,
                        borderRadius: 6
                    },
                    {
                        label: 'Pengeluaran',
                        data: [<?php echo implode(',', array_column($cqcProjects, 'spent_idr')); ?>],
                        backgroundColor: 'rgba(239, 68, 68, 0.7)',
                        borderColor: '#ef4444',
                        borderWidth: 2,
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { padding: 12, font: { size: 11, weight: '500' }, usePointStyle: true, pointStyle: 'circle', boxWidth: 8 } },
                    tooltip: {
                        backgroundColor: 'rgba(13,31,60,0.95)', titleColor: '#f0b429', bodyColor: '#fff', cornerRadius: 8, padding: 12,
                        callbacks: {
                            label: function(ctx) { return ctx.dataset.label + ': Rp ' + ctx.parsed.y.toLocaleString('id-ID'); }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,0.08)' }, ticks: { callback: function(v) { return v >= 1000000 ? 'Rp ' + (v/1000000).toFixed(1) + 'jt' : 'Rp ' + (v/1000).toFixed(0) + 'rb'; }, font: { size: 10 } } },
                    x: { grid: { display: false }, ticks: { font: { size: 10, weight: '600' } } }
                }
            }
        });
    })();
    <?php endif; ?>
    
    <?php if (!empty($topCategories)): ?>
    const topCategoriesCtx = document.getElementById('topCategoriesChart').getContext('2d');
    new Chart(topCategoriesCtx, {
        type: 'bar',
        data: {
            labels: [
                <?php foreach ($topCategories as $cat): ?>
                    '<?php echo $cat['category_name']; ?> (<?php echo $cat['division_name']; ?>)',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Total Transaksi',
                data: [
                    <?php foreach ($topCategories as $cat): ?>
                        <?php echo $cat['total']; ?>,
                    <?php endforeach; ?>
                ],
                backgroundColor: [
                    <?php foreach ($topCategories as $index => $cat): ?>
                        '<?php echo $cat['transaction_type'] === 'income' ? 'rgba(16, 185, 129, 0.8)' : 'rgba(239, 68, 68, 0.8)'; ?>',
                    <?php endforeach; ?>
                ],
                borderColor: [
                    <?php foreach ($topCategories as $index => $cat): ?>
                        '<?php echo $cat['transaction_type'] === 'income' ? 'rgb(16, 185, 129)' : 'rgb(239, 68, 68)'; ?>',
                    <?php endforeach; ?>
                ],
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 12,
                    titleFont: { size: 14, weight: '700' },
                    bodyFont: { size: 13 },
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            let value = context.parsed.x || 0;
                            return 'Total: Rp ' + value.toLocaleString('id-ID');
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(148, 163, 184, 0.1)',
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                        },
                        font: { size: 12, weight: '600' },
                        color: getChartTextColor()
                    }
                },
                y: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: { size: 12, weight: '600' },
                        color: getLegendTextColor()
                    }
                }
            }
        }
    });
    <?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>