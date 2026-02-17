<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();

$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : 'Owner Dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Owner Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        
        /* Header */
        .header {
            background: white;
            padding: 12px 16px;
            border-bottom: 1px solid #e0e0e0;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
        }
        .header-time {
            font-size: 11px;
            color: #666;
        }
        .header-actions {
            display: flex;
            gap: 8px;
        }
        .icon-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: #f0f0f0;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
        }
        .icon-btn:hover { background: #e0e0e0; }
        
        /* Business Tabs */
        .biz-tabs {
            display: flex;
            gap: 4px;
            margin-top: 12px;
            background: #f0f0f0;
            padding: 4px;
            border-radius: 10px;
        }
        .biz-tab {
            flex: 1;
            padding: 8px 12px;
            border: none;
            background: transparent;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .biz-tab:hover { background: #e5e5e5; }
        .biz-tab.active {
            background: #2563eb;
            color: white;
        }
        .biz-icon { font-size: 14px; }
        
        /* Content */
        .content {
            padding: 16px;
            padding-bottom: 80px;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .card-title {
            font-size: 13px;
            font-weight: 700;
            color: #333;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .card-title svg { width: 16px; height: 16px; color: #2563eb; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        .stat-box {
            padding: 12px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box.green { background: #ecfdf5; }
        .stat-box.red { background: #fef2f2; }
        .stat-box.blue { background: #eff6ff; }
        .stat-box.yellow { background: #fefce8; }
        .stat-box.purple { background: #f5f3ff; }
        .stat-box.cyan { background: #ecfeff; }
        
        .stat-label {
            font-size: 10px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .stat-value {
            font-size: 16px;
            font-weight: 700;
        }
        .stat-box.green .stat-value { color: #16a34a; }
        .stat-box.red .stat-value { color: #dc2626; }
        .stat-box.blue .stat-value { color: #2563eb; }
        .stat-box.yellow .stat-value { color: #ca8a04; }
        .stat-box.purple .stat-value { color: #7c3aed; }
        .stat-box.cyan .stat-value { color: #0891b2; }
        
        .stat-sub {
            font-size: 10px;
            color: #999;
            margin-top: 2px;
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 180px;
        }
        
        /* Comparison Business Cards */
        .biz-compare-grid {
            display: grid;
            gap: 12px;
        }
        .biz-compare-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px;
            border-left: 4px solid #2563eb;
        }
        .biz-compare-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .biz-compare-name {
            font-size: 13px;
            font-weight: 700;
            color: #333;
        }
        .biz-compare-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }
        .biz-compare-badge.profit { background: #dcfce7; color: #16a34a; }
        .biz-compare-badge.loss { background: #fee2e2; color: #dc2626; }
        
        .biz-compare-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .biz-stat-item {
            text-align: center;
        }
        .biz-stat-label {
            font-size: 9px;
            color: #888;
            text-transform: uppercase;
        }
        .biz-stat-val {
            font-size: 12px;
            font-weight: 700;
        }
        .biz-stat-val.green { color: #16a34a; }
        .biz-stat-val.red { color: #dc2626; }
        .biz-stat-val.blue { color: #2563eb; }
        
        /* Frontdesk Section */
        .fd-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-bottom: 12px;
        }
        .fd-item {
            text-align: center;
            padding: 10px 4px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .fd-val {
            font-size: 18px;
            font-weight: 700;
            color: #333;
        }
        .fd-label {
            font-size: 9px;
            color: #666;
            margin-top: 2px;
        }
        
        /* Occupancy */
        .occ-section {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .occ-chart-wrap {
            position: relative;
            width: 90px;
            height: 90px;
        }
        .occ-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        .occ-pct {
            font-size: 18px;
            font-weight: 800;
            color: #16a34a;
        }
        .occ-txt {
            font-size: 9px;
            color: #666;
        }
        .occ-stats {
            flex: 1;
        }
        .occ-stat-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 12px;
        }
        .occ-stat-row:last-child { border-bottom: none; }
        .occ-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .occ-dot.green { background: #16a34a; }
        .occ-dot.gray { background: #9ca3af; }
        .occ-dot.red { background: #dc2626; }
        
        /* Transactions */
        .txn-list { }
        .txn-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .txn-item:last-child { border-bottom: none; }
        .txn-desc {
            font-size: 12px;
            font-weight: 500;
            color: #333;
        }
        .txn-meta {
            font-size: 10px;
            color: #999;
            margin-top: 2px;
        }
        .txn-amount {
            font-size: 12px;
            font-weight: 700;
        }
        .txn-amount.green { color: #16a34a; }
        .txn-amount.red { color: #dc2626; }
        
        /* Health Score */
        .health-section {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 12px;
            background: linear-gradient(135deg, #1e293b, #334155);
            border-radius: 10px;
        }
        .health-score {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 800;
            color: white;
        }
        .health-score.good { background: linear-gradient(135deg, #16a34a, #22c55e); }
        .health-score.warning { background: linear-gradient(135deg, #ca8a04, #eab308); }
        .health-score.danger { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .health-info { flex: 1; }
        .health-status {
            font-size: 14px;
            font-weight: 700;
            color: white;
        }
        .health-detail {
            font-size: 11px;
            color: rgba(255,255,255,0.7);
            margin-top: 4px;
        }
        
        /* Bottom Nav */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e0e0e0;
            display: flex;
            padding: 8px 16px;
            z-index: 100;
        }
        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #666;
            font-size: 10px;
            font-weight: 500;
            gap: 4px;
        }
        .nav-item.active { color: #2563eb; }
        .nav-item svg { width: 20px; height: 20px; }
        
        /* Views */
        .view { display: none; }
        .view.active { display: block; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 24px;
            color: #999;
            font-size: 12px;
        }
        
        /* Loading */
        .loading {
            text-align: center;
            padding: 24px;
            color: #666;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-row">
            <div>
                <div class="header-title" id="headerTitle">Owner Dashboard</div>
                <div class="header-time" id="headerTime">Loading...</div>
            </div>
            <div class="header-actions">
                <a href="manage-user-access.php" class="icon-btn"><i data-feather="users"></i></a>
                <button class="icon-btn" onclick="refreshData()"><i data-feather="refresh-cw"></i></button>
            </div>
        </div>
        
        <!-- Business Tabs -->
        <div class="biz-tabs">
            <button class="biz-tab active" data-biz="all" onclick="switchBusiness('all')">
                <span class="biz-icon">🏢</span> All
            </button>
        </div>
    </div>
    
    <!-- Content -->
    <div class="content">
        <!-- ALL BUSINESSES VIEW -->
        <div class="view active" id="view-all">
            <!-- Comparison Pie Chart -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="pie-chart"></i>
                    Revenue Comparison
                </div>
                <div class="chart-container">
                    <canvas id="compareChart"></canvas>
                </div>
            </div>
            
            <!-- Business Cards -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="briefcase"></i>
                    Business Performance
                </div>
                <div class="biz-compare-grid" id="bizCompareGrid">
                    <div class="loading">Loading...</div>
                </div>
            </div>
            
            <!-- Health Score -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="activity"></i>
                    Overall Health
                </div>
                <div class="health-section" id="healthSection">
                    <div class="health-score good">85</div>
                    <div class="health-info">
                        <div class="health-status">Excellent</div>
                        <div class="health-detail">All businesses performing well</div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Transactions All -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="list"></i>
                    Recent Transactions
                </div>
                <div class="txn-list" id="allTxnList">
                    <div class="loading">Loading...</div>
                </div>
            </div>
        </div>
        
        <!-- SINGLE BUSINESS VIEW (Narayana/Bens) -->
        <div class="view" id="view-single">
            <!-- Financial Summary -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="dollar-sign"></i>
                    Financial Summary
                </div>
                <div class="stats-grid">
                    <div class="stat-box green">
                        <div class="stat-label">Today Income</div>
                        <div class="stat-value" id="singleTodayIncome">Rp 0</div>
                        <div class="stat-sub" id="singleTodayIncomeTxn">0 txn</div>
                    </div>
                    <div class="stat-box red">
                        <div class="stat-label">Today Expense</div>
                        <div class="stat-value" id="singleTodayExpense">Rp 0</div>
                        <div class="stat-sub" id="singleTodayExpenseTxn">0 txn</div>
                    </div>
                    <div class="stat-box blue">
                        <div class="stat-label">Month Income</div>
                        <div class="stat-value" id="singleMonthIncome">Rp 0</div>
                    </div>
                    <div class="stat-box yellow">
                        <div class="stat-label">Month Expense</div>
                        <div class="stat-value" id="singleMonthExpense">Rp 0</div>
                    </div>
                </div>
            </div>
            
            <!-- Petty Cash & Modal -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="credit-card"></i>
                    Kas Operasional
                </div>
                <div class="stats-grid">
                    <div class="stat-box cyan">
                        <div class="stat-label">Petty Cash</div>
                        <div class="stat-value" id="singlePettyCash">Rp 0</div>
                    </div>
                    <div class="stat-box purple">
                        <div class="stat-label">Modal Owner</div>
                        <div class="stat-value" id="singleModalOwner">Rp 0</div>
                    </div>
                </div>
            </div>
            
            <!-- Income Chart -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="bar-chart-2"></i>
                    Income per Division
                </div>
                <div class="chart-container">
                    <canvas id="divisionChart"></canvas>
                </div>
            </div>
            
            <!-- Frontdesk Section (Hotel Only) -->
            <div class="card" id="frontdeskCard" style="display:none;">
                <div class="card-title">
                    <i data-feather="home"></i>
                    Frontdesk Dashboard
                </div>
                
                <!-- Activity Stats -->
                <div class="fd-grid">
                    <div class="fd-item">
                        <div class="fd-val" id="fdArrivals">0</div>
                        <div class="fd-label">Arrivals</div>
                    </div>
                    <div class="fd-item">
                        <div class="fd-val" id="fdDepartures">0</div>
                        <div class="fd-label">Departures</div>
                    </div>
                    <div class="fd-item">
                        <div class="fd-val" id="fdInhouse">0</div>
                        <div class="fd-label">In-house</div>
                    </div>
                    <div class="fd-item">
                        <div class="fd-val" id="fdStayovers">0</div>
                        <div class="fd-label">Stayovers</div>
                    </div>
                </div>
                
                <!-- Occupancy -->
                <div class="occ-section">
                    <div class="occ-chart-wrap">
                        <canvas id="occChart"></canvas>
                        <div class="occ-center">
                            <div class="occ-pct" id="occPct">0%</div>
                            <div class="occ-txt">Occupancy</div>
                        </div>
                    </div>
                    <div class="occ-stats">
                        <div class="occ-stat-row">
                            <span><span class="occ-dot green"></span>Booked</span>
                            <span id="occBooked">0</span>
                        </div>
                        <div class="occ-stat-row">
                            <span><span class="occ-dot gray"></span>Available</span>
                            <span id="occAvailable">0</span>
                        </div>
                        <div class="occ-stat-row">
                            <span><span class="occ-dot red"></span>Maintenance</span>
                            <span id="occMaintenance">0</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Transactions -->
            <div class="card">
                <div class="card-title">
                    <i data-feather="list"></i>
                    Recent Transactions
                </div>
                <div class="txn-list" id="singleTxnList">
                    <div class="loading">Loading...</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bottom Nav -->
    <div class="bottom-nav">
        <a href="#" class="nav-item active" onclick="scrollTo({top:0,behavior:'smooth'});return false;">
            <i data-feather="home"></i>
            Dashboard
        </a>
        <a href="investor-dashboard.php" class="nav-item">
            <i data-feather="trending-up"></i>
            Investor
        </a>
        <a href="../../logout.php" class="nav-item">
            <i data-feather="log-out"></i>
            Logout
        </a>
    </div>
    
    <script>
        let currentBranch = 'all';
        let branches = [];
        let compareChart = null;
        let divisionChart = null;
        let occChart = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
            updateTime();
            setInterval(updateTime, 1000);
            loadBranches();
        });
        
        function updateTime() {
            const now = new Date();
            document.getElementById('headerTime').textContent = now.toLocaleDateString('id-ID', {
                weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
        }
        
        function formatRupiah(num) {
            if (num >= 1000000000) return 'Rp ' + (num/1000000000).toFixed(1) + 'B';
            if (num >= 1000000) return 'Rp ' + (num/1000000).toFixed(1) + 'M';
            if (num >= 1000) return 'Rp ' + (num/1000).toFixed(0) + 'K';
            return 'Rp ' + num.toLocaleString('id-ID');
        }
        
        async function loadBranches() {
            try {
                const response = await fetch('../../api/owner-branches.php');
                const data = await response.json();
                
                if (data.success && data.branches) {
                    branches = data.branches;
                    renderTabs();
                    loadAllView();
                }
            } catch (error) {
                console.error('Error loading branches:', error);
            }
        }
        
        function renderTabs() {
            const tabsContainer = document.querySelector('.biz-tabs');
            tabsContainer.innerHTML = `
                <button class="biz-tab ${currentBranch === 'all' ? 'active' : ''}" onclick="switchBusiness('all')">
                    <span class="biz-icon">🏢</span> All
                </button>
            `;
            
            branches.forEach(branch => {
                const icon = branch.business_type === 'hotel' ? '🏨' : '☕';
                const shortName = branch.name.length > 12 ? branch.name.substring(0, 10) + '..' : branch.name;
                tabsContainer.innerHTML += `
                    <button class="biz-tab ${currentBranch == branch.id ? 'active' : ''}" onclick="switchBusiness(${branch.id})">
                        <span class="biz-icon">${icon}</span> ${shortName}
                    </button>
                `;
            });
        }
        
        function switchBusiness(bizId) {
            currentBranch = bizId;
            
            // Update tabs
            document.querySelectorAll('.biz-tab').forEach(tab => tab.classList.remove('active'));
            event.target.closest('.biz-tab').classList.add('active');
            
            // Update header
            if (bizId === 'all') {
                document.getElementById('headerTitle').textContent = 'All Businesses';
                document.getElementById('view-all').classList.add('active');
                document.getElementById('view-single').classList.remove('active');
                loadAllView();
            } else {
                const branch = branches.find(b => b.id == bizId);
                document.getElementById('headerTitle').textContent = branch ? branch.name : 'Business';
                document.getElementById('view-all').classList.remove('active');
                document.getElementById('view-single').classList.add('active');
                
                // Show/hide frontdesk card
                const isHotel = branch && branch.business_type === 'hotel';
                document.getElementById('frontdeskCard').style.display = isHotel ? 'block' : 'none';
                
                loadSingleView(bizId);
            }
        }
        
        async function loadAllView() {
            await Promise.all([
                loadCompareChart(),
                loadBusinessCards(),
                loadAllTransactions()
            ]);
        }
        
        async function loadCompareChart() {
            try {
                const response = await fetch('../../api/owner-comparison.php?period=this_month');
                const data = await response.json();
                
                if (data.success && data.branches) {
                    const labels = data.branches.map(b => b.name);
                    const incomes = data.branches.map(b => b.income || 0);
                    const colors = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed'];
                    
                    const ctx = document.getElementById('compareChart').getContext('2d');
                    if (compareChart) compareChart.destroy();
                    
                    compareChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: incomes,
                                backgroundColor: colors.slice(0, labels.length),
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { font: { size: 11 }, padding: 15 }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading compare chart:', error);
            }
        }
        
        async function loadBusinessCards() {
            try {
                const response = await fetch('../../api/owner-comparison.php?period=this_month');
                const data = await response.json();
                
                const container = document.getElementById('bizCompareGrid');
                
                if (data.success && data.branches && data.branches.length > 0) {
                    container.innerHTML = data.branches.map(branch => {
                        const profit = (branch.income || 0) - (branch.expense || 0);
                        const isProfitable = profit >= 0;
                        return `
                            <div class="biz-compare-card">
                                <div class="biz-compare-header">
                                    <div class="biz-compare-name">${branch.business_type === 'hotel' ? '🏨' : '☕'} ${branch.name}</div>
                                    <div class="biz-compare-badge ${isProfitable ? 'profit' : 'loss'}">
                                        ${isProfitable ? '✓ Profit' : '✗ Loss'}
                                    </div>
                                </div>
                                <div class="biz-compare-stats">
                                    <div class="biz-stat-item">
                                        <div class="biz-stat-label">Income</div>
                                        <div class="biz-stat-val green">${formatRupiah(branch.income || 0)}</div>
                                    </div>
                                    <div class="biz-stat-item">
                                        <div class="biz-stat-label">Expense</div>
                                        <div class="biz-stat-val red">${formatRupiah(branch.expense || 0)}</div>
                                    </div>
                                    <div class="biz-stat-item">
                                        <div class="biz-stat-label">Net</div>
                                        <div class="biz-stat-val ${profit >= 0 ? 'green' : 'red'}">${formatRupiah(Math.abs(profit))}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('');
                    
                    // Update health score
                    updateHealthScore(data.branches);
                } else {
                    container.innerHTML = '<div class="empty-state">No business data</div>';
                }
            } catch (error) {
                console.error('Error loading business cards:', error);
                document.getElementById('bizCompareGrid').innerHTML = '<div class="empty-state">Failed to load</div>';
            }
        }
        
        function updateHealthScore(branchesData) {
            let totalProfit = 0;
            let totalIncome = 0;
            branchesData.forEach(b => {
                totalProfit += (b.income || 0) - (b.expense || 0);
                totalIncome += b.income || 0;
            });
            
            const profitMargin = totalIncome > 0 ? (totalProfit / totalIncome) * 100 : 0;
            let score = Math.min(100, Math.max(0, 50 + profitMargin));
            let status = 'Good';
            let scoreClass = 'good';
            let detail = 'Businesses are performing well';
            
            if (score >= 80) {
                status = 'Excellent';
                scoreClass = 'good';
                detail = 'All businesses showing strong profit';
            } else if (score >= 50) {
                status = 'Good';
                scoreClass = 'warning';
                detail = 'Performance is stable';
            } else {
                status = 'Needs Attention';
                scoreClass = 'danger';
                detail = 'Some businesses need improvement';
            }
            
            document.getElementById('healthSection').innerHTML = `
                <div class="health-score ${scoreClass}">${Math.round(score)}</div>
                <div class="health-info">
                    <div class="health-status">${status}</div>
                    <div class="health-detail">${detail}</div>
                </div>
            `;
        }
        
        async function loadAllTransactions() {
            try {
                const response = await fetch('../../api/owner-recent-transactions.php');
                const data = await response.json();
                
                renderTransactions('allTxnList', data.transactions || []);
            } catch (error) {
                console.error('Error loading transactions:', error);
                document.getElementById('allTxnList').innerHTML = '<div class="empty-state">Failed to load</div>';
            }
        }
        
        async function loadSingleView(branchId) {
            await Promise.all([
                loadSingleStats(branchId),
                loadSingleDivisionChart(branchId),
                loadSingleTransactions(branchId),
                loadFrontdeskData(branchId)
            ]);
        }
        
        async function loadSingleStats(branchId) {
            try {
                const response = await fetch(`../../api/owner-stats.php?branch_id=${branchId}`);
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('singleTodayIncome').textContent = formatRupiah(data.today?.income || 0);
                    document.getElementById('singleTodayExpense').textContent = formatRupiah(data.today?.expense || 0);
                    document.getElementById('singleTodayIncomeTxn').textContent = (data.today?.income_count || 0) + ' txn';
                    document.getElementById('singleTodayExpenseTxn').textContent = (data.today?.expense_count || 0) + ' txn';
                    document.getElementById('singleMonthIncome').textContent = formatRupiah(data.month?.income || 0);
                    document.getElementById('singleMonthExpense').textContent = formatRupiah(data.month?.expense || 0);
                    document.getElementById('singlePettyCash').textContent = formatRupiah(data.petty_cash?.balance || 0);
                    document.getElementById('singleModalOwner').textContent = formatRupiah(data.owner_capital?.balance || 0);
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        async function loadSingleDivisionChart(branchId) {
            try {
                const response = await fetch(`../../api/owner-division-income.php?branch_id=${branchId}`);
                const data = await response.json();
                
                if (data.success && data.divisions && data.divisions.length > 0) {
                    const labels = data.divisions.map(d => d.name);
                    const values = data.divisions.map(d => d.total);
                    const colors = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2'];
                    
                    const ctx = document.getElementById('divisionChart').getContext('2d');
                    if (divisionChart) divisionChart.destroy();
                    
                    divisionChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: colors.slice(0, labels.length),
                                borderRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return formatRupiah(value);
                                        },
                                        font: { size: 10 }
                                    }
                                },
                                x: {
                                    ticks: { font: { size: 10 } }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading division chart:', error);
            }
        }
        
        async function loadFrontdeskData(branchId) {
            const branch = branches.find(b => b.id == branchId);
            if (!branch || branch.business_type !== 'hotel') return;
            
            try {
                const response = await fetch(`../../api/owner-occupancy.php?branch_id=${branchId}`);
                const data = await response.json();
                
                if (data.success) {
                    const total = data.total_rooms || 0;
                    const booked = data.occupied_rooms || 0;
                    const available = data.available_rooms || 0;
                    const maintenance = data.maintenance_rooms || 0;
                    const rate = data.occupancy_rate || 0;
                    
                    // Activity stats
                    document.getElementById('fdArrivals').textContent = data.today_checkins || 0;
                    document.getElementById('fdDepartures').textContent = data.today_checkouts || 0;
                    document.getElementById('fdInhouse').textContent = booked;
                    document.getElementById('fdStayovers').textContent = Math.max(0, booked - (data.today_checkins || 0));
                    
                    // Occupancy stats
                    document.getElementById('occPct').textContent = rate.toFixed(0) + '%';
                    document.getElementById('occBooked').textContent = booked;
                    document.getElementById('occAvailable').textContent = available;
                    document.getElementById('occMaintenance').textContent = maintenance;
                    
                    // Occupancy chart
                    const ctx = document.getElementById('occChart').getContext('2d');
                    if (occChart) occChart.destroy();
                    
                    occChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Booked', 'Available', 'Maintenance'],
                            datasets: [{
                                data: [booked || 0, available || 0, maintenance || 0],
                                backgroundColor: ['#16a34a', '#9ca3af', '#dc2626'],
                                borderWidth: 0,
                                cutout: '70%'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: { display: false },
                                tooltip: { enabled: true }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading frontdesk data:', error);
            }
        }
        
        async function loadSingleTransactions(branchId) {
            try {
                const response = await fetch(`../../api/owner-recent-transactions.php?branch_id=${branchId}`);
                const data = await response.json();
                
                renderTransactions('singleTxnList', data.transactions || []);
            } catch (error) {
                console.error('Error loading transactions:', error);
                document.getElementById('singleTxnList').innerHTML = '<div class="empty-state">Failed to load</div>';
            }
        }
        
        function renderTransactions(containerId, transactions) {
            const container = document.getElementById(containerId);
            
            if (transactions.length === 0) {
                container.innerHTML = '<div class="empty-state">No recent transactions</div>';
                return;
            }
            
            container.innerHTML = transactions.slice(0, 10).map(txn => {
                const isIncome = txn.transaction_type === 'income';
                return `
                    <div class="txn-item">
                        <div>
                            <div class="txn-desc">${txn.description || txn.category_name || 'Transaction'}</div>
                            <div class="txn-meta">${txn.division_name || ''} • ${txn.transaction_date}</div>
                        </div>
                        <div class="txn-amount ${isIncome ? 'green' : 'red'}">
                            ${isIncome ? '+' : '-'}${formatRupiah(txn.amount)}
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        function refreshData() {
            if (currentBranch === 'all') {
                loadAllView();
            } else {
                loadSingleView(currentBranch);
            }
        }
    </script>
</body>
</html>
