<?php
/**
 * INVESTOR LEDGER - Buku Kas + Gaji Pekerja + Pengeluaran Divisi
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
} else {
    $base_url = BASE_URL;
}

$db = Database::getInstance()->getConnection();

// ====== AUTO-CREATE TABLES ======
$tables_sql = [
    "CREATE TABLE IF NOT EXISTS project_workers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        role VARCHAR(100) DEFAULT 'Tukang',
        daily_rate DECIMAL(15,2) DEFAULT 0,
        phone VARCHAR(20),
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS project_salaries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        worker_id INT NOT NULL,
        period_type ENUM('weekly','monthly') DEFAULT 'weekly',
        period_label VARCHAR(50),
        daily_rate DECIMAL(15,2) DEFAULT 0,
        overtime_per_day DECIMAL(15,2) DEFAULT 0,
        other_per_day DECIMAL(15,2) DEFAULT 0,
        total_days INT DEFAULT 0,
        total_salary DECIMAL(15,2) DEFAULT 0,
        status ENUM('draft','submitted','approved','paid') DEFAULT 'draft',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT
    )",
    "CREATE TABLE IF NOT EXISTS project_division_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        division_name VARCHAR(100) NOT NULL,
        contractor_name VARCHAR(100),
        description TEXT,
        amount DECIMAL(15,2) NOT NULL,
        expense_date DATE,
        receipt_file VARCHAR(255),
        status ENUM('pending','approved','paid') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT
    )"
];
foreach ($tables_sql as $sql) {
    try { $db->exec($sql); } catch (Exception $e) { /* table exists */ }
}

// ====== HELPER: Detect columns ======
function getTableColumns($db, $table) {
    try {
        $stmt = $db->query("DESCRIBE `$table`");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Exception $e) {
        return [];
    }
}

function buildProjectNameCol($columns) {
    $col = 'COALESCE(';
    if (in_array('project_name', $columns)) $col .= 'project_name, ';
    if (in_array('name', $columns)) $col .= 'name, ';
    return $col . "'Project') as project_name";
}

function buildBudgetCol($columns) {
    $col = 'COALESCE(';
    if (in_array('budget_idr', $columns)) $col .= 'budget_idr, ';
    if (in_array('budget', $columns)) $col .= 'budget, ';
    return $col . '0) as budget_idr';
}

// ====== LOAD DATA ======
$project_id = intval($_GET['project_id'] ?? 0);
$tab = $_GET['tab'] ?? 'expenses';
$project = null;
$expenses = [];
$workers = [];
$salaries = [];
$division_expenses = [];
$projCols = getTableColumns($db, 'projects');

