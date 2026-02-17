<?php
// DEV MODE - No session check for development
$userName = 'Dev Owner';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard Owner - Monitoring Bisnis [DEV]</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1f2937;
            --light: #f3f4f6;
            --white: #ffffff;
            --text: #374151;
            --text-light: #6b7280;
            --border: #e5e7eb;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--light);
            color: var(--text);
            line-height: 1.6;
            font-size: 14px;
        }
        
        /* Dev Badge */
        .dev-badge {
            position: fixed;
            top: 10px;
            right: 10px;
            background: var(--danger);
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        /* Header Mobile First */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: var(--white);
            padding: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logo-icon {
            width: 32px;
            height: 32px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .logo-text {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.15);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
        }
        
        .user-avatar {
            width: 24px;
            height: 24px;
            background: var(--white);
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 11px;
        }
        
        .greeting {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 4px;
        }
        
        .page-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        /* Container */
        .container {
            padding: 16px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Section */
        .section {
            margin-bottom: 24px;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title::before {
            content: '';
            width: 3px;
            height: 18px;
            background: var(--primary);
            border-radius: 2px;
        }
        
        /* Card Base */
        .card {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 12px;
        }
        
        /* Business Selector - Fase 2 */
        .business-selector {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .selector-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: block;
        }
        
        .business-dropdown {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            color: var(--dark);
            background: var(--white);
            cursor: pointer;
            transition: all 0.2s ease;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 40px;
        }
        
        .business-dropdown:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .business-dropdown option {
            padding: 12px;
            font-size: 15px;
        }
        
        /* Loading State */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-light);
        }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Stats Cards Grid - Mobile First */
        .stats-section {
            margin: 20px 0;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); }
        .stat-icon.red { background: rgba(239, 68, 68, 0.1); }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.1); }
        .stat-icon.purple { background: rgba(139, 92, 246, 0.1); }
        .stat-icon.orange { background: rgba(251, 146, 60, 0.1); }
        
        .stat-info {
            flex: 1;
        }
        
        .stat-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-light);
            margin-bottom: 2px;
        }
        
        .stat-period {
            font-size: 11px;
            color: var(--text-lighter);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-top: 4px;
        }
        
        .stat-value.positive { color: var(--success); }
        .stat-value.negative { color: var(--danger); }
        
        .stat-change {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            margin-top: 8px;
        }
        
        .stat-change.up {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .stat-change.down {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .stat-divider {
            height: 1px;
            background: var(--border);
            margin: 16px 0;
        }
        
        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 6px;
        }
        
        .skeleton-value {
            height: 32px;
            width: 60%;
            margin: 8px 0;
        }
        
        .skeleton-label {
            height: 16px;
            width: 40%;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Placeholder for next phases */
        .placeholder {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
            border-radius: 12px;
            margin: 20px 0;
        }
        
        .placeholder h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }
        
        .placeholder p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        /* Responsive */
        @media (min-width: 768px) {
            body {
                font-size: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            
            .header {
                padding: 20px 24px;
            }
            
            .logo-text {
                font-size: 20px;
            }
            
            .page-title {
                font-size: 24px;
            }
            
            .container {
                padding: 24px;
            }
            
            .section-title {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <!-- Dev Badge -->
    <div class="dev-badge">DEV MODE</div>
    
    <!-- Header -->
    <header class="header">
        <div class="header-top">
            <div class="logo">
                <div class="logo-icon">📊</div>
                <div class="logo-text">SmartBiz</div>
            </div>
            <div class="user-info">
                <div class="user-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                <span><?= htmlspecialchars($userName) ?></span>
            </div>
        </div>
        <div class="greeting">Selamat datang,</div>
        <div class="page-title">Dashboard Monitoring</div>
    </header>
    
    <!-- Main Container -->
    <main class="container">
        <!-- Phase 2: Business Selector ✓ -->
        <section class="section">
            <div class="business-selector">
                <label class="selector-label">Pilih Bisnis</label>
                <select class="business-dropdown" id="businessSelector">
                    <option value="">Memuat data...</option>
                </select>
            </div>
        </section>
        
        <!-- Phase 3: Stats Cards - Financial Health Monitoring -->
        <section class="stats-section">
            <h2 class="section-title">💰 Hari Ini</h2>
            <div class="stats-grid">
                <!-- Today Income -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green">📈</div>
                        <div class="stat-info">
                            <div class="stat-label">Pemasukan</div>
                            <div class="stat-period">Hari ini</div>
                        </div>
                    </div>
                    <div class="stat-value positive" id="todayIncome">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Today Expense -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon red">📉</div>
                        <div class="stat-info">
                            <div class="stat-label">Pengeluaran</div>
                            <div class="stat-period">Hari ini</div>
                        </div>
                    </div>
                    <div class="stat-value negative" id="todayExpense">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Today Profit -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon blue">💎</div>
                        <div class="stat-info">
                            <div class="stat-label">Laba Bersih</div>
                            <div class="stat-period">Hari ini</div>
                        </div>
                    </div>
                    <div class="stat-value" id="todayProfit">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
            </div>
        </section>
        
        <section class="stats-section">
            <h2 class="section-title">📅 Bulan Ini</h2>
            <div class="stats-grid">
                <!-- Month Income -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green">📊</div>
                        <div class="stat-info">
                            <div class="stat-label">Total Pemasukan</div>
                            <div class="stat-period">Bulan ini</div>
                        </div>
                    </div>
                    <div class="stat-value positive" id="monthIncome">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                    <span class="stat-change up" id="monthIncomeChange" style="display: none;">
                        ↑ +0% dari bulan lalu
                    </span>
                </div>
                
                <!-- Month Expense -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon red">💳</div>
                        <div class="stat-info">
                            <div class="stat-label">Total Pengeluaran</div>
                            <div class="stat-period">Bulan ini</div>
                        </div>
                    </div>
                    <div class="stat-value negative" id="monthExpense">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                    <span class="stat-change down" id="monthExpenseChange" style="display: none;">
                        ↑ +0% dari bulan lalu
                    </span>
                </div>
                
                <!-- Month Profit -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon purple">🏆</div>
                        <div class="stat-info">
                            <div class="stat-label">Laba Bersih</div>
                            <div class="stat-period">Bulan ini</div>
                        </div>
                    </div>
                    <div class="stat-value" id="monthProfit">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                    <span class="stat-change" id="monthProfitChange" style="display: none;">
                        ↑ +0% dari bulan lalu
                    </span>
                </div>
            </div>
        </section>
        
        <!-- Hotel Specific: Occupancy (Hidden for cafe/all) -->
        <section class="stats-section" id="hotelStats" style="display: none;">
            <h2 class="section-title">🏨 Hotel Occupancy</h2>
            <div class="stats-grid">
                <!-- Occupancy Rate -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon orange">📍</div>
                        <div class="stat-info">
                            <div class="stat-label">Occupancy Rate</div>
                            <div class="stat-period">Status saat ini</div>
                        </div>
                    </div>
                    <div class="stat-value" id="occupancyRate">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Available Rooms -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green">🛏️</div>
                        <div class="stat-info">
                            <div class="stat-label">Kamar Tersedia</div>
                            <div class="stat-period">Available</div>
                        </div>
                    </div>
                    <div class="stat-value" id="availableRooms">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Occupied Rooms -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon blue">👥</div>
                        <div class="stat-info">
                            <div class="stat-label">Kamar Terisi</div>
                            <div class="stat-period">Occupied</div>
                        </div>
                    </div>
                    <div class="stat-value" id="occupiedRooms">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Phase 4: Charts (Next) -->
        <div class="placeholder">
            <h3>📊 Fase 4: Charts</h3>
            <p>Grafik monitoring akan ditambahkan di sini</p>
        </div>
        
        <!-- Phase 5: Navigation (Next) -->
        <div class="placeholder">
            <h3>🧭 Fase 5: Navigation</h3>
            <p>Menu navigasi akan ditambahkan di sini</p>
        </div>
    </main>
    
    <script>
        let currentBusiness = 'all';
        let businessData = [];
        
        // Format currency to Rupiah
        function formatCurrency(amount) {
            const num = parseFloat(amount) || 0;
            return 'Rp ' + num.toLocaleString('id-ID', { 
                minimumFractionDigits: 0, 
                maximumFractionDigits: 0 
            });
        }
        
        // Format percentage
        function formatPercent(value) {
            return (parseFloat(value) || 0).toFixed(1) + '%';
        }
        
        // Load businesses from API
        async function loadBusinesses() {
            const selector = document.getElementById('businessSelector');
            
            try {
                const response = await fetch('../../api/owner-branches.php');
                const data = await response.json();
                
                if (data.success && data.branches && data.branches.length > 0) {
                    businessData = data.branches;
                    selector.innerHTML = '<option value="all">🏢 Semua Bisnis</option>';
                    
                    data.branches.forEach(branch => {
                        const icon = branch.business_type === 'hotel' ? '🏨' : '☕';
                        const name = branch.branch_name || branch.name || 'Unknown';
                        selector.innerHTML += `<option value="${branch.id}">${icon} ${name}</option>`;
                    });
                    
                    // Load initial stats
                    loadStats('all');
                } else {
                    selector.innerHTML = '<option value="">Tidak ada data bisnis</option>';
                    console.error('No branches found:', data);
                }
            } catch (error) {
                console.error('Error loading businesses:', error);
                selector.innerHTML = '<option value="">Gagal memuat data</option>';
            }
        }
        
        // Load financial stats
        async function loadStats(branchId) {
            try {
                const response = await fetch(`../../api/owner-stats.php?branch_id=${branchId}`);
                const data = await response.json();
                
                if (data.success) {
                    // Today stats
                    document.getElementById('todayIncome').innerHTML = formatCurrency(data.todayIncome);
                    document.getElementById('todayExpense').innerHTML = formatCurrency(data.todayExpense);
                    
                    const todayProfit = data.todayIncome - data.todayExpense;
                    const todayProfitEl = document.getElementById('todayProfit');
                    todayProfitEl.innerHTML = formatCurrency(todayProfit);
                    todayProfitEl.className = 'stat-value ' + (todayProfit >= 0 ? 'positive' : 'negative');
                    
                    // Month stats
                    document.getElementById('monthIncome').innerHTML = formatCurrency(data.monthIncome);
                    document.getElementById('monthExpense').innerHTML = formatCurrency(data.monthExpense);
                    
                    const monthProfit = data.monthIncome - data.monthExpense;
                    const monthProfitEl = document.getElementById('monthProfit');
                    monthProfitEl.innerHTML = formatCurrency(monthProfit);
                    monthProfitEl.className = 'stat-value ' + (monthProfit >= 0 ? 'positive' : 'negative');
                    
                    // Calculate growth vs last month if available
                    if (data.lastMonth) {
                        const incomeGrowth = data.lastMonth.income > 0 
                            ? ((data.monthIncome - data.lastMonth.income) / data.lastMonth.income * 100).toFixed(1)
                            : 0;
                        const expenseGrowth = data.lastMonth.expense > 0 
                            ? ((data.monthExpense - data.lastMonth.expense) / data.lastMonth.expense * 100).toFixed(1)
                            : 0;
                        
                        // Show growth indicators
                        const incomeChangeEl = document.getElementById('monthIncomeChange');
                        incomeChangeEl.style.display = 'inline-flex';
                        incomeChangeEl.className = 'stat-change ' + (incomeGrowth >= 0 ? 'up' : 'down');
                        incomeChangeEl.innerHTML = `${incomeGrowth >= 0 ? '↑' : '↓'} ${Math.abs(incomeGrowth)}% dari bulan lalu`;
                        
                        const expenseChangeEl = document.getElementById('monthExpenseChange');
                        expenseChangeEl.style.display = 'inline-flex';
                        expenseChangeEl.className = 'stat-change ' + (expenseGrowth > 0 ? 'down' : 'up');
                        expenseChangeEl.innerHTML = `${expenseGrowth >= 0 ? '↑' : '↓'} ${Math.abs(expenseGrowth)}% dari bulan lalu`;
                    }
                } else {
                    console.error('Failed to load stats:', data.message);
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        // Load hotel occupancy stats
        async function loadOccupancy(branchId) {
            const hotelStats = document.getElementById('hotelStats');
            
            // Check if selected business is hotel
            const selectedBusiness = businessData.find(b => b.id == branchId);
            const isHotel = selectedBusiness && selectedBusiness.business_type === 'hotel';
            
            if (!isHotel && branchId !== 'all') {
                hotelStats.style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch(`../../api/owner-occupancy.php?branch_id=${branchId}`);
                const data = await response.json();
                
                if (data.success) {
                    hotelStats.style.display = 'block';
                    
                    document.getElementById('occupancyRate').innerHTML = formatPercent(data.occupancy_rate || 0);
                    document.getElementById('availableRooms').innerHTML = data.available_rooms || 0;
                    document.getElementById('occupiedRooms').innerHTML = data.occupied_rooms || 0;
                } else {
                    hotelStats.style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading occupancy:', error);
                hotelStats.style.display = 'none';
            }
        }
        
        // Handle business change
        function switchBusiness(branchId) {
            currentBusiness = branchId;
            console.log('Switching to business:', branchId);
            
            // Load stats and occupancy for selected business
            loadStats(branchId);
            loadOccupancy(branchId);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadBusinesses();
        });
        
        // Handle business change
        document.getElementById('businessSelector').addEventListener('change', function() {
            switchBusiness(this.value);
        });
    </script>
</body>
</html>
