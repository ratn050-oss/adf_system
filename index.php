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

// Load business configuration
$businessConfig = require 'config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

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

// Get date range (today, this month, this year)
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear = date('Y');

// ============================================
// EXCLUDE OWNER CAPITAL FROM OPERATIONAL STATS
// ============================================
// Get owner capital account IDs to exclude from operational income
$ownerCapitalAccountIds = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessIdentifier = ACTIVE_BUSINESS_ID;
    $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
    $businessId = $businessMapping[$businessIdentifier] ?? 1;
    
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching owner capital accounts: " . $e->getMessage());
}

// Build exclusion clause
$excludeOwnerCapital = '';
if (!empty($ownerCapitalAccountIds)) {
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
// ALL TIME CASH (REAL MONEY - Operational Only)
// ============================================
$allTimeCashResult = $db->fetchOne(
    "SELECT SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as balance FROM cash_book WHERE 1=1" . $excludeOwnerCapital
);
$totalRealCash = $allTimeCashResult['balance'] ?? 0;

// ============================================
// KAS OPERASIONAL HARIAN (This Month) - From Master DB
// ============================================
try {
    // Get owner capital account from master database
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // CRITICAL: ACTIVE_BUSINESS_ID is STRING identifier, convert to INT database ID
    $businessIdentifier = ACTIVE_BUSINESS_ID;
    $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
    $businessId = $businessMapping[$businessIdentifier] ?? 1;
    
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
    
    // Query Modal Owner stats
    if (!empty($capitalAccounts)) {
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
    
    // Query Petty Cash stats
    if (!empty($pettyCashAccounts)) {
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
    
} catch (Exception $e) {
    error_log("Error fetching operational cash stats: " . $e->getMessage());
    $capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $totalOperationalCash = 0;
    $totalOperationalExpense = 0;
}

// ============================================
// TOP DIVISIONS (This Month)
// ============================================
// Exclude owner capital ONLY from income, not from expense
$divisionOwnerCapitalFilter = '';
if (!empty($ownerCapitalAccountIds)) {
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
        d.division_name,
        c.category_name,
        u.full_name as created_by_name
    FROM cash_book cb
    JOIN divisions d ON cb.division_id = d.id
    JOIN categories c ON cb.category_id = c.id
    JOIN users u ON cb.created_by = u.id
    ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
    LIMIT 10"
);

// ============================================
// CHART DATA - Division Income (Pie Chart)
// ============================================
// Exclude owner capital from income
$divisionIncomeFilter = '';
if (!empty($ownerCapitalAccountIds)) {
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
if (!empty($ownerCapitalAccountIds)) {
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

include 'includes/header.php';
?>

<?php 
// Show trial notification if applicable
if ($trialStatus) {
    echo getTrialNotificationHtml($trialStatus, $developerWA);
}
?>

<!-- PREMIUM TRADING CHART - PALING ATAS -->
<div class="card" style="margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.05)); border: 2px solid rgba(99, 102, 241, 0.2); box-shadow: 0 10px 40px rgba(99, 102, 241, 0.15);">
    <div style="padding: 0.75rem; border-bottom: 1px solid rgba(99, 102, 241, 0.15); background: linear-gradient(90deg, rgba(99, 102, 241, 0.1), transparent);">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 0.95rem; color: var(--text-primary); font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0;">
                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); border-radius: 8px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
                        <i data-feather="trending-up" style="width: 20px; height: 20px; color: white;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em;">NARAYANA HOTEL</div>
                        <div style="font-size: 0.875rem;">Financial Performance Monitor</div>
                        <div style="font-size: 0.688rem; color: #10b981; font-weight: 600; margin-top: 0.125rem;">💰 Hotel Revenue / Pemasukan Hotel</div>
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
    <div style="padding: 1rem; border-top: 1px solid rgba(99, 102, 241, 0.15); background: var(--bg-secondary);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.05)); border-radius: 8px; border-left: 4px solid var(--success);">
                <div style="font-size: 0.75rem; color: var(--success); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">Total Pemasukan</div>
                <div id="totalIncome" style="font-size: 1.5rem; font-weight: 800; color: var(--success);">
                    <?php 
                    $totalIncome = array_sum(array_column($dailyData, 'income'));
                    echo formatCurrency($totalIncome);
                    ?>
                </div>
            </div>
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.12), rgba(239, 68, 68, 0.05)); border-radius: 8px; border-left: 4px solid var(--danger);">
                <div style="font-size: 0.75rem; color: var(--danger); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">Total Pengeluaran</div>
                <div id="totalExpense" style="font-size: 1.5rem; font-weight: 800; color: var(--danger);">
                    <?php 
                    $totalExpense = array_sum(array_column($dailyData, 'expense'));
                    echo formatCurrency($totalExpense);
                    ?>
                </div>
            </div>
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.12), rgba(139, 92, 246, 0.05)); border-radius: 8px; border-left: 4px solid var(--primary-color);">
                <div style="font-size: 0.75rem; color: var(--primary-color); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">Net Balance</div>
                <div id="netBalance" style="font-size: 1.5rem; font-weight: 800; color: <?php echo ($totalIncome - $totalExpense) >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo formatCurrency($totalIncome - $totalExpense); ?>
                </div>
            </div>
            <!-- Total Uang Cash (Real Money) -->
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(6, 182, 212, 0.12), rgba(6, 182, 212, 0.05)); border-radius: 8px; border-left: 4px solid #06b6d4;">
                <div style="font-size: 0.75rem; color: #0891b2; font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">Total Uang Cash</div>
                <div style="font-size: 1.5rem; font-weight: 800; color: #0891b2;">
                    <?php echo formatCurrency($totalRealCash); ?>
                </div>
            </div>
            <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.12), rgba(139, 92, 246, 0.05)); border-radius: 8px; border-left: 4px solid var(--primary-color);">
                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">Periode</div>
                <div id="periodDisplay" style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                    1 - <?php echo date('t', strtotime($firstDay)); ?> <?php echo date('M Y', strtotime($firstDay)); ?>
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
</style>