// Get selected project
if ($project_id) {
    try {
        $name_col = buildProjectNameCol($projCols);
        $budget_col = buildBudgetCol($projCols);
        $stmt = $db->prepare("SELECT id, $name_col, $budget_col, created_at FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($project) {
            // Expenses
            try {
                $expCols = getTableColumns($db, 'project_expenses');
                $sel = ['id','project_id','amount'];
                if (in_array('description', $expCols)) $sel[] = 'description';
                if (in_array('expense_date', $expCols)) $sel[] = 'expense_date';
                if (in_array('created_at', $expCols)) $sel[] = 'created_at';
                $stmt = $db->prepare("SELECT " . implode(',', $sel) . " FROM project_expenses WHERE project_id = ? ORDER BY id DESC");
                $stmt->execute([$project_id]);
                $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $expenses = []; }

            $total_expenses = array_sum(array_column($expenses, 'amount'));
            $project['total_expenses'] = $total_expenses;

            // Workers
            try {
                $stmt = $db->prepare("SELECT * FROM project_workers WHERE project_id = ? ORDER BY name");
                $stmt->execute([$project_id]);
                $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $workers = []; }

            // Salaries
            try {
                $stmt = $db->prepare("
                    SELECT s.*, w.name as worker_name, w.role as worker_role
                    FROM project_salaries s
                    LEFT JOIN project_workers w ON s.worker_id = w.id
                    WHERE s.project_id = ?
                    ORDER BY s.created_at DESC
                ");
                $stmt->execute([$project_id]);
                $salaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $salaries = []; }

            // Division Expenses
            try {
                $stmt = $db->prepare("SELECT * FROM project_division_expenses WHERE project_id = ? ORDER BY created_at DESC");
                $stmt->execute([$project_id]);
                $division_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $division_expenses = []; }
        }
    } catch (Exception $e) {
        error_log('Ledger error: ' . $e->getMessage());
    }
}

// Get all projects
try {
    $name_col = buildProjectNameCol($projCols);
    $budget_col = buildBudgetCol($projCols);
    $stmt = $db->query("SELECT id, $name_col, $budget_col FROM projects ORDER BY id DESC LIMIT 100");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $projects = []; }

$total_gaji = array_sum(array_column($salaries, 'total_salary'));
$total_divisi = array_sum(array_column($division_expenses, 'amount'));

$pageTitle = 'Buku Kas - Investor';
include $base_path . '/includes/header.php';
?>

<style>
:root { --primary: #6366f1; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --blue: #3b82f6; }
.ledger-page { padding: 1.5rem; max-width: 1500px; margin: 0 auto; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid rgba(99,102,241,0.1); }
.page-header h1 { font-size: 1.6rem; font-weight: 700; background: linear-gradient(135deg, #6366f1, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.btn { padding: 0.6rem 1.2rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; text-decoration: none; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-primary { background: linear-gradient(135deg, var(--primary), #8b5cf6); color: #fff; }
.btn-success { background: linear-gradient(135deg, var(--success), #059669); color: #fff; }
.btn-blue { background: linear-gradient(135deg, var(--blue), #2563eb); color: #fff; }
.btn-warning { background: linear-gradient(135deg, var(--warning), #d97706); color: #fff; }
.btn-danger { background: linear-gradient(135deg, var(--danger), #dc2626); color: #fff; }
.btn-sm { padding: 0.4rem 0.8rem; font-size: 0.75rem; border-radius: 6px; }
.btn-delete { background: var(--danger); color: #fff; padding: 0.3rem 0.7rem; border-radius: 5px; font-size: 0.7rem; cursor: pointer; border: none; }
.main-layout { display: grid; grid-template-columns: 260px 1fr; gap: 1.5rem; }
.sidebar { background: var(--bg-secondary, #fff); border: 1px solid var(--border-color, #e5e7eb); border-radius: 12px; padding: 1rem; height: fit-content; position: sticky; top: 80px; }
.sidebar h3 { font-size: 0.9rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-primary, #111); }
.proj-item { padding: 0.7rem; border-radius: 8px; cursor: pointer; margin-bottom: 0.5rem; transition: all 0.2s; border-left: 3px solid transparent; }
.proj-item:hover { background: rgba(99,102,241,0.06); border-left-color: var(--primary); }
.proj-item.active { background: rgba(99,102,241,0.12); border-left-color: var(--primary); font-weight: 600; }
.proj-item .pname { font-size: 0.85rem; color: var(--text-primary, #111); }
.proj-item .pbudget { font-size: 0.7rem; color: var(--text-muted, #888); margin-top: 2px; }
.content { min-width: 0; }
.tabs { display: flex; gap: 0; margin-bottom: 1.5rem; background: var(--bg-secondary, #fff); border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color, #e5e7eb); }
.tab { flex: 1; padding: 0.75rem; text-align: center; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s; color: var(--text-muted, #888); border-bottom: 2px solid transparent; text-decoration: none; }
.tab:hover { background: rgba(99,102,241,0.05); color: var(--primary); }
.tab.active { color: var(--primary); background: rgba(99,102,241,0.08); border-bottom-color: var(--primary); }
.summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
.s-card { background: var(--bg-secondary, #fff); border: 1px solid var(--border-color, #e5e7eb); border-radius: 10px; padding: 1rem; border-left: 3px solid var(--primary); }
.s-card .label { font-size: 0.7rem; color: var(--text-muted, #888); text-transform: uppercase; font-weight: 600; }
.s-card .val { font-size: 1.15rem; font-weight: 700; color: var(--text-primary, #111); margin-top: 0.3rem; }
.s-card.green { border-left-color: var(--success); } .s-card.orange { border-left-color: var(--warning); }
.s-card.red { border-left-color: var(--danger); } .s-card.blue { border-left-color: var(--blue); }
.form-card { background: var(--bg-secondary, #fff); border: 1px solid var(--border-color, #e5e7eb); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; }
.form-card h3 { font-size: 1rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-primary, #111); display: flex; align-items: center; gap: 0.5rem; }
.form-row { display: grid; gap: 0.75rem; margin-bottom: 0.75rem; align-items: end; }
.form-row.cols-4 { grid-template-columns: repeat(4, 1fr); } .form-row.cols-5 { grid-template-columns: 2fr 1fr 1fr 1fr auto; }
.form-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; } .form-row.cols-6 { grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr auto; }
.fg { display: flex; flex-direction: column; }
.fg label { font-size: 0.75rem; font-weight: 600; margin-bottom: 0.3rem; color: var(--text-muted, #888); }
.fg input, .fg select, .fg textarea { padding: 0.6rem; border: 1.5px solid var(--border-color, #e5e7eb); border-radius: 7px; background: var(--bg-primary, #fff); color: var(--text-primary, #111); font-size: 0.85rem; }
.fg input:focus, .fg select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 0.7rem; text-align: left; border-bottom: 1px solid var(--border-color, #e5e7eb); font-size: 0.82rem; }
.data-table th { background: rgba(99,102,241,0.05); font-weight: 700; color: var(--text-muted, #888); text-transform: uppercase; font-size: 0.7rem; }
.data-table .amt { font-weight: 700; color: var(--warning); }
.data-table .total-row { background: rgba(99,102,241,0.05); font-weight: 700; }
.data-table .total-row td { border-top: 2px solid var(--primary); }
.empty-msg { text-align: center; padding: 2rem; color: var(--text-muted, #888); font-size: 0.85rem; }
.badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; }
.badge-draft { background: #fef3c7; color: #92400e; } .badge-submitted { background: #dbeafe; color: #1e40af; }
.badge-approved { background: #d1fae5; color: #065f46; } .badge-paid { background: #e0e7ff; color: #3730a3; }
.badge-pending { background: #fef3c7; color: #92400e; } .badge-active { background: #d1fae5; color: #065f46; }
.actions-bar { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
@media print { .sidebar, .tabs, .page-header, .form-card, .btn, .btn-delete, .actions-bar, .no-print { display: none !important; } .main-layout { grid-template-columns: 1fr !important; } .data-table th, .data-table td { padding: 0.4rem; font-size: 0.75rem; } .s-card { border: 1px solid #ccc; } }
@media (max-width: 768px) { .main-layout { grid-template-columns: 1fr; } .summary-row { grid-template-columns: repeat(2, 1fr); } .form-row.cols-4, .form-row.cols-5, .form-row.cols-6 { grid-template-columns: 1fr; } }
</style>

<div class="ledger-page">
    <div class="page-header">
        <h1>💰 Buku Kas Projek</h1>
        <div style="display:flex;gap:0.5rem">
            <a href="<?= BASE_URL ?>/modules/investor/" class="btn btn-primary">← Kembali</a>
        </div>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-msg">
            <p>Belum ada projek. <a href="<?= BASE_URL ?>/modules/investor/" style="color:var(--primary)">Buat projek terlebih dahulu.</a></p>
        </div>
    <?php else: ?>
    <div class="main-layout">
        <!-- SIDEBAR -->
        <div class="sidebar">
            <h3>📋 Pilih Projek</h3>
            <?php foreach ($projects as $p): ?>
            <div class="proj-item <?= $project_id == $p['id'] ? 'active' : '' ?>" onclick="location.href='?project_id=<?= $p['id'] ?>&tab=<?= $tab ?>'">
                <div class="pname"><?= htmlspecialchars($p['project_name']) ?></div>
                <div class="pbudget">Rp <?= number_format($p['budget_idr'] ?? 0, 0, ',', '.') ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- CONTENT -->
        <div class="content">
            <?php if (!$project): ?>
                <div class="empty-msg">👈 Pilih projek dari daftar di samping</div>
            <?php else: ?>
                <h2 style="font-size:1.3rem;font-weight:700;margin-bottom:1rem"><?= htmlspecialchars($project['project_name']) ?></h2>

                <!-- TABS -->
                <div class="tabs no-print">
                    <a class="tab <?= $tab == 'expenses' ? 'active' : '' ?>" href="?project_id=<?= $project_id ?>&tab=expenses">💸 Pengeluaran</a>
                    <a class="tab <?= $tab == 'workers' ? 'active' : '' ?>" href="?project_id=<?= $project_id ?>&tab=workers">👷 Data Pekerja</a>
                    <a class="tab <?= $tab == 'salary' ? 'active' : '' ?>" href="?project_id=<?= $project_id ?>&tab=salary">💰 Gaji Tukang</a>
                    <a class="tab <?= $tab == 'division' ? 'active' : '' ?>" href="?project_id=<?= $project_id ?>&tab=division">🏗️ Divisi/Kontraktor</a>
                </div>

                <!-- SUMMARY -->
                <div class="summary-row">
                    <div class="s-card blue"><div class="label">Budget</div><div class="val">Rp <?= number_format($project['budget_idr'] ?? 0, 0, ',', '.') ?></div></div>
                    <div class="s-card orange"><div class="label">Pengeluaran</div><div class="val">Rp <?= number_format($project['total_expenses'] ?? 0, 0, ',', '.') ?></div></div>
                    <div class="s-card red"><div class="label">Gaji + Divisi</div><div class="val">Rp <?= number_format($total_gaji + $total_divisi, 0, ',', '.') ?></div></div>
                    <div class="s-card green"><div class="label">Sisa Budget</div><div class="val">Rp <?= number_format(($project['budget_idr'] ?? 0) - ($project['total_expenses'] ?? 0) - $total_gaji - $total_divisi, 0, ',', '.') ?></div></div>
                </div>

                <!-- ============ TAB: PENGELUARAN ============ -->
                <?php if ($tab == 'expenses'): ?>
                <div class="form-card no-print">
                    <h3>📝 Catat Pengeluaran</h3>
                    <form id="expenseForm" onsubmit="saveExpense(event)">
                        <div class="form-row cols-5">
                            <div class="fg"><label>Deskripsi</label><input type="text" name="description" required placeholder="Nama item/pengeluaran"></div>
                            <div class="fg"><label>Jumlah (Rp)</label><input type="number" name="amount" required min="1" placeholder="0"></div>
                            <div class="fg"><label>Tanggal</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
                            <div class="fg"><label>&nbsp;</label><button type="submit" class="btn btn-success btn-sm">+ Catat</button></div>
                        </div>
                    </form>
                </div>

                <div class="form-card">
                    <div class="actions-bar no-print">
                        <button onclick="window.print()" class="btn btn-blue btn-sm">🖨️ Print PDF</button>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>No</th><th>Tanggal</th><th>Deskripsi</th><th>Jumlah</th><th class="no-print">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr><td colspan="5" class="empty-msg">Belum ada pengeluaran</td></tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $i => $e): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= date('d/m/Y', strtotime($e['expense_date'] ?? $e['created_at'] ?? 'now')) ?></td>
                                <td><?= htmlspecialchars($e['description'] ?? '-') ?></td>
                                <td class="amt">Rp <?= number_format($e['amount'] ?? 0, 0, ',', '.') ?></td>
                                <td class="no-print"><button class="btn-delete" onclick="deleteExpense(<?= $e['id'] ?>)">Hapus</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row"><td colspan="3"><strong>TOTAL</strong></td><td class="amt">Rp <?= number_format($project['total_expenses'], 0, ',', '.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ============ TAB: DATA PEKERJA ============ -->
                <?php elseif ($tab == 'workers'): ?>
                <div class="form-card no-print">
                    <h3>👷 Tambah Pekerja</h3>
                    <form id="workerForm" onsubmit="saveWorker(event)">
                        <div class="form-row cols-4">
                            <div class="fg"><label>Nama Pekerja</label><input type="text" name="name" required placeholder="Nama lengkap"></div>
                            <div class="fg"><label>Jabatan/Role</label>
                                <select name="role">
                                    <option value="Tukang">Tukang</option>
                                    <option value="Kepala Tukang">Kepala Tukang</option>
                                    <option value="Kuli">Kuli</option>
                                    <option value="Mandor">Mandor</option>
                                    <option value="Tukang Listrik">Tukang Listrik</option>
                                    <option value="Tukang Cat">Tukang Cat</option>
                                    <option value="Tukang Las">Tukang Las</option>
                                    <option value="Helper">Helper</option>
                                </select>
                            </div>
                            <div class="fg"><label>Upah Harian (Rp)</label><input type="number" name="daily_rate" placeholder="150000" min="0"></div>
                            <div class="fg"><label>No. HP</label><input type="text" name="phone" placeholder="08xx"></div>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm" style="margin-top:0.5rem">+ Tambah Pekerja</button>
                    </form>
                </div>

                <div class="form-card">
                    <div class="actions-bar no-print">
                        <button onclick="window.print()" class="btn btn-blue btn-sm">🖨️ Print PDF</button>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>No</th><th>Nama</th><th>Jabatan</th><th>Upah/Hari</th><th>HP</th><th>Status</th><th class="no-print">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($workers)): ?>
                            <tr><td colspan="7" class="empty-msg">Belum ada data pekerja</td></tr>
                        <?php else: ?>
                            <?php foreach ($workers as $i => $w): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($w['name']) ?></strong></td>
                                <td><?= htmlspecialchars($w['role'] ?? 'Tukang') ?></td>
                                <td class="amt">Rp <?= number_format($w['daily_rate'] ?? 0, 0, ',', '.') ?></td>
                                <td><?= htmlspecialchars($w['phone'] ?? '-') ?></td>
                                <td><span class="badge badge-<?= $w['status'] ?? 'active' ?>"><?= $w['status'] ?? 'active' ?></span></td>
                                <td class="no-print"><button class="btn-delete" onclick="deleteWorker(<?= $w['id'] ?>)">Hapus</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ============ TAB: GAJI TUKANG ============ -->
                <?php elseif ($tab == 'salary'): ?>
                <div class="form-card no-print">
                    <h3>💰 Hitung & Catat Gaji</h3>
                    <p style="font-size:0.78rem;color:var(--text-muted,#888);margin-bottom:1rem">Rumus: <strong>(Upah Harian + Lembur + Lain²) × Total Hari = Total Gaji</strong></p>
                    <?php if (empty($workers)): ?>
                        <div class="empty-msg">⚠️ Tambahkan pekerja terlebih dahulu di tab <a href="?project_id=<?= $project_id ?>&tab=workers" style="color:var(--primary)">Data Pekerja</a></div>
                    <?php else: ?>
                    <form id="salaryForm" onsubmit="saveSalary(event)">
                        <div class="form-row cols-6">
                            <div class="fg"><label>Pekerja</label>
                                <select name="worker_id" id="workerSelect" required onchange="fillRate(this)">
                                    <option value="">-- Pilih Pekerja --</option>
                                    <?php foreach ($workers as $w): ?>
                                    <option value="<?= $w['id'] ?>" data-rate="<?= $w['daily_rate'] ?? 0 ?>"><?= htmlspecialchars($w['name']) ?> (<?= $w['role'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fg"><label>Upah/Hari</label><input type="number" name="daily_rate" id="dailyRate" required min="0" placeholder="150000"></div>
                            <div class="fg"><label>Lembur/Hari</label><input type="number" name="overtime_per_day" value="0" min="0" placeholder="0"></div>
                            <div class="fg"><label>Lain²/Hari</label><input type="number" name="other_per_day" value="0" min="0" placeholder="0"></div>
                            <div class="fg"><label>Total Hari</label><input type="number" name="total_days" required min="1" placeholder="7"></div>
                            <div class="fg"><label>&nbsp;</label></div>
                        </div>
                        <div class="form-row cols-4" style="margin-top:0.5rem">
                            <div class="fg"><label>Periode</label>
                                <select name="period_type"><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option></select>
                            </div>
                            <div class="fg"><label>Label Periode</label><input type="text" name="period_label" placeholder="Minggu 1 Feb 2026"></div>
                            <div class="fg"><label>💵 Total Gaji</label>
                                <input type="text" id="totalSalaryDisplay" readonly style="background:#f0fdf4;font-weight:700;color:#059669;font-size:1rem;border:2px solid #10b981" placeholder="Rp 0">
                            </div>
                            <div class="fg"><label>&nbsp;</label><button type="submit" class="btn btn-success btn-sm">💾 Simpan Gaji</button></div>
                        </div>
                        <div class="fg" style="margin-top:0.5rem"><label>Catatan</label><input type="text" name="notes" placeholder="Catatan (opsional)"></div>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="form-card">
                    <div class="actions-bar no-print">
                        <button onclick="window.print()" class="btn btn-blue btn-sm">🖨️ Print PDF</button>
                        <button onclick="submitToOwner()" class="btn btn-warning btn-sm">📤 Ajukan ke Owner</button>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>No</th><th>Pekerja</th><th>Periode</th><th>Upah/Hari</th><th>Lembur</th><th>Lain²</th><th>Hari</th><th>Total Gaji</th><th>Status</th><th class="no-print">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($salaries)): ?>
                            <tr><td colspan="10" class="empty-msg">Belum ada data gaji</td></tr>
                        <?php else: ?>
                            <?php foreach ($salaries as $i => $s): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($s['worker_name'] ?? 'Unknown') ?></strong><br><small style="color:var(--text-muted)"><?= htmlspecialchars($s['worker_role'] ?? '') ?></small></td>
                                <td><?= htmlspecialchars($s['period_label'] ?: ($s['period_type'] == 'weekly' ? 'Mingguan' : 'Bulanan')) ?></td>
                                <td>Rp <?= number_format($s['daily_rate'] ?? 0, 0, ',', '.') ?></td>
                                <td>Rp <?= number_format($s['overtime_per_day'] ?? 0, 0, ',', '.') ?></td>
                                <td>Rp <?= number_format($s['other_per_day'] ?? 0, 0, ',', '.') ?></td>
                                <td><?= $s['total_days'] ?? 0 ?></td>
                                <td class="amt">Rp <?= number_format($s['total_salary'] ?? 0, 0, ',', '.') ?></td>
                                <td><span class="badge badge-<?= $s['status'] ?? 'draft' ?>"><?= $s['status'] ?? 'draft' ?></span></td>
                                <td class="no-print"><button class="btn-delete" onclick="deleteSalary(<?= $s['id'] ?>)">Hapus</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row"><td colspan="7"><strong>TOTAL GAJI</strong></td><td class="amt" colspan="2">Rp <?= number_format($total_gaji, 0, ',', '.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ============ TAB: DIVISI/KONTRAKTOR ============ -->
                <?php elseif ($tab == 'division'): ?>
                <div class="form-card no-print">
                    <h3>🏗️ Catat Pengeluaran Divisi / Kontraktor</h3>
                    <form id="divisionForm" onsubmit="saveDivision(event)">
                        <div class="form-row cols-5">
                            <div class="fg"><label>Nama Divisi/Kontraktor</label><input type="text" name="division_name" required placeholder="Divisi Listrik / CV. ABC"></div>
                            <div class="fg"><label>Deskripsi Pekerjaan</label><input type="text" name="description" required placeholder="Instalasi listrik lantai 2"></div>
                            <div class="fg"><label>Jumlah (Rp)</label><input type="number" name="amount" required min="1" placeholder="0"></div>
                            <div class="fg"><label>Tanggal</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
                            <div class="fg"><label>&nbsp;</label><button type="submit" class="btn btn-success btn-sm">+ Catat</button></div>
                        </div>
                        <div class="form-row cols-3" style="margin-top:0.5rem">
                            <div class="fg"><label>Nama PIC / Kontraktor</label><input type="text" name="contractor_name" placeholder="Nama penanggung jawab"></div>
                        </div>
                    </form>
                </div>

                <div class="form-card">
                    <div class="actions-bar no-print">
                        <button onclick="window.print()" class="btn btn-blue btn-sm">🖨️ Print PDF</button>
                        <button onclick="submitDivToOwner()" class="btn btn-warning btn-sm">📤 Ajukan ke Owner</button>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>No</th><th>Divisi</th><th>Kontraktor</th><th>Deskripsi</th><th>Tanggal</th><th>Jumlah</th><th>Status</th><th class="no-print">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($division_expenses)): ?>
                            <tr><td colspan="8" class="empty-msg">Belum ada pengeluaran divisi/kontraktor</td></tr>
                        <?php else: ?>
                            <?php foreach ($division_expenses as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($d['division_name']) ?></strong></td>
                                <td><?= htmlspecialchars($d['contractor_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($d['description'] ?? '-') ?></td>
                                <td><?= $d['expense_date'] ? date('d/m/Y', strtotime($d['expense_date'])) : '-' ?></td>
                                <td class="amt">Rp <?= number_format($d['amount'] ?? 0, 0, ',', '.') ?></td>
                                <td><span class="badge badge-<?= $d['status'] ?? 'pending' ?>"><?= $d['status'] ?? 'pending' ?></span></td>
                                <td class="no-print"><button class="btn-delete" onclick="deleteDivision(<?= $d['id'] ?>)">Hapus</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row"><td colspan="5"><strong>TOTAL</strong></td><td class="amt" colspan="2">Rp <?= number_format($total_divisi, 0, ',', '.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const BASE = '<?= BASE_URL ?>';
const PID = <?= $project_id ?: 0 ?>;

function fillRate(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('dailyRate').value = opt.getAttribute('data-rate') || 0;
    calcTotal();
}

function calcTotal() {
    const f = document.getElementById('salaryForm');
    if (!f) return;
    const daily = parseFloat(f.daily_rate.value) || 0;
    const ot = parseFloat(f.overtime_per_day.value) || 0;
    const other = parseFloat(f.other_per_day.value) || 0;
    const days = parseInt(f.total_days.value) || 0;
    const total = (daily + ot + other) * days;
    document.getElementById('totalSalaryDisplay').value = 'Rp ' + total.toLocaleString('id-ID');
}

document.addEventListener('DOMContentLoaded', () => {
    const sf = document.getElementById('salaryForm');
    if (sf) sf.querySelectorAll('input[type=number]').forEach(el => el.addEventListener('input', calcTotal));
});

async function apiPost(url, data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    fd.append('project_id', PID);
    const r = await fetch(BASE + url, { method: 'POST', body: fd });
    return await r.json();
}

async function saveExpense(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('project_id', PID);
    const r = await fetch(BASE + '/api/investor-expense-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteExpense(id) {
    if (!confirm('Hapus pengeluaran ini?')) return;
    const res = await apiPost('/api/investor-expense-delete.php', { expense_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function saveWorker(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('project_id', PID);
    const r = await fetch(BASE + '/api/investor-worker-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteWorker(id) {
    if (!confirm('Hapus data pekerja ini?')) return;
    const res = await apiPost('/api/investor-worker-delete.php', { worker_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function saveSalary(e) {
    e.preventDefault();
    const f = e.target;
    const daily = parseFloat(f.daily_rate.value) || 0;
    const ot = parseFloat(f.overtime_per_day.value) || 0;
    const other = parseFloat(f.other_per_day.value) || 0;
    const days = parseInt(f.total_days.value) || 0;
    const total = (daily + ot + other) * days;
    const fd = new FormData(f);
    fd.append('project_id', PID);
    fd.append('total_salary', total);
    const r = await fetch(BASE + '/api/investor-salary-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteSalary(id) {
    if (!confirm('Hapus data gaji ini?')) return;
    const res = await apiPost('/api/investor-salary-delete.php', { salary_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function saveDivision(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('project_id', PID);
    const r = await fetch(BASE + '/api/investor-division-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteDivision(id) {
    if (!confirm('Hapus pengeluaran divisi ini?')) return;
    const res = await apiPost('/api/investor-division-delete.php', { division_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

function submitToOwner() {
    if (!confirm('Ajukan semua data gaji ke Owner untuk approval?')) return;
    apiPost('/api/investor-salary-submit.php', {}).then(res => {
        if (res.success) { alert('✅ Berhasil diajukan ke Owner!'); location.reload(); }
        else alert('Error: ' + res.message);
    });
}

function submitDivToOwner() {
    if (!confirm('Ajukan semua pengeluaran divisi ke Owner untuk approval?')) return;
    apiPost('/api/investor-division-submit.php', {}).then(res => {
        if (res.success) { alert('✅ Berhasil diajukan ke Owner!'); location.reload(); }
        else alert('Error: ' + res.message);
    });
}
</script>

<?php include $base_path . '/includes/footer.php'; ?>
