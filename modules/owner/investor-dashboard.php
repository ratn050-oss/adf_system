<?php
/**
 * Investor Dashboard
 * Shows investment overview and returns from Narayana investor module
 */
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ../../login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'], ['owner', 'admin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

// Connect to Narayana database where investor data is stored
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_narayana_hotel') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all investors
    $investors = $pdo->query("SELECT * FROM investors ORDER BY total_capital DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total investor capital
    $totalCapital = $pdo->query("SELECT COALESCE(SUM(total_capital), 0) FROM investors")->fetchColumn();
    
    // Get projects
    $projects = $pdo->query("SELECT * FROM projects ORDER BY budget DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total project budget
    $totalBudget = $pdo->query("SELECT COALESCE(SUM(budget), 0) FROM projects")->fetchColumn();
    
    // Get total project expenses
    $totalExpenses = $pdo->query("SELECT COALESCE(SUM(total_expenses), 0) FROM projects")->fetchColumn();
    
    // Get recent expenses
    $recentExpenses = $pdo->query("
        SELECT pe.*, p.name as project_name 
        FROM project_expenses pe 
        LEFT JOIN projects p ON pe.project_id = p.id 
        ORDER BY pe.expense_date DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get investor transactions with investor names
    $transactions = $pdo->query("
        SELECT t.*, i.name as investor_name 
        FROM investor_transactions t 
        LEFT JOIN investors i ON t.investor_id = i.id 
        ORDER BY t.transaction_date DESC, t.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $investors = [];
    $projects = [];
    $totalCapital = 0;
    $totalBudget = 0;
    $totalExpenses = 0;
    $recentExpenses = [];
    $transactions = [];
}

// Calculate remaining balance
$remainingBalance = $totalBudget - $totalExpenses;
$usagePercent = $totalBudget > 0 ? ($totalExpenses / $totalBudget * 100) : 0;

// Format function
function formatMoney($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Investor Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(180deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
            min-height: 100vh;
            color: white;
        }
        
        .header {
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .header p {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.6);
        }
        
        .container {
            padding: 1rem;
            padding-bottom: 5rem;
        }
        
        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }
        
        .summary-card.full {
            grid-column: span 2;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
        }
        
        .summary-icon {
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
        }
        
        .summary-label {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.7);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .summary-value {
            font-size: 1rem;
            font-weight: 700;
        }
        
        .summary-card.full .summary-value {
            font-size: 1.3rem;
        }
        
        /* Investor List */
        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .investor-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .investor-item {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .investor-name {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .investor-amount {
            font-size: 0.8rem;
            color: #34d399;
            font-weight: 600;
        }
        
        /* Project Card */
        .project-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .project-name {
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .project-status {
            font-size: 0.6rem;
            padding: 0.25rem 0.5rem;
            background: rgba(52, 211, 153, 0.2);
            color: #34d399;
            border-radius: 20px;
            text-transform: uppercase;
        }
        
        .progress-bar {
            background: rgba(255,255,255,0.2);
            height: 8px;
            border-radius: 4px;
            margin-bottom: 0.5rem;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            border-radius: 4px;
            transition: width 0.5s;
        }
        
        .project-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: rgba(255,255,255,0.7);
        }
        
        /* Transaction List */
        .transaction-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .transaction-item {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 0.75rem;
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.25rem;
        }
        
        .transaction-investor {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .transaction-type {
            font-size: 0.55rem;
            padding: 0.2rem 0.4rem;
            border-radius: 10px;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .transaction-type.capital {
            background: rgba(52, 211, 153, 0.2);
            color: #34d399;
        }
        
        .transaction-type.expense {
            background: rgba(248, 113, 113, 0.2);
            color: #f87171;
        }
        
        .transaction-type.return {
            background: rgba(96, 165, 250, 0.2);
            color: #60a5fa;
        }
        
        .transaction-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .transaction-date {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.5);
        }
        
        .transaction-amount {
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .transaction-amount.positive {
            color: #34d399;
        }
        
        .transaction-amount.negative {
            color: #f87171;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
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
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Investor Dashboard</h1>
        <p>Narayana Hotel Investment Overview</p>
    </div>
    
    <div class="container">
        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card full">
                <div class="summary-icon">
                    <i data-feather="users" style="width: 18px; height: 18px; color: white;"></i>
                </div>
                <div class="summary-label">Total Investor Capital</div>
                <div class="summary-value"><?= formatMoney($totalCapital) ?></div>
            </div>
            
            <div class="summary-card">
                <div class="summary-icon">
                    <i data-feather="briefcase" style="width: 18px; height: 18px; color: white;"></i>
                </div>
                <div class="summary-label">Project Budget</div>
                <div class="summary-value"><?= formatMoney($totalBudget) ?></div>
            </div>
            
            <div class="summary-card">
                <div class="summary-icon">
                    <i data-feather="trending-down" style="width: 18px; height: 18px; color: white;"></i>
                </div>
                <div class="summary-label">Total Expenses</div>
                <div class="summary-value"><?= formatMoney($totalExpenses) ?></div>
            </div>
        </div>
        
        <!-- Investor List -->
        <div class="section-title">
            <i data-feather="users" style="width: 16px; height: 16px;"></i>
            Investors (<?= count($investors) ?>)
        </div>
        <div class="investor-list">
            <?php foreach ($investors as $investor): ?>
            <div class="investor-item">
                <div class="investor-name"><?= htmlspecialchars($investor['name']) ?></div>
                <div class="investor-amount"><?= formatMoney($investor['total_capital']) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($investors)): ?>
            <div class="investor-item">
                <div class="investor-name" style="color: rgba(255,255,255,0.5);">No investors found</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Projects -->
        <div class="section-title">
            <i data-feather="folder" style="width: 16px; height: 16px;"></i>
            Projects (<?= count($projects) ?>)
        </div>
        <?php foreach ($projects as $project): 
            $projectUsage = $project['budget'] > 0 ? ($project['total_expenses'] / $project['budget'] * 100) : 0;
        ?>
        <div class="project-card">
            <div class="project-header">
                <div class="project-name"><?= htmlspecialchars($project['name']) ?></div>
                <div class="project-status"><?= htmlspecialchars($project['status'] ?? 'Active') ?></div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= min($projectUsage, 100) ?>%"></div>
            </div>
            <div class="project-stats">
                <span>Used: <?= formatMoney($project['total_expenses']) ?></span>
                <span>Budget: <?= formatMoney($project['budget']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($projects)): ?>
        <div class="project-card">
            <div class="project-name" style="color: rgba(255,255,255,0.5);">No projects found</div>
        </div>
        <?php endif; ?>
        
        <!-- Transaction List -->
        <div class="section-title">
            <i data-feather="list" style="width: 16px; height: 16px;"></i>
            Investor Transactions (<?= count($transactions) ?>)
        </div>
        <div class="transaction-list">
            <?php foreach ($transactions as $tx): 
                $isPositive = $tx['type'] === 'capital' || $tx['type'] === 'return';
            ?>
            <div class="transaction-item">
                <div class="transaction-header">
                    <div class="transaction-investor"><?= htmlspecialchars($tx['investor_name'] ?? 'Unknown') ?></div>
                    <div class="transaction-type <?= $tx['type'] ?>"><?= ucfirst($tx['type']) ?></div>
                </div>
                <div class="transaction-details">
                    <div class="transaction-date"><?= date('d M Y', strtotime($tx['transaction_date'])) ?></div>
                    <div class="transaction-amount <?= $isPositive ? 'positive' : 'negative' ?>">
                        <?= $isPositive ? '+' : '-' ?><?= formatMoney($tx['amount']) ?>
                    </div>
                </div>
                <?php if (!empty($tx['description'])): ?>
                <div class="transaction-date" style="margin-top: 0.25rem;"><?= htmlspecialchars($tx['description']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($transactions)): ?>
            <div class="transaction-item">
                <div class="transaction-investor" style="color: rgba(255,255,255,0.5);">No transactions found</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i data-feather="home" style="width: 18px; height: 18px;"></i>
            Dashboard
        </a>
        <a href="investor-dashboard.php" class="nav-item active">
            <i data-feather="trending-up" style="width: 18px; height: 18px;"></i>
            Investor
        </a>
        <a href="../../logout.php" class="nav-item">
            <i data-feather="log-out" style="width: 18px; height: 18px;"></i>
            Logout
        </a>
    </div>
    
    <script>
        feather.replace();
    </script>
</body>
</html>
