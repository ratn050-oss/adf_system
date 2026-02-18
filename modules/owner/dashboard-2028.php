<?php
/**
 * OWNER DASHBOARD 2028
 * Data langsung dari PHP - Same logic as System Dashboard (index.php)
 * Multi-business aware via business_helper.php
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
$allBusinesses = getAvailableBusinesses();
$activeBusinessId = getActiveBusinessId();
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
    
    // Get owner_capital account IDs from master DB
    $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get cash (Petty Cash) account IDs from master DB
    $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build exclude owner capital condition for income/expense totals
    $excludeOwnerCapital = '';
    if (!empty($capitalAccounts)) {
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
    if (!empty($capitalAccounts)) {
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
    if (!empty($pettyCashAccounts)) {
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
    echo '<div style="background:#fee;color:#b91c1c;padding:16px 20px;margin:20px 0;border-radius:8px;font-size:15px;font-family:monospace;">ERROR: '.htmlspecialchars($error).'</div>';
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
    <title>Owner Dashboard - Narayana Hotel</title>
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
            filter: drop-shadow(0 0 18px rgba(99,102,241,0.25)) drop-shadow(0 4px 8px rgba(0,0,0,0.3));
        }
        
        .pie-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50%;
            width: 72px;
            height: 72px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .pie-center-label {
            font-size: 7px;
            opacity: 0.6;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }
        
        .pie-center-value {
            font-size: 15px;
            font-weight: 800;
            background: linear-gradient(135deg, #34d399, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Legend - Glass Chips */
        .legend {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 0;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 10px;
            padding: 6px 10px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            transition: background 0.2s;
        }
        .legend-item:active {
            background: rgba(255,255,255,0.1);
        }
        
        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .legend-dot.income { background: #34d399; box-shadow: 0 0 8px rgba(52,211,153,0.5); }
        .legend-dot.expense { background: #fb7185; box-shadow: 0 0 8px rgba(251,113,133,0.5); }
        
        .legend-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        
        .legend-label {
            font-size: 8px;
            opacity: 0.5;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 500;
        }
        
        .legend-value {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: -0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
        }
        
        .operational-title {
            font-size: 11px;
            font-weight: 600;
            color: #0369a1;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .operational-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        
        .op-card {
            background: white;
            border-radius: 10px;
            padding: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        
        .op-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gradient-start), var(--gradient-end));
        }
        
        .op-card.modal-owner { --gradient-start: #10b981; --gradient-end: #34d399; }
        .op-card.petty-cash { --gradient-start: #f59e0b; --gradient-end: #fbbf24; }
        .op-card.digunakan { --gradient-start: #f43f5e; --gradient-end: #fb7185; }
        .op-card.total-kas { --gradient-start: #6366f1; --gradient-end: #818cf8; }
        
        .op-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            color: var(--gradient-start);
            margin-bottom: 4px;
        }
        
        .op-value {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .op-detail-btn {
            display: block;
            width: 100%;
            margin-top: 10px;
            padding: 8px 12px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .op-detail-btn:active {
            transform: scale(0.98);
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
        
        /* Hero Today Row - Glass Bar */
        .hero-today-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-radius: 14px;
            padding: 10px 12px;
            margin-top: 14px;
            gap: 4px;
        }
        .hero-today-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }
        .hero-today-label {
            font-size: 8px;
            opacity: 0.45;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 3px;
            font-weight: 500;
        }
        .hero-today-value {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: -0.2px;
        }
        .hero-today-value.income { color: #34d399; text-shadow: 0 0 12px rgba(52,211,153,0.3); }
        .hero-today-value.expense { color: #fb7185; text-shadow: 0 0 12px rgba(251,113,133,0.3); }
        .hero-today-divider {
            width: 1px;
            height: 28px;
            background: linear-gradient(180deg, transparent, rgba(255,255,255,0.15), transparent);
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
                width: 60px;
                height: 60px;
            }
            .pie-center-value { font-size: 13px; }
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

        <?php if ($error): ?>
            <div class="info-card error">
                <div class="info-card-icon">❌</div>
                <div class="info-card-content">
                    <div class="info-card-title">Connection Error</div>
                    <div class="info-card-value"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php else: ?>

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
        
        <!-- Hero with Pie Chart -->
        <div class="hero">
            <div class="hero-content">
                <div class="hero-title">Financial Performance</div>
                <div class="hero-subtitle"><?= date('F Y') ?> &nbsp;·&nbsp; <?= date('d M Y') ?></div>

                <div class="chart-container">
                    <!-- Pie Chart -->
                    <div class="pie-wrapper">
                        <canvas id="pieChart" width="160" height="160"></canvas>
                        <div class="pie-center">
                            <div class="pie-center-label">NET</div>
                            <div class="pie-center-value"><?= $netProfit >= 0 ? '+' : '' ?><?= number_format($netProfit/1000000, 1) ?>M</div>
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
                            <span class="legend-dot" style="background:linear-gradient(135deg,#a78bfa,#60a5fa);box-shadow:0 0 8px rgba(167,139,250,0.5)"></span>
                            <div class="legend-text">
                                <span class="legend-label">Profit</span>
                                <span class="legend-value" style="color:<?= $netProfit >= 0 ? '#34d399' : '#fb7185' ?>"><?= $netProfit >= 0 ? '+' : '' ?><?= rp($netProfit) ?></span>
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
                        <span class="hero-today-value" style="color:<?= $expenseRatio < 50 ? '#34d399' : ($expenseRatio < 75 ? '#fbbf24' : '#fb7185') ?>;text-shadow:0 0 12px <?= $expenseRatio < 50 ? 'rgba(52,211,153,0.3)' : ($expenseRatio < 75 ? 'rgba(251,191,36,0.3)' : 'rgba(251,113,133,0.3)') ?>"><?= number_format($expenseRatio, 1) ?>%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Operational Section - SAME DATA AS SYSTEM DASHBOARD -->
        <div class="operational-section">
            <div class="operational-title">💰 Daily Operational - <?= date('F Y') ?></div>
            <div style="font-size: 9px; color: #0369a1; margin-top: -8px; margin-bottom: 10px;">📊 Daily Operational Cash (Petty Cash + Owner Capital)</div>
            <div class="operational-grid">
                <div class="op-card modal-owner">
                    <div class="op-label">Owner Capital</div>
                    <div class="op-value"><?= rp($capitalStats['received']) ?></div>
                    <div style="font-size: 8px; color: #059669; margin-top: 2px;">Owner deposit</div>
                </div>
                <div class="op-card petty-cash">
                    <div class="op-label">Petty Cash</div>
                    <div class="op-value"><?= rp($pettyCashStats['balance']) ?></div>
                    <div style="font-size: 8px; color: #d97706; margin-top: 2px;">Cash from guests</div>
                </div>
                <div class="op-card digunakan">
                    <div class="op-label">Used</div>
                    <div class="op-value"><?= rp($totalOperationalExpense) ?></div>
                    <div style="font-size: 8px; color: #dc2626; margin-top: 2px;">Total expenses</div>
                </div>
                <div class="op-card total-kas">
                    <div class="op-label">Total Cash</div>
                    <div class="op-value"><?= rp($totalOperationalCash) ?></div>
                    <div style="font-size: 8px; color: #4f46e5; margin-top: 2px;">Available cash</div>
                </div>
            </div>
            <a href="<?= $basePath ?>/modules/owner/owner-capital-monitor.php" class="op-detail-btn">
                📋 Detail Monitor (Owner Capital & Petty Cash)
            </a>

            <!-- Pie Chart - Pengeluaran per Divisi -->
            <div class="expense-division-card">
                <div class="expense-division-header">
                    <div class="expense-division-title">
                        <span class="icon-circle">🥧</span>
                        Pengeluaran per Divisi
                    </div>
                    <input type="month" id="expenseDivisionMonth" class="expense-month-input" value="<?= $thisMonth ?>" onchange="updateExpenseDivisionChart(this.value)">
                </div>
                <div class="expense-division-body">
                    <?php if (empty($expenseDivisionData)): ?>
                        <div class="expense-division-empty">
                            <span style="font-size:28px;margin-bottom:6px;">📭</span>
                            Belum ada data pengeluaran
                        </div>
                    <?php else: ?>
                        <canvas id="expenseDivisionChart"></canvas>
                    <?php endif; ?>
                </div>
                <?php if (!empty($expenseDivisionData)): ?>
                <div class="expense-division-legend" id="expenseDivisionLegend">
                    <?php 
                    $divColors = [
                        'rgba(239,68,68,0.85)', 'rgba(251,146,60,0.85)', 'rgba(245,158,11,0.85)',
                        'rgba(234,179,8,0.85)', 'rgba(132,204,22,0.85)', 'rgba(34,197,94,0.85)',
                        'rgba(20,184,166,0.85)', 'rgba(6,182,212,0.85)', 'rgba(59,130,246,0.85)',
                        'rgba(99,102,241,0.85)', 'rgba(139,92,246,0.85)', 'rgba(168,85,247,0.85)'
                    ];
                    foreach ($expenseDivisionData as $i => $div): ?>
                        <div class="edl-item">
                            <span class="edl-dot" style="background:<?= $divColors[$i % count($divColors)] ?>"></span>
                            <?= htmlspecialchars($div['division_name']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
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

        <?php endif; // end else (no error) ?>

    </div><!-- end .container -->

    <!-- Footer Nav -->
    <nav class="nav-bottom">
        <a href="<?= $basePath ?>/modules/owner/dashboard-2028.php" class="nav-item active">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#127968;</span>
            <span>Home</span>
        </a>
        <?php if (in_array('frontdesk', $enabledModules)): ?>
        <a href="<?= $basePath ?>/modules/owner/frontdesk-mobile.php" class="nav-item">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#128197;</span>
            <span>Frontdesk</span>
        </a>
        <?php endif; ?>
        <?php if (in_array('project', $enabledModules) || in_array('investor', $enabledModules)): ?>
        <a href="<?= $basePath ?>/modules/owner/investor-monitor.php" class="nav-item">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#128200;</span>
            <span>Projects</span>
        </a>
        <?php endif; ?>
        <a href="<?= $basePath ?>/logout.php" class="nav-item">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#128682;</span>
            <span>Logout</span>
        </a>
    </nav>

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
                        body.innerHTML = '<div class="expense-division-empty"><span style="font-size:28px;margin-bottom:6px;">📭</span>Belum ada data pengeluaran</div>';
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
        function drawArc(start, end, gradient) {
            ctx.beginPath();
            ctx.arc(cx, cy, r, start, end);
            ctx.arc(cx, cy, innerR, end, start, true);
            ctx.closePath();
            ctx.fillStyle = gradient;
            ctx.fill();
        }

        // Income arc (green neon)
        var gIncome = ctx.createLinearGradient(0, 0, size, size);
        gIncome.addColorStop(0, '#10b981');
        gIncome.addColorStop(1, '#6ee7b7');
        drawArc(startAngle + gap/2, startAngle + incomeAngle - gap/2, gIncome);

        // Expense arc (red/coral)
        var gExpense = ctx.createLinearGradient(size, 0, 0, size);
        gExpense.addColorStop(0, '#f43f5e');
        gExpense.addColorStop(1, '#fda4af');
        drawArc(startAngle + incomeAngle + gap/2, startAngle + incomeAngle + expenseAngle - gap/2, gExpense);

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
    </script>
</body>
</html>
