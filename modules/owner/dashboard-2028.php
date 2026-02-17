<?php
// Include main config (handles session with NARAYANA_SESSION name)
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';

// Determine base path
$isLocal = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
$basePath = $isLocal ? '/adf_system' : '';

// Auth check - try session role, fallback to DB lookup
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
            margin-bottom: 24px;
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
        
        /* Hero Section with Chart */
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.3);
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 40px;
            align-items: center;
        }
        
        .hero-info {
            color: white;
        }
        
        .hero-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .hero-subtitle {
            font-size: 15px;
            opacity: 0.9;
            margin-bottom: 24px;
        }
        
        .hero-stats {
            display: flex;
            gap: 32px;
        }
        
        .hero-stat {
            display: flex;
            flex-direction: column;
        }
        
        .hero-stat-label {
            font-size: 12px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .hero-stat-value {
            font-size: 26px;
            font-weight: 700;
        }
        
        .hero-chart {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        
        #heroChart {
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.15));
        }
        
        .hero-legend {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .hero-legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
        }
        
        .hero-legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .hero-legend-text {
            display: flex;
            flex-direction: column;
        }
        
        .hero-legend-label {
            font-size: 11px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .hero-legend-value {
            font-size: 16px;
            font-weight: 600;
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
        
        /* Operational Cash Section - like System Dashboard */
        .operational-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bae6fd;
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 32px;
            box-shadow: 0 4px 16px rgba(56, 189, 248, 0.1);
        }
        
        .operational-title {
            font-size: 15px;
            font-weight: 600;
            color: #0369a1;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .operational-title::before {
            content: '💰';
            font-size: 20px;
        }
        
        .operational-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        
        .op-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .op-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gradient-start), var(--gradient-end));
        }
        
        .op-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        
        .op-card.modal-owner {
            --gradient-start: #10b981;
            --gradient-end: #34d399;
        }
        
        .op-card.petty-cash {
            --gradient-start: #f59e0b;
            --gradient-end: #fbbf24;
        }
        
        .op-card.digunakan {
            --gradient-start: #f43f5e;
            --gradient-end: #fb7185;
        }
        
       .op-card.total-kas {
            --gradient-start: #6366f1;
            --gradient-end: #818cf8;
        }
        
        .op-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gradient-start);
        }
        
        .op-value {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .op-desc {
            font-size: 11px;
            color: var(--text-muted);
        }
        
        .op-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 36px;
            opacity: 0.15;
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
        
        /* AI Health Section */
        .ai-health-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .ai-health-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .ai-health-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
        }
        
        .ai-icon {
            font-size: 24px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .ai-health-score {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .ai-score-value {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
        }
        
        .ai-score-label {
            font-size: 11px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ai-insights {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .ai-insight {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: all 0.2s ease;
        }
        
        .ai-insight:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(4px);
        }
        
        .ai-insight-icon {
            font-size: 20px;
            min-width: 20px;
        }
        
        .ai-insight-content {
            flex: 1;
        }
        
        .ai-insight-title {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .ai-insight-text {
            font-size: 12px;
            opacity: 0.9;
            line-height: 1.5;
        }
        
        .ai-insight-loading {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .shimmer-box {
            height: 60px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            animation: shimmerWhite 1.5s infinite;
        }
        
        @keyframes shimmerWhite {
            0% { opacity: 0.3; }
            50% { opacity: 0.5; }
            100% { opacity: 0.3; }
        }
        
        /* Transactions Section */
        .transactions-section {
            margin-bottom: 100px;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 16px;
            padding-left: 4px;
        }
        
        .transactions-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .transaction-item {
            background: var(--surface);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border);
            transition: all 0.2s ease;
        }
        
        .transaction-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .transaction-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .transaction-icon.income {
            background: linear-gradient(135deg, #10b981, #34d399);
        }
        
        .transaction-icon.expense {
            background: linear-gradient(135deg, #f43f5e, #fb7185);
        }
        
        .transaction-details {
            display: flex;
            flex-direction: column;
        }
        
        .transaction-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        
        .transaction-desc {
            font-size: 11px;
            color: var(--text-secondary);
        }
        
        .transaction-amount {
            text-align: right;
        }
        
        .transaction-value {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        
        .transaction-value.income {
            color: var(--success);
        }
        
        .transaction-value.expense {
            color: var(--danger);
        }
        
        .transaction-time {
            font-size: 10px;
            color: var(--text-secondary);
        }
        
        .transaction-loading {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .transaction-loading .shimmer-box {
            height: 68px;
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        /* Footer Navigation */
        .footer-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-around;
            padding: 8px 0 max(8px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
            z-index: 1000;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            flex: 1;
            padding: 8px;
            text-decoration: none;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            border-radius: 8px;
        }
        
        .nav-item:hover {
            background: rgba(102, 126, 234, 0.05);
            color: var(--accent);
        }
        
        .nav-item.active {
            color: var(--accent);
        }
        
        .nav-icon {
            font-size: 22px;
        }
        
        .nav-label {
            font-size: 11px;
            font-weight: 500;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .hero-section {
                padding: 24px 20px;
                border-radius: 20px;
                margin-bottom: 24px;
            }
            
            .hero-content {
                display: flex;
                flex-direction: column;
                gap: 24px;
                text-align: center;
            }
            
            .hero-chart {
                order: 0;
                justify-content: center;
                flex-direction: column;
                gap: 20px;
            }
            
            .hero-info {
                order: 1;
            }
            
            .hero-title {
                font-size: 26px;
            }
            
            .hero-subtitle {
                font-size: 13px;
            }
            
            .hero-stats {
                justify-content: center;
            }
            
            .hero-stat-value {
                font-size: 22px;
            }
            
            .hero-legend {
                flex-direction: row;
                gap: 20px;
                justify-content: center;
            }
            
            #heroChart {
                width: 180px;
                height: 180px;
            }
            
            .operational-section {
                padding: 20px;
                margin-bottom: 24px;
            }
            
            .operational-title {
                text-align: center;
                font-size: 15px;
            }
            
            .operational-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 14px;
            }
            
            .op-card {
                padding: 18px 14px;
                text-align: center;
            }
            
            .op-label {
                font-size: 10px;
            }
            
            .op-value {
                font-size: 22px;
            }
            
            .op-desc {
                font-size: 10px;
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
            
            .hero-section {
                padding: 20px 16px;
                border-radius: 20px;
                margin-bottom: 20px;
            }
            
            .hero-content {
                display: flex;
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .hero-info {
                order: 1;
            }
            
            .hero-title {
                font-size: 24px;
                margin-bottom: 6px;
            }
            
            .hero-subtitle {
                font-size: 12px;
                margin-bottom: 20px;
            }
            
            .hero-stats {
                gap: 24px;
                justify-content: center;
            }
            
            .hero-stat-label {
                font-size: 10px;
            }
            
            .hero-stat-value {
                font-size: 20px;
            }
            
            .hero-chart {
                order: 0;
                flex-direction: column;
                gap: 20px;
                align-items: center;
                justify-content: center;
            }
            
            #heroChart {
                width: 200px;
                height: 200px;
            }
            
            .hero-legend {
                flex-direction: row;
                gap: 16px;
                justify-content: center;
            }
            
            .hero-legend-item {
                flex-direction: column;
                align-items: center;
                gap: 6px;
            }
            
            .hero-legend-label {
                font-size: 10px;
            }
            
            .hero-legend-value {
                font-size: 16px;
                font-weight: 600;
            }
            
            .operational-section {
                padding: 16px;
                margin-bottom: 20px;
                border-radius: 20px;
            }
            
            .operational-title {
                font-size: 14px;
                margin-bottom: 16px;
                text-align: center;
            }
            
            .operational-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .op-card {
                padding: 16px 12px;
                text-align: center;
            }
            
            .op-label {
                font-size: 9px;
                margin-bottom: 8px;
            }
            
            .op-value {
                font-size: 20px;
                margin-bottom: 4px;
            }
            
            .op-desc {
                font-size: 9px;
            }
            
            .op-icon {
                font-size: 32px;
                margin-bottom: 8px;
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
        </div>
        
        <!-- Business Selector -->
        <div class="business-selector">
            <select class="business-select" id="businessSelect">
                <option value="">Loading businesses...</option>
                <option value="adf_narayana_hotel" data-biz-id="1">Narayana Hotel</option>
                <option value="adf_benscafe" data-biz-id="2">Ben's Cafe</option>
            </select>
        </div>
        
        <!-- Hero Section with Chart -->
        <div class="hero-section">
            <div class="hero-content">
                <div class="hero-info">
                    <div class="hero-title">Financial Performance</div>
                    <div class="hero-subtitle" id="currentDate">Loading...</div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <div class="hero-stat-label">Month Income</div>
                            <div class="hero-stat-value" id="heroIncome">-</div>
                        </div>
                        <div class="hero-stat">
                            <div class="hero-stat-label">Month Expense</div>
                            <div class="hero-stat-value" id="heroExpense">-</div>
                        </div>
                    </div>
                </div>
                <div class="hero-chart">
                    <canvas id="heroChart"></canvas>
                    <div class="hero-legend">
                        <div class="hero-legend-item">
                            <div class="hero-legend-dot" style="background: linear-gradient(135deg, #10b981, #34d399);"></div>
                            <div class="hero-legend-text">
                                <div class="hero-legend-label">Income</div>
                                <div class="hero-legend-value" id="heroLegendIncome">-</div>
                            </div>
                        </div>
                        <div class="hero-legend-item">
                            <div class="hero-legend-dot" style="background: linear-gradient(135deg, #f43f5e, #fb7185);"></div>
                            <div class="hero-legend-text">
                                <div class="hero-legend-label">Expense</div>
                                <div class="hero-legend-value" id="heroLegendExpense">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Operational Cash Section (like System Dashboard) -->
        <div class="operational-section">
            <div class="operational-title">Daily Operational - February 2026</div>
            <div class="operational-grid">
                <div class="op-card modal-owner">
                    <div class="op-label">💵 Modal Owner</div>
                    <div class="op-value" id="modalOwner">-</div>
                    <div class="op-desc">Setoran owner</div>
                    <div class="op-icon">💰</div>
                </div>
                <div class="op-card petty-cash">
                    <div class="op-label">💰 Petty Cash</div>
                    <div class="op-value" id="pettyCashOp">-</div>
                    <div class="op-desc">Uang cash dari tamu</div>
                    <div class="op-icon">💵</div>
                </div>
                <div class="op-card digunakan">
                    <div class="op-label">💸 Digunakan</div>
                    <div class="op-value" id="digunakan">-</div>
                    <div class="op-desc">Total pengeluaran operasional</div>
                    <div class="op-icon">📊</div>
                </div>
                <div class="op-card total-kas">
                    <div class="op-label">💎 Total Kas</div>
                    <div class="op-value" id="totalKas">-</div>
                    <div class="op-desc">Uang cash tersedia</div>
                    <div class="op-icon">💼</div>
                </div>
            </div>
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
        </div>
        
        <!-- AI Health Section -->
        <div class="ai-health-section">
            <div class="ai-health-header">
                <div class="ai-health-title">
                    <span class="ai-icon">🤖</span>
                    <span>AI Business Health</span>
                </div>
                <div class="ai-health-score" id="aiHealthScore">
                    <div class="ai-score-value">-</div>
                    <div class="ai-score-label">Health Score</div>
                </div>
            </div>
            <div class="ai-insights" id="aiInsights">
                <div class="ai-insight-loading">
                    <div class="shimmer-box"></div>
                    <div class="shimmer-box"></div>
                    <div class="shimmer-box"></div>
                </div>
            </div>
        </div>
        
        <!-- Recent Transactions Section -->
        <div class="transactions-section">
            <div class="section-title">Recent Transactions</div>
            <div class="transactions-list" id="transactionsList">
                <div class="transaction-loading">
                    <div class="shimmer-box"></div>
                    <div class="shimmer-box"></div>
                    <div class="shimmer-box"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer Navigation -->
    <div class="footer-nav">
        <a href="#" class="nav-item" id="navDashboard">
            <div class="nav-icon">📊</div>
            <div class="nav-label">Dashboard</div>
        </a>
        <a href="<?php echo $basePath; ?>/modules/investor/index.php" class="nav-item">
            <div class="nav-icon">💼</div>
            <div class="nav-label">Investor</div>
        </a>
        <a href="<?php echo $basePath; ?>/modules/projects/index.php" class="nav-item">
            <div class="nav-icon">📋</div>
            <div class="nav-label">Projek</div>
        </a>
        <a href="<?php echo $basePath; ?>/modules/frontdesk/index.php" class="nav-item">
            <div class="nav-icon">🏨</div>
            <div class="nav-label">Frontdesk</div>
        </a>
    </div>

    
    <script>
        // Base path: /adf_system for local, empty for hosting
        const isLocal = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
        const basePath = isLocal ? '/adf_system' : '';
        
        console.log('Dashboard loaded. BasePath:', basePath);
        console.log('Current URL:', window.location.href);
        
        // Format currency - full numbers with thousand separators
        function formatRp(num) {
            if (!num && num !== 0) return 'Rp 0';
            return 'Rp ' + Math.round(num).toLocaleString('id-ID');
        }
        
        // Draw hero chart
        function drawHeroChart(income, expense) {
            const canvas = document.getElementById('heroChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            const total = income + expense;
            
            // Responsive canvas size
            const isMobile = window.innerWidth <= 768;
            const size = isMobile ? 200 : 180;
            canvas.width = size;
            canvas.height = size;
            
            const centerX = canvas.width / 2;
            const centerY = canvas.height / 2;
            const radius = (size / 2) - 12;
            
            // Clear
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            if (total === 0) {
                // Empty state
                ctx.beginPath();
                ctx.arc(centerX, centerY, radius, 0, 2 * Math.PI);
                ctx.fillStyle = 'rgba(255,255,255,0.2)';
                ctx.fill();
                return;
            }
            
            const incomeAngle = (income / total) * 2 * Math.PI;
            const startAngle = -Math.PI / 2;
            
            // Income slice (emerald)
            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, startAngle, startAngle + incomeAngle);
            ctx.closePath();
            const incomeGrad = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            incomeGrad.addColorStop(0, '#10b981');
            incomeGrad.addColorStop(1, '#34d399');
            ctx.fillStyle = incomeGrad;
            ctx.fill();
            
            // Expense slice (rose)
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
            ctx.arc(centerX, centerY, radius * 0.6, 0, 2 * Math.PI);
            ctx.fillStyle = 'rgba(118, 75, 162, 0.8)'; // Purple matching hero background
            ctx.fill();
            
            // Center text - percentage
            const incomePercent = Math.round((income / total) * 100);
            ctx.fillStyle = 'white';
            ctx.font = 'bold ' + (size / 6) + 'px Inter';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(incomePercent + '%', centerX, centerY);
        }
        
        // Load data
        async function loadStats() {
            // Set date
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', options);
            
            try {
                // Get selected business database and ID
                const select = document.getElementById('businessSelect');
                const selectedDb = select.value;
                const selectedOption = select.selectedOptions[0];
                const bizId = selectedOption ? selectedOption.getAttribute('data-biz-id') : '';
                
                console.log('=== LOAD STATS START ===');
                console.log('Selected DB:', selectedDb);
                console.log('Selected Biz ID:', bizId);
                console.log('Select element has', select.options.length, 'options');
                
                let params = [];
                if (selectedDb) params.push('db=' + encodeURIComponent(selectedDb));
                if (bizId) params.push('biz_id=' + encodeURIComponent(bizId));
                const queryString = params.length ? '?' + params.join('&') : '';
                
                const response = await fetch(basePath + '/api/owner-stats-simple.php' + queryString);
                const data = await response.json();
                
                // Debug: Log API response
                console.log('=== STATS API DEBUG ===');
                console.log('API URL:', basePath + '/api/owner-stats-simple.php' + queryString);
                console.log('Response status:', response.status);
                console.log('API Response:', data);
                console.log('cashAccounts array:', data.cashAccounts);
                console.log('Is cashAccounts an array?', Array.isArray(data.cashAccounts));
                console.log('======================');
                
                if (data.success) {
                    // Hero section
                    document.getElementById('heroIncome').textContent = formatRp(data.monthIncome);
                    document.getElementById('heroExpense').textContent = formatRp(data.monthExpense);
                    document.getElementById('heroLegendIncome').textContent = formatRp(data.monthIncome);
                    document.getElementById('heroLegendExpense').textContent = formatRp(data.monthExpense);
                    drawHeroChart(data.monthIncome, data.monthExpense);
                    
                    // Operational Cash Section (like system dashboard)
                    // Modal Owner = received/setoran dari owner_capital accounts
                    let modalOwnerReceived = 0;
                    if (data.cashAccounts && Array.isArray(data.cashAccounts)) {
                        console.log('Finding owner_capital account...');
                        const ownerCapitalAcc = data.cashAccounts.find(acc => acc.account_type === 'owner_capital');
                        console.log('Owner Capital Account:', ownerCapitalAcc);
                        modalOwnerReceived = ownerCapitalAcc?.received || 0;
                        console.log('Modal Owner Received:', modalOwnerReceived);
                    } else {
                        console.warn('cashAccounts is not an array or is undefined!');
                    }
                    document.getElementById('modalOwner').textContent = formatRp(modalOwnerReceived);
                    
                    // Petty Cash Balance (from cash accounts)
                    console.log('Petty Cash from API:', data.pettyCash);
                    document.getElementById('pettyCashOp').textContent = formatRp(data.pettyCash || 0);
                    
                    // Digunakan = Total pengeluaran operasional (Petty Cash used + Modal Owner used)
                    let pettyCashUsed = 0;
                    let modalOwnerUsed = 0;
                    if (data.cashAccounts && Array.isArray(data.cashAccounts)) {
                        const cashAcc = data.cashAccounts.find(acc => acc.account_type === 'cash');
                        const ownerCapitalAcc = data.cashAccounts.find(acc => acc.account_type === 'owner_capital');
                        pettyCashUsed = cashAcc?.used || 0;
                        modalOwnerUsed = ownerCapitalAcc?.used || 0;
                        console.log('Cash Account:', cashAcc);
                        console.log('Petty Cash Used:', pettyCashUsed);
                        console.log('Modal Owner Used:', modalOwnerUsed);
                    }
                    const digunakan = pettyCashUsed + modalOwnerUsed;
                    console.log('Total Digunakan:', digunakan);
                    document.getElementById('digunakan').textContent = formatRp(digunakan);
                    
                    // Total Kas = Petty Cash balance + Owner Capital balance
                    console.log('Owner Capital from API:', data.ownerCapital);
                    const totalKas = (data.pettyCash || 0) + (data.ownerCapital || 0);
                    console.log('Total Kas:', totalKas, '=', data.pettyCash, '+', data.ownerCapital);
                    document.getElementById('totalKas').textContent = formatRp(totalKas);
                    
                    // Today stats
                    document.getElementById('todayIncome').textContent = formatRp(data.todayIncome);
                    document.getElementById('todayExpense').textContent = formatRp(data.todayExpense);
                    document.getElementById('monthIncome').textContent = formatRp(data.monthIncome);
                    document.getElementById('monthExpense').textContent = formatRp(data.monthExpense);
                    
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
                    
                    // Generate AI Health insights
                    generateAIHealth(data);
                } else {
                    console.error('API Error:', data.message);
                    alert('Error loading data: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Fetch Error:', error);
                alert('Failed to load dashboard data. Please check console for details.');
            }
        }
        
        // Load Recent Transactions
        async function loadTransactions() {
            try {
                const select = document.getElementById('businessSelect');
                const selectedDb = select.value;
                const selectedOption = select.selectedOptions[0];
                const bizId = selectedOption ? selectedOption.getAttribute('data-biz-id') : '';
                
                let params = [];
                if (selectedDb) params.push('db=' + encodeURIComponent(selectedDb));
                if (bizId) params.push('biz_id=' + encodeURIComponent(bizId));
                params.push('limit=10'); // Get last 10 transactions
                const queryString = params.length ? '?' + params.join('&') : '?limit=10';
                
                const response = await fetch(basePath + '/api/recent-transactions.php' + queryString);
                const data = await response.json();
                
                if (data.success && data.transactions) {
                    const container = document.getElementById('transactionsList');
                    if (data.transactions.length === 0) {
                        container.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-secondary);">No transactions found</div>';
                        return;
                    }
                    
                    container.innerHTML = data.transactions.map(t => {
                        const isIncome = t.transaction_type === 'income';
                        const icon = isIncome ? '💰' : '💸';
                        const typeClass = isIncome ? 'income' : 'expense';
                        const sign = isIncome ? '+' : '-';
                        
                        return `
                            <div class="transaction-item">
                                <div class="transaction-info">
                                    <div class="transaction-icon ${typeClass}">${icon}</div>
                                    <div class="transaction-details">
                                        <div class="transaction-title">${t.description || t.category || 'Transaction'}</div>
                                        <div class="transaction-desc">${t.account_name || 'Cash Account'}</div>
                                    </div>
                                </div>
                                <div class="transaction-amount">
                                    <div class="transaction-value ${typeClass}">${sign}${formatRp(t.amount)}</div>
                                    <div class="transaction-time">${formatDateTime(t.transaction_date)}</div>
                                </div>
                            </div>
                        `;
                    }).join('');
                } else {
                    document.getElementById('transactionsList').innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-secondary);">Unable to load transactions</div>';
                    console.error('Transactions API Error:', data.message);
                }
            } catch (error) {
                console.error('Load transactions error:', error);
                document.getElementById('transactionsList').innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-secondary);">Error loading transactions. Check console for details.</div>';
            }
        }
        
        // Format date/time
        function formatDateTime(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);
            
            if (diffMins < 60) {
                return diffMins <= 1 ? 'Just now' : diffMins + ' mins ago';
            } else if (diffHours < 24) {
                return diffHours + (diffHours === 1 ? ' hour ago' : ' hours ago');
            } else if (diffDays < 7) {
                return diffDays + (diffDays === 1 ? ' day ago' : ' days ago');
            } else {
                return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
            }
        }
        
        // Generate AI Health Insights
        function generateAIHealth(data) {
            const income = data.monthIncome || 0;
            const expense = data.monthExpense || 0;
            const profit = income - expense;
            const profitMargin = income > 0 ? ((profit / income) * 100) : 0;
            
            // Calculate health score (0-100)
            let score = 50; // Base score
            
            // Profit margin factor (max +30)
            if (profitMargin > 40) score += 30;
            else if (profitMargin > 30) score += 25;
            else if (profitMargin > 20) score += 20;
            else if (profitMargin > 10) score += 15;
            else if (profitMargin > 0) score += 10;
            else score -= 20;
            
            // Income trend factor (max +20)
            const lastMonthIncome = data.lastMonth?.income || 0;
            if (lastMonthIncome > 0) {
                const growthRate = ((income - lastMonthIncome) / lastMonthIncome) * 100;
                if (growthRate > 20) score += 20;
                else if (growthRate > 10) score += 15;
                else if (growthRate > 5) score += 10;
                else if (growthRate > 0) score += 5;
                else score -= 10;
            }
            
            // Ensure score is between 0-100
            score = Math.max(0, Math.min(100, Math.round(score)));
            
            // Display score
            const scoreEl = document.getElementById('aiHealthScore');
            scoreEl.innerHTML = `
                <div class="ai-score-value">${score}</div>
                <div class="ai-score-label">Health Score</div>
            `;
            
            // Generate insights
            const insights = [];
            
            // Profit insight
            if (profit > 0) {
                insights.push({
                    icon: '✅',
                    title: 'Profitable Business',
                    text: `Your business generated ${formatRp(profit)} profit this month with a ${profitMargin.toFixed(1)}% profit margin.`
                });
            } else {
                insights.push({
                    icon: '⚠️',
                    title: 'Loss Alert',
                    text: `Your business has a loss of ${formatRp(Math.abs(profit))} this month. Review expenses to improve profitability.`
                });
            }
            
            // Cash flow insight
            const cashOnHand = (data.pettyCash || 0) + (data.bankBalance || 0);
            const avgDailyExpense = expense / 30;
            const daysOfCash = avgDailyExpense > 0 ? (cashOnHand / avgDailyExpense) : 999;
            
            if (daysOfCash < 7) {
                insights.push({
                    icon: '🔴',
                    title: 'Critical Cash Flow',
                    text: `You have only ${Math.round(daysOfCash)} days of cash reserves. Consider increasing capital or reducing expenses.`
                });
            } else if (daysOfCash < 30) {
                insights.push({
                    icon: '⚡',
                    title: 'Cash Flow Warning',
                    text: `Cash reserves cover ${Math.round(daysOfCash)} days. Maintain healthy cash flow for operations.`
                });
            } else {
                insights.push({
                    icon: '💎',
                    title: 'Strong Cash Position',
                    text: `Excellent! You have ${formatRp(cashOnHand)} in cash reserves, covering ${Math.round(Math.min(daysOfCash, 999))} days of operations.`
                });
            }
            
            // Growth insight
            if (lastMonthIncome > 0) {
                const growthRate = ((income - lastMonthIncome) / lastMonthIncome) * 100;
                if (growthRate > 10) {
                    insights.push({
                        icon: '📈',
                        title: 'Strong Growth',
                        text: `Revenue increased by ${growthRate.toFixed(1)}% compared to last month. Keep up the momentum!`
                    });
                } else if (growthRate < -10) {
                    insights.push({
                        icon: '📉',
                        title: 'Revenue Decline',
                        text: `Revenue decreased by ${Math.abs(growthRate).toFixed(1)}% from last month. Analyze market conditions.`
                    });
                }
            }
            
            // Expense efficiency
            const expenseRatio = income > 0 ? (expense / income) * 100 : 100;
            if (expenseRatio < 60) {
                insights.push({
                    icon: '🎯',
                    title: 'Efficient Operations',
                    text: `Your operational efficiency is excellent at ${(100 - expenseRatio).toFixed(0)}%. Expenses are well controlled.`
                });
            } else if (expenseRatio > 90) {
                insights.push({
                    icon: '⚠️',
                    title: 'High Expense Ratio',
                    text: `Expenses are ${expenseRatio.toFixed(0)}% of revenue. Look for cost optimization opportunities.`
                });
            }
            
            // Display insights
            const insightsContainer = document.getElementById('aiInsights');
            insightsContainer.innerHTML = insights.map(insight => `
                <div class="ai-insight">
                    <div class="ai-insight-icon">${insight.icon}</div>
                    <div class="ai-insight-content">
                        <div class="ai-insight-title">${insight.title}</div>
                        <div class="ai-insight-text">${insight.text}</div>
                    </div>
                </div>
            `).join('');
        }
        
        // Update dashboard navigation link
        function updateDashboardNav() {
            const select = document.getElementById('businessSelect');
            const selectedOption = select.selectedOptions[0];
            const bizId = selectedOption ? selectedOption.getAttribute('data-biz-id') : '';
            const bizName = selectedOption ? selectedOption.textContent.toLowerCase() : '';
            
            const navDashboard = document.getElementById('navDashboard');
            
            // Determine which dashboard to link to based on business
            if (bizId && bizName) {
                // Link to business-specific dashboard
                navDashboard.href = basePath + '/modules/dashboard/index.php?biz_id=' + bizId;
            } else {
                // Link to main dashboard
                navDashboard.href = basePath + '/modules/dashboard/index.php';
            }
        }
                } else {
                    console.error('API Error:', data.message);
                }
            } catch (error) {
                console.error('Fetch Error:', error);
            }
        }
        
        // Store business data for DB lookup
        let businessList = [];
        
        // Load businesses
        async function loadBusinesses() {
            console.log('=== LOADING BUSINESSES ===');
            console.log('BasePath:', basePath);
            console.log('API URL:', basePath + '/api/owner-branches-simple.php');
            
            try {
                const response = await fetch(basePath + '/api/owner-branches-simple.php');
                console.log('Businesses API response status:', response.status);
                
                if (!response.ok) {
                    console.error('HTTP error! status:', response.status);
                    const text = await response.text();
                    console.error('Response text:', text);
                    throw new Error('HTTP error! status: ' + response.status);
                }
                
                const data = await response.json();
                console.log('Businesses API data:', data);
                console.log('Success?', data.success);
                console.log('Branches:', data.branches);
                console.log('Debug info:', data.debug);
                
                if (data.success && data.branches && data.branches.length > 0) {
                    businessList = data.branches;
                    const select = document.getElementById('businessSelect');
                    select.innerHTML = '<option value="">All Businesses</option>';
                    
                    data.branches.forEach(biz => {
                        const option = document.createElement('option');
                        option.value = biz.database_name || biz.id;
                        option.setAttribute('data-biz-id', biz.id);
                        option.textContent = biz.branch_name || biz.business_name;
                        select.appendChild(option);
                        console.log('Added option:', biz.branch_name, 'DB:', biz.database_name, 'ID:', biz.id);
                    });
                    
                    console.log('✅ Businesses loaded from API:', data.branches.length);
                } else {
                    console.warn('⚠️ API failed or empty, keeping hardcoded businesses');
                    // Use hardcoded businesses from HTML
                    businessList = [
                        { id: 1, database_name: 'adf_narayana_hotel', branch_name: 'Narayana Hotel' },
                        { id: 2, database_name: 'adf_benscafe', branch_name: "Ben's Cafe" }
                    ];
                }
            } catch (error) {
                console.error('❌ Load businesses error:', error);
                console.error('Error message:', error.message);
                console.error('Error stack:', error.stack);
                // Fallback: use hardcoded businesses from HTML
                businessList = [
                    { id: 1, database_name: 'adf_narayana_hotel', branch_name: 'Narayana Hotel' },
                    { id: 2, database_name: 'adf_benscafe', branch_name: "Ben's Cafe" }
                ];
                console.log('✅ Using fallback hardcoded businesses');
            }
            
            console.log('Final businessList:', businessList);
            console.log('=== BUSINESSES LOADED ===');
        }
        
        // Business change handler
        document.getElementById('businessSelect').addEventListener('change', function() {
            console.log('=== BUSINESS CHANGED ===');
            console.log('New selection:', this.value);
            console.log('Selected option:', this.selectedOptions[0]);
            loadStats();
            loadTransactions();
            updateDashboardNav();
        });
        
        // Init - load businesses first, then stats and transactions
        console.log('=== DASHBOARD INITIALIZATION START ===');
        console.log('Page URL:', window.location.href);
        console.log('BasePath:', basePath);
        
        // Always load stats and transactions after a short delay, even if loadBusinesses fails
        loadBusinesses()
            .then(() => {
                console.log('✅ Businesses loaded successfully');
            })
            .catch(err => {
                console.error('⚠️ LoadBusinesses error (non-fatal):', err);
            })
            .finally(() => {
                console.log('🚀 Loading initial stats and transactions...');
                // Small delay to ensure DOM is ready
                setTimeout(() => {
                    loadStats();
                    loadTransactions();
                    updateDashboardNav();
                    console.log('✅ Initial load complete');
                }, 500);
            });
        
        // Refresh every 30 seconds
        setInterval(() => {
            console.log('♻️ Auto-refresh stats and transactions');
            loadStats();
            loadTransactions();
        }, 30000);
        
        // Log ready state
        console.log('✅ Dashboard script initialized');
        console.log('=== INITIALIZATION COMPLETE ===');
    </script>
</body>
</html>
