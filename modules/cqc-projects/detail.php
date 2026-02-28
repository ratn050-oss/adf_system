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

$pageTitle = htmlspecialchars($project['project_name']) . " - CQC Projects";
$pageSubtitle = "Detail Proyek Solar Panel";

include '../../includes/header.php';
?>

<style>
        /* CQC Detail Styles - Compact & Clean */
        .cqc-detail-header {
            background: #fff;
            padding: 10px 14px;
            border-radius: 5px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #f0b429;
        }

        .cqc-detail-header h1 { font-size: 14px; margin: 0 0 2px; color: #0d1f3c !important; font-weight: 700; }
        .cqc-project-meta { display: flex; gap: 14px; font-size: 11px; color: #64748b; }

        .cqc-detail-actions { display: flex; gap: 6px; }
        .cqc-detail-actions a, .cqc-detail-actions button {
            background: #0d1f3c; color: #fff; border: none; padding: 5px 12px;
            border-radius: 4px; cursor: pointer; text-decoration: none; font-weight: 600; font-size: 11px;
        }
        .cqc-detail-actions a:hover, .cqc-detail-actions button:hover { background: #122a4e; }

        .cqc-main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 10px; margin-bottom: 12px; }

        .cqc-card {
            background: #fff; border-radius: 5px; padding: 12px 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;
        }
        .cqc-card h3 {
            color: #0d1f3c; font-size: 13px; margin-bottom: 10px;
            padding-bottom: 6px; border-bottom: 2px solid #f0b429;
        }

        .cqc-status-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; }

        .status-badge { padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 11px; display: inline-block; }
        .status-planning { background: #e8f0fe; color: #0d1f3c; }
        .status-procurement { background: #fef3cd; color: #8a6d00; }
        .status-installation { background: #dbeafe; color: #0d1f3c; }
        .status-testing { background: #d1fae5; color: #065f46; }
        .status-completed { background: #a7f3d0; color: #064e3b; }

        .cqc-progress-section { margin-bottom: 12px; }
        .cqc-progress-bar { width: 100%; height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-bottom: 4px; }
        .cqc-progress-fill { height: 100%; background: linear-gradient(90deg, #0d1f3c, #f0b429); transition: width 0.3s ease; }
        .cqc-progress-text { display: flex; justify-content: space-between; font-size: 11px; color: #64748b; }

        .cqc-budget-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 10px; }
        .cqc-budget-item { border: 1px solid #e2e8f0; border-radius: 5px; padding: 8px 10px; text-align: center; }
        .cqc-budget-label { font-size: 10px; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.3px; }
        .cqc-budget-value { font-size: 13px; font-weight: 700; color: #0d1f3c; }
        .cqc-budget-item.warn .cqc-budget-value { color: #f0b429; }

        .cqc-info-block { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; }
        .cqc-info-label { font-size: 10px; color: #94a3b8; text-transform: uppercase; margin-bottom: 3px; font-weight: 600; letter-spacing: 0.3px; }
        .cqc-info-value { font-size: 12px; color: #334155; font-weight: 500; }

        .cqc-category-item { display: flex; align-items: center; justify-content: space-between; padding: 7px 4px; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
        .cqc-category-item:hover { background: #f8fafc; }
        .cqc-category-left { display: flex; align-items: center; gap: 8px; flex: 1; }
        .cqc-category-icon { font-size: 16px; }
        .cqc-category-info h4 { color: #334155; font-size: 12px; margin-bottom: 1px; }
        .cqc-category-info p { font-size: 10px; color: #94a3b8; }
        .cqc-category-amount .amount { font-size: 12px; font-weight: 700; color: #0d1f3c; }
        .cqc-category-amount .count { font-size: 10px; color: #94a3b8; }

        .cqc-expenses-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .cqc-expenses-table thead { background: #0d1f3c; }
        .cqc-expenses-table th { padding: 6px 8px; text-align: left; color: #fff; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
        .cqc-expenses-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; font-size: 11px; }

        .cqc-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); z-index: 9999; padding: 20px; }
        .cqc-modal.active { display: flex; align-items: center; justify-content: center; }
        .cqc-modal-content { background: #fff; border-radius: 6px; padding: 16px 20px; max-width: 420px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .cqc-modal-header { font-size: 14px; font-weight: 700; color: #0d1f3c; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #f0b429; }
        .cqc-form-group { margin-bottom: 10px; }
        .cqc-form-group label { display: block; font-weight: 600; color: #334155; margin-bottom: 4px; font-size: 11px; }
        .cqc-form-group input, .cqc-form-group select, .cqc-form-group textarea {
            width: 100%; padding: 6px 8px; border: 1px solid #e2e8f0; border-radius: 4px;
            font-family: inherit; font-size: 12px; background: #fff; color: #334155;
        }
        .cqc-form-group input:focus, .cqc-form-group select:focus, .cqc-form-group textarea:focus { outline: none; border-color: #f0b429; box-shadow: 0 0 0 2px rgba(240,180,41,0.15); }
        .cqc-modal-actions { display: flex; gap: 8px; margin-top: 14px; }
        .cqc-modal-actions button { flex: 1; padding: 7px; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 11px; }
        .cqc-btn-submit { background: #0d1f3c; color: white; }
        .cqc-btn-submit:hover { background: #122a4e; }
        .cqc-btn-cancel { background: #f1f5f9; color: #334155; }

        .cqc-alert { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; font-size: 11px; }

        @media (max-width: 768px) {
            .cqc-main-grid { grid-template-columns: 1fr; }
            .cqc-budget-grid { grid-template-columns: 1fr; }
            .cqc-detail-header { flex-direction: column; gap: 8px; }
        }
</style>

        <!-- Header -->
        <div class="cqc-detail-header">
            <div>
                <h1><?php echo htmlspecialchars($project['project_name']); ?></h1>
                <div class="cqc-project-meta">
                    <span>📍 <?php echo htmlspecialchars($project['location']); ?></span>
                    <span>📅 <?php echo date('d M Y', strtotime($project['start_date'])); ?> - <?php echo date('d M Y', strtotime($project['estimated_completion'] ?? $project['end_date'])); ?></span>
                </div>
            </div>
            <div class="cqc-detail-actions">
                <a href="add.php?id=<?php echo $project['id']; ?>">✏️ Edit</a>
                <a href="dashboard.php">← Kembali</a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="cqc-alert">
                ✅ <?php echo $_GET['success'] === 'expense_added' ? 'Pengeluaran berhasil ditambahkan!' : 'Perubahan berhasil disimpan!'; ?>
            </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="cqc-main-grid">
            <!-- Left Column -->
            <div>
                <!-- Project Overview -->
                <div class="cqc-card">
                    <h3>📊 Status & Progress</h3>
                    
                    <div class="cqc-status-bar">
                        <span class="status-badge status-<?php echo $project['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                        </span>
                    </div>

                    <div class="cqc-progress-section">
                        <strong style="color: #0d1f3c; font-size: 11px;">Progress Proyek</strong>
                        <div class="cqc-progress-bar">
                            <div class="cqc-progress-fill" style="width: <?php echo $project['progress_percentage']; ?>%"></div>
                        </div>
                        <div class="cqc-progress-text">
                            <span><?php echo $project['progress_percentage']; ?>% Selesai</span>
                            <span><?php echo 100 - $project['progress_percentage']; ?>% Tersisa</span>
                        </div>
                    </div>

                    <!-- Budget Info -->
                    <h3 style="margin-top: 12px;">💰 Budget & Pengeluaran</h3>
                    <div class="cqc-budget-grid">
                        <div class="cqc-budget-item">
                            <div class="cqc-budget-label">Budget Total</div>
                            <div class="cqc-budget-value">Rp <?php echo number_format($project['budget_idr'], 0); ?></div>
                        </div>
                        <div class="cqc-budget-item warn">
                            <div class="cqc-budget-label">Terpakai</div>
                            <div class="cqc-budget-value">Rp <?php echo number_format($project['spent_idr'] ?? 0, 0); ?></div>
                        </div>
                        <div class="cqc-budget-item">
                            <div class="cqc-budget-label">Sisa</div>
                            <div class="cqc-budget-value">Rp <?php echo number_format(($project['budget_idr'] - ($project['spent_idr'] ?? 0)), 0); ?></div>
                        </div>
                    </div>

                    <!-- Usage Percentage -->
                    <?php 
                        $usage = $project['budget_idr'] > 0 ? (($project['spent_idr'] ?? 0) / $project['budget_idr'] * 100) : 0;
                    ?>
                    <div class="cqc-progress-section">
                        <strong style="color: #0d1f3c; font-size: 11px;">Penggunaan Budget</strong>
                        <div class="cqc-progress-bar">
                            <div class="cqc-progress-fill" style="width: <?php echo min($usage, 100); ?>%; background: <?php echo $usage > 90 ? '#ef4444' : 'linear-gradient(90deg, #0d1f3c, #f0b429)'; ?>;"></div>
                        </div>
                        <div class="cqc-progress-text">
                            <span><?php echo number_format($usage, 1); ?>% Digunakan</span>
                            <span><?php echo number_format(max(0, 100 - $usage), 1); ?>% Tersedia</span>
                        </div>
                    </div>
                </div>

                <!-- Expenses by Category -->
                <div class="cqc-card" style="margin-top: 8px;">
                    <h3>📋 Pengeluaran per Kategori</h3>
                    <div>
                        <?php foreach ($categories as $cat): ?>
                            <div class="cqc-category-item">
                                <div class="cqc-category-left">
                                    <div class="cqc-category-icon"><?php echo htmlspecialchars($cat['category_icon'] ?? '📦'); ?></div>
                                    <div class="cqc-category-info">
                                        <h4><?php echo htmlspecialchars($cat['category_name']); ?></h4>
                                        <p><?php echo $cat['expense_count']; ?> transaksi</p>
                                    </div>
                                </div>
                                <div class="cqc-category-amount">
                                    <div class="amount">Rp <?php echo number_format($cat['total_amount'] ?? 0, 0); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Expenses -->
                <div class="cqc-card" style="margin-top: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h3 style="margin: 0;">📝 Pengeluaran Terbaru</h3>
                        <button style="background: #0d1f3c; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px;" onclick="openExpenseModal()">+ Tambah</button>
                    </div>
                    
                    <?php if (!empty($expenses)): ?>
                        <table class="cqc-expenses-table">
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
                                        <td style="color: #94a3b8; font-size: 10px;"><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($exp['description'] ?? '-'); ?></td>
                                        <td style="text-align: right; font-weight: 600; color: #0d1f3c;">Rp <?php echo number_format($exp['amount'], 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #94a3b8; padding: 16px 0; font-size: 11px;">Belum ada pengeluaran yang dicatat.</p>
                        <button style="width: 100%; background: #0d1f3c; color: white; border: none; padding: 8px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px;" onclick="openExpenseModal()">📝 Tambah Pengeluaran Pertama</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column (Sidebar) -->
            <div>
                <div class="cqc-card">
                    <h3>ℹ️ Detail Proyek</h3>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Kode Proyek</div>
                        <div class="cqc-info-value"><?php echo htmlspecialchars($project['project_code']); ?></div>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Klien</div>
                        <div class="cqc-info-value"><?php echo htmlspecialchars($project['client_name'] ?? '-'); ?></div>
                        <?php if ($project['client_phone']): ?>
                            <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">☎️ <?php echo htmlspecialchars($project['client_phone']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Kapasitas Panel</div>
                        <div class="cqc-info-value"><?php echo htmlspecialchars($project['solar_capacity_kwp'] ?? '-'); ?> KWp</div>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Jumlah Panel</div>
                        <div class="cqc-info-value"><?php echo htmlspecialchars($project['panel_count'] ?? '-'); ?> Unit</div>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Tipe Panel</div>
                        <div class="cqc-info-value"><?php echo htmlspecialchars($project['panel_type'] ?? '-'); ?></div>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Inverter</div>
                        <div class="cqc-info-value"><?php echo htmlspecialchars($project['inverter_type'] ?? '-'); ?></div>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Tanggal Mulai</div>
                        <div class="cqc-info-value"><?php echo date('d M Y', strtotime($project['start_date'])); ?></div>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Estimasi Selesai</div>
                        <div class="cqc-info-value"><?php echo date('d M Y', strtotime($project['estimated_completion'] ?? $project['end_date'])); ?></div>
                    </div>

                    <div class="cqc-info-block">
                        <div class="cqc-info-label">Dibuat</div>
                        <div class="cqc-info-value"><?php echo date('d M Y H:i', strtotime($project['created_at'])); ?></div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Add Expense Modal -->
    <div class="cqc-modal" id="expenseModal">
        <div class="cqc-modal-content">
            <div class="cqc-modal-header">➕ Tambah Pengeluaran</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_expense">

                <div class="cqc-form-group">
                    <label>Kategori Pengeluaran</label>
                    <select name="category_id" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars(($cat['category_icon'] ?? '') . ' ' . $cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cqc-form-group">
                    <label>Tanggal Pengeluaran</label>
                    <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="cqc-form-group">
                    <label>Jumlah (Rp)</label>
                    <input type="text" name="amount" placeholder="1000000" required>
                </div>

                <div class="cqc-form-group">
                    <label>Deskripsi/Keterangan</label>
                    <textarea name="description" placeholder="Detail pengeluaran..."></textarea>
                </div>

                <div class="cqc-modal-actions">
                    <button type="submit" class="cqc-btn-submit">✅ Simpan Pengeluaran</button>
                    <button type="button" class="cqc-btn-cancel" onclick="closeExpenseModal()">Batal</button>
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

<?php include '../../includes/footer.php'; ?>
