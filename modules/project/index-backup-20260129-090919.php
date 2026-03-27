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

// Get all projects
$projects = $project_manager->getAllProjects();
$categories = $project_manager->getExpenseCategories();

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
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modul Project - Manajemen Pengeluaran</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .project-container {
            padding: 2rem;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .card-header {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
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

        .project-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-secondary);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .project-table thead {
            background: var(--bg-tertiary);
            border-bottom: 2px solid var(--border-color);
        }

        .project-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
        }

        .project-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .project-table tbody tr:hover {
            background: var(--bg-tertiary);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .status-planning {
            background: rgba(156, 163, 175, 0.1);
            color: #9ca3af;
        }

        .status-completed {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .status-cancelled {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        .btn-small {
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .header-section h1 {
            margin: 0;
            font-size: 2rem;
        }

        .chart-container {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .chart-wrapper {
            position: relative;
            height: 400px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--bg-secondary);
            border-radius: 8px;
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
        }

        .modal-header h2 {
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-muted);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 1rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--bg-tertiary);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header-simple.php'; ?>

    <main class="main-content">
        <div class="project-container">
            <!-- Header Section -->
            <div class="header-section">
                <h1>📋 Manajemen Project</h1>
                <button class="btn btn-primary" onclick="openAddProjectModal()">
                    <i data-feather="plus" style="display: inline; margin-right: 0.5rem; width: 18px; height: 18px;"></i>
                    Tambah Project
                </button>
            </div>

            <!-- Alert Messages -->
            <div id="alertContainer"></div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-header">Total Pengeluaran</div>
                    <div class="card-value">Rp <?php echo number_format($total_expenses, 0, ',', '.'); ?></div>
                    <div class="card-subtext">Semua project yang disetujui</div>
                </div>

                <div class="card">
                    <div class="card-header">Total Budget</div>
                    <div class="card-value">Rp <?php echo number_format($total_budget, 0, ',', '.'); ?></div>
                    <div class="card-subtext">Budget yang dialokasikan</div>
                </div>

                <div class="card">
                    <div class="card-header">Project Aktif</div>
                    <div class="card-value"><?php echo $active_projects; ?></div>
                    <div class="card-subtext">Sedang berjalan</div>
                </div>

                <div class="card">
                    <div class="card-header">Total Project</div>
                    <div class="card-value"><?php echo count($projects); ?></div>
                    <div class="card-subtext">Semua project</div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="chart-container">
                <h3 style="margin-top: 0;">📊 Pengeluaran Per Project</h3>
                <div class="chart-wrapper">
                    <canvas id="expenseChart"></canvas>
                </div>
            </div>

            <!-- Projects Table -->
            <div style="margin-bottom: 2rem;">
                <h2 style="margin-bottom: 1rem;">Daftar Project</h2>
                <?php if (count($projects) > 0): ?>
                    <table class="project-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama Project</th>
                                <th>Tanggal Mulai</th>
                                <th>Pengeluaran</th>
                                <th>Budget</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $proj): 
                                $progress = ($proj['budget'] ?? 0) > 0 ? (($proj['total_expenses'] ?? 0) / $proj['budget'] * 100) : 0;
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $proj['id']; ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($proj['name']); ?></strong><br>
                                        <small style="color: var(--text-muted);"><?php echo htmlspecialchars($proj['description'] ?? '-'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($proj['start_date'] ?? '-'); ?></td>
                                    <td>
                                        <strong>Rp <?php echo number_format($proj['total_expenses'] ?? 0, 0, ',', '.'); ?></strong>
                                        <?php if ($proj['budget']): ?>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min($progress, 100); ?>%"></div>
                                            </div>
                                            <small><?php echo number_format($progress, 1); ?>%</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>Rp <?php echo number_format($proj['budget'] ?? 0, 0, ',', '.'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $proj['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-secondary btn-small" onclick="viewProject(<?php echo $proj['id']; ?>)">
                                                <i data-feather="eye" style="width: 14px; height: 14px;"></i>
                                            </button>
                                            <button class="btn btn-secondary btn-small" onclick="addExpense(<?php echo $proj['id']; ?>)">
                                                <i data-feather="plus" style="width: 14px; height: 14px;"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="card" style="text-align: center; padding: 3rem;">
                        <p style="color: var(--text-muted); margin: 0;">Belum ada project. Klik tombol "Tambah Project" untuk memulai.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add Project Modal -->
    <div id="addProjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Tambah Project Baru</h2>
                <button class="close-btn" onclick="closeAddProjectModal()">✕</button>
            </div>
            <form id="addProjectForm" onsubmit="submitAddProject(event)">
                <div class="form-group">
                    <label for="project_code">Kode Project *</label>
                    <input type="text" id="project_code" name="project_code" required placeholder="e.g., PRJ001">
                </div>

                <div class="form-group">
                    <label for="project_name">Nama Project *</label>
                    <input type="text" id="project_name" name="project_name" required>
                </div>

                <div class="form-group">
                    <label for="location">Lokasi</label>
                    <input type="text" id="location" name="location">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Tanggal Mulai</label>
                        <input type="date" id="start_date" name="start_date">
                    </div>
                    <div class="form-group">
                        <label for="end_date">Tanggal Selesai</label>
                        <input type="date" id="end_date" name="end_date">
                    </div>
                </div>

                <div class="form-group">
                    <label for="budget_idr">Budget (IDR)</label>
                    <input type="number" id="budget_idr" name="budget_idr" step="1000">
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="planning">Planning</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="description">Deskripsi</label>
                    <textarea id="description" name="description"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddProjectModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Project</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div id="addExpenseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Tambah Pengeluaran Project</h2>
                <button class="close-btn" onclick="closeAddExpenseModal()">✕</button>
            </div>
            <form id="addExpenseForm" onsubmit="submitAddExpense(event)">
                <input type="hidden" id="project_id_hidden" name="project_id">

                <div class="form-group">
                    <label for="expense_category_id">Kategori Pengeluaran *</label>
                    <select id="expense_category_id" name="expense_category_id" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="expense_date">Tanggal Pengeluaran *</label>
                        <input type="date" id="expense_date" name="expense_date" required>
                    </div>
                    <div class="form-group">
                        <label for="expense_time">Waktu</label>
                        <input type="time" id="expense_time" name="expense_time">
                    </div>
                </div>

                <div class="form-group">
                    <label for="amount_idr">Jumlah (IDR) *</label>
                    <input type="number" id="amount_idr" name="amount_idr" step="1000" required>
                </div>

                <div class="form-group">
                    <label for="payment_method">Metode Pembayaran</label>
                    <select id="payment_method" name="payment_method">
                        <option value="cash">Tunai</option>
                        <option value="bank_transfer">Transfer Bank</option>
                        <option value="check">Cek</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="reference_no">No. Referensi</label>
                    <input type="text" id="reference_no" name="reference_no">
                </div>

                <div class="form-group">
                    <label for="expense_description">Deskripsi Pengeluaran</label>
                    <textarea id="expense_description" name="description"></textarea>
                </div>

                <div class="form-group">
                    <label for="expense_status">Status Pengeluaran</label>
                    <select id="expense_status" name="status">
                        <option value="draft">Draft (Simpan Dulu)</option>
                        <option value="submitted">Submitted (Pengajuan)</option>
                        <option value="approved">Approved (Langsung Potong Saldo)</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddExpenseModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Pengeluaran</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../../../assets/js/feather.min.js"></script>
    <script>
        // Set today's date as default
        document.getElementById('expense_date').valueAsDate = new Date();
        document.getElementById('expense_time').value = new Date().toTimeString().slice(0, 5);

        // Modal functions
        function openAddProjectModal() {
            document.getElementById('addProjectModal').classList.add('active');
        }

        function closeAddProjectModal() {
            document.getElementById('addProjectModal').classList.remove('active');
            document.getElementById('addProjectForm').reset();
        }

        function addExpense(projectId) {
            document.getElementById('project_id_hidden').value = projectId;
            document.getElementById('addExpenseModal').classList.add('active');
        }

        function closeAddExpenseModal() {
            document.getElementById('addExpenseModal').classList.remove('active');
            document.getElementById('addExpenseForm').reset();
        }

        // Submit add project
        async function submitAddProject(event) {
            event.preventDefault();
            const form = document.getElementById('addProjectForm');
            const btn = event.target.querySelector('button[type="submit"]');
            btn.disabled = true;

            try {
                const formData = new FormData(form);
                const response = await fetch('<?php echo BASE_URL; ?>/api/project-create.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                showAlert(data.message, data.success ? 'success' : 'error');

                if (data.success) {
                    closeAddProjectModal();
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        // Submit add expense
        async function submitAddExpense(event) {
            event.preventDefault();
            const form = document.getElementById('addExpenseForm');
            const btn = event.target.querySelector('button[type="submit"]');
            btn.disabled = true;

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
            }
        }

        // View project
        function viewProject(projectId) {
            window.location.href = '<?php echo BASE_URL; ?>/modules/project/project-detail.php?id=' + projectId;
        }

        // Show alert
        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type;
            alert.textContent = message;
            container.innerHTML = '';
            container.appendChild(alert);

            setTimeout(() => alert.remove(), 5000);
        }

        // Initialize Chart.js
        const ctx = document.getElementById('expenseChart').getContext('2d');
        const chartData = <?php
            $labels = [];
            $data = [];
            foreach ($projects as $proj) {
                $labels[] = '#' . $proj['id'] . ' - ' . substr($proj['name'], 0, 20);
                $data[] = $proj['total_expenses'] ?? 0;
            }
            echo json_encode([
                'labels' => $labels,
                'data' => $data
            ]);
        ?>;

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Total Pengeluaran (IDR)',
                    data: chartData.data,
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.7)',
                        'rgba(139, 92, 246, 0.7)',
                        'rgba(34, 197, 94, 0.7)',
                        'rgba(245, 158, 11, 0.7)',
                        'rgba(239, 68, 68, 0.7)',
                        'rgba(34, 211, 238, 0.7)'
                    ],
                    borderColor: [
                        'rgb(59, 130, 246)',
                        'rgb(139, 92, 246)',
                        'rgb(34, 197, 94)',
                        'rgb(245, 158, 11)',
                        'rgb(239, 68, 68)',
                        'rgb(34, 211, 238)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });

        // Initialize feather icons
        feather.replace();
    </script>
</body>
</html>
?>
