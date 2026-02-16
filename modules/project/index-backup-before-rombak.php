<?php
// Enable error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define APP_ACCESS constant
define('APP_ACCESS', true);

// Get base path
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';
require_once $base_path . '/includes/ProjectManager.php';
require_once $base_path . '/includes/InvestorManager.php';

// Check permission
$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('project')) {
    header('HTTP/1.1 403 Forbidden');
    echo "You do not have permission to access this module.";
    exit;
}

$db = Database::getInstance()->getConnection();
$project_manager = new ProjectManager($db);
$investor_manager = new InvestorManager($db);

// Get all projects
$projects = $project_manager->getAllProjects();
$categories = $project_manager->getExpenseCategories();
$investors = $investor_manager->getAllInvestors();

// Calculate totals
$total_expenses = 0;
$total_budget = 0;
$active_projects = 0;

foreach ($projects as $proj) {
    $total_expenses += $proj['total_expenses'] ?? 0;
    $total_budget += $proj['budget'] ?? 0;
    if ($proj['status'] === 'active') {
        $active_projects++;
    }
}

// Get available investor balance (Kas Besar)
$total_available_balance = 0;
foreach ($investors as $inv) {
    $total_available_balance += $inv['remaining_balance_idr'] ?? 0;
}

// Set page title and include header
$pageTitle = 'Manajemen Project';
$inlineStyles = '
<style>
    .main-content {
            background: var(--bg-primary) !important;
            min-height: 100vh !important;
            padding: 0 !important;
        }
        
        .project-container {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .project-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            border-color: var(--primary-color);
        }

        .project-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }

        .project-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 0.5rem 0;
        }

        .project-status {
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

        .status-completed {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .status-planning {
            background: rgba(234, 179, 8, 0.15);
            color: #eab308;
        }

        .project-description {
            color: var(--text-muted);
            font-size: 0.938rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .project-chart {
            height: 200px;
            margin: 1rem 0;
            position: relative;
        }

        .project-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .stat-item {
            text-align: center;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .project-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
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
            justify-content: center;
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

        .btn-block {
            width: 100%;
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
            max-width: 600px;
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
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .form-group label .required {
            color: #ef4444;
            margin-left: 0.25rem;
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
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-helper {
            font-size: 0.813rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
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

        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid #3b82f6;
        }

        /* Info Box */
        .info-box {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .info-box-label {
            font-size: 0.938rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .info-box-value {
            font-size: 2rem;
            font-weight: 800;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .empty-state i {
            width: 64px;
            height: 64px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.125rem;
            margin: 0 0 1.5rem 0;
        }
    </style>
';

include '../../includes/header.php'; 
?>

<main class="main-content">
        <div class="project-container">
            <!-- Header Section -->
            <div class="header-section">
                <h1>
                    <span>📁</span>
                    Manajemen Project
                </h1>
                <button class="btn btn-primary" onclick="openAddExpenseModal()">
                    <i data-feather="plus"></i>
                    Tambah Pengeluaran
                </button>
            </div>

            <!-- Alert Messages -->
            <div id="alertContainer"></div>

            <!-- Kas Besar Info -->
            <div class="info-box">
                <div>
                    <div class="info-box-label">💰 Kas Besar Tersedia (dari Investor)</div>
                    <div class="info-box-value">
                        Rp <?php echo number_format($total_available_balance, 0, ',', '.'); ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div class="info-box-label">Total Terpakai</div>
                    <div class="info-box-value" style="font-size: 1.5rem;">
                        Rp <?php echo number_format($total_expenses, 0, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-header">
                        <i data-feather="folder"></i>
                        Total Project
                    </div>
                    <div class="card-value"><?php echo count($projects); ?></div>
                    <div class="card-subtext"><?php echo $active_projects; ?> project aktif</div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <i data-feather="trending-down"></i>
                        Total Pengeluaran
                    </div>
                    <div class="card-value">Rp <?php echo number_format($total_expenses, 0, ',', '.'); ?></div>
                    <div class="card-subtext">Dari semua project</div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <i data-feather="dollar-sign"></i>
                        Total Budget
                    </div>
                    <div class="card-value">Rp <?php echo number_format($total_budget, 0, ',', '.'); ?></div>
                    <div class="card-subtext">Budget allocation</div>
                </div>
            </div>

            <!-- Projects Grid -->
            <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="grid" style="width: 24px; height: 24px;"></i>
                Daftar Project
            </h2>

            <?php if (count($projects) > 0): ?>
                <div class="projects-grid">
                    <?php foreach ($projects as $proj): ?>
                        <div class="project-card" onclick="viewProjectDetail(<?php echo $proj['id']; ?>)">
                            <div class="project-card-header">
                                <div>
                                    <h3 class="project-title"><?php echo htmlspecialchars($proj['name']); ?></h3>
                                    <span class="project-status status-<?php echo $proj['status']; ?>">
                                        <?php echo ucfirst($proj['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <p class="project-description">
                                <?php echo htmlspecialchars(substr($proj['description'] ?? 'Tidak ada deskripsi', 0, 100)); ?>...
                            </p>

                            <!-- Pie Chart Container -->
                            <div class="project-chart">
                                <canvas id="chart-<?php echo $proj['id']; ?>"></canvas>
                            </div>

                            <div class="project-stats">
                                <div class="stat-item">
                                    <div class="stat-label">Budget</div>
                                    <div class="stat-value" style="color: #64b5f6;">
                                        Rp <?php echo number_format($proj['budget'] ?? 0, 0, ',', '.'); ?>
                                    </div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">Terpakai</div>
                                    <div class="stat-value" style="color: #ef4444;">
                                        Rp <?php echo number_format($proj['total_expenses'] ?? 0, 0, ',', '.'); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="project-actions" onclick="event.stopPropagation();">
                                <button class="btn btn-secondary btn-small btn-block" onclick="addExpenseToProject(<?php echo $proj['id']; ?>)">
                                    <i data-feather="plus"></i>
                                    Tambah Pengeluaran
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card empty-state">
                    <i data-feather="folder"></i>
                    <p>Belum ada project. Buat project baru untuk memulai.</p>
                    <button class="btn btn-primary" onclick="window.location.href='create-project.php'">
                        <i data-feather="plus"></i>
                        Buat Project Baru
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Add Expense Modal -->
    <div id="addExpenseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i data-feather="dollar-sign"></i>
                    Tambah Pengeluaran Project
                </h2>
                <button class="close-btn" onclick="closeAddExpenseModal()">✕</button>
            </div>
            
            <form id="addExpenseForm" onsubmit="submitAddExpense(event)">
                <div class="form-group">
                    <label for="project_id">
                        Project<span class="required">*</span>
                    </label>
                    <select id="project_id" name="project_id" required>
                        <option value="">Pilih Project</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>">
                                <?php echo htmlspecialchars($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-helper">Pilih project untuk pengeluaran ini</div>
                </div>

                <div class="form-group">
                    <label for="expense_category_id">
                        Kategori Pengeluaran<span class="required">*</span>
                    </label>
                    <select id="expense_category_id" name="expense_category_id" required>
                        <option value="">Pilih Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-helper">Kategori membantu analisis pengeluaran</div>
                </div>

                <div class="form-group">
                    <label for="amount_idr">
                        Jumlah (IDR)<span class="required">*</span>
                    </label>
                    <input type="number" id="amount_idr" name="amount_idr" min="0" step="1000" required placeholder="0">
                    <div class="form-helper">Dana akan otomatis dipotong dari kas investor</div>
                </div>

                <div class="form-group">
                    <label for="expense_date">
                        Tanggal Pengeluaran<span class="required">*</span>
                    </label>
                    <input type="date" id="expense_date" name="expense_date" required>
                </div>

                <div class="form-group">
                    <label for="expense_time">
                        Waktu Pengeluaran
                    </label>
                    <input type="time" id="expense_time" name="expense_time">
                </div>

                <div class="form-group">
                    <label for="payment_method">
                        Metode Pembayaran
                    </label>
                    <select id="payment_method" name="payment_method">
                        <option value="cash">Tunai</option>
                        <option value="bank_transfer">Transfer Bank</option>
                        <option value="check">Cek</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="reference_no">
                        No. Referensi / Nota
                    </label>
                    <input type="text" id="reference_no" name="reference_no" placeholder="INV-2026-001">
                    <div class="form-helper">Optional: Nomor invoice atau nota</div>
                </div>

                <div class="form-group">
                    <label for="description">
                        Deskripsi<span class="required">*</span>
                    </label>
                    <textarea id="description" name="description" required placeholder="Jelaskan detail pengeluaran..."></textarea>
                    <div class="form-helper">Berikan deskripsi yang jelas</div>
                </div>

                <!-- Kas Besar Info in Form -->
                <div class="alert alert-info" style="margin-top: 1.5rem;">
                    <i data-feather="info"></i>
                    <div>
                        <strong>Kas Besar Tersedia:</strong><br>
                        Rp <?php echo number_format($total_available_balance, 0, ',', '.'); ?>
                        <br><small>Pengeluaran akan otomatis mengurangi saldo kas investor</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddExpenseModal()">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i data-feather="check"></i>
                        Simpan Pengeluaran
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php ob_start(); ?>
    // Initialize Feather Icons
    feather.replace();

        // Set today's date and time as default
        document.getElementById('expense_date').valueAsDate = new Date();
        const now = new Date();
        document.getElementById('expense_time').value = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');

        // Modal Functions
        function openAddExpenseModal(projectId = null) {
            if (projectId) {
                document.getElementById('project_id').value = projectId;
            }
            document.getElementById('addExpenseModal').classList.add('active');
            feather.replace();
        }

        function closeAddExpenseModal() {
            document.getElementById('addExpenseModal').classList.remove('active');
            document.getElementById('addExpenseForm').reset();
        }

        function addExpenseToProject(projectId) {
            openAddExpenseModal(projectId);
        }

        function viewProjectDetail(projectId) {
            // Navigate to project detail page (to be created)
            window.location.href = 'project-detail.php?id=' + projectId;
        }

        // Submit Add Expense
        async function submitAddExpense(event) {
            event.preventDefault();
            const form = document.getElementById('addExpenseForm');
            const btn = event.target.querySelector('button[type="submit"]');
            const btnText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i data-feather="loader" style="animation: spin 1s linear infinite;"></i> Menyimpan...';

            try {
                const formData = new FormData(form);
                const response = await fetch('<?php echo BASE_URL; ?>/api/project-add-expense.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                showAlert(data.message, data.success ? 'success' : 'error');

                if (data.success) {
                    closeAddExpenseModal();
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = btnText;
                feather.replace();
            }
        }

        // Show Alert
        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type;
            
            const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'x-circle' : 'info';
            alert.innerHTML = `<i data-feather="${icon}"></i><span>${message}</span>`;
            
            container.innerHTML = '';
            container.appendChild(alert);
            feather.replace();

            setTimeout(() => alert.remove(), 5000);
        }

        // Initialize Charts for each project
        <?php foreach ($projects as $proj): ?>
        <?php
        // Get expense breakdown for this project
        $stmt = $db->prepare("
            SELECT 
                pec.category_name,
                SUM(pe.amount_idr) as total
            FROM project_expenses pe
            JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
            WHERE pe.project_id = ?
            GROUP BY pec.id, pec.category_name
            ORDER BY total DESC
            LIMIT 5
        ");
        $stmt->execute([$proj['id']]);
        $expense_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($expense_breakdown)):
        ?>
        (function() {
            const ctx = document.getElementById('chart-<?php echo $proj['id']; ?>');
            if (ctx) {
                new Chart(ctx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode(array_column($expense_breakdown, 'category_name')); ?>,
                        datasets: [{
                            data: <?php echo json_encode(array_column($expense_breakdown, 'total')); ?>,
                            backgroundColor: ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981'],
                            borderColor: '#1e293b',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'bottom',
                                labels: {
                                    color: '#cbd5e1',
                                    font: { size: 10 },
                                    padding: 8,
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
            }
        })();
        <?php else: ?>
        (function() {
            const ctx = document.getElementById('chart-<?php echo $proj['id']; ?>');
            if (ctx) {
                const parent = ctx.parentElement;
                parent.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-size:0.875rem;">Belum ada data pengeluaran</div>';
            }
        })();
        <?php endif; ?>
        <?php endforeach; ?>

        // Refresh icons after DOM updates
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
        });

        // Add spin animation for loader
        const style = document.createElement('style');
        style.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
<?php 
$inlineScript = ob_get_clean();
include '../../includes/footer.php';
?>
