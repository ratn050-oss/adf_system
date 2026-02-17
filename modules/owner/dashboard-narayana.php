<?php
/**
 * DASHBOARD NARAYANA HOTEL ONLY
 * Simple, langsung konek ke database Narayana tanpa ribet
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

// PAKAI CREDENTIALS DARI CONFIG.PHP!
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$dbName = $isProduction ? 'adfb2574_narayana_hotel' : 'adf_narayana_hotel';

// Get stats langsung dari database
$stats = [
    'today_income' => 0,
    'today_expense' => 0,
    'month_income' => 0,
    'month_expense' => 0,
    'total_transactions' => 0
];
$transactions = [];
$error = null;

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    
    // Today Income
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'");
    $stmt->execute([$today]);
    $stats['today_income'] = (float)$stmt->fetchColumn();
    
    // Today Expense
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'expense'");
    $stmt->execute([$today]);
    $stats['today_expense'] = (float)$stmt->fetchColumn();
    
    // Month Income
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'");
    $stmt->execute([$thisMonth]);
    $stats['month_income'] = (float)$stmt->fetchColumn();
    
    // Month Expense
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'");
    $stmt->execute([$thisMonth]);
    $stats['month_expense'] = (float)$stmt->fetchColumn();
    
    // Total transactions
    $stmt = $pdo->query("SELECT COUNT(*) FROM cash_book");
    $stats['total_transactions'] = (int)$stmt->fetchColumn();
    
    // Recent transactions
    $stmt = $pdo->query("SELECT id, transaction_date, description, transaction_type, amount FROM cash_book ORDER BY transaction_date DESC, id DESC LIMIT 10");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Format rupiah
function rp($num) {
    return 'Rp ' . number_format($num, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Narayana Hotel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f7;
            padding: 16px;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        .header h1 { font-size: 20px; margin-bottom: 4px; }
        .header p { opacity: 0.9; font-size: 13px; }
        .date { font-size: 12px; opacity: 0.8; margin-top: 8px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .stat-label { font-size: 11px; color: #666; margin-bottom: 4px; }
        .stat-value { font-size: 18px; font-weight: 700; }
        .stat-value.income { color: #10b981; }
        .stat-value.expense { color: #ef4444; }
        
        .section {
            background: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .section-title { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #333; }
        
        .tx-list { list-style: none; }
        .tx-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .tx-item:last-child { border-bottom: none; }
        .tx-desc { font-size: 13px; color: #333; }
        .tx-date { font-size: 11px; color: #888; }
        .tx-amount { font-size: 13px; font-weight: 600; }
        .tx-amount.income { color: #10b981; }
        .tx-amount.expense { color: #ef4444; }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 13px;
        }
        .summary-row.total {
            border-top: 2px solid #eee;
            margin-top: 8px;
            padding-top: 12px;
            font-weight: 700;
        }
        
        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
        }
        
        .db-info {
            background: #f0fdf4;
            color: #166534;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 11px;
            margin-bottom: 12px;
        }

        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 12px;
            border-top: 1px solid #eee;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 11px;
            color: #666;
        }
        .nav-item.active { color: #667eea; }
        .nav-icon { font-size: 20px; margin-bottom: 2px; }
        
        body { padding-bottom: 80px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏨 Narayana Hotel</h1>
        <p>Owner Dashboard</p>
        <div class="date"><?= date('l, d F Y') ?></div>
    </div>
    
    <?php if ($error): ?>
        <div class="error">
            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php else: ?>
    
    <div class="db-info">
        ✅ Connected: <?= $dbName ?> | <?= $stats['total_transactions'] ?> transactions
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">📈 Pemasukan Hari Ini</div>
            <div class="stat-value income"><?= rp($stats['today_income']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">📉 Pengeluaran Hari Ini</div>
            <div class="stat-value expense"><?= rp($stats['today_expense']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">📈 Pemasukan Bulan Ini</div>
            <div class="stat-value income"><?= rp($stats['month_income']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">📉 Pengeluaran Bulan Ini</div>
            <div class="stat-value expense"><?= rp($stats['month_expense']) ?></div>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">📊 Ringkasan Bulan Ini</div>
        <div class="summary-row">
            <span>Total Pemasukan</span>
            <span style="color:#10b981"><?= rp($stats['month_income']) ?></span>
        </div>
        <div class="summary-row">
            <span>Total Pengeluaran</span>
            <span style="color:#ef4444"><?= rp($stats['month_expense']) ?></span>
        </div>
        <div class="summary-row total">
            <span>Net Profit</span>
            <span style="color:<?= ($stats['month_income'] - $stats['month_expense']) >= 0 ? '#10b981' : '#ef4444' ?>">
                <?= rp($stats['month_income'] - $stats['month_expense']) ?>
            </span>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">🕐 Transaksi Terakhir</div>
        <?php if (empty($transactions)): ?>
            <p style="color:#888;font-size:13px">Belum ada transaksi</p>
        <?php else: ?>
            <ul class="tx-list">
                <?php foreach ($transactions as $tx): ?>
                <li class="tx-item">
                    <div>
                        <div class="tx-desc"><?= htmlspecialchars($tx['description']) ?></div>
                        <div class="tx-date"><?= date('d/m/Y H:i', strtotime($tx['transaction_date'])) ?></div>
                    </div>
                    <div class="tx-amount <?= $tx['transaction_type'] ?>">
                        <?= $tx['transaction_type'] == 'income' ? '+' : '-' ?><?= rp($tx['amount']) ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    
    <?php endif; ?>
    
    <nav class="nav-bottom">
        <a href="dashboard-narayana.php" class="nav-item active">
            <span class="nav-icon">📊</span>
            Dashboard
        </a>
        <a href="../frontdesk/dashboard.php" class="nav-item">
            <span class="nav-icon">🛎️</span>
            Frontdesk
        </a>
        <a href="<?= $basePath ?>/modules/investor/dashboard.php" class="nav-item">
            <span class="nav-icon">💰</span>
            Investor
        </a>
    </nav>
</body>
</html>
