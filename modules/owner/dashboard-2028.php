<?php
/**
 * OWNER DASHBOARD 2028
 * Data langsung dari PHP - Same logic as System Dashboard (index.php)
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';

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

// DATABASE CONFIG
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
$businessDbName = $isProduction ? 'adfb2574_narayana_hotel' : 'adf_narayana_hotel';

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
    $businessId = 1; // Narayana Hotel
    
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

    // Query Petty Cash stats
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
    
    // Total transactions
    $stmt = $pdo->query("SELECT COUNT(*) FROM cash_book");
    $stats['total_transactions'] = (int)$stmt->fetchColumn();
    
    // Recent transactions
    $stmt = $pdo->query("SELECT id, transaction_date, description, transaction_type, amount FROM cash_book ORDER BY transaction_date DESC, id DESC LIMIT 10");
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
            font-size: 24px;
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
        
        /* Info Card */
        .info-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .info-card.error {
            background-color: #fff1f2;
            color: var(--danger);
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
        
        /* Hero Section with Pie Chart */
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        
        .hero-subtitle {
            font-size: 11px;
            opacity: 0.9;
            margin-bottom: 4px;
        }
        
        .hero-date {
            font-size: 10px;
            opacity: 0.7;
        }
        
        /* Chart Container */
        .chart-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin: 16px 0 0 0;
        }
        
        .pie-wrapper {
            position: relative;
            width: 140px;
            height: 140px;
        }
        
        #pieChart {
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.2));
        }
        
        .pie-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            width: 70px;
            height: 70px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .pie-center-label {
            font-size: 8px;
            opacity: 0.8;
            text-transform: uppercase;
        }
        
        .pie-center-value {
            font-size: 14px;
            font-weight: 700;
        }
        
        .legend {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .legend-dot.income { background: linear-gradient(135deg, #10b981, #34d399); }
        .legend-dot.expense { background: linear-gradient(135deg, #f43f5e, #fb7185); }
        
        .legend-text {
            display: flex;
            flex-direction: column;
        }
        
        .legend-label {
            font-size: 9px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .legend-value {
            font-size: 12px;
            font-weight: 600;
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
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .operational-title {
            font-size: 14px;
            font-weight: 600;
            color: #0369a1;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .operational-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        .op-card {
            background: white;
            border-radius: 14px;
            padding: 14px;
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
            height: 3px;
            background: linear-gradient(90deg, var(--gradient-start), var(--gradient-end));
        }
        
        .op-card.modal-owner { --gradient-start: #10b981; --gradient-end: #34d399; }
        .op-card.petty-cash { --gradient-start: #f59e0b; --gradient-end: #fbbf24; }
        .op-card.digunakan { --gradient-start: #f43f5e; --gradient-end: #fb7185; }
        .op-card.total-kas { --gradient-start: #6366f1; --gradient-end: #818cf8; }
        
        .op-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            color: var(--gradient-start);
            margin-bottom: 6px;
        }
        
        .op-value {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
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
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .tx-item:last-child { border-bottom: none; }
        
        .tx-desc {
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        
        .tx-date {
            font-size: 11px;
            color: var(--text-muted);
        }
        
        .tx-amount {
            font-size: 13px;
            font-weight: 600;
        }
        
        .tx-amount.income { color: var(--success); }
        .tx-amount.expense { color: var(--danger); }
        
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
        
        /* Hero Today Row */
        .hero-today-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.12);
            border-radius: 14px;
            padding: 12px 16px;
            margin-top: 20px;
            gap: 8px;
        }
        .hero-today-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }
        .hero-today-label {
            font-size: 10px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 4px;
        }
        .hero-today-value {
            font-size: 13px;
            font-weight: 700;
        }
        .hero-today-value.income { color: #34d399; }
        .hero-today-value.expense { color: #fb7185; }
        .hero-today-divider {
            width: 1px;
            height: 32px;
            background: rgba(255,255,255,0.2);
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
        
        /* Mobile Optimizations */
        @media (max-width: 400px) {
            .chart-container {
                flex-direction: column;
                gap: 16px;
            }
            .legend {
                flex-direction: row;
                justify-content: center;
                gap: 20px;
            }
            .operational-grid {
                grid-template-columns: 1fr;
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
                <span class="brand-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#127976;</span>
                <div class="brand-text">
                    Narayana Hotel
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
            <div class="info-card">
                <div class="info-card-icon">✅</div>
                <div class="info-card-content">
                    <div class="info-card-title">Connected · <?= $businessDbName ?></div>
                    <div class="info-card-value"><?= number_format($stats['total_transactions']) ?> Transactions recorded</div>
                </div>
                <div style="font-size:11px;color:var(--text-muted);white-space:nowrap"><?= date('d M Y') ?></div>
            </div>
        
        <!-- Hero with Pie Chart -->
        <div class="hero">
            <div class="hero-content">
                <div class="hero-title">Financial Performance</div>
                <div class="hero-subtitle"><?= date('F Y') ?> &nbsp;·&nbsp; <?= date('d M Y') ?></div>

                <div class="chart-container">
                    <!-- Pie Chart -->
                    <div class="pie-wrapper">
                        <canvas id="pieChart" width="140" height="140"></canvas>
                        <div class="pie-center">
                            <div class="pie-center-label">NET BULAN</div>
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
                            <span class="legend-dot" style="background:linear-gradient(135deg,#a78bfa,#818cf8)"></span>
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
                        <span class="hero-today-label">Hari Ini In</span>
                        <span class="hero-today-value income"><?= rp($stats['today_income']) ?></span>
                    </div>
                    <div class="hero-today-divider"></div>
                    <div class="hero-today-item">
                        <span class="hero-today-label">Hari Ini Out</span>
                        <span class="hero-today-value expense"><?= rp($stats['today_expense']) ?></span>
                    </div>
                    <div class="hero-today-divider"></div>
                    <div class="hero-today-item">
                        <span class="hero-today-label">Rasio Expense</span>
                        <span class="hero-today-value" style="color:<?= $expenseRatio < 50 ? '#34d399' : ($expenseRatio < 75 ? '#fbbf24' : '#fb7185') ?>"><?= number_format($expenseRatio, 1) ?>%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Operational Section - SAME DATA AS SYSTEM DASHBOARD -->
        <div class="operational-section">
            <div class="operational-title">💰 Kas Operasional Harian - <?= date('F Y') ?></div>
            <div class="operational-grid">
                <div class="op-card modal-owner">
                    <div class="op-label">💵 Modal Owner</div>
                    <div class="op-value"><?= rp($capitalStats['balance']) ?></div>
                </div>
                <div class="op-card petty-cash">
                    <div class="op-label">💰 Petty Cash</div>
                    <div class="op-value"><?= rp($pettyCashStats['balance']) ?></div>
                </div>
                <div class="op-card digunakan">
                    <div class="op-label">💸 Digunakan</div>
                    <div class="op-value"><?= rp($totalOperationalExpense) ?></div>
                </div>
                <div class="op-card total-kas">
                    <div class="op-label">💎 Total Kas</div>
                    <div class="op-value"><?= rp($totalOperationalCash) ?></div>
                </div>
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
                    echo "🟢 <strong>Excellent!</strong> Expense ratio " . number_format($expenseRatio, 1) . "% dari income. Keuangan sangat sehat dengan margin profit tinggi.";
                } elseif ($expenseRatio < 70) {
                    echo "🟡 <strong>Good.</strong> Expense ratio " . number_format($expenseRatio, 1) . "% dari income. Pertahankan efisiensi operasional.";
                } elseif ($expenseRatio < 90) {
                    echo "🟠 <strong>Warning.</strong> Expense ratio " . number_format($expenseRatio, 1) . "% dari income. Perlu optimasi pengeluaran untuk meningkatkan margin.";
                } else {
                    echo "🔴 <strong>Critical!</strong> Expense ratio " . number_format($expenseRatio, 1) . "% dari income. Segera evaluasi pengeluaran dan strategi revenue.";
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
            <div class="tx-title">⚡ Transaksi Terbaru</div>
            <ul class="tx-list">
                <?php foreach ($transactions as $tx): ?>
                <li class="tx-item">
                    <div>
                        <div class="tx-desc"><?= htmlspecialchars($tx['description'] ?? '-') ?></div>
                        <div class="tx-date"><?= date('d M Y', strtotime($tx['transaction_date'])) ?></div>
                    </div>
                    <div class="tx-amount <?= $tx['transaction_type'] ?>">
                        <?= $tx['transaction_type'] === 'income' ? '+' : '-' ?><?= rp($tx['amount']) ?>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <li class="tx-item" style="justify-content:center;color:var(--text-muted)">Belum ada transaksi</li>
                <?php endif; ?>
            </ul>
        </div>

        <?php endif; // end else (no error) ?>

    </div><!-- end .container -->

    <!-- Footer Nav -->
    <nav class="nav-bottom">
        <a href="<?= $basePath ?>/index.php" class="nav-item">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#127968;</span>
            <span>Home</span>
        </a>
        <a href="#" class="nav-item active">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#128202;</span>
            <span>Overview</span>
        </a>
        <a href="<?= $basePath ?>/modules/cashbook/index.php" class="nav-item">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#128179;</span>
            <span>Cashbook</span>
        </a>
        <a href="<?= $basePath ?>/logout.php" class="nav-item">
            <span class="nav-icon" style="font-family: 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;">&#128682;</span>
            <span>Logout</span>
        </a>
    </nav>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var canvas = document.getElementById('pieChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var income = <?= (float)$stats['month_income'] ?>;
        var expense = <?= (float)$stats['month_expense'] ?>;
        var total = income + expense;
        if (total === 0) { income = 1; expense = 1; total = 2; }

        var cx = 70, cy = 70, r = 60, innerR = 35;
        var startAngle = -Math.PI / 2;
        var incomeAngle = (income / total) * 2 * Math.PI;
        var expenseAngle = (expense / total) * 2 * Math.PI;

        // Income arc (green)
        var gIncome = ctx.createLinearGradient(0, 0, 140, 140);
        gIncome.addColorStop(0, '#10b981');
        gIncome.addColorStop(1, '#34d399');
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle, startAngle + incomeAngle);
        ctx.closePath();
        ctx.fillStyle = gIncome;
        ctx.fill();

        // Expense arc (red)
        var gExpense = ctx.createLinearGradient(140, 0, 0, 140);
        gExpense.addColorStop(0, '#f43f5e');
        gExpense.addColorStop(1, '#fb7185');
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, startAngle + incomeAngle, startAngle + incomeAngle + expenseAngle);
        ctx.closePath();
        ctx.fillStyle = gExpense;
        ctx.fill();

        // Inner donut hole
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.fillStyle = 'rgba(102,126,234,0.55)';
        ctx.fill();

        // Thin white border ring
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(255,255,255,0.25)';
        ctx.lineWidth = 2;
        ctx.stroke();
    });
    </script>
</body>
</html>
