<?php
session_start();

// Determine base path
$isLocal = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
$basePath = $isLocal ? '/adf_system' : '';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}
$userName = $_SESSION['username'] ?? 'Owner';
$isDev = ($_SESSION['role'] ?? '') === 'developer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Owner Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--border);
            margin-bottom: 32px;
        }
        
        .brand {
            font-size: 20px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }
        
        .user-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 100px;
            font-size: 13px;
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
            font-size: 11px;
            font-weight: 600;
        }
        
        /* Page Title */
        .page-title {
            margin-bottom: 32px;
        }
        
        .page-title h1 {
            font-size: 28px;
            font-weight: 600;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }
        
        .page-title p {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }
        
        .stat-value.income {
            color: var(--success);
        }
        
        .stat-value.expense {
            color: var(--danger);
        }
        
        .stat-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        /* Cash Panel */
        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, var(--accent), var(--accent-light));
            border-radius: 2px;
        }
        
        .cash-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .cash-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .cash-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        
        .cash-card.primary {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.25);
        }
        
        .cash-card.primary .cash-label {
            color: rgba(255,255,255,0.7);
        }
        
        .cash-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        
        .cash-value {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -1px;
        }
        
        .cash-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 48px;
            opacity: 0.3;
        }
        
        .cash-card.primary .cash-icon {
            opacity: 0.4;
        }
        
        /* Overview Panel */
        .overview-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        
        .panel-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        
        /* Performance List */
        .perf-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .perf-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .perf-label {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .perf-value {
            font-size: 15px;
            font-weight: 600;
        }
        
        .perf-bar {
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .perf-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent) 0%, var(--accent-light) 100%);
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        /* Pie Chart */
        .chart-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        
        #pieChart {
            width: 160px;
            height: 160px;
        }
        
        .chart-legend {
            width: 100%;
        }
        
        .legend-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .legend-item:last-child {
            border-bottom: none;
        }
        
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .legend-label {
            display: flex;
            align-items: center;
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .legend-value {
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Loading */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 4px;
        }
        
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .cash-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .overview-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .header {
                flex-direction: row;
                justify-content: space-between;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 12px;
            }
            
            .header {
                padding: 12px 0;
                margin-bottom: 16px;
            }
            
            .brand {
                font-size: 16px;
            }
            
            .user-badge {
                padding: 6px 10px;
                font-size: 11px;
            }
            
            .avatar {
                width: 22px;
                height: 22px;
                font-size: 9px;
            }
            
            .page-title {
                margin-bottom: 16px;
            }
            
            .page-title h1 {
                font-size: 18px;
            }
            
            .page-title p {
                font-size: 12px;
            }
            
            .stats-grid {
                gap: 8px;
                margin-bottom: 16px;
            }
            
            .stat-card {
                padding: 12px;
                border-radius: 12px;
            }
            
            .stat-label {
                font-size: 10px;
                margin-bottom: 4px;
            }
            
            .stat-value {
                font-size: 16px;
            }
            
            .stat-sub {
                font-size: 10px;
                display: none;
            }
            
            .section-title {
                font-size: 13px;
                margin-bottom: 10px;
            }
            
            .cash-grid {
                gap: 8px;
                margin-bottom: 16px;
            }
            
            .cash-card {
                padding: 14px;
                border-radius: 12px;
            }
            
            .cash-label {
                font-size: 10px;
                margin-bottom: 6px;
            }
            
            .cash-value {
                font-size: 18px;
            }
            
            .cash-icon {
                font-size: 32px;
            }
            
            .overview-grid {
                gap: 12px;
                margin-bottom: 16px;
            }
            
            .panel {
                padding: 14px;
                border-radius: 12px;
            }
            
            .panel-title {
                font-size: 12px;
                margin-bottom: 12px;
                padding-bottom: 8px;
            }
            
            .perf-label {
                font-size: 11px;
            }
            
            .perf-value {
                font-size: 13px;
            }
            
            .perf-bar {
                height: 5px;
                margin-top: 6px;
            }
            
            .perf-row + .perf-row {
                margin-top: 12px !important;
            }
            
            #pieChart {
                width: 120px;
                height: 120px;
            }
            
            .chart-container {
                gap: 12px;
            }
            
            .legend-item {
                padding: 6px 0;
            }
            
            .legend-dot {
                width: 8px;
                height: 8px;
            }
            
            .legend-label,
            .legend-value {
                font-size: 11px;
            }
        }
        
        /* Business Selector */
        .business-selector {
            margin-bottom: 16px;
        }
        
        .business-select {
            width: 100%;
            padding: 10px 14px;
            font-size: 13px;
            font-family: inherit;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text-primary);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
        }
        
        .business-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        @media (max-width: 480px) {
            .business-select {
                padding: 8px 12px;
                font-size: 12px;
                border-radius: 8px;
            }
        }

        /* Dev Badge */
        .dev-badge {
            position: fixed;
            top: 12px;
            right: 12px;
            background: var(--danger);
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            z-index: 9999;
            letter-spacing: 0.5px;
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
            <div class="brand">SmartBiz</div>
            <div class="user-badge">
                <div class="avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                <span><?= htmlspecialchars($userName) ?></span>
            </div>
        </header>
        
        <!-- Page Title -->
        <div class="page-title">
            <h1>Financial Overview</h1>
            <p id="currentDate">Loading...</p>
        </div>
        
        <!-- Business Selector -->
        <div class="business-selector">
            <select class="business-select" id="businessSelect">
                <option value="">Select Business...</option>
            </select>
        </div>
        
        <!-- Today Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Today Income</div>
                <div class="stat-value income" id="todayIncome">-</div>
                <div class="stat-sub">Revenue today</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today Expense</div>
                <div class="stat-value expense" id="todayExpense">-</div>
                <div class="stat-sub">Operational cost</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Month Income</div>
                <div class="stat-value income" id="monthIncome">-</div>
                <div class="stat-sub">This month total</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Month Expense</div>
                <div class="stat-value expense" id="monthExpense">-</div>
                <div class="stat-sub">This month cost</div>
            </div>
        </div>
        
        <!-- Cash Accounts Section -->
        <div class="section-title">Cash Accounts Balance</div>
        <div class="cash-grid">
            <div class="cash-card primary">
                <div class="cash-label">Petty Cash</div>
                <div class="cash-value" id="pettyCash">-</div>
                <div class="cash-icon">💵</div>
            </div>
            <div class="cash-card">
                <div class="cash-label">Bank Balance</div>
                <div class="cash-value" id="bankBalance">-</div>
                <div class="cash-icon">🏦</div>
            </div>
            <div class="cash-card">
                <div class="cash-label">Owner Capital</div>
                <div class="cash-value" id="ownerCapital">-</div>
                <div class="cash-icon">👤</div>
            </div>
        </div>
        
        <!-- Overview Grid -->
        <div class="overview-grid">
            <!-- Performance Panel -->
            <div class="panel">
                <div class="panel-title">Monthly Performance</div>
                <div class="perf-list">
                    <div class="perf-row">
                        <div class="perf-item">
                            <span class="perf-label">Total Revenue</span>
                            <span class="perf-value" id="perfIncome">-</span>
                        </div>
                        <div class="perf-bar">
                            <div class="perf-bar-fill" id="incomeBar" style="width: 0%; background: linear-gradient(90deg, #10b981, #34d399);"></div>
                        </div>
                    </div>
                    <div class="perf-row" style="margin-top: 16px;">
                        <div class="perf-item">
                            <span class="perf-label">Total Expense</span>
                            <span class="perf-value" id="perfExpense">-</span>
                        </div>
                        <div class="perf-bar">
                            <div class="perf-bar-fill" id="expenseBar" style="width: 0%; background: linear-gradient(90deg, #f43f5e, #fb7185);"></div>
                        </div>
                    </div>
                    <div class="perf-row" style="margin-top: 16px;">
                        <div class="perf-item">
                            <span class="perf-label">Net Profit</span>
                            <span class="perf-value" id="perfProfit" style="color: var(--success);">-</span>
                        </div>
                        <div class="perf-bar">
                            <div class="perf-bar-fill" id="profitBar" style="width: 0%; background: linear-gradient(90deg, #10b981, #34d399);"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Chart Panel -->
            <div class="panel">
                <div class="panel-title">Income vs Expense</div>
                <div class="chart-container">
                    <canvas id="pieChart"></canvas>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <span class="legend-label">
                                <span class="legend-dot" style="background: linear-gradient(135deg, #10b981, #34d399);"></span>
                                Income
                            </span>
                            <span class="legend-value" id="legendIncome">-</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-label">
                                <span class="legend-dot" style="background: linear-gradient(135deg, #f43f5e, #fb7185);"></span>
                                Expense
                            </span>
                            <span class="legend-value" id="legendExpense">-</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Format currency
        function formatRp(num) {
            if (num >= 1000000000) return 'Rp ' + (num / 1000000000).toFixed(1) + 'B';
            if (num >= 1000000) return 'Rp ' + (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return 'Rp ' + (num / 1000).toFixed(0) + 'K';
            return 'Rp ' + num.toLocaleString('id-ID');
        }
        
        // Draw pie chart
        function drawPieChart(income, expense) {
            const canvas = document.getElementById('pieChart');
            const ctx = canvas.getContext('2d');
            const total = income + expense;
            
            // Responsive canvas size
            const isMobile = window.innerWidth <= 480;
            const size = isMobile ? 120 : 160;
            canvas.width = size;
            canvas.height = size;
            
            const centerX = canvas.width / 2;
            const centerY = canvas.height / 2;
            const radius = (size / 2) - 10;
            
            // Clear
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            if (total === 0) {
                // Empty state
                ctx.beginPath();
                ctx.arc(centerX, centerY, radius, 0, 2 * Math.PI);
                ctx.fillStyle = '#e2e8f0';
                ctx.fill();
                return;
            }
            
            const incomeAngle = (income / total) * 2 * Math.PI;
            const startAngle = -Math.PI / 2;
            
            // Income slice (emerald gradient)
            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, startAngle, startAngle + incomeAngle);
            ctx.closePath();
            const incomeGrad = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            incomeGrad.addColorStop(0, '#10b981');
            incomeGrad.addColorStop(1, '#34d399');
            ctx.fillStyle = incomeGrad;
            ctx.fill();
            
            // Expense slice (rose gradient)
            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, startAngle + incomeAngle, startAngle + 2 * Math.PI);
            ctx.closePath();
            const expenseGrad = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            expenseGrad.addColorStop(0, '#f43f5e');
            expenseGrad.addColorStop(1, '#fb7185');
            ctx.fillStyle = expenseGrad;
            ctx.fill();
            
            // Center hole (donut)
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius * 0.55, 0, 2 * Math.PI);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
        }
        
        // Load data
        async function loadStats() {
            // Set date
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', options);
            
            try {
                const response = await fetch('/adf_system/api/owner-stats-simple.php');
                const data = await response.json();
                
                if (data.success) {
                    // Today stats
                    document.getElementById('todayIncome').textContent = formatRp(data.todayIncome);
                    document.getElementById('todayExpense').textContent = formatRp(data.todayExpense);
                    document.getElementById('monthIncome').textContent = formatRp(data.monthIncome);
                    document.getElementById('monthExpense').textContent = formatRp(data.monthExpense);
                    
                    // Cash accounts
                    document.getElementById('pettyCash').textContent = formatRp(data.pettyCash || 0);
                    document.getElementById('bankBalance').textContent = formatRp(data.bankBalance || 0);
                    document.getElementById('ownerCapital').textContent = formatRp(data.ownerCapital || 0);
                    
                    // Performance
                    const profit = data.monthIncome - data.monthExpense;
                    document.getElementById('perfIncome').textContent = formatRp(data.monthIncome);
                    document.getElementById('perfExpense').textContent = formatRp(data.monthExpense);
                    document.getElementById('perfProfit').textContent = formatRp(profit);
                    document.getElementById('perfProfit').style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
                    
                    // Progress bars
                    const maxVal = Math.max(data.monthIncome, data.monthExpense, 1);
                    document.getElementById('incomeBar').style.width = ((data.monthIncome / maxVal) * 100) + '%';
                    document.getElementById('expenseBar').style.width = ((data.monthExpense / maxVal) * 100) + '%';
                    document.getElementById('profitBar').style.width = profit > 0 ? ((profit / data.monthIncome) * 100) + '%' : '0%';
                    
                    // Legend
                    document.getElementById('legendIncome').textContent = formatRp(data.monthIncome);
                    document.getElementById('legendExpense').textContent = formatRp(data.monthExpense);
                    
                    // Draw chart
                    drawPieChart(data.monthIncome, data.monthExpense);
                } else {
                    console.error('API Error:', data.message);
                }
            } catch (error) {
                console.error('Fetch Error:', error);
            }
        }
        
        // Load businesses
        async function loadBusinesses() {
            try {
                const response = await fetch('/adf_system/api/owner-branches-simple.php');
                const data = await response.json();
                
                if (data.success && data.branches) {
                    const select = document.getElementById('businessSelect');
                    select.innerHTML = '<option value="">All Businesses</option>';
                    
                    data.branches.forEach(biz => {
                        const option = document.createElement('option');
                        option.value = biz.id;
                        option.textContent = biz.branch_name || biz.business_name;
                        select.appendChild(option);
                    });
                    
                    // Auto-select first if only one
                    if (data.branches.length === 1) {
                        select.value = data.branches[0].id;
                    }
                }
            } catch (error) {
                console.error('Load businesses error:', error);
            }
        }
        
        // Business change handler
        document.getElementById('businessSelect').addEventListener('change', function() {
            loadStats();
        });
        
        // Init
        loadBusinesses();
        loadStats();
        
        // Refresh every 30 seconds
        setInterval(loadStats, 30000);
    </script>
</body>
</html>
