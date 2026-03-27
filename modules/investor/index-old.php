<?php
// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!-- DEBUG: Script started -->\n";

// Define APP_ACCESS constant
define('APP_ACCESS', true);

// Get base path
$base_path = dirname(dirname(dirname(__FILE__)));

echo "<!-- DEBUG: Base path: $base_path -->\n";

require_once $base_path . '/config/config.php';
echo "<!-- DEBUG: Config loaded -->\n";
require_once $base_path . '/config/database.php';
echo "<!-- DEBUG: Database loaded -->\n";
require_once $base_path . '/includes/auth.php';
echo "<!-- DEBUG: Auth loaded -->\n";
require_once $base_path . '/includes/InvestorManager.php';
echo "<!-- DEBUG: InvestorManager loaded -->\n";
require_once $base_path . '/includes/ProjectManager.php';
echo "<!-- DEBUG: ProjectManager loaded -->\n";

// Check permission
$auth = new Auth();
$auth->requireLogin();

echo "<!-- DEBUG: Auth check passed -->\n";

if (!$auth->hasPermission('investor')) {
    header('HTTP/1.1 403 Forbidden');
    echo "You do not have permission to access this module.";
    exit;
}

echo "<!-- DEBUG: Permission check passed -->\n";

$db = Database::getInstance()->getConnection();
echo "<!-- DEBUG: DB connection obtained -->\n";
$investor = new InvestorManager($db);
echo "<!-- DEBUG: InvestorManager created -->\n";
$project = new ProjectManager($db);
echo "<!-- DEBUG: ProjectManager created -->\n";

