<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();

// Check if user is authorized to view owner dashboard
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Get company name from settings
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : 'Narayana';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Owner Dashboard - <?php echo $displayCompanyName; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        
        body {
            margin: 0;
            padding: 0;
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }
        
        /* Header */
        .header {
            background: white;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e7eb;
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
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .header-logo {
            width: 32px;
            height: 32px;
            object-fit: contain;
        }
        
        .header-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .header-subtitle {
            font-size: 0.65rem;
            color: #6b7280;
        }
        
        .header-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .icon-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: #f3f4f6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #6b7280;
            transition: all 0.2s;
        }
        
        .icon-btn:hover {
            background: #e5e7eb;
            color: #374151;
        }
        
        /* Business Selector - Compact */
        .business-selector {
            display: flex;
            gap: 0.35rem;
            padding: 0.25rem;
            background: #f3f4f6;
            border-radius: 8px;
        }
        
        .biz-btn {
            flex: 1;
            padding: 0.4rem 0.5rem;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
        }
        
        .biz-btn:hover {
            background: #e5e7eb;
        }
        
        .biz-btn.active {
            background: #3b82f6;
            color: white;
        }
        
        .biz-btn .biz-icon {
            font-size: 0.75rem;
        }
        
        /* Main Content */
        .main-content {
            padding: 1rem;
            padding-bottom: 80px;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
        }
        
        .card-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Financial Summary */
        .fin-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }
        
        .fin-item {
            padding: 0.75rem;
            border-radius: 10px;
            text-align: center;
        }
        
        .fin-item.income { background: #f0fdf4; }
        .fin-item.expense { background: #fef2f2; }
        .fin-item.balance { background: #eff6ff; }
        .fin-item.profit { background: #faf5ff; }
        
        .fin-label {
            font-size: 0.6rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        
        .fin-value {
            font-size: 0.95rem;
            font-weight: 700;
        }
        
        .fin-item.income .fin-value { color: #16a34a; }
        .fin-item.expense .fin-value { color: #dc2626; }
        .fin-item.balance .fin-value { color: #2563eb; }
        .fin-item.profit .fin-value { color: #7c3aed; }
        
        .fin-sub {
            font-size: 0.55rem;
            color: #9ca3af;
            margin-top: 0.15rem;
        }
        
        /* Occupancy Section */
        .occupancy-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .occ-chart-wrap {
            position: relative;
            width: 100px;
            height: 100px;
            flex-shrink: 0;
        }
        
        .occ-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .occ-percent {
            font-size: 1.25rem;
            font-weight: 800;
            color: #16a34a;
        }
        
        .occ-label {
            font-size: 0.55rem;
            color: #6b7280;
        }
        
        .occ-stats {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        
        .occ-stat {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
        }
        
        .occ-stat.booked { background: #dcfce7; }
        .occ-stat.available { background: #f3f4f6; }
        .occ-stat.blocked { background: #fee2e2; }
        
        .occ-stat-left {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .occ-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .occ-dot.booked { background: #16a34a; }
        .occ-dot.available { background: #9ca3af; }
        .occ-dot.blocked { background: #dc2626; }
        
        .occ-stat-name { color: #374151; }
        .occ-stat-val { font-weight: 700; color: #1f2937; }
        .occ-stat-pct { font-size: 0.6rem; color: #6b7280; margin-left: 0.25rem; }
        
        /* 14 Day Forecast */
        .forecast-bar {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .forecast-label {
            font-size: 0.65rem;
            color: #6b7280;
            margin-bottom: 0.35rem;
        }
        
        .forecast-track {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .forecast-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #60a5fa);
            border-radius: 4px;
            transition: width 0.5s;
        }
        
        .forecast-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.6rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        
        /* Cash Flow */
        .cashflow-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .cf-chart-wrap {
            position: relative;
            width: 100px;
            height: 100px;
            flex-shrink: 0;
        }
        
        .cf-legend {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .cf-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .cf-dot {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }
        
        .cf-dot.income { background: #16a34a; }
        .cf-dot.expense { background: #dc2626; }
        
        .cf-item-info {
            flex: 1;
        }
        
        .cf-item-label {
            font-size: 0.6rem;
            color: #6b7280;
        }
        
        .cf-item-value {
            font-size: 0.85rem;
            font-weight: 700;
        }
        
        .cf-item-value.income { color: #16a34a; }
        .cf-item-value.expense { color: #dc2626; }
        
        /* Activity Stats */
        .activity-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
        }
        
        .act-item {
            text-align: center;
            padding: 0.5rem;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .act-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .act-label {
            font-size: 0.55rem;
            color: #6b7280;
            margin-top: 0.15rem;
        }
        
        /* Transaction List */
        .txn-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .txn-item:last-child {
            border-bottom: none;
        }
        
        .txn-info {
            flex: 1;
        }
        
        .txn-desc {
            font-size: 0.75rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.15rem;
        }
        
        .txn-meta {
            font-size: 0.6rem;
            color: #9ca3af;
        }
        
        .txn-amount {
            font-size: 0.8rem;
            font-weight: 700;
        }
        
        .txn-amount.income { color: #16a34a; }
        .txn-amount.expense { color: #dc2626; }
        
        /* Bottom Nav */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e5e7eb;
            display: flex;
            padding: 0.5rem;
            z-index: 100;
        }
        
        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            text-decoration: none;
            color: #6b7280;
            font-size: 0.6rem;
            font-weight: 500;
            gap: 0.25rem;
        }
        
        .nav-item.active {
            color: #3b82f6;
        }
        
        /* Hotel Only */
        .hotel-only {
            display: none;
        }
        
        .hotel-only.show {
            display: block;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 1.5rem;
            color: #9ca3af;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <div class="header-left">
                <img src="<?php echo BASE_URL . '/' . DEVELOPER_LOGO; ?>" class="header-logo" onerror="this.style.display='none'">
                <div>
                    <div class="header-title" id="headerTitle">All Businesses</div>
                    <div class="header-subtitle" id="currentTime">Loading...</div>
                </div>
            </div>
            <div class="header-actions">
                <a href="manage-user-access.php" class="icon-btn"><i data-feather="users" style="width:16px;height:16px"></i></a>
                <button class="icon-btn" onclick="refreshData()"><i data-feather="refresh-cw" style="width:16px;height:16px"></i></button>
            </div>
        </div>
        
        <!-- Business Selector -->
        <div class="business-selector" id="businessSelector">
            <button class="biz-btn active" data-branch="all" onclick="selectBranch(null)">
                <span class="biz-icon">🏢</span>
                <span>All</span>
            </button>
            <!-- Buttons will be added by JS -->
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Financial Summary -->
        <div class="card">
            <div class="card-title">
                <i data-feather="dollar-sign" style="width:16px;height:16px;color:#3b82f6"></i>
                Financial Summary
            </div>
            <div class="fin-grid">
                <div class="fin-item income">
                    <div class="fin-label">Today Income</div>
                    <div class="fin-value" id="todayIncome">Rp 0</div>
                    <div class="fin-sub" id="todayIncomeCount">0 transactions</div>
                </div>
                <div class="fin-item expense">
                    <div class="fin-label">Today Expense</div>
                    <div class="fin-value" id="todayExpense">Rp 0</div>
                    <div class="fin-sub" id="todayExpenseCount">0 transactions</div>
                </div>
                <div class="fin-item balance">
                    <div class="fin-label">Month Income</div>
                    <div class="fin-value" id="monthIncome">Rp 0</div>
                    <div class="fin-sub" id="monthIncomeChange">+0% vs last month</div>
                </div>
                <div class="fin-item profit">
                    <div class="fin-label">Month Expense</div>
                    <div class="fin-value" id="monthExpense">Rp 0</div>
                    <div class="fin-sub" id="monthExpenseChange">+0% vs last month</div>
                </div>
            </div>
        </div>
        
        <!-- Cash Flow Chart -->
        <div class="card">
            <div class="card-title">
                <i data-feather="pie-chart" style="width:16px;height:16px;color:#3b82f6"></i>
                Cash Flow (This Month)
            </div>
            <div class="cashflow-section">
                <div class="cf-chart-wrap">
                    <canvas id="cashFlowChart"></canvas>
                </div>
                <div class="cf-legend">
                    <div class="cf-item">
                        <div class="cf-dot income"></div>
                        <div class="cf-item-info">
                            <div class="cf-item-label">Income</div>
                            <div class="cf-item-value income" id="cfIncome">Rp 0</div>
                        </div>
                    </div>
                    <div class="cf-item">
                        <div class="cf-dot expense"></div>
                        <div class="cf-item-info">
                            <div class="cf-item-label">Expense</div>
                            <div class="cf-item-value expense" id="cfExpense">Rp 0</div>
                        </div>
                    </div>
                    <div style="font-size:0.65rem;color:#6b7280;margin-top:0.25rem;">
                        Net: <span id="cfNet" style="font-weight:700;color:#16a34a;">Rp 0</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Room Occupancy (Hotel Only) -->
        <div class="card hotel-only" id="occupancyCard">
            <div class="card-title">
                <i data-feather="home" style="width:16px;height:16px;color:#3b82f6"></i>
                Room Occupancy
            </div>
            <div class="occupancy-section">
                <div class="occ-chart-wrap">
                    <canvas id="occupancyChart"></canvas>
                    <div class="occ-center">
                        <div class="occ-percent" id="occPercent">0%</div>
                        <div class="occ-label">Occupancy</div>
                    </div>
                </div>
                <div class="occ-stats">
                    <div class="occ-stat booked">
                        <div class="occ-stat-left">
                            <div class="occ-dot booked"></div>
                            <span class="occ-stat-name">Booked</span>
                        </div>
                        <div>
                            <span class="occ-stat-val" id="occBooked">0</span>
                            <span class="occ-stat-pct" id="occBookedPct">0%</span>
                        </div>
                    </div>
                    <div class="occ-stat available">
                        <div class="occ-stat-left">
                            <div class="occ-dot available"></div>
                            <span class="occ-stat-name">Available</span>
                        </div>
                        <div>
                            <span class="occ-stat-val" id="occAvailable">0</span>
                            <span class="occ-stat-pct" id="occAvailablePct">0%</span>
                        </div>
                    </div>
                    <div class="occ-stat blocked">
                        <div class="occ-stat-left">
                            <div class="occ-dot blocked"></div>
                            <span class="occ-stat-name">Blocked</span>
                        </div>
                        <div>
                            <span class="occ-stat-val" id="occBlocked">0</span>
                            <span class="occ-stat-pct" id="occBlockedPct">0%</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 14 Day Forecast -->
            <div class="forecast-bar">
                <div class="forecast-label">14 Day Forecast</div>
                <div class="forecast-track">
                    <div class="forecast-fill" id="forecastFill" style="width:0%"></div>
                </div>
                <div class="forecast-info">
                    <span>Occupancy: <span id="forecastOcc">0%</span></span>
                    <span>Revenue: <span id="forecastRevenue">Rp 0</span></span>
                </div>
            </div>
        </div>
        
        <!-- Activity Stats (Hotel Only) -->
        <div class="card hotel-only" id="activityCard">
            <div class="card-title">
                <i data-feather="activity" style="width:16px;height:16px;color:#3b82f6"></i>
                Today Activity
            </div>
            <div class="activity-grid">
                <div class="act-item">
                    <div class="act-value" id="actArrivals">0</div>
                    <div class="act-label">Arrivals</div>
                </div>
                <div class="act-item">
                    <div class="act-value" id="actDepartures">0</div>
                    <div class="act-label">Departures</div>
                </div>
                <div class="act-item">
                    <div class="act-value" id="actInhouse">0</div>
                    <div class="act-label">In-House</div>
                </div>
                <div class="act-item">
                    <div class="act-value" id="actStayovers">0</div>
                    <div class="act-label">Stayovers</div>
                </div>
            </div>
        </div>
        
        <!-- Recent Transactions -->
        <div class="card">
            <div class="card-title">
                <i data-feather="list" style="width:16px;height:16px;color:#3b82f6"></i>
                Recent Transactions
            </div>
            <div id="recentTransactions">
                <div class="empty-state">Loading...</div>
            </div>
        </div>
    </div>
    
    <!-- Bottom Nav -->
    <div class="bottom-nav">
        <a href="#" class="nav-item active" onclick="scrollTo({top:0,behavior:'smooth'});return false;">
            <i data-feather="home" style="width:18px;height:18px"></i>
            Dashboard
        </a>
        <a href="investor-dashboard.php" class="nav-item">
            <i data-feather="trending-up" style="width:18px;height:18px"></i>
            Investor
        </a>
        <a href="../../logout.php" class="nav-item">
            <i data-feather="log-out" style="width:18px;height:18px"></i>
            Logout
        </a>
    </div>
    
    <script>
        let currentBranchId = null;
        let cashFlowChart = null;
        let occupancyChart = null;
        let branches = [];
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
            updateTime();
            setInterval(updateTime, 1000);
            loadBranches();
        });
        
        function updateTime() {
            const now = new Date();
            document.getElementById('currentTime').textContent = now.toLocaleDateString('id-ID', {
                weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
        }
        
        function formatRupiah(num) {
            if (num >= 1000000000) return 'Rp ' + (num/1000000000).toFixed(1) + 'B';
            if (num >= 1000000) return 'Rp ' + (num/1000000).toFixed(1) + 'M';
            if (num >= 1000) return 'Rp ' + (num/1000).toFixed(1) + 'K';
            return 'Rp ' + num.toLocaleString('id-ID');
        }
        
        async function loadBranches() {
            try {
                const response = await fetch('../../api/owner-branches.php');
                const data = await response.json();
                
                if (data.success && data.branches) {
                    branches = data.branches;
                    renderBranchButtons();
                    loadAllData();
                }
            } catch (error) {
                console.error('Error loading branches:', error);
            }
        }
        
        function renderBranchButtons() {
            const container = document.getElementById('businessSelector');
            container.innerHTML = `
                <button class="biz-btn ${currentBranchId === null ? 'active' : ''}" data-branch="all" onclick="selectBranch(null)">
                    <span class="biz-icon">🏢</span>
                    <span>All</span>
                </button>
            `;
            
            branches.forEach(branch => {
                const icon = branch.business_type === 'hotel' ? '🏨' : '☕';
                container.innerHTML += `
                    <button class="biz-btn ${currentBranchId === branch.id ? 'active' : ''}" 
                            data-branch="${branch.id}" 
                            onclick="selectBranch(${branch.id})">
                        <span class="biz-icon">${icon}</span>
                        <span>${branch.name.substring(0, 10)}</span>
                    </button>
                `;
            });
        }
        
        function selectBranch(branchId) {
            currentBranchId = branchId;
            
            // Update buttons
            document.querySelectorAll('.biz-btn').forEach(btn => {
                btn.classList.remove('active');
                if ((branchId === null && btn.dataset.branch === 'all') || 
                    (branchId && btn.dataset.branch == branchId)) {
                    btn.classList.add('active');
                }
            });
            
            // Update header title
            if (branchId === null) {
                document.getElementById('headerTitle').textContent = 'All Businesses';
            } else {
                const branch = branches.find(b => b.id === branchId);
                document.getElementById('headerTitle').textContent = branch ? branch.name : 'Business';
            }
            
            // Show/hide hotel sections
            const isHotel = branchId !== null && branches.find(b => b.id === branchId)?.business_type === 'hotel';
            document.querySelectorAll('.hotel-only').forEach(el => {
                el.classList.toggle('show', isHotel);
            });
            
            loadAllData();
        }
        
        async function loadAllData() {
            await Promise.all([
                loadStats(),
                loadRecentTransactions(),
                loadOccupancy()
            ]);
        }
        
        async function loadStats() {
            try {
                const url = currentBranchId 
                    ? `../../api/owner-stats.php?branch_id=${currentBranchId}`
                    : '../../api/owner-stats.php';
                    
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    // Today stats
                    document.getElementById('todayIncome').textContent = formatRupiah(data.today.income || 0);
                    document.getElementById('todayExpense').textContent = formatRupiah(data.today.expense || 0);
                    document.getElementById('todayIncomeCount').textContent = `${data.today.income_count || 0} transactions`;
                    document.getElementById('todayExpenseCount').textContent = `${data.today.expense_count || 0} transactions`;
                    
                    // Month stats
                    document.getElementById('monthIncome').textContent = formatRupiah(data.month.income || 0);
                    document.getElementById('monthExpense').textContent = formatRupiah(data.month.expense || 0);
                    
                    // Calculate change percentages
                    const incomeChange = data.last_month?.income > 0 
                        ? ((data.month.income - data.last_month.income) / data.last_month.income * 100).toFixed(0)
                        : 0;
                    const expenseChange = data.last_month?.expense > 0 
                        ? ((data.month.expense - data.last_month.expense) / data.last_month.expense * 100).toFixed(0)
                        : 0;
                    
                    document.getElementById('monthIncomeChange').textContent = `${incomeChange >= 0 ? '+' : ''}${incomeChange}% vs last month`;
                    document.getElementById('monthExpenseChange').textContent = `${expenseChange >= 0 ? '+' : ''}${expenseChange}% vs last month`;
                    
                    // Cash flow chart
                    const income = data.month.income || 0;
                    const expense = data.month.expense || 0;
                    const net = income - expense;
                    
                    document.getElementById('cfIncome').textContent = formatRupiah(income);
                    document.getElementById('cfExpense').textContent = formatRupiah(expense);
                    document.getElementById('cfNet').textContent = formatRupiah(net);
                    document.getElementById('cfNet').style.color = net >= 0 ? '#16a34a' : '#dc2626';
                    
                    updateCashFlowChart(income, expense);
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        function updateCashFlowChart(income, expense) {
            const ctx = document.getElementById('cashFlowChart').getContext('2d');
            
            if (cashFlowChart) {
                cashFlowChart.destroy();
            }
            
            cashFlowChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Income', 'Expense'],
                    datasets: [{
                        data: [income || 1, expense || 1],
                        backgroundColor: ['#16a34a', '#dc2626'],
                        borderWidth: 0,
                        cutout: '75%'
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
        
        async function loadOccupancy() {
            if (currentBranchId === null) return;
            
            const branch = branches.find(b => b.id === currentBranchId);
            if (!branch || branch.business_type !== 'hotel') return;
            
            try {
                const response = await fetch(`../../api/owner-occupancy.php?branch_id=${currentBranchId}`);
                const data = await response.json();
                
                if (data.success) {
                    const total = data.total_rooms || 0;
                    const occupied = data.occupied_rooms || 0;
                    const available = data.available_rooms || 0;
                    const maintenance = data.maintenance_rooms || 0;
                    const rate = data.occupancy_rate || 0;
                    
                    document.getElementById('occPercent').textContent = rate.toFixed(0) + '%';
                    document.getElementById('occBooked').textContent = occupied;
                    document.getElementById('occAvailable').textContent = available;
                    document.getElementById('occBlocked').textContent = maintenance;
                    
                    document.getElementById('occBookedPct').textContent = total > 0 ? Math.round(occupied/total*100) + '%' : '0%';
                    document.getElementById('occAvailablePct').textContent = total > 0 ? Math.round(available/total*100) + '%' : '0%';
                    document.getElementById('occBlockedPct').textContent = total > 0 ? Math.round(maintenance/total*100) + '%' : '0%';
                    
                    // Activity stats
                    document.getElementById('actArrivals').textContent = data.today_checkins || 0;
                    document.getElementById('actDepartures').textContent = data.today_checkouts || 0;
                    document.getElementById('actInhouse').textContent = occupied;
                    document.getElementById('actStayovers').textContent = Math.max(0, occupied - (data.today_checkins || 0));
                    
                    // Forecast
                    document.getElementById('forecastFill').style.width = rate + '%';
                    document.getElementById('forecastOcc').textContent = rate.toFixed(0) + '%';
                    document.getElementById('forecastRevenue').textContent = formatRupiah(0); // TODO: Calculate from reservations
                    
                    updateOccupancyChart(occupied, available, maintenance);
                }
            } catch (error) {
                console.error('Error loading occupancy:', error);
            }
        }
        
        function updateOccupancyChart(booked, available, blocked) {
            const ctx = document.getElementById('occupancyChart').getContext('2d');
            
            if (occupancyChart) {
                occupancyChart.destroy();
            }
            
            occupancyChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Booked', 'Available', 'Blocked'],
                    datasets: [{
                        data: [booked || 0, available || 0, blocked || 0],
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
        
        async function loadRecentTransactions() {
            try {
                const url = currentBranchId 
                    ? `../../api/owner-recent-transactions.php?branch_id=${currentBranchId}`
                    : '../../api/owner-recent-transactions.php';
                    
                const response = await fetch(url);
                const data = await response.json();
                
                const container = document.getElementById('recentTransactions');
                
                if (data.success && data.transactions && data.transactions.length > 0) {
                    container.innerHTML = data.transactions.slice(0, 8).map(txn => {
                        const isIncome = txn.transaction_type === 'income';
                        return `
                            <div class="txn-item">
                                <div class="txn-info">
                                    <div class="txn-desc">${txn.description || txn.category_name || 'Transaction'}</div>
                                    <div class="txn-meta">${txn.division_name || ''} • ${txn.transaction_date} ${txn.transaction_time || ''}</div>
                                </div>
                                <div class="txn-amount ${isIncome ? 'income' : 'expense'}">
                                    ${isIncome ? '+' : '-'}${formatRupiah(txn.amount)}
                                </div>
                            </div>
                        `;
                    }).join('');
                } else {
                    container.innerHTML = '<div class="empty-state">No recent transactions</div>';
                }
            } catch (error) {
                console.error('Error loading transactions:', error);
                document.getElementById('recentTransactions').innerHTML = '<div class="empty-state">Failed to load</div>';
            }
        }
        
        function refreshData() {
            loadAllData();
        }
    </script>
</body>
</html>
