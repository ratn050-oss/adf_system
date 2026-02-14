<?php
/**
 * Owner Capital Monitoring Dashboard
 * Track dan monitor Kas Modal Owner per bulan
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();

// Check authorization
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Get business ID from ACTIVE_BUSINESS_ID constant (string like 'narayana-hotel')
// Then map to numeric business_id for database queries
$businessMapping = [
    'narayana-hotel' => 1,
    'bens-cafe' => 2
];

$businessIdString = ACTIVE_BUSINESS_ID;
$businessId = $businessMapping[$businessIdString] ?? 1;

// Get period filter from GET or default to current month
$selectedPeriod = $_GET['period'] ?? date('Y-m');
$currentMonth = date('Y-m-01', strtotime($selectedPeriod . '-01'));
$nextMonth = date('Y-m-t', strtotime($currentMonth));

// Get Kas Modal Owner account
$ownerCapitalAccount = $db->fetchOne(
    "SELECT * FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital' AND is_active = 1",
    [$businessId]
);

if (!$ownerCapitalAccount) {
    die('❌ Kas Modal Owner account not found. Please run accounting setup first.');
}

// Determine business DB name for cross-DB join
$businessDbName = '';
if (IS_PRODUCTION) {
    if ($businessId == 1) {
        $businessDbName = 'adfb2574_narayana';
    } else {
        $businessDbName = 'adfb2574_benscafe';
    }
} else {
    if ($businessId == 1) {
        $businessDbName = 'adf_narayana_hotel';
    } else {
        $businessDbName = 'adf_benscafe';
    }
}

// Get ALL transactions for this account in selected period with cross-DB join
$allTransactions = $db->fetchAll(
    "SELECT cat.*, 
            cb.description as cash_book_desc,
            cb.category,
            cb.division_id
     FROM cash_account_transactions cat
     LEFT JOIN {$businessDbName}.cash_book cb ON cat.transaction_id = cb.id AND cat.transaction_type IN ('income', 'expense')
     WHERE cat.cash_account_id = ?
     AND cat.transaction_date >= ? AND cat.transaction_date <= ?
     ORDER BY cat.transaction_date DESC, cat.id DESC",
    [$ownerCapitalAccount['id'], $currentMonth, $nextMonth]
);

// Calculate totals
$totalCapitalInjected = 0;  // Total setoran dari owner (DEBIT - uang masuk)
$totalCapitalUsed = 0;      // Total digunakan untuk operasional (CREDIT - uang keluar)

foreach ($allTransactions as $txn) {
    // DEBIT = Uang masuk ke Kas Modal Owner (setoran dari owner)
    if ($txn['debit'] > 0) {
        $totalCapitalInjected += $txn['debit'];
    }
    // CREDIT = Uang keluar dari Kas Modal Owner (digunakan operasional)
    if ($txn['credit'] > 0) {
        $totalCapitalUsed += $txn['credit'];
    }
}

$currentBalance = $ownerCapitalAccount['current_balance'];
$remainingCapital = $currentBalance;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Kas Modal Owner - Narayana</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 1.5rem;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #64748b;
            font-size: 0.95rem;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .stat-card {
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1), transparent);
            border-radius: 50%;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .stat-change {
            font-size: 0.8rem;
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            display: inline-block;
        }
        
        .stat-change.positive {
            background: #d1fae5;
            color: #047857;
        }
        
        .stat-change.negative {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .stat-change.neutral {
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* Color variations */
        .card-inject {
            border-left: 4px solid #10b981;
        }
        
        .card-inject .stat-label {
            color: #10b981;
        }
        
        .card-use {
            border-left: 4px solid #ef4444;
        }
        
        .card-use .stat-label {
            color: #ef4444;
        }
        
        .card-balance {
            border-left: 4px solid #3b82f6;
        }
        
        .card-balance .stat-label {
            color: #3b82f6;
        }
        
        /* Full width card */
        .card-full {
            grid-column: 1 / -1;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transaction-table th {
            background: #f1f5f9;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transaction-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .transaction-table tr:hover {
            background: #f8fafc;
        }
        
        .badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-injection {
            background: #d1fae5;
            color: #047857;
        }
        
        .badge-expense {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .badge-transfer {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #94a3b8;
        }
        
        .empty-state i {
            width: 48px;
            height: 48px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 1rem;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="<?php echo BASE_URL; ?>/index.php" class="back-link">
            <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
            Kembali ke Dashboard
        </a>
        
        <div class="page-header">
            <div>
                <h1 class="page-title">💰 Monitor Kas Modal Owner</h1>
                <p class="page-subtitle">Tracking pengeluaran modal bulan <?php echo date('F Y', strtotime($currentMonth)); ?></p>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <!-- Period Selector -->
                <select id="periodSelector" onchange="changePeriod(this.value)" style="padding: 0.75rem 1.5rem; background: white; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer; font-weight: 600; color: #334155;">
                    <?php
                    // Generate last 12 months
                    for ($i = 0; $i < 12; $i++) {
                        $month = date('Y-m', strtotime("-$i months"));
                        $monthName = date('F Y', strtotime($month . '-01'));
                        $selected = ($month === $selectedPeriod) ? 'selected' : '';
                        echo "<option value='$month' $selected>$monthName</option>";
                    }
                    ?>
                </select>
                <button onclick="refreshData()" style="padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-feather="refresh-cw" style="width: 16px; height: 16px;"></i>
                    Refresh
                </button>
            </div>
        </div>
        
        <!-- Key Statistics -->
        <div class="grid-2">
            <!-- Total Uang Masuk dari Owner (NEW) -->
            <div class="card stat-card" style="border-left: 4px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(16,185,129,0.02) 100%);">
                <div class="stat-label" style="color: #10b981;">
                    <i data-feather="trending-up" style="width: 16px; height: 16px;"></i>
                    Total Uang Masuk dari Owner
                </div>
                <div class="stat-value" style="color: #10b981;">Rp <?php echo number_format($totalCapitalInjected, 0, ',', '.'); ?></div>
                <div class="stat-change positive">
                    💰 Setoran modal periode ini
                </div>
            </div>
            
            <!-- Capital Used -->
            <div class="card stat-card card-use">
                <div class="stat-label">
                    <i data-feather="arrow-up-circle" style="width: 16px; height: 16px;"></i>
                    Modal Digunakan Operasional
                </div>
                <div class="stat-value">Rp <?php echo number_format($totalCapitalUsed, 0, ',', '.'); ?></div>
                <div class="stat-change negative">
                    📤 Pengeluaran dari kas modal
                </div>
            </div>
            
            <!-- Current Balance -->
            <div class="card stat-card card-balance">
                <div class="stat-label">
                    <i data-feather="credit-card" style="width: 16px; height: 16px;"></i>
                    Saldo Kas Modal Saat Ini
                </div>
                <div class="stat-value">Rp <?php echo number_format($remainingCapital, 0, ',', '.'); ?></div>
                <div class="stat-change neutral">
                    💳 Saldo aktual real-time
                </div>
            </div>
            
            <!-- Efficiency -->
            <div class="card stat-card" style="border-left: 4px solid #f59e0b;">
                <div class="stat-label" style="color: #f59e0b;">
                    <i data-feather="percent" style="width: 16px; height: 16px;"></i>
                    Efisiensi Modal
                </div>
                <div class="stat-value">
                    <?php 
                    if ($totalCapitalInjected > 0) {
                        $efficiency = ($totalCapitalUsed / $totalCapitalInjected) * 100;
                        echo number_format($efficiency, 1);
                    } else {
                        echo '0';
                    }
                    ?>%
                </div>
                <div class="stat-change" style="background: #fef3c7; color: #b45309;">
                    📈 Rasio penggunaan
                </div>
            </div>
        </div>
        
        <!-- Transactions History -->
        <div class="card card-full">
            <div class="section-title">
                <i data-feather="history" style="width: 20px; height: 20px; color: #3b82f6;"></i>
                Riwayat Transaksi Kas Modal Owner - <?php echo date('F Y', strtotime($currentMonth)); ?>
            </div>
            
            <?php if (!empty($allTransactions)): ?>
                <div style="margin-bottom: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <small style="color: #64748b; font-weight: 600;">Total Transaksi</small>
                        <div style="font-size: 1.25rem; font-weight: 700; color: #1e293b;"><?php echo count($allTransactions); ?> transaksi</div>
                    </div>
                    <div>
                        <small style="color: #10b981; font-weight: 600;">Uang Masuk</small>
                        <div style="font-size: 1.25rem; font-weight: 700; color: #10b981;">Rp <?php echo number_format($totalCapitalInjected, 0, ',', '.'); ?></div>
                    </div>
                    <div>
                        <small style="color: #ef4444; font-weight: 600;">Uang Keluar</small>
                        <div style="font-size: 1.25rem; font-weight: 700; color: #ef4444;">Rp <?php echo number_format($totalCapitalUsed, 0, ',', '.'); ?></div>
                    </div>
                    <div>
                        <small style="color: #3b82f6; font-weight: 600;">Selisih</small>
                        <div style="font-size: 1.25rem; font-weight: 700; color: <?php echo ($totalCapitalInjected - $totalCapitalUsed) >= 0 ? '#10b981' : '#ef4444'; ?>;">
                            Rp <?php echo number_format($totalCapitalInjected - $totalCapitalUsed, 0, ',', '.'); ?>
                        </div>
                    </div>
                </div>
                
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Deskripsi</th>
                            <th>Kategori/Divisi</th>
                            <th>Tipe</th>
                            <th>Uang Masuk</th>
                            <th>Uang Keluar</th>
                            <th>Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Calculate running balance from oldest to newest first
                        $balances = [];
                        $runningBalance = 0;
                        
                        // Reverse to calculate from oldest
                        $reversedTxns = array_reverse($allTransactions);
                        foreach ($reversedTxns as $txn) {
                            $runningBalance += ($txn['debit'] - $txn['credit']);
                            $balances[$txn['id']] = $runningBalance;
                        }
                        
                        // Now display in original order (newest first)
                        foreach ($allTransactions as $txn): 
                            // Get description
                            $description = $txn['description'] ?: ($txn['cash_book_desc'] ?: '-');
                        ?>
                            <tr>
                                <td style="white-space: nowrap;"><?php echo date('d M Y', strtotime($txn['transaction_date'])); ?></td>
                                <td><?php echo htmlspecialchars($description); ?></td>
                                <td style="font-size: 0.813rem; color: #64748b;">
                                    <?php 
                                    if ($txn['category']) {
                                        echo '<span style="background: #e0e7ff; color: #4338ca; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">' . htmlspecialchars($txn['category']) . '</span>';
                                    }
                                    if ($txn['division_id']) {
                                        echo '<br><small>Divisi: ' . $txn['division_id'] . '</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = 'badge';
                                    $badgeText = ucfirst($txn['transaction_type']);
                                    
                                    if ($txn['transaction_type'] == 'capital_injection' || $txn['debit'] > 0) {
                                        $badgeClass = 'badge badge-injection';
                                        $badgeText = 'Setoran Modal';
                                    } elseif ($txn['transaction_type'] == 'expense') {
                                        $badgeClass = 'badge badge-expense';
                                        $badgeText = 'Pengeluaran';
                                    } elseif ($txn['transaction_type'] == 'transfer') {
                                        $badgeClass = 'badge badge-transfer';
                                        $badgeText = 'Transfer';
                                    } elseif ($txn['transaction_type'] == 'income') {
                                        $badgeClass = 'badge badge-injection';
                                        $badgeText = 'Pemasukan';
                                    }
                                    
                                    echo '<span class="' . $badgeClass . '">' . $badgeText . '</span>';
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php
                                    if ($txn['debit'] > 0) {
                                        echo '<span style="color: #10b981;">Rp ' . number_format($txn['debit'], 0, ',', '.') . '</span>';
                                    } else {
                                        echo '<span style="color: #cbd5e1;">-</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php
                                    if ($txn['credit'] > 0) {
                                        echo '<span style="color: #ef4444;">Rp ' . number_format($txn['credit'], 0, ',', '.') . '</span>';
                                    } else {
                                        echo '<span style="color: #cbd5e1;">-</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #1e293b;">
                                    Rp <?php echo number_format($balances[$txn['id']], 0, ',', '.'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i data-feather="inbox"></i>
                    <p>Belum ada transaksi modal pada periode ini</p>
                </div>
            <?php endif; ?>
        </div>
        </div>
        
        <!-- Chart -->
        <div class="card card-full" style="margin-top: 2rem;">
            <div class="section-title">
                <i data-feather="bar-chart-2" style="width: 20px; height: 20px; color: #8b5cf6;"></i>
                Trend Modal Bulanan
            </div>
            <div class="chart-container">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
    
    <script>
        feather.replace();
        
        function refreshData() {
            location.reload();
        }
        
        function changePeriod(period) {
            window.location.href = '?period=' + period;
        }
        
        // Initialize chart
        const ctx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [
                    {
                        label: 'Modal Diterima',
                        data: [<?php echo $totalCapitalInjected; ?>, 0, 0, 0],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        borderWidth: 2,
                        fill: true,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Modal Digunakan',
                        data: [0, <?php echo $totalCapitalUsed / 4; ?>, <?php echo $totalCapitalUsed / 4; ?>, <?php echo $totalCapitalUsed / 2; ?>],
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        borderWidth: 2,
                        fill: true,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                            padding: 15,
                            usePointStyle: true
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + (value / 1000000).toFixed(1) + 'M';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