// Get all investors with error handling
$investors = [];
try {
    $investors = $investor->getAllInvestors();
    echo "<!-- DEBUG: Investors fetched: " . count($investors) . " -->\n";
} catch (Exception $e) {
    echo "<!-- DEBUG ERROR: Failed to fetch investors: " . $e->getMessage() . " -->\n";
    // Check if table exists, if not suggest installation
    $tableCheck = $db->query("SHOW TABLES LIKE 'investors'")->fetch();
    if (!$tableCheck) {
        die('<div style="padding:2rem;font-family:sans-serif;">
            <h2>⚠️ Database Tables Not Found</h2>
            <p>Table <code>investors</code> tidak ditemukan.</p>
            <p>Silakan jalankan installer terlebih dahulu:</p>
            <a href="' . BASE_URL . '/install-investor-project.php" style="display:inline-block;padding:0.75rem 1.5rem;background:#667eea;color:white;text-decoration:none;border-radius:8px;margin-top:1rem;">
                🔧 Install Investor & Project Tables
            </a>
        </div>');
    }
    $investors = [];
}

// Get all projects with expenses
$projects = [];
try {
    $projects = $project->getAllProjects();
} catch (Exception $e) {
    echo "<!-- DEBUG ERROR: Failed to fetch projects: " . $e->getMessage() . " -->\n";
    $projects = [];
}

// Get recent expenses (last 10) - Dari SEMUA project
$recent_expenses = [];
try {
    $stmt = $db->prepare("
        SELECT 
            pe.*,
            p.name as project_name,
            pec.category_name as category
        FROM project_expenses pe
        JOIN projects p ON pe.project_id = p.id
        JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
        ORDER BY pe.expense_date DESC, pe.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_expenses = $stmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
    error_log("Error fetching recent expenses: " . $e->getMessage());
}

// Get expense categories summary (Gabungan dari SEMUA project)
$expense_categories = [];
try {
    $stmt = $db->prepare("
        SELECT 
            pec.category_name as category,
            SUM(pe.amount_idr) as total_amount_idr,
            COUNT(*) as transaction_count
        FROM project_expenses pe
        JOIN projects p ON pe.project_id = p.id
        JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
        WHERE pec.is_active = 1
        GROUP BY pec.id, pec.category_name
        ORDER BY total_amount_idr DESC
    ");
    $stmt->execute();
    $expense_categories = $stmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
    error_log("Error fetching expense categories: " . $e->getMessage());
}

// Get project expenses summary (Top 10 project by pengeluaran)
$project_expenses = [];
try {
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.name as project_name,
            SUM(pe.amount_idr) as total_expenses_idr,
            COUNT(pe.id) as expense_count
        FROM projects p
        LEFT JOIN project_expenses pe ON pe.project_id = p.id
        GROUP BY p.id, p.name
        HAVING total_expenses_idr > 0
        ORDER BY total_expenses_idr DESC
        LIMIT 10
    ");
    $stmt->execute();
    $project_expenses = $stmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
    error_log("Error fetching project expenses: " . $e->getMessage());
}

// Calculate totals
$total_capital = 0;
$total_expenses = 0;
$total_balance = 0;

foreach ($investors as $inv) {
    $total_capital += $inv['total_capital_idr'] ?? 0;
    $total_expenses += $inv['total_expenses_idr'] ?? 0;
    $total_balance += $inv['remaining_balance_idr'] ?? 0;
}

// Set page title and include header
$pageTitle = 'Manajemen Investor';
$inlineStyles = '
<style>
    .main-content {
            background: var(--bg-primary) !important;
            min-height: 100vh !important;
            padding: 0 !important;
        }
        
        .investor-container {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0;
        }

        .tab-btn {
            padding: 1rem 2rem;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-muted);
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .tab-btn:hover {
            color: var(--text-primary);
            background: var(--bg-tertiary);
        }

        .tab-btn.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: transparent;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }

        .card-header {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .card-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .card-subtext {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        /* Header Section */
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .header-section h1 {
            margin: 0;
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Search and Filter */
        .search-filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 0.938rem;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
        }

        /* Table Styles */
        .investor-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-secondary);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .investor-table thead {
            background: var(--bg-tertiary);
            border-bottom: 2px solid var(--border-color);
        }

        .investor-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .investor-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .investor-table tbody tr {
            transition: all 0.2s ease;
        }

        .investor-table tbody tr:hover {
            background: var(--bg-tertiary);
            cursor: pointer;
        }

        /* Charts */
        .chart-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .chart-box h3 {
            margin: 0 0 1.5rem 0;
            font-size: 1.125rem;
            color: var(--text-primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-wrapper {
            position: relative;
            height: 320px;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.938rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.813rem;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-muted);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }

        .close-btn:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.938rem;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 1rem;
            transition: border 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid #22c55e;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .empty-state i {
            width: 64px;
            height: 64px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Kas Besar Box */
        .kas-besar-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .kas-besar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .kas-besar-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .kas-besar-subtitle {
            color: rgba(255,255,255,0.9);
            margin: 0;
            font-size: 1rem;
        }

        .kas-besar-amount {
            text-align: right;
        }

        .kas-besar-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .kas-besar-value {
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .kas-besar-usd {
            color: rgba(255,255,255,0.8);
            font-size: 0.938rem;
            margin-top: 0.25rem;
        }

        .kas-besar-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .kas-besar-stat {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .kas-besar-stat-label {
            color: rgba(255,255,255,0.9);
            font-size: 0.813rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kas-besar-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
        }
    </style>
';

echo "<!-- DEBUG: Before include header -->\n";
include '../../includes/header.php'; 
echo "<!-- DEBUG: After include header -->\n";
echo "<!-- DEBUG: About to output main content -->\n";
?>

<main class="main-content">
        <?php echo "<!-- DEBUG: Inside main tag -->\n"; ?>
        <div class="investor-container">
            <?php echo "<!-- DEBUG: Inside investor-container -->\n"; ?>
            <!-- Header Section -->
            <div class="header-section">
                <h1>
                    <span>💼</span>
                    Manajemen Investor
                </h1>
                <button class="btn btn-primary" onclick="openAddInvestorModal()">
                    <i data-feather="plus"></i>
                    Tambah Investor
                </button>
            </div>

            <!-- Alert Messages -->
            <div id="alertContainer"></div>

            <!-- Tab Navigation -->
            <div class="tab-navigation">
                <button class="tab-btn active" onclick="switchTab('dashboard')">
                    <i data-feather="home" style="width: 18px; height: 18px; margin-right: 6px;"></i>
                    Dashboard
                </button>
                <button class="tab-btn" onclick="switchTab('investors')">
                    <i data-feather="users" style="width: 18px; height: 18px; margin-right: 6px;"></i>
                    Daftar Investor
                </button>
                <button class="tab-btn" onclick="switchTab('analytics')">
                    <i data-feather="bar-chart-2" style="width: 18px; height: 18px; margin-right: 6px;"></i>
                    Analitik & Laporan
                </button>
            </div>

            <!-- Dashboard Tab -->
            <div id="dashboard-tab" class="tab-content active">
                <!-- Kas Besar Section -->
                <div class="kas-besar-box">
                    <div class="kas-besar-header">
                        <div>
                            <h2 class="kas-besar-title">
                                <span style="font-size: 2rem;">💼</span>
                                Kas Besar Projek
                            </h2>
                            <p class="kas-besar-subtitle">Dana pooling dari <?php echo count($investors); ?> investor untuk pembiayaan projek</p>
                        </div>
                        <div class="kas-besar-amount">
                            <div class="kas-besar-label">Total Dana Tersedia</div>
                            <div class="kas-besar-value">
                                Rp <?php echo number_format($total_capital, 0, ',', '.'); ?>
                            </div>
                            <div class="kas-besar-usd">
                                ≈ USD $<?php echo number_format($total_capital / 15500, 2, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="kas-besar-stats">
                        <div class="kas-besar-stat">
                            <div class="kas-besar-stat-label">Modal Terkumpul</div>
                            <div class="kas-besar-stat-value">Rp <?php echo number_format($total_capital, 0, ',', '.'); ?></div>
                        </div>
                        <div class="kas-besar-stat">
                            <div class="kas-besar-stat-label">Terpakai Projek</div>
                            <div class="kas-besar-stat-value">Rp <?php echo number_format($total_expenses, 0, ',', '.'); ?></div>
                        </div>
                        <div class="kas-besar-stat">
                            <div class="kas-besar-stat-label">Sisa Kas</div>
                            <div class="kas-besar-stat-value" style="color: #86efac;">Rp <?php echo number_format($total_balance, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="dashboard-cards">
                    <div class="card">
                        <div class="card-header">Total Investor</div>
                        <div class="card-value"><?php echo count($investors); ?></div>
                        <div class="card-subtext">Investor aktif</div>
                    </div>
                    <div class="card">
                        <div class="card-header">Total Projek</div>
                        <div class="card-value"><?php echo count($projects); ?></div>
                        <div class="card-subtext">Projek berjalan</div>
                    </div>
                    <div class="card">
                        <div class="card-header">Rata-rata Modal</div>
                        <div class="card-value">
                            Rp <?php echo count($investors) > 0 ? number_format($total_capital / count($investors), 0, ',', '.') : 0; ?>
                        </div>
                        <div class="card-subtext">Per investor</div>
                    </div>
                </div>

                <!-- Quick Stats Grid -->
                <div class="chart-container">
                    <div class="chart-box">
                        <h3>
                            <i data-feather="trending-up"></i>
                            Akumulasi Modal Per Investor
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="capitalChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-box">
                        <h3>
                            <i data-feather="pie-chart"></i>
                            Pengeluaran Per Kategori
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.875rem; margin: -0.5rem 0 1rem 0;">
                            💡 Data gabungan dari semua project
                        </p>
                        <div class="chart-wrapper">
                            <?php if (!empty($expense_categories) && array_sum(array_column($expense_categories, 'total_amount_idr')) > 0): ?>
                                <canvas id="expenseCategoryChart"></canvas>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i data-feather="pie-chart"></i>
                                    <p>Belum ada data pengeluaran</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Investors Tab -->
            <div id="investors-tab" class="tab-content">
                <!-- Search and Filter -->
                <div class="search-filter-bar">
                    <div class="search-box">
                        <i data-feather="search"></i>
                        <input type="text" id="searchInvestor" placeholder="Cari nama investor, kontak, atau email..." onkeyup="searchInvestors()">
                    </div>
                    <div class="filter-group">
                        <button class="btn btn-secondary btn-small" onclick="filterInvestors('all')">
                            Semua
                        </button>
                        <button class="btn btn-secondary btn-small" onclick="filterInvestors('active')">
                            Aktif
                        </button>
                    </div>
                </div>

                <!-- Investors Table -->
                <?php if (count($investors) > 0): ?>
                    <table class="investor-table">
                        <thead>
                            <tr>
                                <th>Nama Investor</th>
                                <th>Kontak</th>
                                <th>Modal Masuk</th>
                                <th>Saldo Kas</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="investorTableBody">
                            <?php foreach ($investors as $inv): ?>
                                <tr class="investor-row" data-investor-id="<?php echo $inv['id']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($inv['name']); ?></strong><br>
                                        <small style="color: var(--text-muted);"><?php echo htmlspecialchars($inv['notes'] ?? '-'); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($inv['contact'] ?? '-'); ?><br>
                                        <small style="color: var(--text-muted);"><?php echo htmlspecialchars($inv['email'] ?? '-'); ?></small>
                                    </td>
                                    <td>
                                        <strong style="color: var(--text-primary); font-size: 1rem;">
                                            Rp <?php echo number_format($inv['total_capital_idr'] ?? 0, 0, ',', '.'); ?>
                                        </strong>
                                        <br>
                                        <small style="color: var(--text-muted); font-size: 0.813rem;">
                                            USD $<?php echo number_format(($inv['total_capital_idr'] ?? 0) / 15500, 2, ',', '.'); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong style="color: #22c55e; font-size: 1rem;">
                                            Rp <?php echo number_format($inv['remaining_balance_idr'] ?? 0, 0, ',', '.'); ?>
                                        </strong>
                                        <br>
                                        <small style="color: var(--text-muted); font-size: 0.813rem;">
                                            Kontribusi ke kas
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-active">Active</span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-secondary btn-small" onclick="viewInvestor(<?php echo $inv['id']; ?>)" title="Lihat Detail">
                                                <i data-feather="eye"></i>
                                            </button>
                                            <button class="btn btn-success btn-small" onclick="addCapitalTransaction(<?php echo $inv['id']; ?>)" title="Tambah Modal">
                                                <i data-feather="plus-circle"></i>
                                                Saldo
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="card empty-state">
                        <i data-feather="users"></i>
                        <p>Belum ada investor. Klik tombol "Tambah Investor" untuk memulai.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Analytics Tab -->
            <div id="analytics-tab" class="tab-content">
                <h2 style="margin-bottom: 1.5rem;">Analitik & Laporan</h2>
                
                <!-- Info Banner -->
                <div style="background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                    <i data-feather="info" style="width: 20px; height: 20px; color: #6366f1;"></i>
                    <span style="color: var(--text-primary); font-size: 0.938rem;">
                        <strong>Catatan:</strong> Semua data pengeluaran di bawah adalah <strong>gabungan dari semua project</strong>. 
                        Saat input pengeluaran, admin memilih project mana, tapi untuk analitik disini semua project digabungkan.
                    </span>
                </div>
                
                <div class="chart-container">
                    <div class="chart-box">
                        <h3>
                            <i data-feather="bar-chart"></i>
                            Pengeluaran Per Projek (Top 10)
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.875rem; margin: -0.5rem 0 1rem 0;">
                            Total pengeluaran masing-masing project
                        </p>
                        <div class="chart-wrapper">
                            <?php if (!empty($project_expenses)): ?>
                                <canvas id="projectExpenseChart"></canvas>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i data-feather="bar-chart"></i>
                                    <p>Belum ada data pengeluaran projek</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-box">
                        <h3>
                            <i data-feather="activity"></i>
                            Aktivitas Pengeluaran Terbaru
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.875rem; margin: -0.5rem 0 1rem 0;">
                            10 transaksi terakhir dari semua project
                        </p>
                        <div style="max-height: 320px; overflow-y: auto;">
                            <?php if (!empty($recent_expenses)): ?>
                                <?php foreach ($recent_expenses as $expense): ?>
                                    <div style="padding: 0.75rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: start;">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">
                                                <?php echo htmlspecialchars($expense['project_name']); ?>
                                            </div>
                                            <div style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.25rem;">
                                                📁 <?php echo htmlspecialchars($expense['category'] ?? 'Kategori belum tersedia'); ?>
                                            </div>
                                            <div style="font-size: 0.875rem; color: var(--text-secondary);">
                                                <?php echo htmlspecialchars($expense['description'] ?? '-'); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                📅 <?php echo date('d M Y', strtotime($expense['expense_date'])); ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right; white-space: nowrap; margin-left: 1rem;">
                                            <div style="font-weight: 600; color: #ef4444; font-size: 1rem;">
                                                -Rp <?php echo number_format($expense['amount_idr'] ?? 0, 0, ',', '.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i data-feather="activity"></i>
                                    <p>Belum ada transaksi pengeluaran</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Investor Modal -->
    <div id="addInvestorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Tambah Investor Baru</h2>
                <button class="close-btn" onclick="closeAddInvestorModal()">✕</button>
            </div>
            <form id="addInvestorForm" onsubmit="submitAddInvestor(event)">
                <div class="form-group">
                    <label for="investor_name">Nama Investor *</label>
                    <input type="text" id="investor_name" name="investor_name" required>
                </div>

                <div class="form-group">
                    <label for="investor_address">Alamat Lengkap *</label>
                    <textarea id="investor_address" name="investor_address" required></textarea>
                </div>

                <div class="form-group">
                    <label for="contact_phone">Nomor Telepon</label>
                    <input type="tel" id="contact_phone" name="contact_phone">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                </div>

                <div class="form-group">
                    <label for="notes">Catatan</label>
                    <textarea id="notes" name="notes" style="min-height: 60px;"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddInvestorModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Investor</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Capital Transaction Modal -->
    <div id="capitalTransactionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Tambah Transaksi Modal</h2>
                <button class="close-btn" onclick="closeCapitalTransactionModal()">✕</button>
            </div>
            <form id="capitalTransactionForm" onsubmit="submitCapitalTransaction(event)">
                <input type="hidden" id="investor_id_hidden" name="investor_id">

                <div class="form-group">
                    <label for="amount_usd">Jumlah USD *</label>
                    <input type="number" id="amount_usd" name="amount_usd" step="0.01" required>
                </div>

                <div class="form-group">
                    <label for="exchange_rate_display">Kurs USD → IDR</label>
                    <input type="text" id="exchange_rate_display" readonly style="background: var(--bg-tertiary); cursor: not-allowed;">
                </div>

                <div class="form-group">
                    <label for="amount_idr_display">Total IDR</label>
                    <input type="text" id="amount_idr_display" readonly style="background: var(--bg-tertiary); cursor: not-allowed;">
                </div>

                <div class="form-group">
                    <label for="transaction_date">Tanggal Transaksi *</label>
                    <input type="date" id="transaction_date" name="transaction_date" required>
                </div>

                <div class="form-group">
                    <label for="payment_method">Metode Pembayaran</label>
                    <select id="payment_method" name="payment_method">
                        <option value="bank_transfer">Transfer Bank</option>
                        <option value="cash">Tunai</option>
                        <option value="check">Cek</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="reference_no">No. Referensi</label>
                    <input type="text" id="reference_no" name="reference_no">
                </div>

                <div class="form-group">
                    <label for="transaction_description">Deskripsi</label>
                    <textarea id="transaction_description" name="description" style="min-height: 60px;"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCapitalTransactionModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Transaksi</button>
                </div>
            </form>
        </div>
    </div>

<?php
// Set inline script untuk chart dan functions
ob_start();
?>
        // Initialize Feather Icons
        feather.replace();
        
        // Set today's date as default
        document.getElementById('transaction_date').valueAsDate = new Date();

        // Tab Switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Set active button
            event.target.closest('.tab-btn').classList.add('active');
            
            // Refresh feather icons
            feather.replace();
        }

        // Search Investors
        function searchInvestors() {
            const searchValue = document.getElementById('searchInvestor').value.toLowerCase();
            const rows = document.querySelectorAll('.investor-row');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Filter Investors
        function filterInvestors(filter) {
            // This can be expanded based on your filtering logic
            console.log('Filter:', filter);
        }

        // Modal Functions
        function openAddInvestorModal() {
            document.getElementById('addInvestorModal').classList.add('active');
        }

        function closeAddInvestorModal() {
            document.getElementById('addInvestorModal').classList.remove('active');
            document.getElementById('addInvestorForm').reset();
        }

        function addCapitalTransaction(investorId) {
            document.getElementById('investor_id_hidden').value = investorId;
            document.getElementById('capitalTransactionModal').classList.add('active');
            loadExchangeRate();
        }

        function closeCapitalTransactionModal() {
            document.getElementById('capitalTransactionModal').classList.remove('active');
            document.getElementById('capitalTransactionForm').reset();
        }

        // Fixed exchange rate
        const FIXED_USD_RATE = 15500; // Rp 15,500 per USD

        function loadExchangeRate() {
            document.getElementById('exchange_rate_display').value = 'Rp ' + FIXED_USD_RATE.toLocaleString('id-ID');
        }

        // Calculate IDR amount when USD changes
        document.getElementById('amount_usd').addEventListener('input', function() {
            const amountUsd = parseFloat(this.value) || 0;
            if (amountUsd > 0) {
                const amountIdr = amountUsd * FIXED_USD_RATE;
                document.getElementById('exchange_rate_display').value = 'Rp ' + FIXED_USD_RATE.toLocaleString('id-ID');
                document.getElementById('amount_idr_display').value = 'Rp ' + amountIdr.toLocaleString('id-ID', {maximumFractionDigits: 0});
            }
        });

        // Submit add investor
        async function submitAddInvestor(event) {
            event.preventDefault();
            const form = document.getElementById('addInvestorForm');
            const btn = event.target.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Menyimpan...';

            try {
                const formData = new FormData(form);
                const response = await fetch('<?php echo BASE_URL; ?>/api/investor-create.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                showAlert(data.message, data.success ? 'success' : 'error');

                if (data.success) {
                    closeAddInvestorModal();
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Simpan Investor';
            }
        }

        // Submit capital transaction
        async function submitCapitalTransaction(event) {
            event.preventDefault();
            const form = document.getElementById('capitalTransactionForm');
            const btn = event.target.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Menyimpan...';

            try {
                const formData = new FormData(form);
                const response = await fetch('<?php echo BASE_URL; ?>/api/investor-add-capital.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                showAlert(data.message, data.success ? 'success' : 'error');

                if (data.success) {
                    closeCapitalTransactionModal();
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Simpan Transaksi';
            }
        }

        // View investor details
        function viewInvestor(investorId) {
            // You can create a detail page or open a modal
            window.location.href = '<?php echo BASE_URL; ?>/modules/investor/investor-detail.php?id=' + investorId;
        }

        // Show alert
        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type;
            
            const icon = type === 'success' ? '✓' : '✗';
            alert.innerHTML = `<span style="font-size: 1.25rem;">${icon}</span> ${message}`;
            
            container.innerHTML = '';
            container.appendChild(alert);

            setTimeout(() => alert.remove(), 5000);
        }

        // Initialize Charts
        const chartData = <?php
            $labels = [];
            $data = [];
            foreach ($investors as $inv) {
                $labels[] = $inv['name'];
                $data[] = $inv['total_capital_idr'] ?? 0;
            }
            echo json_encode([
                'labels' => $labels,
                'data' => $data
            ]);
        ?>;

        // Capital Chart
        const ctx1 = document.getElementById('capitalChart').getContext('2d');
        const gradient1 = ctx1.createLinearGradient(0, 0, 0, 300);
        gradient1.addColorStop(0, 'rgba(99, 102, 241, 0.4)');
        gradient1.addColorStop(0.5, 'rgba(99, 102, 241, 0.15)');
        gradient1.addColorStop(1, 'rgba(99, 102, 241, 0.02)');

        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Total Modal (IDR)',
                    data: chartData.data,
                    borderColor: '#6366f1',
                    backgroundColor: gradient1,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 6,
                    pointBackgroundColor: '#6366f1',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.08)' },
                        ticks: {
                            color: '#94a3b8',
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return 'Rp ' + (value / 1000000).toFixed(1) + 'Jt';
                                }
                                return 'Rp ' + (value / 1000).toFixed(0) + 'K';
                            }
                        }
                    }
                }
            }
        });

        // Expense Category Chart
        <?php if (!empty($expense_categories) && array_sum(array_column($expense_categories, 'total_amount_idr')) > 0): ?>
        const ctx2 = document.getElementById('expenseCategoryChart').getContext('2d');
        const categoryData = <?php
            $category_labels = [];
            $category_amounts = [];
            $category_colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#14b8a6'];
            
            foreach ($expense_categories as $cat) {
                $category_labels[] = $cat['category'] ?? 'Lainnya';
                $category_amounts[] = $cat['total_amount_idr'] ?? 0;
            }
            
            echo json_encode([
                'labels' => $category_labels,
                'data' => $category_amounts,
                'colors' => array_slice($category_colors, 0, count($category_labels))
            ]);
        ?>;

        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: categoryData.labels,
                datasets: [{
                    data: categoryData.data,
                    backgroundColor: categoryData.colors,
                    borderColor: '#1e293b',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#cbd5e1',
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': Rp ' + context.parsed.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Project Expense Chart
        <?php if (!empty($project_expenses)): ?>
        const ctx3 = document.getElementById('projectExpenseChart').getContext('2d');
        const projectData = <?php
            $project_labels = [];
            $project_amounts = [];
            
            foreach ($project_expenses as $proj) {
                $project_labels[] = $proj['project_name'];
                $project_amounts[] = $proj['total_expenses_idr'] ?? 0;
            }
            
            echo json_encode([
                'labels' => $project_labels,
                'data' => $project_amounts
            ]);
        ?>;

        new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: projectData.labels,
                datasets: [{
                    label: 'Total Pengeluaran (IDR)',
                    data: projectData.data,
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderColor: '#6366f1',
                    borderWidth: 2,
                    borderRadius: 8,
                    barThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { 
                            color: '#94a3b8',
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.08)' },
                        ticks: {
                            color: '#94a3b8',
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return 'Rp ' + (value / 1000000).toFixed(1) + 'Jt';
                                }
                                return 'Rp ' + (value / 1000).toFixed(0) + 'K';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Initialize feather icons after page load
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
        });
<?php
$inlineScript = ob_get_clean();
include '../../includes/footer.php';
?>
