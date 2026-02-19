<?php
/**
 * INVESTOR & PROJECT MONITOR
 * Mobile-optimized project monitoring for owner
 * Clean, Compact, Modern - Light Theme
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';

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

// Database config - connect to BUSINESS database for investors/projects
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$businessDbName = $isProduction ? 'adfb2574_narayana_hotel' : 'adf_narayana_hotel';

// Initialize variables
$investors = [];
$projects = [];
$totalCapital = 0;
$totalBudget = 0;
$totalExpenses = 0;
$projectExpenses = [];
$selectedProject = null;
$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$error = null;

try {
    // Connect to business database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if investors table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'investors'");
    $hasInvestorTables = $tableCheck->rowCount() > 0;
    
    if ($hasInvestorTables) {
        // Get all investors
        $stmt = $pdo->query("SELECT * FROM investors ORDER BY total_capital DESC");
        $investors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($investors as $inv) {
            $totalCapital += $inv['total_capital'] ?? 0;
        }
        
        // Get all projects
        $stmt = $pdo->query("SELECT * FROM projects ORDER BY budget DESC");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate real expenses for each project (expenses + salaries + division costs)
        foreach ($projects as &$proj) {
            $pid = $proj['id'];
            $totalBudget += $proj['budget'] ?? 0;
            
            // Get project_expenses
            $proj['total_expenses'] = 0;
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_expenses WHERE project_id = ?");
                $stmt->execute([$pid]);
                $proj['total_expenses'] = floatval($stmt->fetchColumn());
            } catch (Exception $e) {}
            
            // Get project_salaries
            $proj['total_gaji'] = 0;
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_salary),0) as total FROM project_salaries WHERE project_id = ?");
                $stmt->execute([$pid]);
                $proj['total_gaji'] = floatval($stmt->fetchColumn());
            } catch (Exception $e) {}
            
            // Get project_division_expenses
            $proj['total_divisi'] = 0;
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_division_expenses WHERE project_id = ?");
                $stmt->execute([$pid]);
                $proj['total_divisi'] = floatval($stmt->fetchColumn());
            } catch (Exception $e) {}
            
            // Calculate grand total
            $proj['grand_expenses'] = $proj['total_expenses'] + $proj['total_gaji'] + $proj['total_divisi'];
            $totalExpenses += $proj['grand_expenses'];
        }
        unset($proj);
        
        // If a project is selected, get its details and expenses
        if ($selectedProjectId) {
            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$selectedProjectId]);
            $selectedProject = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($selectedProject) {
                // Calculate grand expenses for selected project
                $selectedProject['total_expenses'] = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_expenses WHERE project_id = ?");
                    $stmt->execute([$selectedProjectId]);
                    $selectedProject['total_expenses'] = floatval($stmt->fetchColumn());
                } catch (Exception $e) {}
                
                $selectedProject['total_gaji'] = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_salary),0) as total FROM project_salaries WHERE project_id = ?");
                    $stmt->execute([$selectedProjectId]);
                    $selectedProject['total_gaji'] = floatval($stmt->fetchColumn());
                } catch (Exception $e) {}
                
                $selectedProject['total_divisi'] = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_division_expenses WHERE project_id = ?");
                    $stmt->execute([$selectedProjectId]);
                    $selectedProject['total_divisi'] = floatval($stmt->fetchColumn());
                } catch (Exception $e) {}
                
                $selectedProject['grand_expenses'] = $selectedProject['total_expenses'] + $selectedProject['total_gaji'] + $selectedProject['total_divisi'];
                
                // Get division breakdown for pie chart
                $divisionBreakdown = [];
                
                // From project_expenses (if has division_name column)
                try {
                    $stmt = $pdo->query("DESCRIBE project_expenses");
                    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (in_array('division_name', $columns)) {
                        $stmt = $pdo->prepare("
                            SELECT division_name, SUM(amount) as total 
                            FROM project_expenses 
                            WHERE project_id = ? 
                              AND division_name IS NOT NULL 
                              AND division_name != '' 
                            GROUP BY division_name
                        ");
                        $stmt->execute([$selectedProjectId]);
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $dn = $row['division_name'];
                            if (!isset($divisionBreakdown[$dn])) $divisionBreakdown[$dn] = 0;
                            $divisionBreakdown[$dn] += floatval($row['total']);
                        }
                    }
                } catch (Exception $e) {}
                
                // From project_division_expenses
                try {
                    $stmt = $pdo->prepare("
                        SELECT division_name, SUM(amount) as total 
                        FROM project_division_expenses 
                        WHERE project_id = ? 
                        GROUP BY division_name
                    ");
                    $stmt->execute([$selectedProjectId]);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $dn = $row['division_name'];
                        if (!isset($divisionBreakdown[$dn])) $divisionBreakdown[$dn] = 0;
                        $divisionBreakdown[$dn] += floatval($row['total']);
                    }
                } catch (Exception $e) {}
                
                // Get project expenses list
                try {
                    $stmt = $pdo->prepare("
                        SELECT pe.*, pec.category_name 
                        FROM project_expenses pe 
                        LEFT JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
                        WHERE pe.project_id = ? 
                        ORDER BY pe.expense_date DESC
                        LIMIT 20
                    ");
                    $stmt->execute([$selectedProjectId]);
                    $projectExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // Fallback without category join
                    $stmt = $pdo->prepare("SELECT * FROM project_expenses WHERE project_id = ? ORDER BY expense_date DESC LIMIT 20");
                    $stmt->execute([$selectedProjectId]);
                    $projectExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

function rp($num) {
    if ($num >= 1000000000) {
        return 'Rp ' . number_format($num / 1000000000, 1, ',', '.') . 'B';
    } elseif ($num >= 1000000) {
        return 'Rp ' . number_format($num / 1000000, 1, ',', '.') . 'M';
    } else {
        return 'Rp ' . number_format($num, 0, ',', '.');
    }
}

function rpFull($num) {
    return 'Rp ' . number_format($num, 0, ',', '.');
}

$usagePercent = $totalBudget > 0 ? round(($totalExpenses / $totalBudget) * 100) : 0;

// Prepare chart data - Expenses by Project (using grand_expenses)
$chartExpensesByProject = [];
foreach ($projects as $proj) {
    $projExpenses = $proj['grand_expenses'] ?? 0;
    if ($projExpenses > 0) {
        $chartExpensesByProject[$proj['name'] ?? 'Project'] = $projExpenses;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Projects & Investors - Owner</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 80px;
        }
        
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 16px;
            border-radius: 20px;
            margin-bottom: 16px;
            text-align: center;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
        }
        
        .header-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .header-logo {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            margin: 0 auto 10px;
            background: white;
            padding: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .header-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .header-subtitle {
            font-size: 12px;
            opacity: 0.9;
        }
        
        /* Overview Cards */
        .overview-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .overview-card {
            background: var(--card);
            border-radius: 14px;
            padding: 14px;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        
        .overview-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        
        .overview-card.capital::before { background: var(--success); }
        .overview-card.budget::before { background: var(--info); }
        .overview-card.used::before { background: var(--warning); }
        .overview-card.remaining::before { background: var(--primary); }
        
        .overview-icon {
            font-size: 24px;
            margin-bottom: 6px;
        }
        
        .overview-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .overview-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }
        
        .overview-hint {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        /* Progress Bar */
        .progress-section {
            background: var(--card);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .progress-content {
            display: flex;
            gap: 16px;
            align-items: center;
        }
        
        .progress-left {
            flex: 1;
        }
        
        .progress-chart {
            width: 120px;
            height: 120px;
            flex-shrink: 0;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .progress-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text);
        }
        
        .progress-percent {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .progress-bar {
            height: 10px;
            background: var(--border);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--warning));
            border-radius: 5px;
            transition: width 0.5s ease;
        }
        
        .progress-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 10px;
            color: var(--text-muted);
        }
        
        @media (max-width: 480px) {
            .progress-content {
                flex-direction: column;
            }
            .progress-chart {
                width: 100%;
                max-width: 200px;
                height: 180px;
                margin: 0 auto;
            }
        }
        
        /* Section Title */
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title .badge {
            background: var(--primary);
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        /* List Card */
        .list-card {
            background: var(--card);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .list-item {
            display: flex;
            align-items: center;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
        }
        
        .list-item:last-child { border-bottom: none; }
        .list-item:hover { background: var(--bg); }
        
        .list-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 12px;
        }
        
        .list-icon.investor {
            background: linear-gradient(135deg, #10b981, #34d399);
        }
        
        .list-icon.project {
            background: linear-gradient(135deg, #6366f1, #818cf8);
        }
        
        .list-info { flex: 1; }
        
        .list-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .list-detail {
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .list-amount {
            text-align: right;
        }
        
        .list-value {
            font-size: 12px;
            font-weight: 700;
            color: var(--success);
        }
        
        .list-label {
            font-size: 9px;
            color: var(--text-muted);
        }
        
        /* Project Detail */
        .project-detail {
            background: var(--card);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .project-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }
        
        .project-status {
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .project-status.active {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .project-status.completed {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .project-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        
        .project-stat {
            background: var(--bg);
            padding: 10px;
            border-radius: 10px;
            text-align: center;
        }
        
        .project-stat-label {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        
        .project-stat-value {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-top: 2px;
        }
        
        /* Expense List */
        .expense-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
        }
        
        .expense-item:last-child { border-bottom: none; }
        
        .expense-info {
            flex: 1;
        }
        
        .expense-desc {
            font-size: 12px;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .expense-date {
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .expense-category {
            font-size: 9px;
            background: var(--bg);
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--text-muted);
        }
        
        .expense-amount {
            font-size: 12px;
            font-weight: 600;
            color: var(--danger);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: var(--text-muted);
        }
        
        .empty-icon {
            font-size: 40px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        .empty-text {
            font-size: 13px;
        }
        
        /* Error State */
        .error-card {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        
        .error-title {
            color: var(--danger);
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .error-text {
            font-size: 12px;
            color: #991b1b;
        }
        
        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 12px;
            font-weight: 500;
        }
        
        /* Footer Nav */
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0 14px;
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 16px rgba(0,0,0,0.06);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 10px;
            color: var(--text-muted);
            transition: color 0.2s;
            padding: 4px 12px;
        }
        
        .nav-item.active { color: var(--primary); }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 2px;
        }
        
        /* Chart Card */
        .chart-card {
            background: var(--card);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 16px;
        }
        
        .chart-wrapper {
            width: 100%;
            max-width: 240px;
            height: 240px;
            margin: 0 auto 16px;
        }
        
        .chart-legend {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            background: var(--bg);
            border-radius: 8px;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            flex-shrink: 0;
        }
        
        .legend-info {
            flex: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .legend-name {
            font-size: 12px;
            font-weight: 500;
            color: var(--text);
        }
        
        .legend-details {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
        }
        
        .legend-amount {
            font-size: 11px;
            font-weight: 600;
            color: var(--text);
        }
        
        .legend-percent {
            font-size: 9px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-logo">
                <img src="<?= $basePath ?>/uploads/logos/narayana-hotel_logo.png" alt="Logo" onerror="this.parentElement.style.display='none'">
            </div>
            <div class="header-title">Projects & Investors</div>
            <div class="header-subtitle">Investment Overview</div>
        </div>
        
        <?php if ($error): ?>
        <div class="error-card">
            <div class="error-title">⚠️ Connection Error</div>
            <div class="error-text"><?= htmlspecialchars($error) ?></div>
        </div>
        <?php elseif ($selectedProject): ?>
        
        <!-- Project Detail View -->
        <a href="<?= $basePath ?>/modules/owner/investor-monitor.php" class="back-btn">← Back to Overview</a>
        
        <div class="project-detail">
            <div class="project-header">
                <div class="project-name"><?= htmlspecialchars($selectedProject['name'] ?? 'Project') ?></div>
                <span class="project-status <?= ($selectedProject['status'] ?? '') === 'active' ? 'active' : 'completed' ?>">
                    <?= ucfirst($selectedProject['status'] ?? 'active') ?>
                </span>
            </div>
            <div class="project-stats">
                <div class="project-stat">
                    <div class="project-stat-label">Budget</div>
                    <div class="project-stat-value"><?= rp($selectedProject['budget'] ?? 0) ?></div>
                </div>
                <div class="project-stat">
                    <div class="project-stat-label">Total Used</div>
                    <div class="project-stat-value"><?= rp($selectedProject['grand_expenses'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($divisionBreakdown)): ?>
        <!-- Division Breakdown Chart -->
        <div class="section-title">
            📊 Expense by Division
        </div>
        
        <div class="chart-card">
            <div class="chart-wrapper">
                <canvas id="divisionBreakdownChart"></canvas>
            </div>
            <div class="chart-legend">
                <?php 
                arsort($divisionBreakdown);
                $totalDivision = array_sum($divisionBreakdown);
                $colors = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#14b8a6', '#f97316', '#6366f1'];
                $index = 0;
                foreach ($divisionBreakdown as $divName => $divAmount): 
                    $percentage = $totalDivision > 0 ? round(($divAmount / $totalDivision) * 100, 1) : 0;
                    $color = $colors[$index % count($colors)];
                    $index++;
                ?>
                <div class="legend-item">
                    <div class="legend-color" style="background: <?= $color ?>"></div>
                    <div class="legend-info">
                        <div class="legend-name"><?= htmlspecialchars($divName) ?></div>
                        <div class="legend-details">
                            <span class="legend-amount"><?= rp($divAmount) ?></span>
                            <span class="legend-percent"><?= $percentage ?>%</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="section-title">
            📋 Recent Expenses
            <span class="badge"><?= count($projectExpenses) ?></span>
        </div>
        
        <div class="list-card">
            <?php if (empty($projectExpenses)): ?>
            <div class="empty-state">
                <div class="empty-icon">📝</div>
                <div class="empty-text">No expenses recorded yet</div>
            </div>
            <?php else: ?>
                <?php foreach ($projectExpenses as $exp): ?>
                <div class="expense-item">
                    <div class="expense-info">
                        <div class="expense-desc"><?= htmlspecialchars($exp['description'] ?? '-') ?></div>
                        <div class="expense-date">
                            <?= date('d M Y', strtotime($exp['expense_date'] ?? 'now')) ?>
                            <?php if (!empty($exp['category_name'])): ?>
                                <span class="expense-category"><?= htmlspecialchars($exp['category_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="expense-amount">-<?= rp($exp['amount'] ?? 0) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        
        <!-- Overview -->
        <div class="overview-grid">
            <div class="overview-card capital">
                <div class="overview-icon">💰</div>
                <div class="overview-label">Total Capital</div>
                <div class="overview-value"><?= rp($totalCapital) ?></div>
                <div class="overview-hint"><?= count($investors) ?> investors</div>
            </div>
            <div class="overview-card budget">
                <div class="overview-icon">📊</div>
                <div class="overview-label">Total Budget</div>
                <div class="overview-value"><?= rp($totalBudget) ?></div>
                <div class="overview-hint"><?= count($projects) ?> projects</div>
            </div>
        </div>
        
        <!-- Budget Usage Progress -->
        <div class="progress-section">
            <div class="progress-content">
                <div class="progress-left">
                    <div class="progress-header">
                        <span class="progress-title">Budget Usage</span>
                        <span class="progress-percent"><?= $usagePercent ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= min($usagePercent, 100) ?>%"></div>
                    </div>
                    <div class="progress-labels">
                        <span>Used: <?= rp($totalExpenses) ?></span>
                        <span>Remaining: <?= rp($totalBudget - $totalExpenses) ?></span>
                    </div>
                </div>
                <?php if (!empty($chartExpensesByProject)): ?>
                <div class="progress-chart">
                    <canvas id="expensePieChart"></canvas>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Investors List -->
        <div class="section-title">
            👤 Investors
            <span class="badge"><?= count($investors) ?></span>
        </div>
        
        <div class="list-card">
            <?php if (empty($investors)): ?>
            <div class="empty-state">
                <div class="empty-icon">👥</div>
                <div class="empty-text">No investors found</div>
            </div>
            <?php else: ?>
                <?php foreach ($investors as $inv): ?>
                <div class="list-item">
                    <div class="list-icon investor">👤</div>
                    <div class="list-info">
                        <div class="list-name"><?= htmlspecialchars($inv['name'] ?? 'Investor') ?></div>
                        <div class="list-detail"><?= htmlspecialchars($inv['contact'] ?? '-') ?></div>
                    </div>
                    <div class="list-amount">
                        <div class="list-value"><?= rp($inv['total_capital'] ?? 0) ?></div>
                        <div class="list-label">Capital</div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Projects List -->
        <div class="section-title">
            📁 Projects
            <span class="badge"><?= count($projects) ?></span>
        </div>
        
        <div class="list-card">
            <?php if (empty($projects)): ?>
            <div class="empty-state">
                <div class="empty-icon">📂</div>
                <div class="empty-text">No projects found</div>
            </div>
            <?php else: ?>
                <?php foreach ($projects as $proj): ?>
                <a href="?project_id=<?= $proj['id'] ?>" class="list-item">
                    <div class="list-icon project">📁</div>
                    <div class="list-info">
                        <div class="list-name"><?= htmlspecialchars($proj['name'] ?? 'Project') ?></div>
                        <div class="list-detail">
                            Budget: <?= rp($proj['budget'] ?? 0) ?>
                        </div>
                    </div>
                    <div class="list-amount">
                        <div class="list-value" style="color: var(--warning);"><?= rp($proj['grand_expenses'] ?? 0) ?></div>
                        <div class="list-label">Used</div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
    </div>
    
    <!-- Footer Nav -->
    <nav class="nav-bottom">
        <a href="<?= $basePath ?>/modules/owner/dashboard-2028.php" class="nav-item">
            <span class="nav-icon">🏠</span>
            <span>Home</span>
        </a>
        <a href="<?= $basePath ?>/modules/owner/frontdesk-mobile.php" class="nav-item">
            <span class="nav-icon">📋</span>
            <span>Frontdesk</span>
        </a>
        <a href="<?= $basePath ?>/modules/owner/investor-monitor.php" class="nav-item active">
            <span class="nav-icon">📈</span>
            <span>Projects</span>
        </a>
        <a href="<?= $basePath ?>/logout.php" class="nav-item">
            <span class="nav-icon">🚪</span>
            <span>Logout</span>
        </a>
    </nav>

    <script>
        // Expense Pie Chart
        <?php if (!empty($chartExpensesByProject)): ?>
        const pieCtx = document.getElementById('expensePieChart');
        if (pieCtx) {
            const pieColors = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ef4444','#14b8a6'];
            new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_keys($chartExpensesByProject)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($chartExpensesByProject)) ?>,
                        backgroundColor: pieColors.slice(0, <?= count($chartExpensesByProject) ?>),
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const val = ctx.parsed;
                                    const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                    const pct = ((val/total)*100).toFixed(1);
                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Division Breakdown Pie Chart (for selected project)
        <?php if (!empty($divisionBreakdown)): ?>
        const divisionCtx = document.getElementById('divisionBreakdownChart');
        if (divisionCtx) {
            const divisionColors = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#14b8a6', '#f97316', '#6366f1'];
            const divisionLabels = <?= json_encode(array_keys($divisionBreakdown)) ?>;
            const divisionData = <?= json_encode(array_values($divisionBreakdown)) ?>;
            
            new Chart(divisionCtx, {
                type: 'doughnut',
                data: {
                    labels: divisionLabels,
                    datasets: [{
                        data: divisionData,
                        backgroundColor: divisionColors.slice(0, divisionLabels.length),
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            cornerRadius: 8,
                            titleFont: {
                                size: 13,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 12
                            },
                            callbacks: {
                                label: function(ctx) {
                                    const val = ctx.parsed;
                                    const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                    const pct = ((val/total)*100).toFixed(1);
                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                }
                            }
                        }
                    },
                    cutout: '65%',
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 800,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>
