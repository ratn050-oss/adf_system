<?php
/**
 * CQC Projects - Detail & Expense Tracking
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('cqc-projects')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once 'db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Get project
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$_GET['id']]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    header('Location: dashboard.php');
    exit;
}

$projectId = $project['id'];

// Get expenses grouped by category
$stmt = $pdo->query("
    SELECT ec.id, ec.category_name, ec.category_icon, 
           COUNT(pe.id) as expense_count,
           SUM(pe.amount) as total_amount
    FROM cqc_expense_categories ec
    LEFT JOIN cqc_project_expenses pe ON ec.id = pe.category_id AND pe.project_id = $projectId
    WHERE ec.is_active = 1
    GROUP BY ec.id
    ORDER BY ec.id
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get latest expenses
$stmt = $pdo->prepare("
    SELECT pe.*, ec.category_name, ec.category_icon
    FROM cqc_project_expenses pe
    JOIN cqc_expense_categories ec ON pe.category_id = ec.id
    WHERE pe.project_id = ?
    ORDER BY pe.expense_date DESC
    LIMIT 10
");
$stmt->execute([$projectId]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle add expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO cqc_project_expenses 
            (project_id, category_id, expense_date, amount, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $projectId,
            $_POST['category_id'],
            $_POST['expense_date'],
            str_replace('.', '', $_POST['amount'] ?? 0),
            $_POST['description'] ?? '',
            $_SESSION['user_id']
        ]);
        
        // Update project spent amount
        $result = $pdo->query("SELECT SUM(amount) as total FROM cqc_project_expenses WHERE project_id = $projectId");
        $total = $result->fetch()['total'] ?? 0;
        
        $pdo->prepare("UPDATE cqc_projects SET spent_idr = ? WHERE id = ?")
            ->execute([$total, $projectId]);
        
        header('Location: detail.php?id=' . $projectId . '&success=expense_added');
        exit;
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Refresh project data to get updated spent amount
$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = htmlspecialchars($project['project_name']);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - CQC Projects</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .header-left h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .project-meta {
            display: flex;
            gap: 20px;
            font-size: 14px;
            opacity: 0.9;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-actions a, .header-actions button {
            background: #FFD700;
            color: #0066CC;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s ease;
        }

        .header-actions a:hover, .header-actions button:hover {
            background: #FFC700;
            transform: translateY(-2px);
        }

        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .card h3 {
            color: #0066CC;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #FFD700;
        }

        /* Status Bar */
        .status-bar {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
        }

        .status-planning {
            background: #e3f2fd;
            color: #0066CC;
        }

        .status-procurement {
            background: #fff3cd;
            color: #994500;
        }

        .status-installation {
            background: #d1ecff;
            color: #0066CC;
        }

        .status-testing {
            background: #c8e6c9;
            color: #2e7d32;
        }

        .status-completed {
            background: #a5d6a7;
            color: #1b5e20;
        }

        .progress-section {
            margin-bottom: 25px;
        }

        .progress-bar {
            width: 100%;
            height: 12px;
            background: #eee;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0066CC, #FFD700);
            width: 0%;
            transition: width 0.3s ease;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #666;
        }

        /* Budget Section */
        .budget-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .budget-item {
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .budget-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .budget-value {
            font-size: 18px;
            font-weight: bold;
            color: #0066CC;
        }

        .budget-item.warn .budget-value {
            color: #FFD700;
        }

        /* Sidebar */
        .sidebar .info-block {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .info-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .info-value {
            font-size: 14px;
            color: #333;
            font-weight: 500;
        }

        /* Expenses by Category */
        .expense-categories {
            margin-bottom: 30px;
        }

        .category-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .category-item:hover {
            background: #f9f9f9;
        }

        .category-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .category-icon {
            font-size: 24px;
        }

        .category-info h4 {
            color: #333;
            font-size: 14px;
            margin-bottom: 3px;
        }

        .category-info p {
            font-size: 12px;
            color: #999;
        }

        .category-amount {
            text-align: right;
        }

        .category-amount .amount {
            font-size: 14px;
            font-weight: bold;
            color: #0066CC;
        }

        .category-amount .count {
            font-size: 12px;
            color: #999;
        }

        /* Recent Expenses Table */
        .expenses-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .expenses-table thead {
            background: #f5f5f5;
        }

        .expenses-table th {
            padding: 12px;
            text-align: left;
            color: #666;
            font-weight: 600;
            border-bottom: 2px solid #e0e0e0;
        }

        .expenses-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .expense-date {
            color: #999;
            font-size: 12px;
        }

        /* Add Expense Modal/Form */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            padding: 20px;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            font-size: 20px;
            font-weight: bold;
            color: #0066CC;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            font-size: 14px;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #0066CC;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }

        .modal-actions button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-submit {
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            color: white;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 102, 204, 0.3);
        }

        .btn-cancel {
            background: #f0f0f0;
            color: #333;
        }

        .btn-cancel:hover {
            background: #e0e0e0;
        }

        /* Alert */
        .alert {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .main-grid {
                grid-template-columns: 1fr;
            }

            .budget-grid {
                grid-template-columns: 1fr;
            }

            .header-actions {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1><?php echo htmlspecialchars($project['project_name']); ?></h1>
                <div class="project-meta">
                    <span>📍 <?php echo htmlspecialchars($project['location']); ?></span>
                    <span>📅 <?php echo date('d M Y', strtotime($project['start_date'])); ?> - <?php echo date('d M Y', strtotime($project['estimated_completion'] ?? $project['end_date'])); ?></span>
                </div>
            </div>
            <div class="header-actions">
                <a href="add.php?id=<?php echo $project['id']; ?>">✏️ Edit</a>
                <a href="dashboard.php">← Kembali</a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert">
                ✅ <?php echo $_GET['success'] === 'expense_added' ? 'Pengeluaran berhasil ditambahkan!' : 'Perubahan berhasil disimpan!'; ?>
            </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="main-grid">
            <!-- Left Column -->
            <div>
                <!-- Project Overview -->
                <div class="card">
                    <h3>📊 Status & Progress</h3>
                    
                    <div class="status-bar">
                        <span class="status-badge status-<?php echo $project['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                        </span>
                    </div>

                    <div class="progress-section">
                        <strong style="color: #0066CC;">Progress Proyek</strong>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $project['progress_percentage']; ?>%"></div>
                        </div>
                        <div class="progress-text">
                            <span><?php echo $project['progress_percentage']; ?>% Selesai</span>
                            <span><?php echo 100 - $project['progress_percentage']; ?>% Tersisa</span>
                        </div>
                    </div>

                    <!-- Budget Info -->
                    <h3 style="margin-top: 25px;">💰 Budget & Pengeluaran</h3>
                    <div class="budget-grid">
                        <div class="budget-item">
                            <div class="budget-label">Budget Total</div>
                            <div class="budget-value">Rp <?php echo number_format($project['budget_idr'], 0); ?></div>
                        </div>
                        <div class="budget-item warn">
                            <div class="budget-label">Terpakai</div>
                            <div class="budget-value">Rp <?php echo number_format($project['spent_idr'] ?? 0, 0); ?></div>
                        </div>
                        <div class="budget-item">
                            <div class="budget-label">Sisa</div>
                            <div class="budget-value">Rp <?php echo number_format(($project['budget_idr'] - ($project['spent_idr'] ?? 0)), 0); ?></div>
                        </div>
                    </div>

                    <!-- Usage Percentage -->
                    <?php 
                        $usage = $project['budget_idr'] > 0 ? (($project['spent_idr'] ?? 0) / $project['budget_idr'] * 100) : 0;
                    ?>
                    <div class="progress-section">
                        <strong style="color: #0066CC;">Penggunaan Budget</strong>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min($usage, 100); ?>%; background: <?php echo $usage > 90 ? '#ff4444' : 'linear-gradient(90deg, #0066CC, #FFD700)'; ?>;"></div>
                        </div>
                        <div class="progress-text">
                            <span><?php echo number_format($usage, 1); ?>% Digunakan</span>
                            <span><?php echo number_format(max(0, 100 - $usage), 1); ?>% Tersedia</span>
                        </div>
                    </div>
                </div>

                <!-- Expenses by Category -->
                <div class="card" style="margin-top: 20px;">
                    <h3>📋 Pengeluaran per Kategori</h3>
                    <div class="expense-categories">
                        <?php foreach ($categories as $cat): ?>
                            <div class="category-item" onclick="expandCategory(this)">
                                <div class="category-left">
                                    <div class="category-icon"><?php echo htmlspecialchars($cat['category_icon'] ?? '📦'); ?></div>
                                    <div class="category-info">
                                        <h4><?php echo htmlspecialchars($cat['category_name']); ?></h4>
                                        <p><?php echo $cat['expense_count']; ?> transaksi</p>
                                    </div>
                                </div>
                                <div class="category-amount">
                                    <div class="amount">Rp <?php echo number_format($cat['total_amount'] ?? 0, 0); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Expenses -->
                <div class="card" style="margin-top: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0;">📝 Pengeluaran Terbaru</h3>
                        <button style="background: #0066CC; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600;" onclick="openExpenseModal()">+ Tambah</button>
                    </div>
                    
                    <?php if (!empty($expenses)): ?>
                        <table class="expenses-table">
                            <thead>
                                <tr>
                                    <th>Kategori</th>
                                    <th>Tanggal</th>
                                    <th>Deskripsi</th>
                                    <th style="text-align: right;">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses as $exp): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(($exp['category_icon'] ?? '') . ' ' . $exp['category_name']); ?></td>
                                        <td class="expense-date"><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($exp['description'] ?? '-'); ?></td>
                                        <td style="text-align: right; font-weight: 600; color: #0066CC;">Rp <?php echo number_format($exp['amount'], 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #999; padding: 30px 0;">Belum ada pengeluaran yang dicatat.</p>
                        <button style="width: 100%; background: #0066CC; color: white; border: none; padding: 12px; border-radius: 6px; cursor: pointer; font-weight: 600; margin-top: 20px;" onclick="openExpenseModal()">📝 Tambah Pengeluaran Pertama</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column (Sidebar) -->
            <div>
                <div class="card">
                    <h3>ℹ️ Detail Proyek</h3>

                    <div class="info-block">
                        <div class="info-label">Kode Proyek</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['project_code']); ?></div>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Klien</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['client_name'] ?? '-'); ?></div>
                        <?php if ($project['client_phone']): ?>
                            <div style="font-size: 12px; color: #999; margin-top: 4px;">☎️ <?php echo htmlspecialchars($project['client_phone']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Kapasitas Panel</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['solar_capacity_kwp'] ?? '-'); ?> KWp</div>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Jumlah Panel</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['panel_count'] ?? '-'); ?> Unit</div>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Tipe Panel</div>
                        <div class="info-value" style="font-size: 13px;"><?php echo htmlspecialchars($project['panel_type'] ?? '-'); ?></div>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Inverter</div>
                        <div class="info-value" style="font-size: 13px;"><?php echo htmlspecialchars($project['inverter_type'] ?? '-'); ?></div>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Tanggal Mulai</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($project['start_date'])); ?></div>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Estimasi Selesai</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($project['estimated_completion'] ?? $project['end_date'])); ?></div>
                    </div>

                    <div class="info-block">
                        <div class="info-label">Dibuat</div>
                        <div class="info-value"><?php echo date('d M Y H:i', strtotime($project['created_at'])); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal" id="expenseModal">
        <div class="modal-content">
            <div class="modal-header">➕ Tambah Pengeluaran</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_expense">

                <div class="form-group">
                    <label>Kategori Pengeluaran</label>
                    <select name="category_id" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars(($cat['category_icon'] ?? '') . ' ' . $cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Tanggal Pengeluaran</label>
                    <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label>Jumlah (Rp)</label>
                    <input type="text" name="amount" placeholder="1000000" required>
                </div>

                <div class="form-group">
                    <label>Deskripsi/Keterangan</label>
                    <textarea name="description" placeholder="Detail pengeluaran..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn-submit">✅ Simpan Pengeluaran</button>
                    <button type="button" class="btn-cancel" onclick="closeExpenseModal()">Batal</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openExpenseModal() {
            document.getElementById('expenseModal').classList.add('active');
        }

        function closeExpenseModal() {
            document.getElementById('expenseModal').classList.remove('active');
        }

        function expandCategory(element) {
            // Simple toggle for future expansion
            element.style.background = element.style.background === 'rgb(249, 249, 249)' ? 'white' : '#f9f9f9';
        }

        // Format amount input
        document.querySelector('input[name="amount"]').addEventListener('change', function() {
            const value = this.value.replace(/\D/g, '');
            this.value = value ? new Intl.NumberFormat('id-ID').format(value) : '';
        });

        // Close modal when clicking outside
        document.getElementById('expenseModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeExpenseModal();
            }
        });
    </script>
</body>
</html>
