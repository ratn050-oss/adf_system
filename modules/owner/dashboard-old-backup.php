<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();

// Check if user is authorized to view owner dashboard
// Allow admin, manager, owner, and developer role
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

// Use logo-alt.png which is verified to work
$logoFile = 'logo-alt.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-app-capable" content="yes">
    <meta name="theme-color" content="#1e1b4b">
    <title>Owner Dashboard - <?php echo $displayCompanyName; ?> Monitoring</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Mobile-First Responsive Design */
        * {
            -webkit-tap-highlight-color: transparent;
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #0a0e27 0%, #1a1d3d 50%, #0f1729 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            overflow-x: hidden;
            min-height: 100vh;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(99, 102, 241, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(139, 92, 246, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .mobile-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.9) 0%, rgba(139, 92, 246, 0.9) 100%);
            padding: 1rem 1.25rem;
            box-shadow: 0 8px 32px rgba(99, 102, 241, 0.3), 0 2px 8px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(20px) saturate(180%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }
        
        .header-title {
            font-size: 1.125rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.3;
            letter-spacing: -0.02em;
        }
        
        .header-subtitle {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-top: 0.25rem;
            font-weight: 500;
        }
        
        .refresh-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            padding: 0.5rem;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .refresh-btn:active {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(0.95);
        }
        
        .branch-selector {
            background: white;
            margin: -0.5rem 1rem 1rem;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .branch-select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            background: white;
            cursor: pointer;
        }
        
        .content-wrapper {
            padding: 1rem 1rem 6rem;
            max-width: 100%;
            overflow-x: hidden;
            position: relative;
            z-index: 1;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(248, 250, 252, 0.95) 100%);
            padding: 0.65rem 0.75rem;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 0 1px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #6366f1 0%, #8b5cf6 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-card:active {
            transform: scale(0.98);
        }
        
        /* Income Card - Green */
        .stat-card.income-card {
            background: linear-gradient(135deg, rgba(236, 253, 245, 0.98) 0%, rgba(209, 250, 229, 0.98) 100%);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .stat-card.income-card::before {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }
        
        .stat-card.income-card .stat-label {
            color: #065f46;
        }
        
        .stat-card.income-card .stat-value {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Expense Card - Red */
        .stat-card.expense-card {
            background: linear-gradient(135deg, rgba(254, 242, 242, 0.98) 0%, rgba(254, 226, 226, 0.98) 100%);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .stat-card.expense-card::before {
            background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
        }
        
        .stat-card.expense-card .stat-label {
            color: #7f1d1d;
        }
        
        .stat-card.expense-card .stat-value {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            font-size: 0.6rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .stat-label svg {
            width: 14px;
            height: 14px;
            opacity: 0.7;
        }
        
        .stat-value {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.1;
            background: linear-gradient(135deg, #1e293b 0%, #4f46e5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }
        
        .stat-change {
            font-size: 0.55rem;
            margin-top: 0.15rem;
            font-weight: 500;
            color: #9ca3af;
        }
        
        .section-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(248, 250, 252, 0.95) 100%);
            padding: 1.25rem;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08), 0 0 1px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            margin-bottom: 1.25rem;
        }
        
        .section-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: -0.01em;
        }
        
        .section-title svg {
            width: 18px;
            height: 18px;
            color: #6366f1;
        }
        
        /* Responsive Pie Chart Container */
        .chart-comparison-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        @media (min-width: 768px) {
            .chart-comparison-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .chart-box {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .chart-box h4 {
            margin: 0 0 15px 0;
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 600;
        }
        
        .chart-box canvas {
            max-width: 100%;
            height: auto !important;
        }
        
        .occupancy-bar {
            height: 36px;
            background: #f3f4f6;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            margin-bottom: 0.5rem;
        }
        
        .occupancy-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.8rem;
            transition: width 0.5s ease;
        }
        
        .occupancy-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: #6b7280;
        }
        
        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.875rem 0;
            border-bottom: 1px solid rgba(241, 245, 249, 0.8);
            gap: 0.75rem;
            transition: all 0.2s;
        }
        
        .transaction-item:hover {
            padding-left: 0.5rem;
            background: rgba(248, 250, 252, 0.5);
            margin: 0 -0.5rem;
            padding-right: 0.5rem;
            border-radius: 8px;
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-info {
            flex: 1;
            min-width: 0;
        }
        
        .transaction-desc {
            font-size: 0.875rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .transaction-meta {
            font-size: 0.7rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .transaction-amount {
            font-size: 1rem;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
            border-top: none;
            display: flex;
            justify-content: space-around;
            padding: 0.6rem 0;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.25);
            z-index: 1000;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.2rem;
            color: rgba(255,255,255,0.6);
            font-size: 0.6rem;
            text-decoration: none;
            padding: 0.35rem 0.75rem;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .nav-item:hover {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        
        .nav-item.active {
            color: white;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: #6b7280;
        }
        
        .empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            opacity: 0.5;
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 260px;
            margin-top: 1rem;
        }
        
        @media (min-width: 768px) {
            .chart-container {
                height: 360px;
            }
        }        
        .period-selector {
            display: flex;
            gap: 0.375rem;
            margin-bottom: 1rem;
            background: rgba(248, 250, 252, 0.8);
            padding: 0.35rem;
            border-radius: 12px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .period-btn {
            flex: 1;
            padding: 0.5rem 0.4rem;
            border: none;
            background: transparent;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .period-btn.active {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
            transform: translateY(-1px);
        }
        
        .period-btn.active {
            background: white;
            color: #4338ca;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }        
        /* Pull to Refresh */
        .pull-to-refresh {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4338ca;
            font-size: 0.875rem;
            transform: translateY(-100%);
            transition: transform 0.3s;
        }
        
        .pull-to-refresh.visible {
            transform: translateY(0);
        }
        
        /* Tablet and Desktop */
        @media (min-width: 768px) {
            .content-wrapper {
                max-width: 1200px;
                margin: 0 auto;
                padding: 1rem 2rem 2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .bottom-nav {
                display: none;
            }
        }
        
        /* Business Button Styles */
        .business-btn {
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(248,250,252,0.95) 100%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            padding: 0.5rem 0.4rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            text-align: center;
            min-height: 55px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        
        .business-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(67, 56, 202, 0.05) 0%, rgba(99, 102, 241, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .business-btn:hover::before {
            opacity: 1;
        }
        
        .business-btn:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(99, 102, 241, 0.25), 0 0 24px rgba(139, 92, 246, 0.15);
            border-color: rgba(99, 102, 241, 0.4);
        }
        
        .business-btn.active {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border-color: transparent;
            color: white;
            box-shadow: 0 8px 32px rgba(99, 102, 241, 0.5), 0 0 40px rgba(139, 92, 246, 0.3);
            transform: translateY(-2px);
        }
        
        .business-btn.active::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 100%);
            pointer-events: none;
        }
        
        .business-btn.all-branches {
            background: linear-gradient(135deg, #10b981 0%, #34d399 50%, #6ee7b7 100%);
            border-color: #10b981;
            color: white;
        }
        
        .business-btn.all-branches.active {
            background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
            box-shadow: 0 8px 28px rgba(16, 185, 129, 0.4), 0 0 30px rgba(52, 211, 153, 0.2);
        }
        
        .business-icon {
            font-size: 18px;
            line-height: 1;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.1));
            transition: all 0.3s ease;
        }
        
        .business-btn:hover .business-icon {
            transform: scale(1.1);
            filter: drop-shadow(0 3px 8px rgba(67, 56, 202, 0.3));
        }
        
        .business-btn.active .business-icon {
            filter: drop-shadow(0 2px 8px rgba(255,255,255,0.4));
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        /* Health Indicator Styles */
        .health-indicator {
            background: linear-gradient(135deg, rgba(255,255,255,0.98) 0%, rgba(248,250,252,0.98) 100%);
            border-radius: 20px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08), 0 0 1px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            display: none;
            animation: fadeIn 0.4s ease-in;
        }
        
        .health-score {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .health-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.6rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.7rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            animation: fadeIn 0.5s ease;
        }
        
        .health-badge.excellent {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
        }
        
        .health-badge.good {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            color: white;
        }
        
        .health-badge.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            color: white;
        }
        
        .health-badge.critical {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
            color: white;
        }
        
        .health-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
            margin-bottom: 1rem;
        }
        
        .health-metric {
            text-align: center;
            padding: 0.875rem 0.5rem;
            background: linear-gradient(135deg, rgba(241,245,249,0.8) 0%, rgba(248,250,252,0.8) 100%);
            border-radius: 14px;
            border: 1px solid rgba(226, 232, 240, 0.5);
            transition: all 0.2s;
        }
        
        .health-metric:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
        }
        
        .health-metric-label {
            font-size: 0.7rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
        }
        
        .health-metric-value {
            font-size: 1.25rem;
            font-weight: 800;
            background: linear-gradient(135deg, #4f46e5 0%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .business-name {
            font-size: 0.5rem;
            font-weight: 600;
            line-height: 1.1;
            color: #374151;
        }
        
        .business-btn.active .business-name {
            color: white;
        }
        
        .business-btn.all-branches .business-name {
            color: white;
        }
    </style>
</head>
<body>
    <div class="pull-to-refresh" id="pullToRefresh">
        <div class="loading-spinner"></div>
        <span style="margin-left: 0.5rem;">Pull to refresh...</span>
    </div>
    
    <div class="mobile-header">
        <div class="header-content">
            <div style="display: flex; align-items: center; gap: 1rem; flex: 1;">
                <!-- Dynamic Logo -->
                <img src="<?php echo BASE_URL . '/' . DEVELOPER_LOGO; ?>" 
                     alt="Developer" 
                     id="headerLogo"
                     style="height: 45px; width: auto; object-fit: contain; background: white; padding: 0.5rem; border-radius: 8px;"
                     onerror="this.style.display='none'; document.getElementById('logoFallback').style.display='flex';"
                     onload="this.style.display='block'; document.getElementById('logoFallback').style.display='none';">
                <div id="logoFallback" style="width: 45px; height: 45px; background: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                    👨‍💻
                </div>
                <div>
                    <div class="header-title">All Businesses</div>
                    <div class="header-subtitle" id="currentTime">Loading...</div>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <a href="manage-user-access.php" class="refresh-btn" style="text-decoration: none; color: white; display: flex; align-items: center;" title="Manage User Access">
                    <i data-feather="users" style="width: 20px; height: 20px;"></i>
                </a>
                <button class="refresh-btn" onclick="refreshData()" id="refreshBtn">
                    <i data-feather="refresh-cw" style="width: 20px; height: 20px;"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Business Selector - Compact -->
    <div class="business-selector" style="padding: 0.75rem; background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(248,250,252,0.95) 100%); border-radius: 14px; margin: 0.75rem; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid rgba(255, 255, 255, 0.2);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
            <div style="font-size: 0.7rem; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 0.35rem;">
                <i data-feather="briefcase" style="width: 12px; height: 12px; color: #6366f1;"></i>
                Select Business
            </div>
            <div id="selectedBusinessName" style="font-size: 0.6rem; color: #6366f1; font-weight: 600; background: rgba(99, 102, 241, 0.1); padding: 0.2rem 0.5rem; border-radius: 6px;">All Businesses</div>
        </div>
        <div id="businessButtons" class="business-buttons-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(70px, 1fr)); gap: 0.5rem;">
            <!-- Buttons will be inserted here by JavaScript -->
        </div>
    </div>
    
    <!-- AI Health Indicator - Auto changes based on business -->
    <div id="healthIndicator" class="health-indicator" style="margin: 1rem; display: none;">
        <div class="health-score">
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem;">
                    🤖 AI Health Score
                </div>
                <div id="businessNameHealth" style="font-size: 0.875rem; font-weight: 600; color: #1e293b;"></div>
            </div>
            <div id="healthBadge" class="health-badge excellent">
                <span>●</span>
                <span id="healthStatus">Excellent</span>
            </div>
        </div>
        <div class="health-metrics">
            <div class="health-metric">
                <div class="health-metric-label">Profit</div>
                <div class="health-metric-value" id="healthProfit">0%</div>
            </div>
            <div class="health-metric">
                <div class="health-metric-label">Growth</div>
                <div class="health-metric-value" id="healthGrowth">0%</div>
            </div>
            <div class="health-metric">
                <div class="health-metric-label">Efficiency</div>
                <div class="health-metric-value" id="healthEfficiency">0%</div>
            </div>
        </div>
        <div id="healthRecommendation" style="margin-top: 1rem; padding: 0.875rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.08) 0%, rgba(139, 92, 246, 0.08) 100%); border-radius: 14px; font-size: 0.8rem; color: #475569; line-height: 1.5; border: 1px solid rgba(99, 102, 241, 0.2); box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);">
            <strong style="color: #4f46e5; font-size: 0.8rem; display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.35rem;">
                <span style="font-size: 1.1rem;">💡</span> Smart AI Insight
            </strong>
            <span id="healthInsight" style="display: block; color: #334155; font-weight: 500;">Loading intelligent analysis...</span>
        </div>
    </div>
    
    <!-- Comparison View - Only visible when All Branches selected -->
    <div id="comparisonView" class="content-wrapper" style="display: none;">
        <div class="section-card">
            <div class="section-title">
                <i data-feather="pie-chart" style="width: 20px; height: 20px; color: #4338ca;"></i>
                <span>Revenue Distribution</span>
            </div>
            <div class="period-selector">
                <button class="period-btn" onclick="changeComparisonPeriod('today')" data-period="today">
                    Today
                </button>
                <button class="period-btn active" onclick="changeComparisonPeriod('this_month')" data-period="this_month">
                    This Month
                </button>
                <button class="period-btn" onclick="changeComparisonPeriod('this_year')" data-period="this_year">
                    This Year
                </button>
            </div>
            
            <!-- Elegant Pie Chart -->
            <div style="margin-top: 20px; padding: 1.5rem; background: linear-gradient(135deg, rgba(249, 250, 251, 0.95) 0%, rgba(255, 255, 255, 0.95) 100%); border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.06);">
                <div style="display: flex; align-items: center; gap: 2rem; flex-wrap: wrap;">
                    <div style="position: relative; width: 180px; height: 180px; margin: 0 auto;">
                        <canvas id="comparisonPieChart"></canvas>
                        <div id="pieChartCenter" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                            <div style="font-size: 0.65rem; color: #6b7280; font-weight: 600;">TOTAL</div>
                            <div id="totalRevenue" style="font-size: 1rem; font-weight: 700; color: #1f2937;">Rp 0</div>
                        </div>
                    </div>
                    <div id="pieChartLegend" style="flex: 1; min-width: 150px;">
                        <!-- Legend will be generated by JS -->
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Smart AI Business Health Analysis -->
        <div class="section-card" style="margin-top: 1rem; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border: none; padding: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                <div style="width: 28px; height: 28px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <i data-feather="cpu" style="width: 14px; height: 14px; color: white;"></i>
                </div>
                <span style="font-size: 0.85rem; font-weight: 700; color: white; letter-spacing: 0.5px;">AI Business Health</span>
            </div>
            
            <!-- Overall Health Score -->
            <div id="allBusinessHealth">
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; background: rgba(255,255,255,0.05); padding: 0.5rem; border-radius: 10px;">
                    <div id="overallHealthGauge" style="position: relative; width: 50px; height: 50px; flex-shrink: 0;">
                        <canvas id="healthGaugeChart"></canvas>
                        <div id="healthScoreDisplay" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                            <div style="font-size: 0.85rem; font-weight: 800; color: #10b981;">0</div>
                        </div>
                    </div>
                    <div style="flex: 1;">
                        <div id="healthStatusText" style="font-size: 0.75rem; font-weight: 700; color: white; margin-bottom: 0.15rem;">Analyzing...</div>
                        <div id="healthSummary" style="font-size: 0.55rem; color: rgba(255,255,255,0.6); line-height: 1.4;">Loading...</div>
                    </div>
                </div>
                
                <!-- Detailed Metrics -->
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; margin-bottom: 0.75rem;">
                    <div style="background: rgba(16, 185, 129, 0.1); padding: 0.6rem; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                        <div style="font-size: 0.55rem; color: rgba(255,255,255,0.5); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Profit Margin</div>
                        <div id="allProfitMargin" style="font-size: 1rem; font-weight: 700; color: #34d399;">0%</div>
                        <div id="profitMarginStatus" style="font-size: 0.5rem; color: rgba(255,255,255,0.4);">-</div>
                    </div>
                    <div style="background: rgba(251, 191, 36, 0.1); padding: 0.6rem; border-radius: 10px; border: 1px solid rgba(251, 191, 36, 0.2);">
                        <div style="font-size: 0.55rem; color: rgba(255,255,255,0.5); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Expense Ratio</div>
                        <div id="allExpenseRatio" style="font-size: 1rem; font-weight: 700; color: #fbbf24;">0%</div>
                        <div id="expenseRatioStatus" style="font-size: 0.5rem; color: rgba(255,255,255,0.4);">-</div>
                    </div>
                    <div style="background: rgba(99, 102, 241, 0.1); padding: 0.6rem; border-radius: 10px; border: 1px solid rgba(99, 102, 241, 0.2);">
                        <div style="font-size: 0.55rem; color: rgba(255,255,255,0.5); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Top Performer</div>
                        <div id="topPerformer" style="font-size: 0.8rem; font-weight: 700; color: #a5b4fc;">-</div>
                        <div id="topPerformerValue" style="font-size: 0.5rem; color: rgba(255,255,255,0.4);">-</div>
                    </div>
                    <div style="background: rgba(239, 68, 68, 0.1); padding: 0.6rem; border-radius: 10px; border: 1px solid rgba(239, 68, 68, 0.2);">
                        <div style="font-size: 0.55rem; color: rgba(255,255,255,0.5); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Needs Attention</div>
                        <div id="needsAttention" style="font-size: 0.8rem; font-weight: 700; color: #f87171;">-</div>
                        <div id="needsAttentionValue" style="font-size: 0.5rem; color: rgba(255,255,255,0.4);">-</div>
                    </div>
                </div>
                
                <!-- AI Recommendations -->
                <div style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(99, 102, 241, 0.1) 100%); padding: 0.75rem; border-radius: 10px; border: 1px solid rgba(139, 92, 246, 0.2);">
                    <div style="font-size: 0.6rem; font-weight: 700; color: #c4b5fd; margin-bottom: 0.4rem; display: flex; align-items: center; gap: 0.35rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i data-feather="zap" style="width: 10px; height: 10px;"></i> AI Insights
                    </div>
                    <div id="aiRecommendations" style="font-size: 0.65rem; color: rgba(255,255,255,0.75); line-height: 1.6;">
                        Loading intelligent analysis...
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Business Cards Grid -->
        <div id="businessCardsGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-top: 1rem;">
            <!-- Business cards will be inserted here by JavaScript -->
        </div>
    </div>
    
    <!-- Single Branch View - visible by default -->
    <div id="singleBranchView" class="content-wrapper">
        <!-- Combined Chart + Stats Row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
            <!-- Left: Elegant Pie Chart -->
            <div class="section-card" style="padding: 0.75rem; background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%); position: relative; overflow: hidden;">
                <!-- Glassmorphism overlay -->
                <div style="position: absolute; top: -50%; right: -30%; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); pointer-events: none;"></div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; position: relative; z-index: 1;">
                    <div style="font-size: 0.65rem; font-weight: 700; color: rgba(255,255,255,0.9); display: flex; align-items: center; gap: 0.35rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i data-feather="pie-chart" style="width: 12px; height: 12px; color: #a5b4fc;"></i>
                        Cash Flow
                    </div>
                    <div style="font-size: 0.5rem; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.1); padding: 0.15rem 0.4rem; border-radius: 10px;">This Month</div>
                </div>
                
                <div style="display: flex; align-items: center; gap: 0.75rem; position: relative; z-index: 1;">
                    <!-- Pie Chart -->
                    <div style="position: relative; width: 80px; height: 80px; flex-shrink: 0;">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                    
                    <!-- Legend -->
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.35rem;">
                            <div style="width: 10px; height: 10px; border-radius: 3px; background: linear-gradient(135deg, #10b981, #34d399);"></div>
                            <div>
                                <div style="font-size: 0.5rem; color: rgba(255,255,255,0.6);">Income</div>
                                <div id="pieIncomeValue" style="font-size: 0.7rem; font-weight: 700; color: #34d399;">Rp 0</div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                            <div style="width: 10px; height: 10px; border-radius: 3px; background: linear-gradient(135deg, #ef4444, #f87171);"></div>
                            <div>
                                <div style="font-size: 0.5rem; color: rgba(255,255,255,0.6);">Expense</div>
                                <div id="pieExpenseValue" style="font-size: 0.7rem; font-weight: 700; color: #f87171;">Rp 0</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Ratio Bar -->
                <div style="margin-top: 0.5rem; position: relative; z-index: 1;">
                    <div style="height: 4px; background: rgba(255,255,255,0.15); border-radius: 2px; overflow: hidden; display: flex;">
                        <div id="incomeRatioBar" style="height: 100%; background: linear-gradient(90deg, #10b981, #34d399); width: 70%; transition: width 0.5s ease;"></div>
                        <div id="expenseRatioBar" style="height: 100%; background: linear-gradient(90deg, #ef4444, #f87171); width: 30%; transition: width 0.5s ease;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 0.2rem;">
                        <span id="incomePercent" style="font-size: 0.5rem; color: #34d399; font-weight: 600;">70%</span>
                        <span id="expensePercent" style="font-size: 0.5rem; color: #f87171; font-weight: 600;">30%</span>
                    </div>
                </div>
                
                <!-- Top 3 Income Sources -->
                <div style="margin-top: 0.6rem; padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.1); position: relative; z-index: 1;">
                    <div style="font-size: 0.55rem; font-weight: 600; color: rgba(255,255,255,0.7); margin-bottom: 0.4rem; display: flex; align-items: center; gap: 0.25rem;">
                        <i data-feather="trending-up" style="width: 10px; height: 10px; color: #34d399;"></i>
                        Top Income
                    </div>
                    <div id="topIncomeList" style="display: flex; flex-direction: column; gap: 0.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.25rem 0.4rem; background: rgba(255,255,255,0.08); border-radius: 4px;">
                            <span style="font-size: 0.55rem; color: rgba(255,255,255,0.8);">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right: Stats Container -->
            <div class="section-card" style="padding: 0.75rem;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #374151; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <i data-feather="dollar-sign" style="width: 14px; height: 14px; color: #10b981;"></i>
                    Financial Summary
                </div>
                
                <!-- Operational Balance & Capital Received -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <div style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.3);">
                        <div style="font-size: 0.55rem; color: #1e3a8a; font-weight: 600; text-transform: uppercase; margin-bottom: 0.15rem; display: flex; align-items: center; gap: 0.25rem;">
                            <i data-feather="wallet" style="width: 10px; height: 10px;"></i>
                            Saldo Operasional
                        </div>
                        <div id="operationalBalance" style="font-size: 0.85rem; font-weight: 700; color: #1e40af;">Rp 0</div>
                        <div style="font-size: 0.5rem; color: #6b7280;">Kas Harian</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.3);">
                        <div style="font-size: 0.55rem; color: #78350f; font-weight: 600; text-transform: uppercase; margin-bottom: 0.15rem; display: flex; align-items: center; gap: 0.25rem;">
                            <i data-feather="gift" style="width: 10px; height: 10px;"></i>
                            Cash dari Owner
                        </div>
                        <div id="todayCapitalReceived" style="font-size: 0.85rem; font-weight: 700; color: #d97706;">Rp 0</div>
                        <div style="font-size: 0.5rem; color: #6b7280;">Hari Ini</div>
                    </div>
                </div>
                
                <!-- Today Stats -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2);">
                        <div style="font-size: 0.55rem; color: #065f46; font-weight: 600; text-transform: uppercase; margin-bottom: 0.15rem; display: flex; align-items: center; gap: 0.25rem;">
                            <i data-feather="trending-up" style="width: 10px; height: 10px;"></i>
                            Pendapatan Tamu
                        </div>
                        <div id="todayIncome" style="font-size: 0.85rem; font-weight: 700; color: #10b981;">Rp 0</div>
                        <div id="todayIncomeCount" style="font-size: 0.5rem; color: #6b7280;">0 txn</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2);">
                        <div style="font-size: 0.55rem; color: #7f1d1d; font-weight: 600; text-transform: uppercase; margin-bottom: 0.15rem;">Today Expense</div>
                        <div id="todayExpense" style="font-size: 0.85rem; font-weight: 700; color: #ef4444;">Rp 0</div>
                        <div id="todayExpenseCount" style="font-size: 0.5rem; color: #6b7280;">0 txn</div>
                    </div>
                </div>
                
                <!-- Monthly Stats -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                    <div style="background: linear-gradient(135deg, #f0fdf4 0%, #bbf7d0 100%); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.2);">
                        <div style="font-size: 0.55rem; color: #166534; font-weight: 600; text-transform: uppercase; margin-bottom: 0.15rem;">Monthly Income</div>
                        <div id="monthIncome" style="font-size: 0.85rem; font-weight: 700; color: #22c55e;">Rp 0</div>
                        <div id="monthIncomeChange" style="font-size: 0.5rem; color: #6b7280;">+0% vs last month</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(249, 115, 22, 0.2);">
                        <div style="font-size: 0.55rem; color: #9a3412; font-weight: 600; text-transform: uppercase; margin-bottom: 0.15rem;">Monthly Expense</div>
                        <div id="monthExpense" style="font-size: 0.85rem; font-weight: 700; color: #f97316;">Rp 0</div>
                        <div id="monthExpenseChange" style="font-size: 0.5rem; color: #6b7280;">+0% vs last month</div>
                    </div>
                </div>
                
                <!-- Net Profit Summary -->
                <div style="margin-top: 0.5rem; padding: 0.5rem; background: linear-gradient(135deg, #eef2ff 0%, #c7d2fe 100%); border-radius: 8px; border: 1px solid rgba(99, 102, 241, 0.2);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 0.55rem; color: #3730a3; font-weight: 600; text-transform: uppercase;">Net Profit (Month)</div>
                            <div id="netProfitMonth" style="font-size: 1rem; font-weight: 800; color: #4f46e5;">Rp 0</div>
                        </div>
                        <div id="profitIndicator" style="width: 36px; height: 36px; border-radius: 50%; background: #10b981; display: flex; align-items: center; justify-content: center;">
                            <i data-feather="trending-up" style="width: 18px; height: 18px; color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Income by Division Pie Chart -->
        <div class="section-card">
            <div class="section-title">
                <i data-feather="pie-chart" style="width: 20px; height: 20px; color: #4338ca;"></i>
                Income by Division
            </div>
            <div style="position: relative; height: 250px; margin-top: 1rem;">
                <canvas id="divisionPieChart"></canvas>
            </div>
            <div id="divisionLegend" style="display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center; margin-top: 1rem;">
                <!-- Legend will be inserted by JavaScript -->
            </div>
        </div>
        
        <!-- Inhouse Guests & Upcoming Check-ins (Hotel Only) -->
        <div class="section-card hotel-only-section">
            <div class="section-title" style="margin-bottom: 1rem;">
                <i data-feather="users" style="width: 20px; height: 20px; color: #4338ca;"></i>
                Guest Overview
            </div>
            
            <!-- Stats Row -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem;">
                <div style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); padding: 0.75rem; border-radius: 10px; text-align: center;">
                    <div style="font-size: 0.7rem; color: #1e40af; font-weight: 600;">🏨 Inhouse</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #1e3a8a;" id="inhouseCount">0</div>
                    <div style="font-size: 0.65rem; color: #3b82f6;" id="inhouseRooms">0 rooms</div>
                </div>
                <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 0.75rem; border-radius: 10px; text-align: center;">
                    <div style="font-size: 0.7rem; color: #92400e; font-weight: 600;">📅 Upcoming</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #78350f;" id="upcomingCheckins">0</div>
                    <div style="font-size: 0.65rem; color: #d97706;" id="upcomingDetails">this week</div>
                </div>
            </div>
            
            <!-- Inhouse Guest List -->
            <div style="background: #f8fafc; border-radius: 10px; padding: 0.75rem; margin-bottom: 0.75rem;">
                <div style="font-size: 0.75rem; font-weight: 700; color: #1e3a8a; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.25rem;">🛏️ Tamu Inhouse</span>
                    <span id="inhouseTotal" style="font-size: 0.65rem; color: #6b7280; font-weight: 500;"></span>
                </div>
                <div id="inhouseList" style="max-height: 180px; overflow-y: auto;">
                    <div style="text-align: center; color: #9ca3af; padding: 0.5rem; font-size: 0.75rem;">Loading...</div>
                </div>
            </div>
            
            <!-- Upcoming Arrivals -->
            <div style="background: #fffbeb; border-radius: 10px; padding: 0.75rem;">
                <div style="font-size: 0.75rem; font-weight: 700; color: #92400e; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.25rem;">
                    <span>📅</span> Upcoming Arrivals
                </div>
                <div id="upcomingList" style="max-height: 120px; overflow-y: auto;">
                    <div style="text-align: center; color: #9ca3af; padding: 0.5rem; font-size: 0.75rem;">Loading...</div>
                </div>
            </div>
        </div>
        
        <!-- Reservation Trend Chart (Hotel Only) -->
        <div class="section-card hotel-only-section">
            <div class="section-title">
                <i data-feather="calendar" style="width: 20px; height: 20px; color: #4338ca;"></i>
                Reservation Trend
            </div>
            <div style="position: relative; height: 200px; margin-top: 1rem;">
                <canvas id="reservationChart"></canvas>
            </div>
        </div>
        
        <!-- Recent Transactions -->
        <div class="section-card">
            <div class="section-title">
                <i data-feather="list" style="width: 20px; height: 20px; color: #4338ca;"></i>
                Recent Transactions
            </div>
            <div id="recentTransactions">
                <div class="empty-state">
                    <div class="loading-spinner" style="width: 40px; height: 40px; border-width: 4px; border-color: #e5e7eb; border-top-color: #4338ca;"></div>
                    <p style="margin-top: 1rem;">Loading transactions...</p>
                </div>
            </div>
        </div>
    </div>
    <!-- End Single Branch View -->
    
    <div class="bottom-nav">
        <a href="#" class="nav-item active" onclick="scrollToTop(); return false;">
            <i data-feather="home" style="width: 18px; height: 18px;"></i>
            Dashboard
        </a>
        <a href="investor-dashboard.php" class="nav-item">
            <i data-feather="trending-up" style="width: 18px; height: 18px;"></i>
            Investor
        </a>
        <a href="../../logout.php" class="nav-item">
            <i data-feather="log-out" style="width: 18px; height: 18px;"></i>
            Logout
        </a>
    </div>
    
    <script>
        let currentBranchId = null;
        let weeklyChart = null;
        let currentPeriod = '7days';
        let currentComparisonPeriod = 'this_month';
        let comparisonPieChart = null;
        let healthGaugeChart = null;
        let occupancyPieChart = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('=== Owner Dashboard Loaded ===');
            console.log('Chart.js available:', typeof Chart !== 'undefined');
            
            feather.replace();
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            // Initialize chart first, then load data
            console.log('Initializing chart...');
            initChart();
            console.log('Chart initialized:', weeklyChart);
            
            console.log('Loading branches...');
            loadBranches(); // This will trigger loadBranchData which loads chart data
            
            // Pull to refresh
            let startY = 0;
            let isPulling = false;
            
            document.addEventListener('touchstart', function(e) {
                if (window.pageYOffset === 0) {
                    startY = e.touches[0].pageY;
                }
            });
            
            document.addEventListener('touchmove', function(e) {
                if (window.pageYOffset === 0) {
                    let currentY = e.touches[0].pageY;
                    let pullDistance = currentY - startY;
                    
                    if (pullDistance > 60 && !isPulling) {
                        document.getElementById('pullToRefresh').classList.add('visible');
                        isPulling = true;
                    }
                }
            });
            
            document.addEventListener('touchend', function() {
                if (isPulling) {
                    refreshData();
                    setTimeout(() => {
                        document.getElementById('pullToRefresh').classList.remove('visible');
                        isPulling = false;
                    }, 1000);
                }
            });
        });
        
        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function updateCurrentTime() {
            const now = new Date();
            const options = { 
                weekday: 'short', 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            document.getElementById('currentTime').textContent = now.toLocaleDateString('id-ID', options);
        }
        
        async function loadBranches() {
            try {
                const response = await fetch('../../api/owner-branches.php');
                const data = await response.json();
                
                if (data.success) {
                    const container = document.getElementById('businessButtons');
                    container.innerHTML = '';
                    
                    // Business icons mapping
                    const businessIcons = {
                        'narayana hotel': '🏨',
                        'bens cafe': '☕',
                        'eat & meet': '🍽️',
                        'furniture': '🪑',
                        'karimunjawa': '⛵',
                        'pabrik kapal': '🚢'
                    };
                    
                    // Add "All Businesses" button with developer logo
                    const allBtn = document.createElement('button');
                    allBtn.className = 'business-btn all-branches active';
                    allBtn.onclick = () => selectBusiness(null, 'All Businesses');
                    allBtn.innerHTML = `
                        <div class="business-icon" style="padding: 0;">
                            <img src="<?php echo BASE_URL . '/' . DEVELOPER_LOGO; ?>" alt="All" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='🏢';">
                        </div>
                        <div class="business-name">All<br>Businesses</div>
                    `;
                    container.appendChild(allBtn);
                    
                    // Add individual business buttons
                    data.branches.forEach(branch => {
                        const btn = document.createElement('button');
                        btn.className = 'business-btn';
                        btn.dataset.branchId = branch.id;
                        btn.onclick = () => selectBusiness(branch.id, branch.branch_name);
                        
                        // Find matching icon
                        let icon = '🏢';
                        const nameLower = branch.branch_name.toLowerCase();
                        for (const [key, value] of Object.entries(businessIcons)) {
                            if (nameLower.includes(key)) {
                                icon = value;
                                break;
                            }
                        }
                        
                        // Shorten name if too long
                        let displayName = branch.branch_name;
                        if (displayName.length > 20) {
                            displayName = displayName.substring(0, 18) + '...';
                        }
                        // Add line break for better display
                        displayName = displayName.replace(/ - /g, '<br>').replace(/ /g, ' ');
                        
                        btn.innerHTML = `
                            <div class="business-icon">${icon}</div>
                            <div class="business-name">${displayName}</div>
                        `;
                        container.appendChild(btn);
                    });
                    
                    feather.replace();
                    
                    // Auto-select All Businesses by default
                    selectBusiness(null, 'All Businesses');
                } else {
                    console.error('Failed to load branches:', data);
                    // Even if no branches, try to load data
                    loadBranchData();
                }
            } catch (error) {
                console.error('Error loading branches:', error);
                // Try to load data anyway
                loadBranchData();
            }
        }
        
        // Store business data globally for header updates
        let businessesData = [];
        
        function selectBusiness(branchId, branchName) {
            currentBranchId = branchId;
            
            // Update button active states
            document.querySelectorAll('.business-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Update header based on selected business
            const headerLogo = document.getElementById('headerLogo');
            const headerTitle = document.querySelector('.header-title');
            const logoFallback = document.getElementById('logoFallback');
            
            if (branchId === null) {
                document.querySelector('.business-btn.all-branches').classList.add('active');
                // Hide health indicator for All Businesses
                document.getElementById('healthIndicator').style.display = 'none';
                // Developer logo for All Businesses
                headerLogo.src = '<?php echo BASE_URL . '/' . DEVELOPER_LOGO; ?>';
                headerLogo.alt = 'Developer';
                headerTitle.textContent = 'All Businesses';
                logoFallback.innerHTML = '👨‍💻';
            } else {
                const selectedBtn = document.querySelector(`[data-branch-id="${branchId}"]`);
                if (selectedBtn) selectedBtn.classList.add('active');
                // Show and load health indicator for specific business
                loadHealthIndicator(branchId, branchName);
                
                // Update header with business info
                const businessIcons = {
                    'narayana hotel': { icon: '🏨', logo: 'logo-alt.png' },
                    'bens cafe': { icon: '☕', logo: 'bens-cafe-logo.png' },
                    'eat & meet': { icon: '🍽️', logo: 'eat-meet-logo.png' },
                    'furniture': { icon: '🪑', logo: 'furniture-logo.png' },
                    'karimunjawa': { icon: '⛵', logo: 'karimunjawa-logo.png' },
                    'pabrik kapal': { icon: '🚢', logo: 'pabrik-kapal-logo.png' }
                };
                
                const nameLower = branchName.toLowerCase();
                let businessInfo = { icon: '🏢', logo: 'logo-alt.png' };
                for (const [key, value] of Object.entries(businessIcons)) {
                    if (nameLower.includes(key)) {
                        businessInfo = value;
                        break;
                    }
                }
                
                headerLogo.src = '../../uploads/logos/' + businessInfo.logo;
                headerLogo.alt = branchName;
                headerTitle.textContent = branchName;
                logoFallback.innerHTML = businessInfo.icon;
            }
            
            // Update selected business name display
            document.getElementById('selectedBusinessName').textContent = branchName;
            
            // Show/hide hotel-only sections based on business type
            const hotelOnlySections = document.querySelectorAll('.hotel-only-section');
            const isRestaurant = branchName && (branchName.toLowerCase().includes('cafe') || 
                                                 branchName.toLowerCase().includes('resto') || 
                                                 branchName.toLowerCase().includes('eat'));
            
            hotelOnlySections.forEach(section => {
                section.style.display = isRestaurant ? 'none' : 'block';
            });
            
            // Load data for selected business
            loadBranchData();
        }
        
        async function loadHealthIndicator(branchId, branchName) {
            try {
                // Fetch stats for this business
                const response = await fetch(`../../api/owner-stats.php?branch_id=${branchId}`);
                const data = await response.json();
                
                if (data.success) {
                    // Calculate metrics from correct API response format
                    const todayIncome = parseFloat(data.today?.income || 0);
                    const todayExpense = parseFloat(data.today?.expense || 0);
                    const monthIncome = parseFloat(data.month?.income || 0);
                    const monthExpense = parseFloat(data.month?.expense || 0);
                    
                    // Profit Margin (current month)
                    const profitMargin = monthIncome > 0 
                        ? ((monthIncome - monthExpense) / monthIncome * 100) 
                        : 0;
                    
                    // Growth Rate (compare today vs average daily)
                    const today = new Date();
                    const dayOfMonth = today.getDate();
                    const avgDailyIncome = monthIncome / dayOfMonth;
                    const growthRate = avgDailyIncome > 0 
                        ? ((todayIncome - avgDailyIncome) / avgDailyIncome * 100) 
                        : 0;
                    
                    // Efficiency Score (revenue per expense)
                    const efficiency = (monthIncome + monthExpense) > 0 
                        ? (monthIncome / (monthIncome + monthExpense) * 100) 
                        : 50;
                    
                    // Calculate overall health score (weighted average)
                    const healthScore = (profitMargin * 0.5) + (Math.min(Math.max(growthRate, 0), 100) * 0.3) + (efficiency * 0.2);
                    
                    // Determine health status
                    let healthStatus, healthClass;
                    if (healthScore >= 80) {
                        healthStatus = 'Excellent';
                        healthClass = 'excellent';
                    } else if (healthScore >= 60) {
                        healthStatus = 'Good';
                        healthClass = 'good';
                    } else if (healthScore >= 40) {
                        healthStatus = 'Warning';
                        healthClass = 'warning';
                    } else {
                        healthStatus = 'Critical';
                        healthClass = 'critical';
                    }
                    
                    // Generate AI insight
                    let insight = '';
                    if (profitMargin < 20) {
                        insight = '💡 Low profit margin detected. Consider optimizing operational costs or increasing prices.';
                    } else if (profitMargin >= 50) {
                        insight = '🎯 Excellent performance! Maintain current strategy and consider expansion.';
                    } else if (growthRate < -10) {
                        insight = '📉 Declining trend detected. Focus on customer retention and marketing strategy.';
                    } else if (efficiency < 60) {
                        insight = '⚠️ High expense ratio. Review cost structure and identify savings opportunities.';
                    } else if (healthScore >= 70) {
                        insight = '✨ Business in healthy condition. Continue monitoring and maintain service quality.';
                    } else {
                        insight = '📊 Stable performance. Look for opportunities to improve efficiency and revenue.';
                    }
                    
                    // Update UI
                    document.getElementById('businessNameHealth').textContent = branchName;
                    document.getElementById('healthBadge').className = `health-badge ${healthClass}`;
                    document.getElementById('healthStatus').textContent = healthStatus;
                    document.getElementById('healthProfit').textContent = `${profitMargin.toFixed(1)}%`;
                    document.getElementById('healthGrowth').textContent = `${growthRate.toFixed(1)}%`;
                    document.getElementById('healthEfficiency').textContent = `${efficiency.toFixed(1)}%`;
                    document.getElementById('healthInsight').textContent = insight;
                    
                    // Show the health indicator with animation
                    const healthIndicator = document.getElementById('healthIndicator');
                    healthIndicator.style.display = 'block';
                    healthIndicator.style.animation = 'fadeIn 0.3s ease-in';
                }
            } catch (error) {
                console.error('Error loading health indicator:', error);
            }
        }
        
        async function loadBranchData() {
            // Toggle views based on selection
            if (currentBranchId === null) {
                // Show comparison view for All Branches
                document.getElementById('singleBranchView').style.display = 'none';
                document.getElementById('comparisonView').style.display = 'block';
                loadComparisonData();
            } else {
                // Show single branch view
                document.getElementById('singleBranchView').style.display = 'block';
                document.getElementById('comparisonView').style.display = 'none';
                await Promise.all([
                    loadStats(),
                    loadRecentTransactions(),
                    loadTopIncome(),
                    loadDivisionPieChart(),
                    loadGuestOverview(),
                    loadReservationTrend()
                ]);
            }
        }
        
        async function loadStats() {
            try {
                const url = currentBranchId 
                    ? `../../api/owner-stats.php?branch_id=${currentBranchId}`
                    : '../../api/owner-stats.php';
                
                console.log('Loading stats from:', url);
                    
                const response = await fetch(url);
                const data = await response.json();
                
                console.log('Stats response:', data);
                
                if (data.success) {
                    // Update operational balance
                    const operationalBalance = document.getElementById('operationalBalance');
                    if (operationalBalance) {
                        operationalBalance.textContent = formatRupiah(data.operational_balance || 0);
                    }
                    
                    // Update capital received today
                    const todayCapitalReceived = document.getElementById('todayCapitalReceived');
                    if (todayCapitalReceived) {
                        todayCapitalReceived.textContent = formatRupiah(data.today.capital_received || 0);
                    }
                    
                    // Update income and expense
                    document.getElementById('todayIncome').textContent = formatRupiah(data.today.income);
                    document.getElementById('todayExpense').textContent = formatRupiah(data.today.expense);
                    document.getElementById('todayIncomeCount').textContent = `${data.today.income_count} txn`;
                    document.getElementById('todayExpenseCount').textContent = `${data.today.expense_count} txn`;
                    
                    document.getElementById('monthIncome').textContent = formatRupiah(data.month.income);
                    document.getElementById('monthExpense').textContent = formatRupiah(data.month.expense);
                    
                    // Calculate change percentages
                    const incomeChange = data.month.income_change || 0;
                    const expenseChange = data.month.expense_change || 0;
                    
                    document.getElementById('monthIncomeChange').textContent = 
                        `${incomeChange >= 0 ? '+' : ''}${incomeChange.toFixed(1)}% vs last month`;
                    document.getElementById('monthExpenseChange').textContent = 
                        `${expenseChange >= 0 ? '+' : ''}${expenseChange.toFixed(1)}% vs last month`;
                    
                    // Net Profit calculation
                    const netProfit = data.month.income - data.month.expense;
                    const netProfitEl = document.getElementById('netProfitMonth');
                    const profitIndicator = document.getElementById('profitIndicator');
                    if (netProfitEl) {
                        netProfitEl.textContent = (netProfit >= 0 ? '+' : '') + formatRupiah(netProfit);
                        netProfitEl.style.color = netProfit >= 0 ? '#10b981' : '#ef4444';
                    }
                    if (profitIndicator) {
                        profitIndicator.style.background = netProfit >= 0 ? '#10b981' : '#ef4444';
                        profitIndicator.innerHTML = netProfit >= 0 
                            ? '<i data-feather="trending-up" style="width: 18px; height: 18px; color: white;"></i>'
                            : '<i data-feather="trending-down" style="width: 18px; height: 18px; color: white;"></i>';
                        feather.replace();
                    }
                    
                    // Update Doughnut Pie Chart
                    if (weeklyChart) {
                        const income = parseFloat(data.month.income) || 0;
                        const expense = parseFloat(data.month.expense) || 0;
                        weeklyChart.data.datasets[0].data = [income, expense];
                        weeklyChart.update();
                        
                        // Update pie legend values
                        const pieIncomeEl = document.getElementById('pieIncomeValue');
                        const pieExpenseEl = document.getElementById('pieExpenseValue');
                        if (pieIncomeEl) pieIncomeEl.textContent = formatRupiah(income);
                        if (pieExpenseEl) pieExpenseEl.textContent = formatRupiah(expense);
                        
                        // Update ratio bar
                        const total = income + expense;
                        if (total > 0) {
                            const incomePercent = (income / total * 100).toFixed(0);
                            const expensePercent = (expense / total * 100).toFixed(0);
                            
                            const incomeBar = document.getElementById('incomeRatioBar');
                            const expenseBar = document.getElementById('expenseRatioBar');
                            const incomePercentEl = document.getElementById('incomePercent');
                            const expensePercentEl = document.getElementById('expensePercent');
                            
                            if (incomeBar) incomeBar.style.width = incomePercent + '%';
                            if (expenseBar) expenseBar.style.width = expensePercent + '%';
                            if (incomePercentEl) incomePercentEl.textContent = incomePercent + '%';
                            if (expensePercentEl) expensePercentEl.textContent = expensePercent + '%';
                        }
                    }
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        async function loadOccupancy() {
            try {
                const url = currentBranchId 
                    ? `../../api/owner-occupancy.php?branch_id=${currentBranchId}`
                    : '../../api/owner-occupancy.php';
                    
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    const percentage = data.occupancy_rate || 0;
                    const occupied = data.occupied_rooms || 0;
                    const available = data.available_rooms || 0;
                    const total = data.total_rooms || 0;
                    
                    // Update text values
                    document.getElementById('occupancyPercent').textContent = percentage.toFixed(1) + '%';
                    document.getElementById('occupiedRooms').textContent = occupied;
                    document.getElementById('availableRooms').textContent = available;
                    document.getElementById('totalRooms').textContent = total;
                    
                    // Create/update pie chart
                    const ctx = document.getElementById('occupancyPieChart').getContext('2d');
                    
                    if (occupancyPieChart) {
                        occupancyPieChart.destroy();
                    }
                    
                    occupancyPieChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Occupied', 'Available'],
                            datasets: [{
                                data: [occupied, available],
                                backgroundColor: ['#10b981', '#e5e7eb'],
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
                console.error('Error loading occupancy:', error);
            }
        }
        
        // Removed: changePeriod and loadChartData - pie chart now updated via loadStats()
        
        async function loadRecentTransactions() {
            try {
                const url = currentBranchId 
                    ? `../../api/owner-recent-transactions.php?branch_id=${currentBranchId}`
                    : '../../api/owner-recent-transactions.php';
                    
                const response = await fetch(url);
                const data = await response.json();
                
                const container = document.getElementById('recentTransactions');
                
                if (data.success && data.transactions.length > 0) {
                    container.innerHTML = '';
                    data.transactions.forEach(trans => {
                        const item = document.createElement('div');
                        item.className = 'transaction-item';
                        
                        const isIncome = trans.transaction_type === 'income';
                        const color = isIncome ? '#10b981' : '#ef4444';
                        const icon = isIncome ? 'arrow-up' : 'arrow-down';
                        
                        item.innerHTML = `
                            <div class="transaction-info">
                                <div class="transaction-desc">
                                    <i data-feather="${icon}" style="width: 14px; height: 14px; color: ${color};"></i>
                                    ${trans.description || trans.category_name}
                                </div>
                                <div class="transaction-meta">
                                    ${trans.division_name} • ${formatDate(trans.transaction_date)} ${trans.transaction_time}
                                </div>
                            </div>
                            <div class="transaction-amount" style="color: ${color};">
                                ${isIncome ? '+' : '-'} ${formatRupiah(trans.amount)}
                            </div>
                        `;
                        container.appendChild(item);
                    });
                    feather.replace();
                } else {
                    container.innerHTML = `
                        <div class="empty-state">
                            <i data-feather="inbox" class="empty-icon"></i>
                            <p>No transactions today</p>
                        </div>
                    `;
                    feather.replace();
                }
            } catch (error) {
                console.error('Error loading transactions:', error);
            }
        }
        
        async function loadTopIncome() {
            try {
                const url = currentBranchId 
                    ? `../../api/owner-top-income.php?branch_id=${currentBranchId}`
                    : '../../api/owner-top-income.php';
                    
                const response = await fetch(url);
                const data = await response.json();
                
                const container = document.getElementById('topIncomeList');
                
                if (data.success && data.top_income && data.top_income.length > 0) {
                    container.innerHTML = '';
                    data.top_income.slice(0, 3).forEach((item, index) => {
                        const rankColors = ['#fbbf24', '#94a3b8', '#cd7f32']; // Gold, Silver, Bronze
                        const div = document.createElement('div');
                        div.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 0.2rem 0.35rem; background: rgba(255,255,255,0.08); border-radius: 4px; border-left: 2px solid ' + rankColors[index] + ';';
                        
                        // Truncate description if too long
                        let desc = item.description || item.category_name || 'Income';
                        if (desc.length > 18) desc = desc.substring(0, 16) + '...';
                        
                        div.innerHTML = `
                            <span style="font-size: 0.55rem; color: rgba(255,255,255,0.85); font-weight: 500;">${desc}</span>
                            <span style="font-size: 0.55rem; color: #34d399; font-weight: 700;">${formatRupiahShort(item.amount)}</span>
                        `;
                        container.appendChild(div);
                    });
                } else {
                    container.innerHTML = '<div style="font-size: 0.5rem; color: rgba(255,255,255,0.5); text-align: center; padding: 0.3rem;">No income data</div>';
                }
            } catch (error) {
                console.error('Error loading top income:', error);
                document.getElementById('topIncomeList').innerHTML = '<div style="font-size: 0.5rem; color: rgba(255,255,255,0.5); text-align: center; padding: 0.3rem;">Error loading</div>';
            }
        }
        
        // Format rupiah short (e.g., 7.7Jt)
        function formatRupiahShort(value) {
            const num = parseFloat(value) || 0;
            if (num >= 1000000) return (num / 1000000).toFixed(1) + 'Jt';
            if (num >= 1000) return (num / 1000).toFixed(0) + 'K';
            return num.toString();
        }
        
        function initChart() {
            const canvas = document.getElementById('weeklyChart');
            if (!canvas) {
                console.error('Canvas element weeklyChart not found!');
                return;
            }
            
            console.log('Initializing doughnut chart on canvas:', canvas);
            const ctx = canvas.getContext('2d');
            
            weeklyChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Income', 'Expense'],
                    datasets: [{
                        data: [0, 0],
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.9)',
                            'rgba(239, 68, 68, 0.9)'
                        ],
                        borderColor: [
                            'rgba(16, 185, 129, 1)',
                            'rgba(239, 68, 68, 1)'
                        ],
                        borderWidth: 2,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleFont: { size: 11, weight: 'bold' },
                            bodyFont: { size: 10 },
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + formatRupiah(context.parsed);
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Division Pie Chart
        let divisionPieChart = null;
        function initDivisionPieChart() {
            const canvas = document.getElementById('divisionPieChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            divisionPieChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#ec4899'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${context.label}: Rp ${Number(value).toLocaleString('id-ID')} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Reservation Chart
        let reservationChart = null;
        function initReservationChart() {
            const canvas = document.getElementById('reservationChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            reservationChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Reservations',
                        data: [],
                        backgroundColor: 'rgba(99, 102, 241, 0.8)',
                        borderColor: '#6366f1',
                        borderWidth: 1,
                        borderRadius: 4
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
                            ticks: { stepSize: 1, font: { size: 10 } }
                        },
                        x: {
                            ticks: { font: { size: 9 } }
                        }
                    }
                }
            });
        }
        
        // Load Division Pie Chart Data
        async function loadDivisionPieChart() {
            try {
                if (!divisionPieChart) initDivisionPieChart();
                
                const url = currentBranchId 
                    ? `../../api/owner-division-income.php?branch_id=${currentBranchId}`
                    : '../../api/owner-division-income.php';
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success && divisionPieChart) {
                    const colors = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#ec4899'];
                    
                    divisionPieChart.data.labels = data.divisions.map(d => d.name);
                    divisionPieChart.data.datasets[0].data = data.divisions.map(d => d.amount);
                    divisionPieChart.update();
                    
                    // Update legend
                    const legendContainer = document.getElementById('divisionLegend');
                    if (legendContainer) {
                        legendContainer.innerHTML = data.divisions.map((d, i) => `
                            <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem;">
                                <div style="width: 12px; height: 12px; border-radius: 3px; background: ${colors[i % colors.length]};"></div>
                                <span style="color: #374151; font-weight: 500;">${d.name}</span>
                            </div>
                        `).join('');
                    }
                }
            } catch (error) {
                console.error('Error loading division pie chart:', error);
            }
        }
        
        // Load Guest Overview (Inhouse & Upcoming)
        async function loadGuestOverview() {
            try {
                const url = currentBranchId 
                    ? `../../api/owner-guest-overview.php?branch_id=${currentBranchId}`
                    : '../../api/owner-guest-overview.php';
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    // Inhouse stats
                    document.getElementById('inhouseCount').textContent = data.inhouse?.guests || 0;
                    document.getElementById('inhouseRooms').textContent = `${data.inhouse?.rooms || 0} rooms`;
                    
                    // Upcoming stats
                    document.getElementById('upcomingCheckins').textContent = data.upcoming?.count || 0;
                    document.getElementById('upcomingDetails').textContent = 'this week';
                    
                    // Inhouse Guest List
                    const inhouseContainer = document.getElementById('inhouseList');
                    const inhouseTotal = document.getElementById('inhouseTotal');
                    const inhouseListData = data.inhouse?.list || [];
                    
                    // Show total count if more than displayed
                    if (inhouseListData.length > 4) {
                        inhouseTotal.textContent = `(${inhouseListData.length} total)`;
                    } else {
                        inhouseTotal.textContent = '';
                    }
                    
                    if (inhouseListData.length > 0) {
                        inhouseContainer.innerHTML = inhouseListData.map(g => `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.4rem 0.5rem; background: white; border-radius: 6px; margin-bottom: 0.35rem; border: 1px solid #e5e7eb;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="background: #dbeafe; color: #1e40af; font-size: 0.65rem; font-weight: 700; padding: 0.2rem 0.4rem; border-radius: 4px; min-width: 36px; text-align: center;">${g.room_number || '-'}</span>
                                    <span style="font-size: 0.75rem; font-weight: 600; color: #1f2937;">${g.guest_name}</span>
                                </div>
                                <div style="text-align: right;">
                                    <span style="font-size: 0.65rem; color: #6b7280;">C/O ${g.check_out_formatted}</span>
                                    ${g.total_guests > 1 ? `<span style="font-size: 0.6rem; color: #9ca3af; margin-left: 0.25rem;">(${g.total_guests}👤)</span>` : ''}
                                </div>
                            </div>
                        `).join('');
                    } else {
                        inhouseContainer.innerHTML = '<div style="text-align: center; color: #9ca3af; padding: 0.5rem; font-size: 0.75rem;">Tidak ada tamu inhouse</div>';
                    }
                    
                    // Debug log
                    console.log('Inhouse data:', data.inhouse);
                    
                    // Upcoming List
                    const upcomingContainer = document.getElementById('upcomingList');
                    if (data.upcoming?.list && data.upcoming.list.length > 0) {
                        upcomingContainer.innerHTML = data.upcoming.list.map(g => `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.4rem 0.5rem; background: white; border-radius: 6px; margin-bottom: 0.35rem; border: 1px solid #fde68a;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="background: #fef3c7; color: #92400e; font-size: 0.65rem; font-weight: 700; padding: 0.2rem 0.4rem; border-radius: 4px; min-width: 36px; text-align: center;">${g.room_number || '-'}</span>
                                    <span style="font-size: 0.75rem; font-weight: 600; color: #1f2937;">${g.guest_name}</span>
                                </div>
                                <div style="text-align: right;">
                                    <span style="font-size: 0.65rem; font-weight: 500; color: #d97706;">${g.check_in_date}</span>
                                    <span style="font-size: 0.6rem; color: #9ca3af; margin-left: 0.25rem;">${g.nights || 1}N</span>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        upcomingContainer.innerHTML = '<div style="text-align: center; color: #9ca3af; padding: 0.5rem; font-size: 0.75rem;">Tidak ada upcoming check-in</div>';
                    }
                }
            } catch (error) {
                console.error('Error loading guest overview:', error);
            }
        }
        
        // Load Reservation Trend
        async function loadReservationTrend() {
            try {
                if (!reservationChart) initReservationChart();
                
                const url = currentBranchId 
                    ? `../../api/owner-reservation-trend.php?branch_id=${currentBranchId}`
                    : '../../api/owner-reservation-trend.php';
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success && reservationChart) {
                    reservationChart.data.labels = data.labels;
                    reservationChart.data.datasets[0].data = data.values;
                    reservationChart.update();
                }
            } catch (error) {
                console.error('Error loading reservation trend:', error);
            }
        }
        
        async function refreshData() {
            const btn = document.getElementById('refreshBtn');
            btn.innerHTML = '<div class="loading-spinner"></div>';
            btn.disabled = true;
            
            await loadBranchData();
            
            setTimeout(() => {
                btn.innerHTML = '<i data-feather="refresh-cw" style="width: 20px; height: 20px;"></i>';
                feather.replace();
                btn.disabled = false;
            }, 500);
        }
        
        function formatRupiah(number) {
            return 'Rp ' + Number(number).toLocaleString('id-ID');
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            const options = { day: 'numeric', month: 'short' };
            return date.toLocaleDateString('id-ID', options);
        }
        
        function showReports() {
            alert('Fitur laporan akan tersedia di update berikutnya');
        }
        
        function showOccupancy() {
            alert('Detailed occupancy feature will be available in the next update');
        }
        
        // ===== COMPARISON VIEW FUNCTIONS =====
        async function loadComparisonData() {
            try {
                const response = await fetch(`../../api/owner-comparison.php?period=${currentComparisonPeriod}`);
                const data = await response.json();
                
                console.log('Comparison data:', data);
                
                if (data.success && data.businesses) {
                    renderComparisonCharts(data);
                    renderBusinessCards(data.businesses);
                }
            } catch (error) {
                console.error('Error loading comparison data:', error);
            }
        }
        
        function renderComparisonCharts(data) {
            const businesses = data.businesses;
            const labels = businesses.map(b => b.name);
            const incomeData = businesses.map(b => b.income);
            
            console.log('=== Rendering Pie Chart ===');
            console.log('Businesses:', businesses);
            
            // Calculate totals
            const totalIncome = incomeData.reduce((a, b) => a + b, 0);
            const totalExpense = businesses.reduce((a, b) => a + b.expense, 0);
            const totalNet = businesses.reduce((a, b) => a + b.net, 0);
            
            // Update total revenue display
            document.getElementById('totalRevenue').textContent = formatRupiah(totalIncome);
            
            // Destroy existing charts
            if (comparisonPieChart) comparisonPieChart.destroy();
            if (healthGaugeChart) healthGaugeChart.destroy();
            
            // Modern colors for pie chart
            const colors = [
                'rgba(16, 185, 129, 0.9)',  // Green
                'rgba(59, 130, 246, 0.9)',  // Blue
                'rgba(139, 92, 246, 0.9)',  // Purple
                'rgba(245, 158, 11, 0.9)',  // Amber
                'rgba(236, 72, 153, 0.9)',  // Pink
            ];
            
            // Create Pie Chart
            const pieCtx = document.getElementById('comparisonPieChart').getContext('2d');
            comparisonPieChart = new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: incomeData,
                        backgroundColor: colors.slice(0, labels.length),
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '65%',
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 800
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleColor: '#f1f5f9',
                            bodyColor: '#e2e8f0',
                            padding: 12,
                            cornerRadius: 10,
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed;
                                    const percent = totalIncome > 0 ? ((value / totalIncome) * 100).toFixed(1) : 0;
                                    return ` ${formatRupiah(value)} (${percent}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Generate legend
            const legendHtml = businesses.map((b, i) => {
                const percent = totalIncome > 0 ? ((b.income / totalIncome) * 100).toFixed(1) : 0;
                return `
                    <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0; border-bottom: 1px solid rgba(0,0,0,0.05);">
                        <div style="width: 12px; height: 12px; border-radius: 3px; background: ${colors[i % colors.length]};"></div>
                        <div style="flex: 1;">
                            <div style="font-size: 0.75rem; font-weight: 600; color: #1f2937;">${b.name}</div>
                            <div style="font-size: 0.65rem; color: #6b7280;">${formatRupiah(b.income)}</div>
                        </div>
                        <div style="font-size: 0.8rem; font-weight: 700; color: ${colors[i % colors.length].replace('0.9', '1')};">${percent}%</div>
                    </div>
                `;
            }).join('');
            document.getElementById('pieChartLegend').innerHTML = legendHtml;
            
            // === AI HEALTH ANALYSIS ===
            analyzeBusinessHealth(businesses, totalIncome, totalExpense, totalNet);
        }
        
        function analyzeBusinessHealth(businesses, totalIncome, totalExpense, totalNet) {
            // Calculate key metrics
            const profitMargin = totalIncome > 0 ? ((totalNet / totalIncome) * 100) : 0;
            const expenseRatio = totalIncome > 0 ? ((totalExpense / totalIncome) * 100) : 0;
            
            // Find top performer and needs attention
            let topPerformer = businesses[0] || { name: '-', net: 0 };
            let worstPerformer = businesses[0] || { name: '-', net: 0 };
            
            businesses.forEach(b => {
                if (b.net > topPerformer.net) topPerformer = b;
                if (b.net < worstPerformer.net) worstPerformer = b;
            });
            
            // Calculate health score (0-100)
            let healthScore = 50; // Base score
            
            // Profit margin contribution (max 30 points)
            if (profitMargin >= 50) healthScore += 30;
            else if (profitMargin >= 30) healthScore += 25;
            else if (profitMargin >= 20) healthScore += 20;
            else if (profitMargin >= 10) healthScore += 10;
            else if (profitMargin > 0) healthScore += 5;
            else healthScore -= 15;
            
            // Expense ratio contribution (max 20 points)
            if (expenseRatio <= 30) healthScore += 20;
            else if (expenseRatio <= 50) healthScore += 15;
            else if (expenseRatio <= 70) healthScore += 5;
            else healthScore -= 10;
            
            // Positive net across all businesses (max 10 points)
            const positiveBusinesses = businesses.filter(b => b.net > 0).length;
            healthScore += (positiveBusinesses / businesses.length) * 10;
            
            healthScore = Math.min(100, Math.max(0, healthScore));
            
            // Determine status
            let statusText, statusColor, summary;
            if (healthScore >= 80) {
                statusText = '🌟 Excellent Performance';
                statusColor = '#10b981';
                summary = 'All business units show excellent financial performance. High profit margin and controlled expenses.';
            } else if (healthScore >= 60) {
                statusText = '✅ Good Standing';
                statusColor = '#3b82f6';
                summary = 'Business in healthy condition with positive profit. Room for further optimization.';
            } else if (healthScore >= 40) {
                statusText = '⚠️ Needs Improvement';
                statusColor = '#f59e0b';
                summary = 'Some areas require attention. Cost structure and pricing strategy review needed.';
            } else {
                statusText = '🚨 Critical Attention';
                statusColor = '#ef4444';
                summary = 'Financial condition requires immediate action. Expenses exceed optimal limits or margin very thin.';
            }
            
            // Update UI
            document.getElementById('healthScoreDisplay').innerHTML = `
                <div style="font-size: 1.25rem; font-weight: 800; color: ${statusColor};">${Math.round(healthScore)}</div>
                <div style="font-size: 0.5rem; color: #64748b;">SCORE</div>
            `;
            document.getElementById('healthStatusText').textContent = statusText;
            document.getElementById('healthStatusText').style.color = statusColor;
            document.getElementById('healthSummary').textContent = summary;
            
            // Metrics
            document.getElementById('allProfitMargin').textContent = profitMargin.toFixed(1) + '%';
            document.getElementById('allProfitMargin').style.color = profitMargin >= 20 ? '#34d399' : profitMargin >= 0 ? '#fbbf24' : '#f87171';
            document.getElementById('profitMarginStatus').textContent = profitMargin >= 30 ? 'Excellent' : profitMargin >= 15 ? 'Good' : profitMargin >= 0 ? 'Improve' : 'Critical';
            
            document.getElementById('allExpenseRatio').textContent = expenseRatio.toFixed(1) + '%';
            document.getElementById('allExpenseRatio').style.color = expenseRatio <= 50 ? '#34d399' : expenseRatio <= 70 ? '#fbbf24' : '#f87171';
            document.getElementById('expenseRatioStatus').textContent = expenseRatio <= 30 ? 'Very Efficient' : expenseRatio <= 50 ? 'Normal' : expenseRatio <= 70 ? 'High' : 'Very High';
            
            document.getElementById('topPerformer').textContent = topPerformer.name;
            document.getElementById('topPerformerValue').textContent = 'Net: ' + formatRupiah(topPerformer.net);
            
            if (worstPerformer.net < topPerformer.net) {
                document.getElementById('needsAttention').textContent = worstPerformer.name;
                document.getElementById('needsAttentionValue').textContent = 'Net: ' + formatRupiah(worstPerformer.net);
            } else {
                document.getElementById('needsAttention').textContent = 'All Good';
                document.getElementById('needsAttentionValue').textContent = 'No issues found';
                document.getElementById('needsAttention').style.color = '#34d399';
            }
            
            // Generate AI Recommendations
            let recommendations = [];
            
            if (profitMargin < 20) {
                recommendations.push('↗️ <strong>Increase Margin:</strong> Profit margin at ' + profitMargin.toFixed(1) + '% is below 20% target. Consider pricing review or supplier renegotiation.');
            }
            
            if (expenseRatio > 50) {
                recommendations.push('💰 <strong>Optimize Spending:</strong> Expense ratio at ' + expenseRatio.toFixed(1) + '% is high. Identify non-essential costs to reduce.');
            }
            
            if (worstPerformer.net < 0) {
                recommendations.push('⚠️ <strong>' + worstPerformer.name + ':</strong> Operating at loss. Needs operational audit and strategy evaluation.');
            }
            
            if (topPerformer.net > 0 && businesses.length > 1) {
                const topMargin = topPerformer.income > 0 ? ((topPerformer.net / topPerformer.income) * 100).toFixed(1) : 0;
                recommendations.push('🏆 <strong>Best Practice:</strong> ' + topPerformer.name + ' achieves ' + topMargin + '% margin. Apply similar strategy to other units.');
            }
            
            if (profitMargin >= 30 && expenseRatio <= 40) {
                recommendations.push('🚀 <strong>Expansion Ready:</strong> Financial health is excellent. Consider reinvesting profits for growth.');
            }
            
            if (recommendations.length === 0) {
                recommendations.push('✨ <strong>Maintain Performance:</strong> All metrics optimal. Focus on service quality and customer retention.');
            }
            
            document.getElementById('aiRecommendations').innerHTML = recommendations.join('<br><br>');
            
            // Create Health Gauge Chart
            const gaugeCtx = document.getElementById('healthGaugeChart').getContext('2d');
            healthGaugeChart = new Chart(gaugeCtx, {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [healthScore, 100 - healthScore],
                        backgroundColor: [statusColor, 'rgba(226, 232, 240, 0.5)'],
                        borderWidth: 0,
                        cutout: '75%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    rotation: -90,
                    circumference: 180,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    }
                }
            });
        }
        
        function renderBusinessCards(businesses) {
            const container = document.getElementById('businessCardsGrid');
            container.innerHTML = '';
            
            businesses.forEach(business => {
                const card = document.createElement('div');
                card.style.cssText = 'background: white; padding: 0.875rem; border-radius: 12px; border: 1px solid rgba(0,0,0,0.06); cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);';
                card.onmouseover = () => card.style.transform = 'translateY(-2px)';
                card.onmouseout = () => card.style.transform = 'translateY(0)';
                card.onclick = () => {
                    // Select this business
                    const btn = document.querySelector(`[data-branch-id="${business.id}"]`);
                    if (btn) btn.click();
                };
                
                const net = business.net;
                const netColor = net >= 0 ? '#10b981' : '#ef4444';
                const margin = business.income > 0 ? ((net / business.income) * 100).toFixed(1) : 0;
                
                card.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <div style="font-size: 0.85rem; font-weight: 700; color: #1f2937;">${business.name}</div>
                        <div style="font-size: 0.65rem; padding: 0.2rem 0.5rem; border-radius: 20px; background: ${net >= 0 ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'}; color: ${netColor}; font-weight: 600;">
                            ${margin}% margin
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.75rem; font-size: 0.7rem;">
                        <div style="flex: 1;">
                            <span style="color: #6b7280;">Income:</span>
                            <span style="color: #10b981; font-weight: 600; margin-left: 0.25rem;">${formatRupiah(business.income)}</span>
                        </div>
                        <div style="flex: 1;">
                            <span style="color: #6b7280;">Expense:</span>
                            <span style="color: #ef4444; font-weight: 600; margin-left: 0.25rem;">${formatRupiah(business.expense)}</span>
                        </div>
                    </div>
                    <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.7rem; color: #6b7280;">Net Profit:</span>
                        <span style="font-size: 0.9rem; font-weight: 700; color: ${netColor};">${net >= 0 ? '+' : ''}${formatRupiah(net)}</span>
                    </div>
                `;
                
                container.appendChild(card);
            });
        }
        
        function changeComparisonPeriod(period) {
            currentComparisonPeriod = period;
            
            // Update button states
            document.querySelectorAll('#comparisonView .period-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.getAttribute('data-period') === period) {
                    btn.classList.add('active');
                }
            });
            
            loadComparisonData();
        }
        
        // Auto refresh every 2 minutes
        setInterval(refreshData, 120000);
    </script>
    
    <!-- Main JavaScript for Sidebar Dropdown -->
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=<?php echo time(); ?>"></script>
    <script>
        // Replace feather icons and reinitialize dropdowns
        if (typeof feather !== 'undefined') {
            feather.replace();
            // Re-setup dropdowns after feather replaces icons
            setTimeout(function() {
                if (typeof setupDropdownToggles === 'function') {
                    setupDropdownToggles();
                }
            }, 100);
        }
    </script>
</body>
</html>
