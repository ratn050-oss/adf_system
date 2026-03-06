<?php
/**
 * OWNER DASHBOARD 2028
 * Mobile-optimized owner monitoring dashboard
 * Multi-business aware - Light Theme
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/business_helper.php';

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
    header('Location: ../../login.php');
    exit;
}

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

// Multi-business setup
require_once __DIR__ . '/../../includes/business_access.php';
$allBusinesses = getUserAvailableBusinesses();
$activeBusinessId = getActiveBusinessId();

if (!empty($allBusinesses) && !isset($allBusinesses[$activeBusinessId])) {
    $firstAllowed = array_key_first($allBusinesses);
    setActiveBusinessId($firstAllowed);
    $activeBusinessId = $firstAllowed;
}

$activeConfig = getActiveBusinessConfig();

$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$businessDbName = getDbName($activeConfig['database'] ?? 'adf_narayana_hotel');
$businessName = $activeConfig['name'] ?? 'Unknown Business';
$businessIcon = $activeConfig['theme']['icon'] ?? '🏢';
$enabledModules = $activeConfig['enabled_modules'] ?? [];

$today = date('Y-m-d');
$thisMonth = date('Y-m');
$lastMonth = date('Y-m', strtotime('-1 month'));
$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Owner';

// Initialize stats
$stats = [
    'today_income' => 0,
    'today_expense' => 0,
    'month_income' => 0,
    'month_expense' => 0,
    'last_month_income' => 0,
    'last_month_expense' => 0,
    'total_rooms' => 0,
    'occupied' => 0,
    'available' => 0,
    'checkins_today' => 0,
    'checkouts_today' => 0,
];
$recentTransactions = [];
$dailyChart = [];
$divisionIncome = [];
$error = null;

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Exclude owner capital from income
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;
    $excludeOC = '';
    $ownerFundFilter = " AND (source_type IS NULL OR source_type != 'owner_fund')";
    try {
        $masterPdo = new PDO("mysql:host=$dbHost;dbname=$masterDbName;charset=utf8mb4", $dbUser, $dbPass);
        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $bizNumId = getMasterBusinessId();
        if ($bizNumId) {
            $ocStmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE account_type = 'owner_capital' AND business_id = ?");
            $ocStmt->execute([$bizNumId]);
        } else {
            $ocStmt = $masterPdo->query("SELECT id FROM cash_accounts WHERE account_type = 'owner_capital'");
        }
        $ocIds = $ocStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($ocIds)) {
            $excludeOC = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ocIds) . "))";
        }
    } catch (Exception $e) {}

    // Today income/expense
    $r = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND transaction_date = ?" . $excludeOC . $ownerFundFilter);
    $r->execute([$today]);
    $stats['today_income'] = (float)$r->fetch(PDO::FETCH_ASSOC)['total'];

    $r = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'expense' AND transaction_date = ?");
    $r->execute([$today]);
    $stats['today_expense'] = (float)$r->fetch(PDO::FETCH_ASSOC)['total'];

    // Month income/expense
    $r = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeOC . $ownerFundFilter);
    $r->execute([$thisMonth]);
    $stats['month_income'] = (float)$r->fetch(PDO::FETCH_ASSOC)['total'];

    $r = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?");
    $r->execute([$thisMonth]);
    $stats['month_expense'] = (float)$r->fetch(PDO::FETCH_ASSOC)['total'];

    // Last month for comparison
    $r = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeOC . $ownerFundFilter);
    $r->execute([$lastMonth]);
    $stats['last_month_income'] = (float)$r->fetch(PDO::FETCH_ASSOC)['total'];

    // Hotel-specific stats
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'rooms'");
    if ($tableCheck->rowCount() > 0) {
        $stats['total_rooms'] = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT room_id) as c FROM bookings WHERE status = 'checked_in'");
        $stmt->execute();
        $stats['occupied'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
        $stats['available'] = max(0, $stats['total_rooms'] - $stats['occupied']);

        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_in_date) = ? AND status IN ('confirmed','checked_in')");
        $stmt->execute([$today]);
        $stats['checkins_today'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_out_date) = ? AND status = 'checked_in'");
        $stmt->execute([$today]);
        $stats['checkouts_today'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
    }

    // Daily chart data (last 14 days)
    $startDate = date('Y-m-d', strtotime('-13 days'));
    $stmt = $pdo->prepare("
        SELECT DATE(transaction_date) as date,
            SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as income,
            SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as expense
        FROM cash_book 
        WHERE transaction_date >= ?
        GROUP BY DATE(transaction_date) ORDER BY date
    ");
    $stmt->execute([$startDate]);
    $chartRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $chartMap = [];
    foreach ($chartRows as $row) $chartMap[$row['date']] = $row;
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $dailyChart[] = [
            'date' => $d,
            'label' => date('d M', strtotime($d)),
            'income' => (float)($chartMap[$d]['income'] ?? 0),
            'expense' => (float)($chartMap[$d]['expense'] ?? 0),
        ];
    }

    // Division income this month
    $divisionIncome = $pdo->prepare("
        SELECT d.division_name, SUM(cb.amount) as total
        FROM cash_book cb
        JOIN divisions d ON cb.division_id = d.id
        WHERE cb.transaction_type = 'income' 
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
        " . $excludeOC . $ownerFundFilter . "
        GROUP BY d.id, d.division_name
        HAVING total > 0
        ORDER BY total DESC
    ");
    $divisionIncome->execute([$thisMonth]);
    $divisionIncome = $divisionIncome->fetchAll(PDO::FETCH_ASSOC);

    // Recent transactions (last 15)
    $recentTransactions = $pdo->query("
        SELECT cb.transaction_date, cb.description, cb.amount, cb.transaction_type, cb.payment_method,
               COALESCE(d.division_name, '-') as division_name
        FROM cash_book cb
        LEFT JOIN divisions d ON cb.division_id = d.id
        ORDER BY cb.transaction_date DESC, cb.id DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Owner Dashboard Error: " . $e->getMessage());
}

// Calculations
$todayProfit = $stats['today_income'] - $stats['today_expense'];
$monthProfit = $stats['month_income'] - $stats['month_expense'];
$occupancyPct = $stats['total_rooms'] > 0 ? round(($stats['occupied'] / $stats['total_rooms']) * 100) : 0;

// Month comparison
$incomeChange = $stats['last_month_income'] > 0 
    ? round((($stats['month_income'] - $stats['last_month_income']) / $stats['last_month_income']) * 100) 
    : 0;

$isHotel = in_array('frontdesk', $enabledModules);

function fmtRp($n) {
    $prefix = $n < 0 ? '-' : '';
    return $prefix . 'Rp ' . number_format(abs($n), 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($businessName) ?> - Owner Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg: #f5f5f7;
            --card: #ffffff;
            --text: #1d1d1f;
            --text2: #86868b;
            --border: #e5e5ea;
            --green: #34c759;
            --red: #ff3b30;
            --blue: #007aff;
            --orange: #ff9500;
            --purple: #af52de;
            --radius: 14px;
            --shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.4;
            padding-bottom: 80px;
            -webkit-font-smoothing: antialiased;
        }

        /* Header */
        .header {
            background: var(--card);
            padding: 16px 20px 12px;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border);
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .header-greeting {
            font-size: 13px;
            color: var(--text2);
        }
        .header-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        .header-date {
            font-size: 12px;
            color: var(--text2);
            text-align: right;
        }

        /* Business Switcher */
        .biz-switcher {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 12px 20px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .biz-switcher::-webkit-scrollbar { display: none; }
        .biz-btn {
            flex-shrink: 0;
            padding: 8px 16px;
            border-radius: 20px;
            border: 1.5px solid var(--border);
            background: var(--card);
            font-size: 13px;
            font-weight: 600;
            color: var(--text2);
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .biz-btn.active {
            background: var(--text);
            color: #fff;
            border-color: var(--text);
        }

        /* Content */
        .content { padding: 0 16px; }

        /* Stat Cards */
        .stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 12px 0;
        }
        .stat-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: var(--shadow);
        }
        .stat-card.wide { grid-column: 1 / -1; }
        .stat-label {
            font-size: 11px;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .stat-value {
            font-size: 22px;
            font-weight: 700;
            margin-top: 4px;
            letter-spacing: -0.5px;
        }
        .stat-sub {
            font-size: 11px;
            color: var(--text2);
            margin-top: 2px;
        }
        .stat-value.green { color: var(--green); }
        .stat-value.red { color: var(--red); }
        .stat-value.blue { color: var(--blue); }
        .stat-value.orange { color: var(--orange); }

        .change-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-top: 4px;
        }
        .change-up { background: #dcfce7; color: #15803d; }
        .change-down { background: #fee2e2; color: #b91c1c; }

        /* Section */
        .section-title {
            font-size: 17px;
            font-weight: 700;
            margin: 20px 0 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title .badge {
            font-size: 11px;
            background: var(--border);
            color: var(--text2);
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
        }

        /* Occupancy Bar */
        .occ-bar-wrap {
            background: var(--card);
            border-radius: var(--radius);
            padding: 16px;
            box-shadow: var(--shadow);
            margin-bottom: 12px;
        }
        .occ-bar-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 8px;
        }
        .occ-bar-label { font-size: 13px; color: var(--text2); font-weight: 600; }
        .occ-bar-pct { font-size: 28px; font-weight: 700; }
        .occ-bar-track {
            height: 10px;
            background: #f0f0f0;
            border-radius: 5px;
            overflow: hidden;
        }
        .occ-bar-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 0.6s ease;
        }
        .occ-stats {
            display: flex;
            justify-content: space-around;
            margin-top: 12px;
            text-align: center;
        }
        .occ-stat-num { font-size: 18px; font-weight: 700; }
        .occ-stat-lbl { font-size: 11px; color: var(--text2); }

        /* Chart */
        .chart-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 16px;
            box-shadow: var(--shadow);
            margin-bottom: 12px;
        }
        .chart-card canvas {
            width: 100% !important;
            height: 200px !important;
        }

        /* Division List */
        .div-list {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 12px;
        }
        .div-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
        }
        .div-item:last-child { border-bottom: none; }
        .div-name { font-size: 14px; font-weight: 500; }
        .div-amount { font-size: 14px; font-weight: 700; color: var(--green); }

        /* Transaction List */
        .tx-list {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 12px;
        }
        .tx-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .tx-item:last-child { border-bottom: none; }
        .tx-desc {
            font-size: 13px;
            font-weight: 500;
            flex: 1;
            margin-right: 12px;
        }
        .tx-meta {
            font-size: 11px;
            color: var(--text2);
            margin-top: 2px;
        }
        .tx-amount {
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }
        .tx-amount.income { color: var(--green); }
        .tx-amount.expense { color: var(--red); }

        /* Error */
        .error-card {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: var(--radius);
            padding: 16px;
            margin: 12px 0;
            font-size: 13px;
        }

        @media (min-width: 600px) {
            .content { max-width: 600px; margin: 0 auto; }
            .biz-switcher { justify-content: center; }
        }
    </style>
