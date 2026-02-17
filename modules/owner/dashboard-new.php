<?php
session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: /index.php');
    exit;
}
$userName = $_SESSION['username'] ?? 'Owner';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard Owner - Monitoring Bisnis</title>
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
        <!-- Phase 1: Basic Structure Done ✓ -->
        
        <!-- Phase 2: Business Selector (Next) -->
        <div class="placeholder">
            <h3>🏢 Fase 2: Business Selector</h3>
            <p>Selector bisnis compact akan ditambahkan di sini</p>
        </div>
        
        <!-- Phase 3: Stats Cards (Next) -->
        <div class="placeholder">
            <h3>📈 Fase 3: Stats Cards</h3>
            <p>Kartu statistik mobile-friendly akan ditambahkan di sini</p>
        </div>
        
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
</body>
</html>
