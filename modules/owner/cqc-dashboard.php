<?php
/**
 * CQC Owner Monitoring Dashboard
 * Elegant project monitoring for CQC business owners
 */

session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['owner', 'admin', 'manager', 'developer'])) {
    header('Location: /login.php');
    exit;
}

define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// CQC Color Theme
$cqcNavy = '#0d1f3c';
$cqcGold = '#f0b429';
$cqcGoldDark = '#d4960d';
$cqcGoldLight = '#f5c842';

// Get CQC project data
$cqcProjects = [];
$projectStats = [
    'total' => 0,
    'planning' => 0,
    'procurement' => 0,
    'installation' => 0,
    'testing' => 0,
    'completed' => 0,
    'on_hold' => 0
];
$totalBudget = 0;
$totalSpent = 0;
$avgProgress = 0;

try {
    require_once __DIR__ . '/../cqc-projects/db-helper.php';
    $cqcPdo = getCQCDatabaseConnection();
    
    // Get all projects with expense data
    $stmt = $cqcPdo->query("
        SELECT p.*, 
               COALESCE(SUM(e.amount), 0) as actual_spent,
               COUNT(DISTINCT e.id) as expense_count
        FROM cqc_projects p
        LEFT JOIN cqc_project_expenses e ON p.id = e.project_id
        GROUP BY p.id
        ORDER BY p.status ASC, p.progress_percentage DESC
    ");
    $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate stats
    $projectStats['total'] = count($cqcProjects);
    $totalProgressSum = 0;
    
    foreach ($cqcProjects as &$proj) {
        // Update spent with actual expense
        if ($proj['actual_spent'] > 0) {
            $proj['spent_idr'] = $proj['actual_spent'];
        }
        
        $totalBudget += floatval($proj['budget_idr'] ?? 0);
        $totalSpent += floatval($proj['spent_idr'] ?? 0);
        $totalProgressSum += intval($proj['progress_percentage'] ?? 0);
        
        // Count by status
        $status = $proj['status'] ?? 'planning';
        if (isset($projectStats[$status])) {
            $projectStats[$status]++;
        }
    }
    unset($proj);
    
    $avgProgress = $projectStats['total'] > 0 ? round($totalProgressSum / $projectStats['total']) : 0;
    
    // Get recent expenses
    $recentExpenses = $cqcPdo->query("
        SELECT e.*, p.project_name, p.project_code
        FROM cqc_project_expenses e
        LEFT JOIN cqc_projects p ON e.project_id = p.id
        ORDER BY e.expense_date DESC, e.id DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get monthly expense trend
    $monthlyTrend = $cqcPdo->query("
        SELECT 
            DATE_FORMAT(expense_date, '%Y-%m') as month,
            SUM(amount) as total
        FROM cqc_project_expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log('CQC Owner Dashboard Error: ' . $e->getMessage());
}

$totalRemaining = $totalBudget - $totalSpent;
$budgetUsedPct = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 1) : 0;

// Format helper
function formatIDR($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

$username = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Owner';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CQC Owner Monitoring | Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --navy: <?php echo $cqcNavy; ?>;
            --gold: <?php echo $cqcGold; ?>;
            --gold-dark: <?php echo $cqcGoldDark; ?>;
            --gold-light: <?php echo $cqcGoldLight; ?>;
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, var(--navy), #1a3a5c);
            padding: 1.5rem 2rem;
            color: white;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-md);
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo {
            width: 48px;
            height: 48px;
            background: var(--gold);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--navy);
        }
        
        .brand h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
        }
        
        .brand span {
            font-size: 0.75rem;
            color: var(--gold);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .datetime {
            text-align: right;
        }
        
        .datetime .date {
            font-size: 0.875rem;
            color: rgba(255,255,255,0.7);
        }
        
        .datetime .time {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gold);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.1);
            border-radius: var(--radius-md);
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--gold);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--navy);
            font-size: 0.875rem;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .user-role {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
        }
        
        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        
        .summary-card.gold::before { background: var(--gold); }
        .summary-card.success::before { background: var(--success); }
        .summary-card.danger::before { background: var(--danger); }
        .summary-card.info::before { background: var(--info); }
        .summary-card.navy::before { background: var(--navy); }
        
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        
        .summary-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }
        
        .summary-card.gold .summary-icon { background: rgba(240,180,41,0.15); color: var(--gold-dark); }
        .summary-card.success .summary-icon { background: rgba(16,185,129,0.15); color: var(--success); }
        .summary-card.danger .summary-icon { background: rgba(239,68,68,0.15); color: var(--danger); }
        .summary-card.info .summary-icon { background: rgba(59,130,246,0.15); color: var(--info); }
        .summary-card.navy .summary-icon { background: rgba(13,31,60,0.15); color: var(--navy); }
        
        .summary-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary);
        }
        
        .summary-value.money {
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 1.1rem;
        }
        
        .summary-sub {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        /* Section Grid */
        .section-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .section-grid.equal {
            grid-template-columns: 1fr 1fr;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-title i {
            color: var(--gold);
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        /* Project Cards Grid */
        .project-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .project-card {
            background: white;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .project-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.75rem;
        }
        
        .project-code {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .project-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--navy);
            margin-top: 0.125rem;
        }
        
        .project-client {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        .status-badge {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            text-transform: uppercase;
        }
        
        .status-planning { background: #fef3c7; color: #d97706; }
        .status-procurement { background: #e0e7ff; color: #4f46e5; }
        .status-installation { background: #dbeafe; color: #2563eb; }
        .status-testing { background: #fce7f3; color: #db2777; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-on_hold { background: #f3f4f6; color: #6b7280; }
        
        /* Progress Ring */
        .progress-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .progress-ring {
            position: relative;
            width: 64px;
            height: 64px;
        }
        
        .progress-ring svg {
            transform: rotate(-90deg);
        }
        
        .progress-ring circle {
            fill: none;
            stroke-width: 6;
        }
        
        .progress-ring .bg {
            stroke: var(--bg-tertiary);
        }
        
        .progress-ring .progress {
            stroke: var(--gold);
            stroke-linecap: round;
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .progress-value {
            font-size: 1rem;
            font-weight: 800;
            color: var(--navy);
        }
        
        .progress-label {
            font-size: 0.55rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        
        .progress-stats {
            flex: 1;
        }
        
        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.35rem 0;
            font-size: 0.8rem;
        }
        
        .stat-row:not(:last-child) {
            border-bottom: 1px dashed var(--border);
        }
        
        .stat-label {
            color: var(--text-secondary);
        }
        
        .stat-value {
            font-weight: 700;
            font-family: 'Monaco', monospace;
        }
        
        .stat-value.success { color: var(--success); }
        .stat-value.danger { color: var(--danger); }
        .stat-value.warning { color: var(--warning); }
        
        /* Charts */
        .chart-container {
            position: relative;
            height: 250px;
        }
        
        /* Table */
        .table-wrapper {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
        }
        
        .data-table td {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-primary);
        }
        
        .data-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .data-table .amount {
            font-family: 'Monaco', monospace;
            font-weight: 600;
        }
        
        .data-table .amount.expense { color: var(--danger); }
        
        /* Status Pills Grid */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }
        
        .status-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: var(--bg-secondary);
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
        }
        
        .status-pill .label {
            color: var(--text-secondary);
        }
        
        .status-pill .count {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--navy);
        }
        
        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .summary-grid { grid-template-columns: repeat(3, 1fr); }
            .section-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .header-content { flex-direction: column; gap: 1rem; text-align: center; }
            .container { padding: 1rem; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <a href="../../index.php" class="back-btn">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <div class="logo">CQ</div>
                <div class="brand">
                    <h1>CQC Enjiniring</h1>
                    <span>Owner Monitoring</span>
                </div>
            </div>
            <div class="header-right">
                <div class="datetime">
                    <div class="date" id="currentDate"><?php echo date('l, d F Y'); ?></div>
                    <div class="time" id="currentTime"><?php echo date('H:i:s'); ?></div>
                </div>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 2)); ?></div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                        <div class="user-role"><?php echo $_SESSION['role'] ?? 'Owner'; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Main Content -->
    <div class="container">
        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card gold">
                <div class="summary-icon">📊</div>
                <div class="summary-label">Total Proyek</div>
                <div class="summary-value"><?php echo $projectStats['total']; ?></div>
                <div class="summary-sub"><?php echo $projectStats['completed']; ?> selesai</div>
            </div>
            <div class="summary-card success">
                <div class="summary-icon">💰</div>
                <div class="summary-label">Total Budget</div>
                <div class="summary-value money"><?php echo formatIDR($totalBudget); ?></div>
                <div class="summary-sub">Semua proyek</div>
            </div>
            <div class="summary-card danger">
                <div class="summary-icon">📤</div>
                <div class="summary-label">Total Pengeluaran</div>
                <div class="summary-value money"><?php echo formatIDR($totalSpent); ?></div>
                <div class="summary-sub"><?php echo $budgetUsedPct; ?>% dari budget</div>
            </div>
            <div class="summary-card info">
                <div class="summary-icon">💵</div>
                <div class="summary-label">Sisa Budget</div>
                <div class="summary-value money" style="color: <?php echo $totalRemaining >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                    <?php echo formatIDR($totalRemaining); ?>
                </div>
                <div class="summary-sub"><?php echo 100 - $budgetUsedPct; ?>% tersisa</div>
            </div>
            <div class="summary-card navy">
                <div class="summary-icon">📈</div>
                <div class="summary-label">Rata-rata Progress</div>
                <div class="summary-value"><?php echo $avgProgress; ?>%</div>
                <div class="summary-sub">Semua proyek aktif</div>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="section-grid equal">
            <!-- Budget vs Spent Chart -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-pie-chart-fill"></i>
                        Budget vs Pengeluaran
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="budgetChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Status Distribution -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-bar-chart-fill"></i>
                        Status Proyek
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status Pills -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <div class="status-grid">
                    <div class="status-pill">
                        <span class="label">🟡 Planning</span>
                        <span class="count"><?php echo $projectStats['planning']; ?></span>
                    </div>
                    <div class="status-pill">
                        <span class="label">🔵 Procurement</span>
                        <span class="count"><?php echo $projectStats['procurement']; ?></span>
                    </div>
                    <div class="status-pill">
                        <span class="label">🛠️ Installation</span>
                        <span class="count"><?php echo $projectStats['installation']; ?></span>
                    </div>
                    <div class="status-pill">
                        <span class="label">🧪 Testing</span>
                        <span class="count"><?php echo $projectStats['testing']; ?></span>
                    </div>
                    <div class="status-pill">
                        <span class="label">✅ Completed</span>
                        <span class="count"><?php echo $projectStats['completed']; ?></span>
                    </div>
                    <div class="status-pill">
                        <span class="label">⏸️ On Hold</span>
                        <span class="count"><?php echo $projectStats['on_hold']; ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Projects Grid with Pie Charts - Same as Main Dashboard -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-pie-chart-fill" style="color: var(--gold);"></i>
                    Pencapaian & Keuangan Per Proyek
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($cqcProjects)): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem;">
                    <?php foreach ($cqcProjects as $idx => $proj): 
                        $budget = floatval($proj['budget_idr'] ?? 0);
                        $spent = floatval($proj['spent_idr'] ?? 0);
                        $remaining = $budget - $spent;
                        $progress = intval($proj['progress_percentage'] ?? 0);
                        $spentPct = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
                        $status = $proj['status'] ?? 'planning';
                        $statusLabels = ['planning'=>'Planning','procurement'=>'Procurement','installation'=>'Instalasi','testing'=>'Testing','completed'=>'Selesai','on_hold'=>'Ditunda'];
                        $statusLabel = $statusLabels[$status] ?? ucfirst($status);
                    ?>
                    <div class="project-pie-card" style="background: #fff; border-radius: 16px; border: 1px solid var(--border); padding: 1.25rem; transition: all 0.3s ease; box-shadow: var(--shadow-sm);">
                        <!-- Header -->
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                            <div>
                                <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;"><?php echo htmlspecialchars($proj['project_code'] ?? ''); ?></div>
                                <div style="font-size: 0.95rem; font-weight: 700; color: var(--navy);"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                                <?php if (!empty($proj['client_name'])): ?>
                                <div style="font-size: 0.75rem; color: var(--text-secondary);">👤 <?php echo htmlspecialchars($proj['client_name']); ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?php echo $status; ?>"><?php echo $statusLabel; ?></span>
                        </div>
                        
                        <!-- Pie Chart with Center Progress -->
                        <div style="position: relative; width: 160px; height: 160px; margin: 0 auto 1rem;">
                            <canvas id="ownerPie<?php echo $idx; ?>"></canvas>
                            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 800; color: var(--navy);"><?php echo $progress; ?>%</div>
                                <div style="font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Progress</div>
                            </div>
                        </div>
                        
                        <!-- Financial Stats -->
                        <div style="margin-top: 0.5rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--bg-tertiary);">
                                <span style="font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem;">🔥 Budget</span>
                                <span style="font-size: 0.85rem; font-weight: 700; font-family: 'Monaco', monospace; color: var(--navy);"><?php echo formatIDR($budget); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--bg-tertiary);">
                                <span style="font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem;">📤 Uang Keluar</span>
                                <span style="font-size: 0.85rem; font-weight: 700; font-family: 'Monaco', monospace; color: var(--danger);"><?php echo formatIDR($spent); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--bg-tertiary);">
                                <span style="font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem;">💵 Sisa Budget</span>
                                <span style="font-size: 0.85rem; font-weight: 700; font-family: 'Monaco', monospace; color: <?php echo $remaining >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;"><?php echo formatIDR($remaining); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;">
                                <span style="font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem;">📊 Budget Terpakai</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: <?php echo $spentPct > 90 ? 'var(--danger)' : ($spentPct > 70 ? 'var(--warning)' : 'var(--success)'); ?>;"><?php echo $spentPct; ?>%</span>
                            </div>
                        </div>
                        
                        <!-- Detail Button -->
                        <a href="../cqc-projects/detail.php?id=<?php echo $proj['id']; ?>" 
                           style="display: block; text-align: center; margin-top: 0.75rem; padding: 0.6rem; background: linear-gradient(135deg, var(--navy), #1a3a5c); color: var(--gold); border-radius: 10px; text-decoration: none; font-size: 0.8rem; font-weight: 700; transition: all 0.3s ease;"
                           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(13,31,60,0.35)';"
                           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                            Lihat Detail →
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">☀️</div>
                    <h3>Belum Ada Proyek</h3>
                    <p>Tambahkan proyek pertama untuk melihat monitoring.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Update time
        function updateTime() {
            const now = new Date();
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('id-ID');
            document.getElementById('currentDate').textContent = now.toLocaleDateString('id-ID', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }
        setInterval(updateTime, 1000);
        
        // Budget vs Spent Chart
        const budgetCtx = document.getElementById('budgetChart');
        if (budgetCtx) {
            new Chart(budgetCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Terpakai', 'Sisa Budget'],
                    datasets: [{
                        data: [<?php echo $totalSpent; ?>, <?php echo max(0, $totalRemaining); ?>],
                        backgroundColor: ['#ef4444', '#10b981'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { 
                                padding: 20,
                                usePointStyle: true,
                                font: { weight: '600' }
                            }
                        }
                    }
                }
            });
        }
        
        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx, {
                type: 'bar',
                data: {
                    labels: ['Planning', 'Procurement', 'Installation', 'Testing', 'Completed', 'On Hold'],
                    datasets: [{
                        label: 'Jumlah Proyek',
                        data: [
                            <?php echo $projectStats['planning']; ?>,
                            <?php echo $projectStats['procurement']; ?>,
                            <?php echo $projectStats['installation']; ?>,
                            <?php echo $projectStats['testing']; ?>,
                            <?php echo $projectStats['completed']; ?>,
                            <?php echo $projectStats['on_hold']; ?>
                        ],
                        backgroundColor: [
                            '#f59e0b',
                            '#6366f1',
                            '#3b82f6',
                            '#ec4899',
                            '#10b981',
                            '#9ca3af'
                        ],
                        borderRadius: 8,
                        barThickness: 32
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
                            ticks: { stepSize: 1 },
                            grid: { color: '#f1f5f9' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }
        
        // Per-Project Pie Charts (same as index.php)
        <?php if (!empty($cqcProjects)): ?>
        <?php foreach ($cqcProjects as $idx => $proj): 
            $progress = intval($proj['progress_percentage'] ?? 0);
            $budget = floatval($proj['budget_idr'] ?? 0);
            $spent = floatval($proj['spent_idr'] ?? 0);
        ?>
        (function() {
            const ctx = document.getElementById('ownerPie<?php echo $idx; ?>');
            if (!ctx) {
                console.error('Canvas ownerPie<?php echo $idx; ?> not found');
                return;
            }
            console.log('Creating chart ownerPie<?php echo $idx; ?> with progress <?php echo $progress; ?>');
            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Selesai', 'Tersisa'],
                    datasets: [{
                        data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                        backgroundColor: [
                            '<?php echo $progress >= 80 ? "#10b981" : ($progress >= 50 ? "#f0b429" : ($progress >= 25 ? "#3b82f6" : "#6b7280")); ?>',
                            'rgba(229, 231, 235, 0.5)'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(13,31,60,0.95)',
                            titleColor: '#f0b429',
                            bodyColor: '#fff',
                            cornerRadius: 8,
                            callbacks: {
                                label: function(ctx) {
                                    return ctx.label + ': ' + ctx.parsed + '%';
                                }
                            }
                        }
                    },
                    animation: { animateRotate: true, duration: 1200 }
                }
            });
        })();
        <?php endforeach; ?>
        <?php endif; ?>
    </script>
</body>
</html>
