<?php
/**
 * OWNER DASHBOARD - Simpel, Elegan & Mobile-Friendly
 * Version 2.0 - Compact Edition
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

// Get company name
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : 'Narayana Hotel';

$today = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear = date('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-app-capable" content="yes">
    <title>Owner Dashboard - <?php echo $displayCompanyName; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border: rgba(148, 163, 184, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            padding-bottom: 70px;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 1rem 1rem 0.75rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .header h1 {
            font-size: 1.125rem;
            font-weight: 700;
            color: white;
        }
        
        .header-time {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.8);
        }
        
        .header-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .header-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            padding: 0.5rem;
            border-radius: 8px;
            color: white;
            cursor: pointer;
        }
        
        /* Content */
        .content {
            padding: 0.75rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Stats Grid - 2x2 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 0.875rem;
            border: 1px solid var(--border);
        }
        
        .stat-label {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .stat-value {
            font-size: 1.25rem;
            font-weight: 800;
            line-height: 1.2;
        }
        
        .stat-sub {
            font-size: 0.625rem;
            color: var(--text-muted);
            margin-top: 0.125rem;
        }
        
        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        .text-warning { color: var(--warning); }
        .text-info { color: var(--info); }
        .text-primary { color: var(--primary); }
        
        /* Section Card */
        .section {
            background: var(--bg-card);
            border-radius: 12px;
            margin-bottom: 0.75rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .section-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 0.813rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-body {
            padding: 0.75rem 1rem;
        }
        
        /* Financial Summary */
        .fin-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }
        
        .fin-item {
            padding: 0.625rem;
            border-radius: 8px;
            background: rgba(148, 163, 184, 0.05);
        }
        
        .fin-label {
            font-size: 0.625rem;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 0.125rem;
        }
        
        .fin-value {
            font-size: 0.938rem;
            font-weight: 700;
        }
        
        /* Net Profit Highlight */
        .net-profit {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15) 0%, rgba(139, 92, 246, 0.1) 100%);
            padding: 0.75rem;
            border-radius: 10px;
            margin-top: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(99, 102, 241, 0.2);
        }
        
        .net-profit .label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .net-profit .value {
            font-size: 1.375rem;
            font-weight: 800;
        }
        
        /* Chart Container */
        .chart-container {
            height: 180px;
            position: relative;
        }
        
        /* Transaction List */
        .tx-list {
            max-height: 280px;
            overflow-y: auto;
        }
        
        .tx-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.625rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .tx-item:last-child {
            border-bottom: none;
        }
        
        .tx-info {
            flex: 1;
            min-width: 0;
        }
        
        .tx-desc {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .tx-meta {
            font-size: 0.625rem;
            color: var(--text-muted);
            margin-top: 0.125rem;
        }
        
        .tx-amount {
            font-size: 0.813rem;
            font-weight: 700;
            margin-left: 0.5rem;
        }
        
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-card);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-around;
            padding: 0.5rem 0;
            z-index: 100;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.625rem;
            font-weight: 600;
            padding: 0.375rem 1rem;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .nav-item.active {
            color: var(--primary);
            background: rgba(99, 102, 241, 0.1);
        }
        
        /* Loading Spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-muted);
        }
        
        /* Utilities */
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-1 { gap: 0.25rem; }
        .gap-2 { gap: 0.5rem; }
        .mt-2 { margin-top: 0.5rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        
        /* Pull to refresh indicator */
        .refresh-indicator {
            text-align: center;
            padding: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            display: none;
        }
        
        /* Responsive - Tablet & Desktop */
        @media (min-width: 768px) {
            .content {
                max-width: 800px;
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .fin-summary {
                grid-template-columns: repeat(4, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <div>
                <h1>📊 <?php echo $displayCompanyName; ?></h1>
                <div class="header-time" id="currentTime"></div>
            </div>
            <div class="header-actions">
                <button class="header-btn" onclick="refreshData()" title="Refresh">
                    <i data-feather="refresh-cw" style="width: 18px; height: 18px;"></i>
                </button>
                <a href="../../index.php" class="header-btn" title="Dashboard Utama">
                    <i data-feather="home" style="width: 18px; height: 18px;"></i>
                </a>
            </div>
        </div>
    </div>
    
    <div class="refresh-indicator" id="refreshIndicator">
        <div class="spinner" style="margin: 0 auto;"></div>
        <div style="margin-top: 0.25rem;">Memperbarui data...</div>
    </div>
    
    <div class="content">
        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">
                    <i data-feather="trending-up" style="width: 12px; height: 12px; color: var(--success);"></i>
                    Hari Ini
                </div>
                <div class="stat-value text-success" id="todayIncome">Rp 0</div>
                <div class="stat-sub" id="todayIncomeCount">0 transaksi</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">
                    <i data-feather="trending-down" style="width: 12px; height: 12px; color: var(--danger);"></i>
                    Pengeluaran
                </div>
                <div class="stat-value text-danger" id="todayExpense">Rp 0</div>
                <div class="stat-sub" id="todayExpenseCount">0 transaksi</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">
                    <i data-feather="wallet" style="width: 12px; height: 12px; color: var(--info);"></i>
                    Saldo Kas
                </div>
                <div class="stat-value text-info" id="operationalBalance">Rp 0</div>
                <div class="stat-sub">Kas operasional</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">
                    <i data-feather="gift" style="width: 12px; height: 12px; color: var(--warning);"></i>
                    Modal Owner
                </div>
                <div class="stat-value text-warning" id="ownerCapital">Rp 0</div>
                <div class="stat-sub">Hari ini</div>
            </div>
        </div>
        
        <!-- Financial Summary -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <i data-feather="bar-chart-2" style="width: 16px; height: 16px; color: var(--primary);"></i>
                    Ringkasan Keuangan
                </div>
                <span style="font-size: 0.65rem; color: var(--text-muted);"><?php echo date('F Y'); ?></span>
            </div>
            <div class="section-body">
                <div class="fin-summary">
                    <div class="fin-item">
                        <div class="fin-label">Pendapatan</div>
                        <div class="fin-value text-success" id="monthIncome">Rp 0</div>
                    </div>
                    <div class="fin-item">
                        <div class="fin-label">Pengeluaran</div>
                        <div class="fin-value text-danger" id="monthExpense">Rp 0</div>
                    </div>
                    <div class="fin-item">
                        <div class="fin-label">Modal Owner</div>
                        <div class="fin-value text-warning" id="monthCapital">Rp 0</div>
                    </div>
                    <div class="fin-item">
                        <div class="fin-label">Growth</div>
                        <div class="fin-value" id="monthGrowth">+0%</div>
                    </div>
                </div>
                
                <div class="net-profit">
                    <div>
                        <div class="label">Net Profit Bulan Ini</div>
                        <div class="value" id="netProfit" style="color: var(--success);">Rp 0</div>
                    </div>
                    <div id="profitIcon" style="width: 40px; height: 40px; border-radius: 50%; background: var(--success); display: flex; align-items: center; justify-content: center;">
                        <i data-feather="trending-up" style="width: 20px; height: 20px; color: white;"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cash Flow Chart -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <i data-feather="pie-chart" style="width: 16px; height: 16px; color: var(--primary);"></i>
                    Cash Flow
                </div>
            </div>
            <div class="section-body">
                <div class="chart-container">
                    <canvas id="cashFlowChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Revenue Trend -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <i data-feather="activity" style="width: 16px; height: 16px; color: var(--primary);"></i>
                    Trend Pendapatan 7 Hari
                </div>
            </div>
            <div class="section-body">
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Recent Transactions -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <i data-feather="list" style="width: 16px; height: 16px; color: var(--primary);"></i>
                    Transaksi Terakhir
                </div>
                <a href="../cashbook/index.php" style="font-size: 0.65rem; color: var(--primary); text-decoration: none;">Lihat Semua →</a>
            </div>
            <div class="section-body">
                <div class="tx-list" id="transactionList">
                    <div class="empty-state">
                        <div class="spinner" style="margin: 0 auto;"></div>
                        <div style="margin-top: 0.5rem;">Memuat transaksi...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <a href="#" class="nav-item active">
            <i data-feather="home" style="width: 18px; height: 18px;"></i>
            Dashboard
        </a>
        <a href="investor-dashboard.php" class="nav-item">
            <i data-feather="users" style="width: 18px; height: 18px;"></i>
            Investor
        </a>
        <a href="owner-capital-monitor.php" class="nav-item">
            <i data-feather="dollar-sign" style="width: 18px; height: 18px;"></i>
            Modal
        </a>
        <a href="../../logout.php" class="nav-item">
            <i data-feather="log-out" style="width: 18px; height: 18px;"></i>
            Keluar
        </a>
    </div>
    
    <script>
        let cashFlowChart = null;
        let trendChart = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
            updateTime();
            setInterval(updateTime, 1000);
            loadDashboardData();
        });
        
        // Update time
        function updateTime() {
            const now = new Date();
            const options = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
            const timeStr = now.toLocaleDateString('id-ID', options) + ' • ' + 
                           now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
            document.getElementById('currentTime').textContent = timeStr;
        }
        
        // Format currency
        function formatRupiah(num) {
            if (num >= 1000000000) {
                return 'Rp ' + (num / 1000000000).toFixed(1) + 'M';
            } else if (num >= 1000000) {
                return 'Rp ' + (num / 1000000).toFixed(1) + 'jt';
            } else if (num >= 1000) {
                return 'Rp ' + (num / 1000).toFixed(0) + 'rb';
            }
            return 'Rp ' + num.toLocaleString('id-ID');
        }
        
        // Refresh data
        function refreshData() {
            document.getElementById('refreshIndicator').style.display = 'block';
            loadDashboardData();
        }
        
        // Load all dashboard data
        async function loadDashboardData() {
            try {
                // Load stats
                const statsResponse = await fetch('../../api/owner-stats.php');
                const statsData = await statsResponse.json();
                
                if (statsData.success) {
                    updateStats(statsData);
                }
                
                // Load recent transactions
                const txResponse = await fetch('../../api/recent-transactions.php?limit=10');
                const txData = await txResponse.json();
                
                if (txData.success) {
                    updateTransactions(txData.transactions);
                }
                
                // Load trend data
                const trendResponse = await fetch('../../api/owner-trend.php?days=7');
                const trendData = await trendResponse.json();
                
                if (trendData.success) {
                    updateTrendChart(trendData);
                }
                
            } catch (error) {
                console.error('Error loading dashboard data:', error);
            } finally {
                document.getElementById('refreshIndicator').style.display = 'none';
            }
        }
        
        // Update stats display
        function updateStats(data) {
            // Today stats
            document.getElementById('todayIncome').textContent = formatRupiah(data.today.income);
            document.getElementById('todayIncomeCount').textContent = data.today.income_count + ' transaksi';
            document.getElementById('todayExpense').textContent = formatRupiah(data.today.expense);
            document.getElementById('todayExpenseCount').textContent = data.today.expense_count + ' transaksi';
            document.getElementById('operationalBalance').textContent = formatRupiah(data.operational_balance);
            document.getElementById('ownerCapital').textContent = formatRupiah(data.today.capital_received);
            
            // Monthly stats
            document.getElementById('monthIncome').textContent = formatRupiah(data.month.income);
            document.getElementById('monthExpense').textContent = formatRupiah(data.month.expense);
            document.getElementById('monthCapital').textContent = formatRupiah(data.month.capital_received || 0);
            
            const growth = data.month.income_change || 0;
            const growthEl = document.getElementById('monthGrowth');
            growthEl.textContent = (growth >= 0 ? '+' : '') + growth + '%';
            growthEl.className = 'fin-value ' + (growth >= 0 ? 'text-success' : 'text-danger');
            
            // Net profit
            const netProfit = data.month.net;
            document.getElementById('netProfit').textContent = formatRupiah(Math.abs(netProfit));
            document.getElementById('netProfit').style.color = netProfit >= 0 ? 'var(--success)' : 'var(--danger)';
            
            const profitIcon = document.getElementById('profitIcon');
            profitIcon.style.background = netProfit >= 0 ? 'var(--success)' : 'var(--danger)';
            profitIcon.innerHTML = netProfit >= 0 
                ? '<i data-feather="trending-up" style="width: 20px; height: 20px; color: white;"></i>'
                : '<i data-feather="trending-down" style="width: 20px; height: 20px; color: white;"></i>';
            feather.replace();
            
            // Update cash flow chart
            updateCashFlowChart(data.month.income, data.month.expense);
        }
        
        // Update cash flow pie chart
        function updateCashFlowChart(income, expense) {
            const ctx = document.getElementById('cashFlowChart').getContext('2d');
            
            if (cashFlowChart) {
                cashFlowChart.destroy();
            }
            
            cashFlowChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Pendapatan', 'Pengeluaran'],
                    datasets: [{
                        data: [income, expense],
                        backgroundColor: ['#10b981', '#ef4444'],
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: '#94a3b8',
                                font: { size: 11, weight: '600' },
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            padding: 10,
                            cornerRadius: 8,
                            titleFont: { size: 12, weight: '600' },
                            bodyFont: { size: 11 },
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return context.label + ': ' + formatRupiah(context.parsed) + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Update trend line chart
        function updateTrendChart(data) {
            const ctx = document.getElementById('trendChart').getContext('2d');
            
            if (trendChart) {
                trendChart.destroy();
            }
            
            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Pendapatan',
                            data: data.income,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true,
                            pointRadius: 3,
                            pointBackgroundColor: '#10b981'
                        },
                        {
                            label: 'Pengeluaran',
                            data: data.expense,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true,
                            pointRadius: 3,
                            pointBackgroundColor: '#ef4444'
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
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: {
                                color: '#94a3b8',
                                font: { size: 10, weight: '600' },
                                padding: 10,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 6,
                                boxHeight: 6
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            padding: 10,
                            cornerRadius: 8,
                            titleFont: { size: 11, weight: '600' },
                            bodyFont: { size: 10 },
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + formatRupiah(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { 
                                color: '#64748b', 
                                font: { size: 10 }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(148, 163, 184, 0.08)' },
                            ticks: { 
                                color: '#64748b', 
                                font: { size: 10 },
                                callback: function(value) {
                                    if (value >= 1000000) return (value / 1000000).toFixed(0) + 'jt';
                                    if (value >= 1000) return (value / 1000).toFixed(0) + 'rb';
                                    return value;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Update transactions list
        function updateTransactions(transactions) {
            const container = document.getElementById('transactionList');
            
            if (!transactions || transactions.length === 0) {
                container.innerHTML = '<div class="empty-state">Belum ada transaksi</div>';
                return;
            }
            
            let html = '';
            transactions.forEach(tx => {
                const isIncome = tx.transaction_type === 'income';
                const amountClass = isIncome ? 'text-success' : 'text-danger';
                const prefix = isIncome ? '+' : '-';
                
                html += `
                    <div class="tx-item">
                        <div class="tx-info">
                            <div class="tx-desc">${tx.description || tx.category_name || 'Transaction'}</div>
                            <div class="tx-meta">${tx.division_name || ''} • ${formatDate(tx.transaction_date)}</div>
                        </div>
                        <div class="tx-amount ${amountClass}">${prefix}${formatRupiah(tx.amount)}</div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
        }
    </script>
</body>
</html>