</head>
<body>

<!-- Header -->
<div class="header">
    <div class="header-top">
        <div>
            <div class="header-greeting">Halo, <?= htmlspecialchars($userName) ?> 👋</div>
            <div class="header-title"><?= $businessIcon ?> <?= htmlspecialchars($businessName) ?></div>
        </div>
        <div class="header-date">
            <?= date('l') ?><br>
            <strong><?= date('d M Y') ?></strong>
        </div>
    </div>
</div>

<!-- Business Switcher -->
<?php if (count($allBusinesses) > 1): ?>
<div class="biz-switcher">
    <?php foreach ($allBusinesses as $bizId => $bizInfo): ?>
    <button class="biz-btn <?= $bizId === $activeBusinessId ? 'active' : '' ?>"
            onclick="switchBusiness('<?= htmlspecialchars($bizId) ?>')">
        <?= htmlspecialchars($bizInfo['icon'] ?? '🏢') ?> <?= htmlspecialchars($bizInfo['name'] ?? $bizId) ?>
    </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="content">

    <?php if ($error): ?>
    <div class="error-card">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Today Stats -->
    <div class="section-title">📊 Hari Ini</div>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">💰 Pendapatan</div>
            <div class="stat-value green"><?= fmtRp($stats['today_income']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">💸 Pengeluaran</div>
            <div class="stat-value red"><?= fmtRp($stats['today_expense']) ?></div>
        </div>
        <div class="stat-card wide">
            <div class="stat-label">📈 Profit Hari Ini</div>
            <div class="stat-value <?= $todayProfit >= 0 ? 'green' : 'red' ?>"><?= fmtRp($todayProfit) ?></div>
        </div>
    </div>

    <!-- Month Stats -->
    <div class="section-title">📅 Bulan Ini <span class="badge"><?= date('F Y') ?></span></div>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">Pendapatan</div>
            <div class="stat-value green"><?= fmtRp($stats['month_income']) ?></div>
            <?php if ($incomeChange != 0): ?>
            <div class="change-badge <?= $incomeChange > 0 ? 'change-up' : 'change-down' ?>">
                <?= $incomeChange > 0 ? '↑' : '↓' ?> <?= abs($incomeChange) ?>% vs bulan lalu
            </div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pengeluaran</div>
            <div class="stat-value red"><?= fmtRp($stats['month_expense']) ?></div>
        </div>
        <div class="stat-card wide">
            <div class="stat-label">💎 Profit Bulan Ini</div>
            <div class="stat-value <?= $monthProfit >= 0 ? 'green' : 'red' ?>" style="font-size: 28px;"><?= fmtRp($monthProfit) ?></div>
        </div>
    </div>

    <?php if ($isHotel): ?>
    <!-- Occupancy -->
    <div class="section-title">🏨 Occupancy</div>
    <div class="occ-bar-wrap">
        <div class="occ-bar-header">
            <div class="occ-bar-label">Room Occupancy</div>
            <div class="occ-bar-pct"><?= $occupancyPct ?>%</div>
        </div>
        <div class="occ-bar-track">
            <div class="occ-bar-fill" style="width: <?= $occupancyPct ?>%; background: <?= $occupancyPct > 80 ? 'var(--green)' : ($occupancyPct > 50 ? 'var(--orange)' : 'var(--red)') ?>;"></div>
        </div>
        <div class="occ-stats">
            <div>
                <div class="occ-stat-num" style="color: var(--red)"><?= $stats['occupied'] ?></div>
                <div class="occ-stat-lbl">Occupied</div>
            </div>
            <div>
                <div class="occ-stat-num" style="color: var(--green)"><?= $stats['available'] ?></div>
                <div class="occ-stat-lbl">Available</div>
            </div>
            <div>
                <div class="occ-stat-num" style="color: var(--blue)"><?= $stats['checkins_today'] ?></div>
                <div class="occ-stat-lbl">Check-in</div>
            </div>
            <div>
                <div class="occ-stat-num" style="color: var(--orange)"><?= $stats['checkouts_today'] ?></div>
                <div class="occ-stat-lbl">Check-out</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Chart -->
    <div class="section-title">📉 Trend 14 Hari Terakhir</div>
    <div class="chart-card">
        <canvas id="trendChart"></canvas>
    </div>

    <!-- Division Income -->
    <?php if (!empty($divisionIncome)): ?>
    <div class="section-title">🏢 Pendapatan per Divisi</div>
    <div class="div-list">
        <?php foreach ($divisionIncome as $div): ?>
        <div class="div-item">
            <div class="div-name"><?= htmlspecialchars($div['division_name']) ?></div>
            <div class="div-amount"><?= fmtRp((float)$div['total']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent Transactions -->
    <div class="section-title">🔄 Transaksi Terbaru <span class="badge"><?= count($recentTransactions) ?></span></div>
    <div class="tx-list">
        <?php if (empty($recentTransactions)): ?>
        <div style="padding: 24px; text-align: center; color: var(--text2);">Belum ada transaksi</div>
        <?php else: ?>
        <?php foreach ($recentTransactions as $tx): ?>
        <div class="tx-item">
            <div>
                <div class="tx-desc"><?= htmlspecialchars(mb_strimwidth($tx['description'] ?? '-', 0, 50, '...')) ?></div>
                <div class="tx-meta"><?= date('d M', strtotime($tx['transaction_date'])) ?> · <?= htmlspecialchars($tx['division_name']) ?> · <?= strtoupper($tx['payment_method'] ?? 'cash') ?></div>
            </div>
            <div class="tx-amount <?= $tx['transaction_type'] ?>">
                <?= $tx['transaction_type'] === 'income' ? '+' : '-' ?><?= fmtRp((float)$tx['amount']) ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php
require_once __DIR__ . '/../../includes/owner_footer_nav.php';
renderOwnerFooterNav('home', $basePath, $enabledModules);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
function switchBusiness(bizId) {
    fetch('<?= $basePath ?>/api/switch-business.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({business_id: bizId})
    }).then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) window.location.reload();
        else alert(d.message || 'Gagal switch business');
    }).catch(function(){ window.location.reload(); });
}

