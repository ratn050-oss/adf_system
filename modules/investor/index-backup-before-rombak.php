<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();

// Get investors
try {
    $investors = $db->query("SELECT * FROM investors ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $investors = [];
}

// Get projects with investor relationship
try {
    $projects = $db->query("SELECT * FROM projects ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $projects = [];
}

// Calculate Global Totals Initial
$globalTotalCapital = 0;
foreach ($investors as $inv) {
    $globalTotalCapital += $inv['total_capital'] ?? 0;
}

$globalTotalExpenses = 0;
try {
    $stmt = $db->query("SELECT COALESCE(SUM(amount_idr), 0) FROM project_expenses");
    $globalTotalExpenses = $stmt->fetchColumn();
} catch (Exception $e) {
    $globalTotalExpenses = 0;
}


// Filter Logic
$selectedProjectId = isset($_GET['project_id']) ? $_GET['project_id'] : 'all';
$dashboardStats = [];
$recentTransactions = [];

// Display Variables for Charts & Stats
$totalCapitalDisplay = 0;
$totalExpensesDisplay = 0;

if ($selectedProjectId !== 'all') {
    // Single Project Stats
    $project = null;
    foreach ($projects as $p) {
        if ($p['id'] == $selectedProjectId) {
            $project = $p;
            break;
        }
    }

    if ($project) {
        // Total Inflow (Budget)
        $totalCapitalDisplay = $project['budget'];
        
        // Total Outflow (Expenses)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount_idr), 0) FROM project_expenses WHERE project_id = ?");
        $stmt->execute([$selectedProjectId]);
        $totalExpensesDisplay = $stmt->fetchColumn();
        
        // Recent Transactions
        $stmt = $db->prepare("SELECT * FROM project_expenses WHERE project_id = ? ORDER BY expense_date DESC, created_at DESC LIMIT 10");
        $stmt->execute([$selectedProjectId]);
        $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    // All Projects Stats (Global) - Default behavior
    $totalCapitalDisplay = $globalTotalCapital;
    $totalExpensesDisplay = $globalTotalExpenses;
    
    // Recent Transactions (Global)
    $stmt = $db->query("SELECT pe.*, p.name as project_name FROM project_expenses pe LEFT JOIN projects p ON pe.project_id = p.id ORDER BY pe.expense_date DESC, pe.created_at DESC LIMIT 10");
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Bind to Dashboard Stats
$dashboardStats['inflow'] = $totalCapitalDisplay;
$dashboardStats['outflow'] = $totalExpensesDisplay;

// Prepare variables for JS Charts (backward compatibility with existing JS)
$totalCapital = $totalCapitalDisplay;
$totalExpenses = $totalExpensesDisplay;

// Get All Expenses for Finance Tab
try {
    $allExpensesStmt = $db->query("
        SELECT pe.*, p.name as project_name 
        FROM project_expenses pe 
        LEFT JOIN projects p ON pe.project_id = p.id 
        ORDER BY pe.expense_date DESC, pe.created_at DESC
        LIMIT 100
    ");
    $allExpenses = $allExpensesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allExpenses = [];
}

// Get project expenses summary for map (if needed below)
try {
    $stmt = $db->prepare("
        SELECT project_id, SUM(amount_idr) as total_expenses 
        FROM project_expenses 
        GROUP BY project_id
    ");
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $expensesMap = [];
    foreach ($expenses as $e) {
        $expensesMap[$e['project_id']] = $e['total_expenses'];
    }
} catch (Exception $e) {
    $expensesMap = [];
}

$pageTitle = 'Invest & Projek';

// Inline styles using CSS variables
$inlineStyles = '
<style>
.investor-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.25rem;
}

.investor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    padding: 1rem 1.25rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.investor-header h1 {
    font-size: 1.35rem;
    font-weight: 700;
    color: white;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-add {
    background: rgba(255,255,255,0.2);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.btn-add:hover {
    background: rgba(255,255,255,0.3);
    transform: translateY(-2px);
}

.tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.25rem;
    background: var(--bg-secondary);
    padding: 0.5rem;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    flex-wrap: wrap;
}

.tab-btn {
    padding: 0.6rem 1rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.85rem;
    white-space: nowrap;
}

.tab-btn.active {
    color: white;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.4);
}

.tab-btn:hover:not(.active) {
    background: var(--bg-tertiary);
    color: #667eea;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}

.stat-card {
    background: var(--bg-secondary);
    border-radius: 10px;
    padding: 0.875rem;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
}

.stat-card h3 {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin: 0 0 0.25rem 0;
}

.stat-card .value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0.2rem 0;
}

.stat-card .label {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.content-section {
    background: var(--bg-secondary);
    border-radius: 10px;
    padding: 1rem;
    border: 1px solid var(--border-color);
    margin-bottom: 1rem;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 1rem 0;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.data-table thead {
    background: var(--bg-tertiary, rgba(0, 0, 0, 0.03));
}

.data-table th {
    padding: 0.75rem;
    text-align: left;
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-bottom: 1px solid var(--border-color);
}

.data-table td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}

.data-table tbody tr {
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--bg-tertiary, rgba(102, 126, 234, 0.03));
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 5px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
}

.amount {
    font-family: "Courier New", monospace;
    font-weight: 600;
    color: #667eea;
    font-size: 0.85rem;
}

.action-links {
    display: flex;
    gap: 0.75rem;
}

.action-links a {
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
    color: #667eea;
}

.action-links a:hover {
    color: #764ba2;
}

.empty-state {
    text-align: center;
    padding: 2rem 1.5rem;
    color: var(--text-secondary);
}

.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 0.75rem;
    opacity: 0.4;
    stroke: var(--text-secondary);
}

.empty-state p {
    font-size: 0.9rem;
    margin: 0;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.accounting-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.accounting-item {
    background: var(--bg-tertiary, rgba(0, 0, 0, 0.03));
    padding: 0.75rem;
    border-radius: 8px;
    border-left: 3px solid #667eea;
}

.accounting-item label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    display: block;
    margin-bottom: 0.35rem;
}

.accounting-item .value {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--text-primary);
    font-family: "Courier New", monospace;
}

</style>
';

include '../../includes/header.php';
?>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="investor-container">
    <!-- Header -->
    <div class="investor-header">
        <h1><i data-feather="briefcase" style="width: 22px; height: 22px;"></i> Invest & Projek</h1>
        <button class="btn-add" onclick="showAddInvestorModal()">+ Tambah Investor</button>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="dashboard"><i data-feather="pie-chart" style="width: 14px; height: 14px; margin-right: 4px;"></i>Dashboard</button>
        <button class="tab-btn" data-tab="finance"><i data-feather="credit-card" style="width: 14px; height: 14px; margin-right: 4px;"></i>Keuangan</button>
        <button class="tab-btn" data-tab="investor"><i data-feather="users" style="width: 14px; height: 14px; margin-right: 4px;"></i>Investor</button>
        <button class="tab-btn" data-tab="project"><i data-feather="folder" style="width: 14px; height: 14px; margin-right: 4px;"></i>Project</button>
        <button class="tab-btn" data-tab="accounting"><i data-feather="file-text" style="width: 14px; height: 14px; margin-right: 4px;"></i>Laporan</button>
    </div>

    <!-- Dashboard Tab -->
    <div id="dashboard" class="tab-content active">
        
        <!-- Project Filter -->
        <div style="margin-bottom: 1rem; background: var(--bg-secondary); padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <label style="font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; display: flex; align-items: center; gap: 0.35rem;">
                <i data-feather="filter" style="width: 14px; height: 14px;"></i> Filter:
            </label>
            <form id="projectFilterForm" method="GET" style="margin: 0; flex: 1; min-width: 200px;">
                <select name="project_id" onchange="this.form.submit()" style="padding: 0.45rem 0.75rem; border-radius: 6px; border: 1px solid var(--border-color); width: 100%; max-width: 280px; font-size: 0.85rem; background: var(--bg-primary);">
                    <option value="all" <?php echo $selectedProjectId === 'all' ? 'selected' : ''; ?>>Global Overview (Semua)</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $selectedProjectId == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card" style="border-left: 3px solid #10b981;">
                <h3>💰 Dana Masuk <?php echo $selectedProjectId !== 'all' ? '(Budget)' : '(Modal)'; ?></h3>
                <div class="value">Rp <?php echo number_format($dashboardStats['inflow'] ?? 0, 0, ',', '.'); ?></div>
                <div class="label"><?php echo $selectedProjectId !== 'all' ? 'Alokasi dana' : 'Total investor'; ?></div>
            </div>

            <div class="stat-card" style="border-left: 3px solid #ef4444;">
                <h3>📤 Pengeluaran</h3>
                <div class="value">Rp <?php echo number_format($dashboardStats['outflow'] ?? 0, 0, ',', '.'); ?></div>
                <div class="label"><?php echo $selectedProjectId !== 'all' ? 'Project ini' : 'Semua system'; ?></div>
            </div>

            <div class="stat-card" style="border-left: 3px solid #3b82f6;">
                <h3>📊 Sisa Dana</h3>
                <div class="value">Rp <?php echo number_format(($dashboardStats['inflow'] - $dashboardStats['outflow']) ?? 0, 0, ',', '.'); ?></div>
                <div class="label">Balance</div>
            </div>
            
            <?php if ($selectedProjectId === 'all'): ?>
            <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
                <h3>👥 Investor</h3>
                <div class="value"><?php echo count($investors) ?? 0; ?></div>
                <div class="label">Investor aktif</div>
            </div>
            <?php else: ?>
            <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
                <h3>📅 Status</h3>
                <div class="value" style="font-size: 0.95rem; text-transform: uppercase;">
                    <?php echo htmlspecialchars($project['status'] ?? 'Active'); ?>
                </div>
                <div class="label"><?php echo htmlspecialchars($project['location'] ?? ''); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Transactions Section -->
        <div class="content-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                <h3 class="section-title" style="margin: 0; border: none; padding: 0;"><i data-feather="clock" style="width: 16px; height: 16px;"></i> Transaksi Terbaru</h3>
                <a href="#" onclick="document.querySelector('[data-tab=\'finance\']').click(); return false;" style="color: #667eea; text-decoration: none; font-weight: 600; font-size: 0.8rem;">Lihat Semua →</a>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Keterangan</th>
                        <?php if ($selectedProjectId === 'all'): ?><th>Project</th><?php endif; ?>
                        <th style="text-align: right;">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentTransactions)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 2rem;">Belum ada transaksi</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentTransactions as $rt): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($rt['expense_date'])); ?></td>
                            <td>
                                <span class="status-badge" style="background: #e0e7ff; color: #3730a3;">
                                    <?php echo htmlspecialchars($rt['category']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($rt['description']); ?></td>
                            <?php if ($selectedProjectId === 'all'): ?>
                                <td><?php echo htmlspecialchars($rt['project_name'] ?? '-'); ?></td>
                            <?php endif; ?>
                            <td class="amount" style="text-align: right; color: #ef4444;">
                                Rp <?php echo number_format($rt['amount_idr'], 0, ',', '.'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <!-- Old Charts Grid (Hidden if specific project is selected to keep it clean, or we can keep it) -->
    <?php if ($selectedProjectId === 'all'): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-top: 1.25rem;">
        <!-- Chart 1: Uang Masuk -->
        <div class="content-section">
            <h3 class="section-title"><i data-feather="download" style="width: 16px; height: 16px;"></i> Dana Masuk Per Investor</h3>
            <div style="height: 200px; position: relative;">
                <canvas id="chartModalMasuk"></canvas>
            </div>
            <div style="margin-top: 10px; max-height: 120px; overflow-y: auto;">
                <table style="width: 100%; font-size: 0.8rem;">
                    <?php foreach ($investors as $inv): ?>
                    <tr style="border-bottom: 1px dashed #eee;">
                        <td style="padding: 6px 0; color: #666;"><?php echo htmlspecialchars($inv['name']); ?></td>
                        <td style="padding: 6px 0; text-align: right; font-weight: 600; color: var(--text-primary);">
                            Rp <?php echo number_format($inv['total_capital'] ?? 0, 0, ',', '.'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <!-- Chart 2: Uang Keluar -->
        <div class="content-section">
            <h3 class="section-title"><i data-feather="bar-chart-2" style="width: 16px; height: 16px;"></i> Modal vs Pengeluaran</h3>
            <div style="height: 200px; position: relative; display: flex; justify-content: center;">
                <canvas id="chartModalKeluar"></canvas>
            </div>
        </div>

        <!-- Chart 3: Progres Project -->
        <div class="content-section">
            <h3 class="section-title"><i data-feather="activity" style="width: 16px; height: 16px;"></i> Progres Pengeluaran</h3>
            <div style="height: 200px; position: relative;">
                <canvas id="chartProgresProject"></canvas>
            </div>
        </div>

        <!-- Chart 4: Detail Pengeluaran -->
        <div class="content-section">
            <h3 class="section-title"><i data-feather="pie-chart" style="width: 16px; height: 16px;"></i> Pengeluaran Per Project</h3>
            <div style="height: 200px; position: relative;">
                <canvas id="chartDetailPengeluaran"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <!-- Investors Tab -->
    <div id="investor" class="tab-content">
        <h2 class="section-title"><i data-feather="users" style="width: 18px; height: 18px;"></i> Daftar Investor</h2>
        
        <?php if (empty($investors)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            <p>Belum ada data investor</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Investor</th>
                    <th>Email</th>
                    <th>Kontak</th>
                    <th>Total Modal</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($investors as $inv): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($inv['name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($inv['email'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($inv['contact'] ?? '-'); ?></td>
                    <td class="amount">
                        Rp <?php echo number_format($inv['total_capital'] ?? 0, 0, ',', '.'); ?>
                    </td>
                    <td>
                        <span class="status-badge status-active">
                            Aktif
                        </span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="#edit">✏️ Edit</a>
                            <a href="#delete">🗑️ Hapus</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    </div>

    <!-- Projects Tab -->
    <div id="project" class="tab-content">
    <div class="content-section">
        <h2 class="section-title"><i data-feather="folder" style="width: 18px; height: 18px;"></i> Daftar Project</h2>
        
        <?php if (empty($projects)): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            <p>Belum ada data project</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Project</th>
                    <th>Lokasi</th>
                    <th>Budget</th>
                    <th>Pengeluaran</th>
                    <th>Sisa Budget</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $proj): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($proj['name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($proj['location'] ?? '-'); ?></td>
                    <td class="amount">
                        <?php echo 'Rp ' . number_format($proj['budget'] ?? 0, 0, ',', '.'); ?>
                    </td>
                    <td class="amount">
                        Rp 0
                    </td>
                    <td class="amount">
                        Rp <?php echo number_format($proj['budget'] ?? 0, 0, ',', '.'); ?>
                    </td>
                    <td>
                        <span class="status-badge status-active">
                            <?php echo ucfirst(htmlspecialchars($proj['status'] ?? 'Active')); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="#edit">✏️ Edit</a>
                            <a href="#delete">🗑️ Hapus</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    </div>

    <!-- Expense Input / Finance Tab -->
    <div id="finance" class="tab-content">
    <div class="content-section">
        <h2 class="section-title"><i data-feather="credit-card" style="width: 18px; height: 18px;"></i> Keuangan & Input Pengeluaran</h2>
        
        <form id="expenseForm" method="POST" action="save-expense.php" style="background: var(--bg-tertiary, rgba(0,0,0,0.05)); padding: 2rem; border-radius: 8px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">Project</label>
                    <select name="project_id" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary);">
                        <option value="">-- Pilih Project --</option>
                        <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['name'] ?? ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">Nominal (Rp)</label>
                    <input type="number" name="amount" required placeholder="0" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary);">
                </div>

                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">Kategori</label>
                    <select name="category" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary);">
                        <option value="">-- Pilih Kategori --</option>
                        <option value="material">Pembelian Material</option>
                        <option value="transport">Pembayaran Truk</option>
                        <option value="ship">Tiket Kapal</option>
                        <option value="labor" style="font-weight: bold; color: #d97706;">💰 Pembayaran Gaji (Tukang/Staff)</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">Tanggal</label>
                    <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary);">
                </div>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">Keterangan</label>
                <textarea name="description" placeholder="Masukkan keterangan pengeluaran..." style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary); min-height: 80px;"></textarea>
            </div>

            <div style="text-align: right;">
                <button type="submit" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.75rem 2rem; border-radius: 8px; font-weight: 600; cursor: pointer;">
                    💾 Simpan Pengeluaran
                </button>
            </div>
        </form>

        <div style="margin-top: 2rem;">
            <h3 style="color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">📋 Detail Keuangan & Riwayat Pengeluaran</h3>
            
            <?php if (empty($allExpenses)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                <p>Belum ada pengeluaran tercatat</p>
            </div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Project</th>
                        <th>Kategori</th>
                        <th>Keterangan</th>
                        <th style="text-align: right;">Jumlah</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allExpenses as $exp): 
                        $isSalary = ($exp['category'] === 'labor');
                        $rowStyle = $isSalary ? 'background-color: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b;' : '';
                    ?>
                    <tr style="<?php echo $rowStyle; ?>">
                        <td><?php echo date('d/m/Y', strtotime($exp['expense_date'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($exp['project_name']); ?></strong></td>
                        <td>
                            <?php if ($isSalary): ?>
                                <span class="status-badge" style="background: #fef3c7; color: #b45309; font-weight: bold;">
                                    💰 Gaji / Upah
                                </span>
                            <?php else: ?>
                                <span class="status-badge" style="background: #e0e7ff; color: #3730a3;">
                                    <?php echo htmlspecialchars(ucfirst($exp['category'])); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($exp['description']); ?></td>
                        <td class="amount" style="text-align: right;">
                            Rp <?php echo number_format($exp['amount_idr'], 0, ',', '.'); ?>
                        </td>
                        <td>
                            <a href="#" style="color: #ef4444; text-decoration: none;" onclick="if(confirm('Hapus pengeluaran ini?')) location.href='delete-expense.php?id=<?php echo $exp['id']; ?>'; return false;">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <!-- Accounting Tab -->
    <div id="accounting" class="tab-content">
    <div class="content-section">
        <h2 class="section-title">📊 Laporan Akuntansi</h2>
        
        <div class="accounting-summary">
            <div class="accounting-item">
                <label>Total Modal Masuk (IDR)</label>
                <div class="value">Rp <?php echo number_format($totalCapital, 0, ',', '.'); ?></div>
            </div>
            <div class="accounting-item">
                <label>Total Pengeluaran (IDR)</label>
                <div class="value">Rp <?php echo number_format($totalExpenses, 0, ',', '.'); ?></div>
            </div>
            <div class="accounting-item">
                <label>Saldo Tersisa (IDR)</label>
                <div class="value">Rp <?php echo number_format($totalCapital - $totalExpenses, 0, ',', '.'); ?></div>
            </div>
        </div>

        <div class="content-section" style="margin-top: 1.5rem;">
            <h3 style="margin-top: 0;">Rincian Per Investor</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Investor</th>
                        <th>Modal</th>
                        <th>Pengeluaran</th>
                        <th>Sisa</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($investors as $inv): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($inv['name'] ?? ''); ?></strong></td>
                        <td class="amount">Rp <?php echo number_format($inv['total_capital'] ?? 0, 0, ',', '.'); ?></td>
                        <td class="amount">Rp <?php echo number_format($inv['total_expenses'] ?? 0, 0, ',', '.'); ?></td>
                        <td class="amount">Rp <?php echo number_format(($inv['total_capital'] ?? 0) - ($inv['total_expenses'] ?? 0), 0, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>

<script>
// Function to detect light/dark theme and get text color
function getChartTextColor() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark' || 
                   window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    return isDark ? '#fff' : '#000';
}

// Prepare chart data from PHP
const investorsData = <?php echo json_encode($investors); ?>;
const projectsData = <?php echo json_encode($projects); ?>;
const totalCapital = <?php echo $totalCapital; ?>;
const totalExpenses = <?php echo $totalExpenses; ?>;

// Chart 1: Uang Masuk Per Investor
const investorNames = investorsData.map(i => i.name);
const investorCapitals = investorsData.map(i => i.total_capital);

const chartModalMasukEl = document.getElementById('chartModalMasuk');
if (chartModalMasukEl) {
    const ctx1 = chartModalMasukEl.getContext('2d');
    new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: investorNames,
            datasets: [{
                label: 'Dana Modal (Rp)',
                data: investorCapitals,
                backgroundColor: [
                    'rgba(102, 126, 234, 0.8)',
                    'rgba(72, 187, 120, 0.8)',
                    'rgba(237, 137, 54, 0.8)'
                ],
                borderColor: [
                    'rgba(102, 126, 234, 1)',
                    'rgba(72, 187, 120, 1)',
                    'rgba(237, 137, 54, 1)'
                ],
                borderWidth: 2,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    ticks: {
                        font: { size: 12, weight: 'bold' },
                        color: getChartTextColor()
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'Rp ' + (value/1000000).toFixed(0) + 'M';
                        },
                        font: { size: 11, weight: '600' },
                        color: getChartTextColor()
                    }
                }
            }
        }
    });
}

// Chart 2: Modal vs Pengeluaran (Pie)
const chartModalKeluarEl = document.getElementById('chartModalKeluar');
if (chartModalKeluarEl) {
    const ctx2 = chartModalKeluarEl.getContext('2d');
    new Chart(ctx2, {
        type: 'doughnut',
        data: {
            labels: ['💚 Sisa Dana', '🟠 Pengeluaran'],
            datasets: [{
                data: [totalCapital - totalExpenses, totalExpenses],
                backgroundColor: [
                    'rgba(72, 187, 120, 0.9)',
                    'rgba(237, 137, 54, 0.9)'
                ],
                borderColor: [
                    'rgba(72, 187, 120, 1)',
                    'rgba(237, 137, 54, 1)'
                ],
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { 
                    position: 'bottom',
                    align: 'center',
                    labels: {
                        font: {
                            size: 16,
                            weight: 'bold',
                            family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                        },
                        color: getChartTextColor(),
                        padding: 20,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 15,
                        boxHeight: 15
                    }
                }
            }
        }
    });
}

// Chart 3: Progres Project
const projectNames = projectsData.map(p => p.name || p.project_name);
const projectBudgets = projectsData.map(p => p.budget || p.budget_idr);

const chartProgresProjectEl = document.getElementById('chartProgresProject');
if (chartProgresProjectEl) {
    const ctx3 = chartProgresProjectEl.getContext('2d');
    new Chart(ctx3, {
        type: 'bar',
        data: {
            labels: projectNames,
            datasets: [{
                label: 'Budget (Rp)',
                data: projectBudgets,
                backgroundColor: 'rgba(102, 126, 234, 0.8)',
                borderColor: 'rgba(102, 126, 234, 1)',
                borderWidth: 2,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    ticks: {
                        font: { size: 12, weight: 'bold' },
                        color: getChartTextColor()
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'Rp ' + (value/1000000).toFixed(0) + 'M';
                        },
                        font: { size: 11, weight: '600' },
                        color: getChartTextColor()
                    }
                }
            }
        }
    });
}

// Chart 4: Detail Pengeluaran
const chartDetailPengeluaranEl = document.getElementById('chartDetailPengeluaran');
if (chartDetailPengeluaranEl) {
    const ctx4 = chartDetailPengeluaranEl.getContext('2d');
    new Chart(ctx4, {
        type: 'line',
        data: {
            labels: projectNames,
            datasets: [{
                label: 'Total Pengeluaran (Rp)',
                data: projectNames.map(() => 0), // Akan diupdate dari DB
                borderColor: 'rgba(237, 137, 54, 1)',
                backgroundColor: 'rgba(237, 137, 54, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgba(237, 137, 54, 1)',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { 
                    display: true,
                    position: 'top',
                    labels: {
                        font: { size: 13, weight: 'bold' },
                        color: getChartTextColor(),
                        padding: 15,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        font: { size: 11, weight: '600' },
                        color: getChartTextColor()
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'Rp ' + (value/1000000).toFixed(0) + 'M';
                        },
                        font: { size: 11, weight: '600' },
                        color: getChartTextColor()
                    }
                }
            }
        }
    });
}

// Tab navigation
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabName = this.getAttribute('data-tab');
        
        // Remove active class dari semua tab buttons
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        // Remove active class dari semua tab content
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        // Tambah active ke tab yang diklik
        this.classList.add('active');
        document.getElementById(tabName).classList.add('active');
    });
});

// Check for tab in URL on load
const urlParams = new URLSearchParams(window.location.search);
const activeTab = urlParams.get('tab');
if (activeTab) {
    const tabBtn = document.querySelector(`.tab-btn[data-tab="${activeTab}"]`);
    if (tabBtn) {
        // Remove default active
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        // Activate requested tab
        tabBtn.classList.add('active');
        document.getElementById(activeTab).classList.add('active');
    }
}

// Handle Form Submission untuk Input Pengeluaran
const expenseForm = document.getElementById('expenseForm');
if (expenseForm) {
    expenseForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const data = {
            project_id: formData.get('project_id'),
            amount: parseFloat(formData.get('amount')),
            category: formData.get('category'),
            expense_date: formData.get('expense_date'),
            description: formData.get('description')
        };

        try {
            const response = await fetch('save-expense.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();
            
            if (result.success) {
                alert('✅ Pengeluaran berhasil disimpan!');
                expenseForm.reset();
                
                // Reload page untuk update data & chart
                setTimeout(() => window.location.reload(), 500);
            } else {
                alert('❌ Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('❌ Gagal menyimpan pengeluaran: ' + error.message);
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