<!-- Secondary Stats - Compact -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <!-- Today Income -->
    <div class="card fade-in">
        <div style="padding: 0.875rem;">
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em;">Hari Ini</div>
            <div style="font-size: 1.125rem; font-weight: 800; color: var(--success); margin-bottom: 0.25rem;">
                <?php echo formatCurrency($todayIncome['total']); ?>
            </div>
            <div style="font-size: 0.688rem; color: var(--success);">↑ Pemasukan</div>
        </div>
    </div>
    
    <!-- Today Expense -->
    <div class="card fade-in">
        <div style="padding: 0.875rem;">
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em;">Hari Ini</div>
            <div style="font-size: 1.125rem; font-weight: 800; color: var(--danger); margin-bottom: 0.25rem;">
                <?php echo formatCurrency($todayExpense['total']); ?>
            </div>
            <div style="font-size: 0.688rem; color: var(--danger);">↓ Pengeluaran</div>
        </div>
    </div>
    
    <!-- Total Balance -->
    <div class="card fade-in">
        <div style="padding: 0.875rem;">
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em;">Saldo <?php echo $thisYear; ?></div>
            <div style="font-size: 1.125rem; font-weight: 800; color: <?php echo $totalBalance >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; margin-bottom: 0.25rem;">
                <?php echo formatCurrency($totalBalance); ?>
            </div>
            <div style="font-size: 0.688rem; color: var(--text-muted);">💰 Net Balance</div>
        </div>
    </div>
    
    <!-- Yearly Income -->
    <div class="card fade-in">
        <div style="padding: 0.875rem;">
            <div style="font-size: 0.688rem; color: var(--text-muted); margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em;">Total <?php echo $thisYear; ?></div>
            <div style="font-size: 1.125rem; font-weight: 800; color: var(--success); margin-bottom: 0.25rem;">
                <?php echo formatCurrency($yearlyIncome['total']); ?>
            </div>
            <div style="font-size: 0.688rem; color: var(--success);">📈 Pemasukan</div>
        </div>
    </div>
</div>