// Chart
document.addEventListener('DOMContentLoaded', function() {
    var chartData = <?= json_encode($dailyChart) ?>;
    var ctx = document.getElementById('trendChart');
    if (!ctx || !chartData.length) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartData.map(function(d) { return d.label; }),
            datasets: [
                {
                    label: 'Pendapatan',
                    data: chartData.map(function(d) { return d.income; }),
                    backgroundColor: 'rgba(52, 199, 89, 0.7)',
                    borderRadius: 4,
                    borderSkipped: false
                },
                {
                    label: 'Pengeluaran',
                    data: chartData.map(function(d) { return d.expense; }),
                    backgroundColor: 'rgba(255, 59, 48, 0.7)',
                    borderRadius: 4,
                    borderSkipped: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, padding: 16, font: { size: 11 } }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 9 }, maxRotation: 45 }
                },
                y: {
                    grid: { color: '#f0f0f0' },
                    ticks: {
                        font: { size: 10 },
                        callback: function(v) {
                            if (v >= 1000000) return (v/1000000).toFixed(1) + 'jt';
                            if (v >= 1000) return (v/1000).toFixed(0) + 'rb';
                            return v;
                        }
                    }
                }
            },
            interaction: { intersect: false, mode: 'index' }
        }
    });
});
</script>

</body>
</html>
