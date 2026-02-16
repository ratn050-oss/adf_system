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
* { box-sizing: border-box; }
.lp { padding: 1.2rem 1.5rem; max-width: 1400px; margin: 0 auto; }

/* ── Top Bar ── */
.top-bar { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.top-bar .back-link { display: inline-flex; align-items: center; gap: .35rem; color: var(--text-muted,#888); font-size: .78rem; font-weight: 600; text-decoration: none; padding: .4rem .8rem; border-radius: 6px; border: 1px solid var(--border-color,#e5e7eb); transition: all .2s; background: var(--bg-secondary,#fff); }
.top-bar .back-link:hover { border-color: #6366f1; color: #6366f1; }
.proj-select { position: relative; }
.proj-select select { appearance: none; -webkit-appearance: none; padding: .5rem 2rem .5rem .85rem; border: 1.5px solid var(--border-color,#e5e7eb); border-radius: 8px; background: var(--bg-secondary,#fff); color: var(--text-primary,#111); font-size: .82rem; font-weight: 600; cursor: pointer; min-width: 200px; transition: all .2s; }
.proj-select select:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.proj-select::after { content: '▾'; position: absolute; right: .7rem; top: 50%; transform: translateY(-50%); font-size: .7rem; color: var(--text-muted,#888); pointer-events: none; }
.top-bar .proj-badge { font-size: .68rem; padding: .2rem .55rem; border-radius: 20px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-weight: 700; letter-spacing: .3px; }

/* ── Tabs ── */
.nav-tabs { display: flex; gap: .35rem; margin-bottom: 1rem; padding: .3rem; background: var(--bg-secondary,#f8f9fa); border-radius: 10px; border: 1px solid var(--border-color,#e5e7eb); }
.nav-tab { flex: 1; padding: .5rem .4rem; text-align: center; font-size: .72rem; font-weight: 600; color: var(--text-muted,#888); border-radius: 7px; text-decoration: none; transition: all .2s; white-space: nowrap; }
.nav-tab:hover { color: #6366f1; background: rgba(99,102,241,.06); }
.nav-tab.active { background: #fff; color: #6366f1; box-shadow: 0 1px 4px rgba(0,0,0,.08); }

/* ── Summary Strip ── */
.summary-strip { display: grid; grid-template-columns: repeat(4,1fr); gap: .6rem; margin-bottom: 1rem; }
.sc { padding: .65rem .8rem; border-radius: 8px; background: var(--bg-secondary,#fff); border: 1px solid var(--border-color,#e5e7eb); position: relative; overflow: hidden; }
.sc::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
.sc.c-blue::before { background: linear-gradient(180deg,#3b82f6,#6366f1); }
.sc.c-amber::before { background: linear-gradient(180deg,#f59e0b,#ef4444); }
.sc.c-red::before { background: linear-gradient(180deg,#ef4444,#dc2626); }
.sc.c-green::before { background: linear-gradient(180deg,#10b981,#059669); }
.sc .sc-label { font-size: .6rem; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; color: var(--text-muted,#999); margin-bottom: .15rem; }
.sc .sc-val { font-size: .92rem; font-weight: 800; color: var(--text-primary,#111); }

/* ── Card / Panel ── */
.panel { background: var(--bg-secondary,#fff); border: 1px solid var(--border-color,#e5e7eb); border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: .8rem; }
.panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .75rem; }
.panel-head h3 { font-size: .85rem; font-weight: 700; color: var(--text-primary,#111); margin: 0; display: flex; align-items: center; gap: .4rem; }
.panel-head .sub { font-size: .68rem; color: var(--text-muted,#888); font-weight: 500; }

/* ── Forms ── */
.inline-form { display: flex; gap: .5rem; flex-wrap: wrap; align-items: flex-end; }
.fg { display: flex; flex-direction: column; flex: 1; min-width: 120px; }
.fg.w2 { flex: 2; min-width: 180px; }
.fg.w-auto { flex: 0 0 auto; min-width: auto; }
.fg label { font-size: .65rem; font-weight: 600; margin-bottom: .2rem; color: var(--text-muted,#888); text-transform: uppercase; letter-spacing: .3px; }
.fg input, .fg select { padding: .45rem .6rem; border: 1.5px solid var(--border-color,#e5e7eb); border-radius: 6px; background: var(--bg-primary,#fff); color: var(--text-primary,#111); font-size: .8rem; transition: border .2s; }
.fg input:focus, .fg select:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,.1); }
.fg .total-display { background: linear-gradient(135deg,#f0fdf4,#dcfce7) !important; font-weight: 800; color: #059669 !important; font-size: .9rem; border: 1.5px solid #86efac !important; }

/* ── Buttons ── */
.btn { padding: .4rem .85rem; border-radius: 6px; font-size: .72rem; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: .3rem; transition: all .15s; text-decoration: none; white-space: nowrap; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,.12); }
.btn-emerald { background: linear-gradient(135deg,#10b981,#059669); color: #fff; }
.btn-indigo { background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; }
.btn-sky { background: linear-gradient(135deg,#0ea5e9,#0284c7); color: #fff; }
.btn-amber { background: linear-gradient(135deg,#f59e0b,#d97706); color: #fff; }
.btn-rose { background: linear-gradient(135deg,#f43f5e,#e11d48); color: #fff; }
.btn-ghost { background: transparent; border: 1px solid var(--border-color,#e5e7eb); color: var(--text-muted,#888); }
.btn-ghost:hover { border-color: #6366f1; color: #6366f1; }
.btn-xs { padding: .25rem .5rem; font-size: .65rem; border-radius: 4px; }
.action-row { display: flex; gap: .4rem; flex-wrap: wrap; }

/* ── Table ── */
.tbl { width: 100%; border-collapse: separate; border-spacing: 0; }
.tbl th { padding: .5rem .6rem; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted,#999); background: rgba(99,102,241,.03); border-bottom: 1.5px solid var(--border-color,#e5e7eb); text-align: left; }
.tbl td { padding: .5rem .6rem; font-size: .78rem; color: var(--text-primary,#111); border-bottom: 1px solid var(--border-color,#f0f0f0); vertical-align: middle; }
.tbl tbody tr { transition: background .15s; }
.tbl tbody tr:hover { background: rgba(99,102,241,.02); }
.tbl .money { font-weight: 700; color: #d97706; font-variant-numeric: tabular-nums; }
.tbl .foot td { background: rgba(99,102,241,.04); font-weight: 700; border-top: 2px solid #6366f1; }
.tbl .empty td { text-align: center; padding: 1.5rem; color: var(--text-muted,#999); font-size: .8rem; }

/* ── Badge ── */
.badge { display: inline-block; padding: .15rem .45rem; border-radius: 20px; font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.badge-draft { background: #fef3c7; color: #92400e; }
.badge-submitted { background: #dbeafe; color: #1e40af; }
.badge-approved { background: #d1fae5; color: #065f46; }
.badge-paid { background: #e0e7ff; color: #3730a3; }
.badge-pending { background: #fef3c7; color: #92400e; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #fee2e2; color: #991b1b; }

.empty-state { text-align: center; padding: 1.5rem; color: var(--text-muted,#999); font-size: .8rem; }
.hint { font-size: .68rem; color: var(--text-muted,#888); font-weight: 500; }
.hint a { color: #6366f1; text-decoration: none; font-weight: 600; }

@media print {
    .top-bar, .nav-tabs, .no-print, .btn, .action-row { display: none !important; }
    .panel { border: 1px solid #ddd; box-shadow: none; }
    .tbl th, .tbl td { padding: .3rem .4rem; font-size: .7rem; }
}
@media (max-width: 768px) {
    .summary-strip { grid-template-columns: repeat(2,1fr); }
    .inline-form { flex-direction: column; }
    .fg { min-width: 100% !important; }
}
</style>

<div class="lp">
    <!-- TOP BAR -->
    <div class="top-bar no-print">
        <a href="<?= BASE_URL ?>/modules/investor/" class="back-link">← Kembali</a>
        <div class="proj-select">
            <select onchange="if(this.value) location.href='?project_id='+this.value+'&tab=<?= $tab ?>'">
                <option value="">— Pilih Projek —</option>
                <?php foreach ($projects as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $project_id == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['project_name']) ?> — Rp <?= number_format($p['budget_idr']??0,0,',','.') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($project): ?>
        <span class="proj-badge">BUKU KAS</span>
        <?php endif; ?>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-state"><p>Belum ada projek. <a href="<?= BASE_URL ?>/modules/investor/" style="color:#6366f1">Buat projek dulu →</a></p></div>
    <?php elseif (!$project): ?>
        <div class="empty-state">Pilih projek dari dropdown di atas untuk melihat buku kas</div>
    <?php else: ?>

        <!-- TABS -->
        <div class="nav-tabs no-print">
            <a class="nav-tab <?= $tab=='expenses'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=expenses">💸 Pengeluaran</a>
            <a class="nav-tab <?= $tab=='workers'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=workers">👷 Pekerja</a>
            <a class="nav-tab <?= $tab=='salary'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=salary">💰 Gaji</a>
            <a class="nav-tab <?= $tab=='division'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=division">🏗️ Divisi</a>
        </div>

        <!-- SUMMARY -->
        <div class="summary-strip">
            <div class="sc c-blue"><div class="sc-label">Budget</div><div class="sc-val">Rp <?= number_format($project['budget_idr']??0,0,',','.') ?></div></div>
            <div class="sc c-amber"><div class="sc-label">Pengeluaran</div><div class="sc-val">Rp <?= number_format($project['total_expenses']??0,0,',','.') ?></div></div>
            <div class="sc c-red"><div class="sc-label">Gaji + Divisi</div><div class="sc-val">Rp <?= number_format($total_gaji+$total_divisi,0,',','.') ?></div></div>
            <div class="sc c-green"><div class="sc-label">Sisa</div><div class="sc-val">Rp <?= number_format(($project['budget_idr']??0)-($project['total_expenses']??0)-$total_gaji-$total_divisi,0,',','.') ?></div></div>
        </div>

                <!-- ═══ TAB: PENGELUARAN ═══ -->
                <?php if ($tab == 'expenses'): ?>
                <div class="panel no-print">
                    <div class="panel-head"><h3>📝 Catat Pengeluaran</h3></div>
                    <form id="expenseForm" onsubmit="saveExpense(event)">
                        <div class="inline-form">
                            <div class="fg w2"><label>Deskripsi</label><input type="text" name="description" required placeholder="Nama item pengeluaran"></div>
                            <div class="fg"><label>Jumlah (Rp)</label><input type="number" name="amount" required min="1" placeholder="0"></div>
                            <div class="fg"><label>Tanggal</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">+ Catat</button></div>
                        </div>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Riwayat Pengeluaran</h3>
                        <div class="action-row no-print"><button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button></div>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>#</th><th>Tanggal</th><th>Deskripsi</th><th style="text-align:right">Jumlah</th><th class="no-print" style="width:50px">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr class="empty"><td colspan="5">Belum ada data pengeluaran</td></tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $i => $e): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><?= date('d/m/Y', strtotime($e['expense_date'] ?? $e['created_at'] ?? 'now')) ?></td>
                                <td><?= htmlspecialchars($e['description'] ?? '-') ?></td>
                                <td class="money" style="text-align:right">Rp <?= number_format($e['amount']??0,0,',','.') ?></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteExpense(<?= $e['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="foot"><td colspan="3">TOTAL</td><td class="money" style="text-align:right">Rp <?= number_format($project['total_expenses'],0,',','.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ═══ TAB: DATA PEKERJA ═══ -->
                <?php elseif ($tab == 'workers'): ?>
                <div class="panel no-print">
                    <div class="panel-head"><h3>👷 Tambah Pekerja</h3></div>
                    <form id="workerForm" onsubmit="saveWorker(event)">
                        <div class="inline-form">
                            <div class="fg w2"><label>Nama</label><input type="text" name="name" required placeholder="Nama lengkap"></div>
                            <div class="fg"><label>Jabatan</label>
                                <select name="role">
                                    <option>Tukang</option><option>Kepala Tukang</option><option>Kuli</option><option>Mandor</option>
                                    <option>Tukang Listrik</option><option>Tukang Cat</option><option>Tukang Las</option><option>Helper</option>
                                </select>
                            </div>
                            <div class="fg"><label>Upah/Hari</label><input type="number" name="daily_rate" placeholder="150000" min="0"></div>
                            <div class="fg"><label>HP</label><input type="text" name="phone" placeholder="08xx"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">+ Tambah</button></div>
                        </div>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Daftar Pekerja <span class="badge badge-active" style="margin-left:.4rem"><?= count($workers) ?> orang</span></h3>
                        <div class="action-row no-print"><button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button></div>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>#</th><th>Nama</th><th>Jabatan</th><th style="text-align:right">Upah/Hari</th><th>HP</th><th>Status</th><th class="no-print" style="width:50px">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($workers)): ?>
                            <tr class="empty"><td colspan="7">Belum ada data pekerja</td></tr>
                        <?php else: ?>
                            <?php foreach ($workers as $i => $w): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><strong><?= htmlspecialchars($w['name']) ?></strong></td>
                                <td><?= htmlspecialchars($w['role'] ?? 'Tukang') ?></td>
                                <td class="money" style="text-align:right">Rp <?= number_format($w['daily_rate']??0,0,',','.') ?></td>
                                <td style="color:var(--text-muted)"><?= htmlspecialchars($w['phone'] ?? '-') ?></td>
                                <td><span class="badge badge-<?= $w['status']??'active' ?>"><?= $w['status']??'active' ?></span></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteWorker(<?= $w['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ═══ TAB: GAJI TUKANG ═══ -->
                <?php elseif ($tab == 'salary'): ?>
                <div class="panel no-print">
                    <div class="panel-head">
                        <h3>💰 Hitung Gaji</h3>
                        <span class="hint">( Upah + Lembur + Lain² ) × Hari = <strong>Total</strong></span>
                    </div>
                    <?php if (empty($workers)): ?>
                        <div class="empty-state">⚠️ Tambahkan pekerja dulu di tab <a class="hint" href="?project_id=<?= $project_id ?>&tab=workers">Pekerja</a></div>
                    <?php else: ?>
                    <form id="salaryForm" onsubmit="saveSalary(event)">
                        <div class="inline-form" style="margin-bottom:.5rem">
                            <div class="fg w2"><label>Pekerja</label>
                                <select name="worker_id" id="workerSelect" required onchange="fillRate(this)">
                                    <option value="">— Pilih —</option>
                                    <?php foreach ($workers as $w): ?>
                                    <option value="<?= $w['id'] ?>" data-rate="<?= $w['daily_rate']??0 ?>"><?= htmlspecialchars($w['name']) ?> (<?= $w['role'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fg"><label>Upah/Hari</label><input type="number" name="daily_rate" id="dailyRate" required min="0" placeholder="150000"></div>
                            <div class="fg"><label>Lembur</label><input type="number" name="overtime_per_day" value="0" min="0"></div>
                            <div class="fg"><label>Lain²</label><input type="number" name="other_per_day" value="0" min="0"></div>
                            <div class="fg"><label>Hari</label><input type="number" name="total_days" required min="1" placeholder="7"></div>
                        </div>
                        <div class="inline-form">
                            <div class="fg"><label>Periode</label>
                                <select name="period_type"><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option></select>
                            </div>
                            <div class="fg"><label>Label</label><input type="text" name="period_label" placeholder="Minggu 1 Feb 2026"></div>
                            <div class="fg"><label>💵 Total Gaji</label><input type="text" id="totalSalaryDisplay" class="total-display" readonly placeholder="Rp 0"></div>
                            <div class="fg"><label>Catatan</label><input type="text" name="notes" placeholder="Opsional"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">💾 Simpan</button></div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Riwayat Gaji</h3>
                        <div class="action-row no-print">
                            <button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button>
                            <button onclick="submitToOwner()" class="btn btn-amber btn-xs">📤 Ajukan ke Owner</button>
                        </div>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>#</th><th>Pekerja</th><th>Periode</th><th style="text-align:right">Upah</th><th style="text-align:right">Lembur</th><th style="text-align:right">Lain²</th><th>Hari</th><th style="text-align:right">Total</th><th>Status</th><th class="no-print" style="width:50px"></th></tr></thead>
                        <tbody>
                        <?php if (empty($salaries)): ?>
                            <tr class="empty"><td colspan="10">Belum ada data gaji</td></tr>
                        <?php else: ?>
                            <?php foreach ($salaries as $i => $s): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><strong><?= htmlspecialchars($s['worker_name']??'?') ?></strong><br><span class="hint"><?= htmlspecialchars($s['worker_role']??'') ?></span></td>
                                <td><?= htmlspecialchars($s['period_label'] ?: ($s['period_type']=='weekly'?'Mingguan':'Bulanan')) ?></td>
                                <td style="text-align:right">Rp <?= number_format($s['daily_rate']??0,0,',','.') ?></td>
                                <td style="text-align:right">Rp <?= number_format($s['overtime_per_day']??0,0,',','.') ?></td>
                                <td style="text-align:right">Rp <?= number_format($s['other_per_day']??0,0,',','.') ?></td>
                                <td style="text-align:center"><?= $s['total_days']??0 ?></td>
                                <td class="money" style="text-align:right">Rp <?= number_format($s['total_salary']??0,0,',','.') ?></td>
                                <td><span class="badge badge-<?= $s['status']??'draft' ?>"><?= $s['status']??'draft' ?></span></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteSalary(<?= $s['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="foot"><td colspan="7">TOTAL GAJI</td><td class="money" style="text-align:right" colspan="2">Rp <?= number_format($total_gaji,0,',','.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ═══ TAB: DIVISI/KONTRAKTOR ═══ -->
                <?php elseif ($tab == 'division'): ?>
                <div class="panel no-print">
                    <div class="panel-head"><h3>🏗️ Pengeluaran Divisi / Kontraktor</h3></div>
                    <form id="divisionForm" onsubmit="saveDivision(event)">
                        <div class="inline-form" style="margin-bottom:.5rem">
                            <div class="fg w2"><label>Divisi / Kontraktor</label><input type="text" name="division_name" required placeholder="Divisi Listrik / CV. ABC"></div>
                            <div class="fg w2"><label>Deskripsi</label><input type="text" name="description" required placeholder="Instalasi listrik lantai 2"></div>
                            <div class="fg"><label>Jumlah (Rp)</label><input type="number" name="amount" required min="1" placeholder="0"></div>
                        </div>
                        <div class="inline-form">
                            <div class="fg"><label>PIC</label><input type="text" name="contractor_name" placeholder="Nama PIC"></div>
                            <div class="fg"><label>Tanggal</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">+ Catat</button></div>
                        </div>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Riwayat Divisi</h3>
                        <div class="action-row no-print">
                            <button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button>
                            <button onclick="submitDivToOwner()" class="btn btn-amber btn-xs">📤 Ajukan ke Owner</button>
                        </div>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>#</th><th>Divisi</th><th>PIC</th><th>Deskripsi</th><th>Tanggal</th><th style="text-align:right">Jumlah</th><th>Status</th><th class="no-print" style="width:50px"></th></tr></thead>
                        <tbody>
                        <?php if (empty($division_expenses)): ?>
                            <tr class="empty"><td colspan="8">Belum ada data</td></tr>
                        <?php else: ?>
                            <?php foreach ($division_expenses as $i => $d): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><strong><?= htmlspecialchars($d['division_name']) ?></strong></td>
                                <td><?= htmlspecialchars($d['contractor_name']??'-') ?></td>
                                <td><?= htmlspecialchars($d['description']??'-') ?></td>
                                <td><?= $d['expense_date'] ? date('d/m/Y', strtotime($d['expense_date'])) : '-' ?></td>
                                <td class="money" style="text-align:right">Rp <?= number_format($d['amount']??0,0,',','.') ?></td>
                                <td><span class="badge badge-<?= $d['status']??'pending' ?>"><?= $d['status']??'pending' ?></span></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteDivision(<?= $d['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="foot"><td colspan="5">TOTAL</td><td class="money" style="text-align:right" colspan="2">Rp <?= number_format($total_divisi,0,',','.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

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