<!-- KAS OPERASIONAL HARIAN Widget -->
<div class="card fade-in" style="margin-bottom: 1.25rem; background: linear-gradient(135deg, #fff5f5 0%, #ffe4e6 100%); border: 2px solid #fbbf24;">
    <div style="padding: 1rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <h3 style="font-size: 1rem; color: #7c2d12; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                <span style="font-size: 1.5rem;">💰</span>
                <div>
                    <div>Daily Operational - <?php echo date('F Y'); ?></div>
                    <div style="font-size: 0.7rem; color: #d97706; font-weight: 500; margin-top: 0.125rem;">📊 Kas Operasional Harian (Petty Cash + Modal Owner)</div>
                </div>
            </h3>
            <a href="modules/owner/owner-capital-monitor.php" style="padding: 0.5rem 1rem; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border-radius: 8px; text-decoration: none; font-size: 0.813rem; font-weight: 600; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i data-feather="external-link" style="width: 14px; height: 14px; margin-right: 4px;"></i>
                Detail Monitor
            </a>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 0.75rem;">
            <!-- Modal dari Owner -->
            <div style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); padding: 0.875rem; border-radius: 8px; border-left: 4px solid #10b981;">
                <div style="font-size: 0.688rem; color: #065f46; font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">💵 Modal Owner</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #10b981;">
                    <?php echo formatCurrency($capitalStats['received']); ?>
                </div>
                <div style="font-size: 0.65rem; color: #059669; margin-top: 0.25rem;">Setoran owner</div>
            </div>
            
            <!-- Saldo Petty Cash (dari tamu) -->
            <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 0.875rem; border-radius: 8px; border-left: 4px solid #f59e0b;">
                <div style="font-size: 0.688rem; color: #78350f; font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">💰 Petty Cash</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #f59e0b;">
                    <?php echo formatCurrency($pettyCashStats['balance']); ?>
                </div>
                <div style="font-size: 0.65rem; color: #d97706; margin-top: 0.25rem;">Uang cash dari tamu</div>
            </div>
            
            <!-- Total Digunakan (Petty Cash + Modal Owner) -->
            <div style="background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%); padding: 0.875rem; border-radius: 8px; border-left: 4px solid #ef4444;">
                <div style="font-size: 0.688rem; color: #7f1d1d; font-weight: 600; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">💸 Digunakan</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #ef4444;">
                    <?php echo formatCurrency($totalOperationalExpense); ?>
                </div>
                <div style="font-size: 0.65rem; color: #dc2626; margin-top: 0.25rem;">Total pengeluaran operasional</div>
            </div>
            
            <!-- TOTAL KAS OPERASIONAL (HIGHLIGHTED - PALING AKHIR) -->
            <div style="background: linear-gradient(135deg, #dbeafe 0%, #93c5fd 100%); padding: 0.875rem; border-radius: 8px; border: 3px solid #3b82f6; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
                <div style="font-size: 0.688rem; color: #1e3a8a; font-weight: 700; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em;">🏦 TOTAL KAS</div>
                <div style="font-size: 1.4rem; font-weight: 900; color: #1e40af;">
                    <?php echo formatCurrency($totalOperationalCash); ?>
                </div>
                <div style="font-size: 0.65rem; color: #2563eb; margin-top: 0.25rem; font-weight: 600;">Uang cash tersedia</div>
            </div>
        </div>
        
        <!-- Info Box -->
        <div style="padding: 0.625rem; background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6; border-radius: 4px;">
            <div style="font-size: 0.75rem; color: #1e40af; font-weight: 600;">
                💡 <strong>Logika Operasional:</strong> Ketika bayar pengeluaran, sistem akan gunakan Petty Cash dulu. Jika kurang, baru potong dari Modal Owner.
            </div>
        </div>
        
        <?php if ($totalOperationalCash < 0): ?>
        <div style="margin-top: 0.75rem; padding: 0.625rem; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 4px;">
            <div style="font-size: 0.75rem; color: #991b1b; font-weight: 600;">
                ⚠️ Peringatan: Total kas operasional negatif! Perlu tambah modal dari owner.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Charts & Data - 3 Pie Charts -->
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
        const color = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim();
        return color || '#666';
    }
    
    function getLegendTextColor() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark' || 
                       window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        return isDark ? '#fff' : '#000';
    }
    
    Chart.defaults.color = getChartTextColor();
    
    // ============================================
    // PIE CHART - Division Income
    // ============================================
    <?php if (!empty($divisionIncomeData)): ?>
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
                        padding: 15,
                        font: { 
                            size: 14, 
                            weight: 'bold',
                            family: "'Inter', sans-serif"
                        },
                        color: getLegendTextColor(),
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 12,
                        boxHeight: 12
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 12,
                    titleFont: { size: 14, weight: '700' },
                    bodyFont: { size: 13 },
                    cornerRadius: 8,
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
    <?php if (!empty($expenseDivisionData)): ?>
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
                        padding: 15,
                        font: { 
                            size: 14, 
                            weight: 'bold' 
                        },
                        color: getLegendTextColor(),
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 12,
                        boxHeight: 12
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 12,
                    titleFont: { size: 14, weight: '700' },
                    bodyFont: { size: 13 },
                    cornerRadius: 8,
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
    netGradient.addColorStop(0, 'rgba(99, 102, 241, 0.3)');
    netGradient.addColorStop(0.5, 'rgba(99, 102, 241, 0.15)');
    netGradient.addColorStop(1, 'rgba(99, 102, 241, 0.02)');
    
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
                    borderColor: 'rgb(99, 102, 241)',
                    backgroundColor: netGradient,
                    borderWidth: 4,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: 'rgb(99, 102, 241)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 3,
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgb(99, 102, 241)',
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
                        padding: 20,
                        font: { 
                            size: 14, 
                            weight: 'bold',
                            family: "'Inter', sans-serif"
                        },
                        color: getLegendTextColor(),
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 10,
                        boxHeight: 10
                    }
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(15, 23, 42, 0.98)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: 'rgba(99, 102, 241, 0.5)',
                    borderWidth: 2,
                    padding: 16,
                    titleFont: { 
                        size: 15, 
                        weight: '800',
                        family: "'Inter', sans-serif"
                    },
                    bodyFont: { 
                        size: 14,
                        weight: '600',
                        family: "'Inter', sans-serif"
                    },
                    cornerRadius: 12,
                    displayColors: true,
                    boxWidth: 12,
                    boxHeight: 12,
                    boxPadding: 8,
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
                            return '─────────────────\nNet Hari Ini: Rp ' + net.toLocaleString('id-ID');
                        },
                        footerColor: function(tooltipItems) {
                            let income = tooltipItems[0].dataset.label === 'Pemasukan' ? tooltipItems[0].parsed.y : (tooltipItems[1]?.parsed.y || 0);
                            let expense = tooltipItems[1]?.dataset.label === 'Pengeluaran' ? tooltipItems[1].parsed.y : (tooltipItems[0]?.parsed.y || 0);
                            let net = income - expense;
                            return net >= 0 ? 'rgb(16, 185, 129)' : 'rgb(239, 68, 68)';
                        }
                    },
                    footerFont: {
                        size: 13,
                        weight: '700'
                    }
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