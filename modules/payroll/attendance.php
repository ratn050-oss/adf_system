<?php
/**
 * Payroll Attendance Admin Dashboard
 * Manajer melihat, export, manage absen + atur lokasi kantor
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Absensi Karyawan';
$baseUrl = defined('BASE_URL') ? BASE_URL : '';

// ── Auto-create tables ──
try {
    $db->query("SELECT 1 FROM payroll_attendance LIMIT 1");
} catch (Exception $e) {
    $pdo = $db->getConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance_config` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `office_lat` DECIMAL(10,7) NOT NULL DEFAULT -6.2000000,
        `office_lng` DECIMAL(10,7) NOT NULL DEFAULT 106.8166700,
        `allowed_radius_m` INT NOT NULL DEFAULT 200,
        `office_name` VARCHAR(100) DEFAULT 'Kantor',
        `checkin_start` TIME DEFAULT '07:00:00',
        `checkin_end` TIME DEFAULT '10:00:00',
        `checkout_start` TIME DEFAULT '16:00:00',
        `allow_outside` TINYINT(1) DEFAULT 0,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `updated_by` INT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO `payroll_attendance_config` (`id`) VALUES (1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `attendance_date` DATE NOT NULL,
        `check_in_time` TIME DEFAULT NULL,
        `check_in_lat` DECIMAL(10,7) DEFAULT NULL,
        `check_in_lng` DECIMAL(10,7) DEFAULT NULL,
        `check_in_distance_m` INT DEFAULT NULL,
        `check_in_address` VARCHAR(255) DEFAULT NULL,
        `check_in_device` VARCHAR(200) DEFAULT NULL,
        `check_out_time` TIME DEFAULT NULL,
        `check_out_lat` DECIMAL(10,7) DEFAULT NULL,
        `check_out_lng` DECIMAL(10,7) DEFAULT NULL,
        `check_out_distance_m` INT DEFAULT NULL,
        `check_out_device` VARCHAR(200) DEFAULT NULL,
        `work_hours` DECIMAL(5,2) DEFAULT NULL,
        `status` ENUM('present','late','absent','leave','holiday','half_day') NOT NULL DEFAULT 'present',
        `is_outside_radius` TINYINT(1) DEFAULT 0,
        `notes` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_attendance` (`employee_id`, `attendance_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Add PIN column if missing
try {
    $db->query("SELECT attendance_pin FROM payroll_employees LIMIT 1");
} catch (Exception $e) {
    $db->getConnection()->exec("ALTER TABLE payroll_employees ADD COLUMN `attendance_pin` VARCHAR(6) DEFAULT NULL");
}

// ── Actions ──
$msg = '';
$msgType = '';

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $lat  = floatval($_POST['office_lat'] ?? 0);
    $lng  = floatval($_POST['office_lng'] ?? 0);
    $rad  = max(10, min(5000, intval($_POST['allowed_radius_m'] ?? 200)));
    $name = trim(htmlspecialchars($_POST['office_name'] ?? 'Kantor'));
    $ciStart = $_POST['checkin_start'];
    $ciEnd   = $_POST['checkin_end'];
    $coStart = $_POST['checkout_start'];
    $allowOut = isset($_POST['allow_outside']) ? 1 : 0;
    $db->query("UPDATE payroll_attendance_config SET office_lat=?, office_lng=?, allowed_radius_m=?, office_name=?, checkin_start=?, checkin_end=?, checkout_start=?, allow_outside=?, updated_by=? WHERE id=1",
        [$lat, $lng, $rad, $name, $ciStart, $ciEnd, $coStart, $allowOut, $currentUser['id']]);
    $msg = 'Pengaturan lokasi berhasil disimpan.';
    $msgType = 'success';
}

// Reset/update employee PIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_face') {
    $empId = (int)$_POST['employee_id'];
    $db->query("UPDATE payroll_employees SET face_descriptor = NULL WHERE id = ?", [$empId]);
    $msg = 'Data wajah karyawan berhasil direset. Karyawan perlu daftar ulang wajah.';
    $msgType = 'success';
}

// Update attendance record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_att') {
    $attId = (int)$_POST['att_id'];
    $status = $_POST['status'] ?? 'present';
    $in = !empty($_POST['check_in_time']) ? $_POST['check_in_time'] . ':00' : null;
    $out = !empty($_POST['check_out_time']) ? $_POST['check_out_time'] . ':00' : null;
    $notes = trim($_POST['notes'] ?? '');
    $wh = null;
    if ($in && $out) {
        $t1 = strtotime("2000-01-01 $in");
        $t2 = strtotime("2000-01-01 $out");
        $wh = ($t2 > $t1) ? round(($t2-$t1)/3600, 2) : null;
    }
    $db->query("UPDATE payroll_attendance SET status=?, check_in_time=?, check_out_time=?, work_hours=?, notes=? WHERE id=?",
        [$status, $in, $out, $wh, $notes, $attId]);
    $msg = 'Data absen berhasil diperbarui.';
    $msgType = 'success';
}

// Manual add attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_att') {
    $empId = (int)$_POST['employee_id'];
    $date = $_POST['attendance_date'];
    $status = $_POST['status'] ?? 'present';
    $in = !empty($_POST['check_in_time']) ? $_POST['check_in_time'] . ':00' : null;
    $out = !empty($_POST['check_out_time']) ? $_POST['check_out_time'] . ':00' : null;
    $notes = trim($_POST['notes'] ?? '');
    $wh = null;
    if ($in && $out) {
        $t1 = strtotime("$date $in"); $t2 = strtotime("$date $out");
        $wh = ($t2 > $t1) ? round(($t2-$t1)/3600, 2) : null;
    }
    try {
        $db->query("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, check_out_time, work_hours, status, notes) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE check_in_time=VALUES(check_in_time), check_out_time=VALUES(check_out_time), work_hours=VALUES(work_hours), status=VALUES(status), notes=VALUES(notes)",
            [$empId, $date, $in, $out, $wh, $status, $notes]);
        $msg = 'Data absen berhasil ditambahkan.';
        $msgType = 'success';
    } catch (Exception $e) { $msg = 'Error: ' . $e->getMessage(); $msgType = 'error'; }
}

// ── Fetch data ──
$config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1") ?: [];

$viewDate = $_GET['date'] ?? date('Y-m-d');
$viewMonth = $_GET['month'] ?? date('Y-m');

$employees = $db->fetchAll("SELECT id, employee_code, full_name, position, face_descriptor FROM payroll_employees WHERE is_active = 1 ORDER BY full_name") ?: [];

// Daily attendance query
$dailyAtt = $db->fetchAll("
    SELECT a.*, e.full_name, e.employee_code, e.position
    FROM payroll_attendance a
    JOIN payroll_employees e ON e.id = a.employee_id
    WHERE a.attendance_date = ?
    ORDER BY e.full_name
", [$viewDate]) ?: [];

// Monthly summary
$monthlyAtt = $db->fetchAll("
    SELECT e.id, e.employee_code, e.full_name, e.position,
        COUNT(a.id) as total_days,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) as leave,
        ROUND(SUM(a.work_hours), 1) as total_hours,
        ROUND(AVG(CASE WHEN a.work_hours > 0 THEN a.work_hours ELSE NULL END), 1) as avg_hours
    FROM payroll_employees e
    LEFT JOIN payroll_attendance a ON a.employee_id = e.id AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
    WHERE e.is_active = 1
    GROUP BY e.id
    ORDER BY e.full_name
", [$viewMonth]) ?: [];

// Today stats
$todayStats = [
    'total'   => count($employees),
    'present' => 0, 'late' => 0, 'checkedout' => 0
];
foreach ($dailyAtt as $a) {
    if ($a['check_in_time']) $todayStats['present']++;
    if ($a['status'] === 'late') $todayStats['late']++;
    if ($a['check_out_time']) $todayStats['checkedout']++;
}

$absenUrl = $baseUrl . '/modules/payroll/absen.php?b=' . ACTIVE_BUSINESS_ID;

include '../../includes/header.php';
?>

<style>
:root {
    --navy: #0d1f3c; --navy-light: #1a3a5c; --gold: #f0b429;
    --green: #059669; --orange: #ea580c; --red: #dc2626; --blue: #2563eb;
    --bg: #f8fafc; --card: #fff; --border: #e2e8f0; --muted: #64748b;
}
.att-container { max-width: 100%; font-family: 'Inter', sans-serif; }

/* Header */
.att-header { background:#fff; padding:16px 20px; border-radius:12px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; border:1px solid var(--border); border-left:4px solid var(--gold); box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.att-header h1 { font-size:18px; font-weight:700; color:var(--navy); margin:0 0 4px; }
.att-header p { font-size:12px; margin:0; color:var(--muted); }

/* Stat Cards */
.att-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
.att-stat { background:#fff; padding:14px 16px; border-radius:10px; border:1px solid var(--border); box-shadow:0 1px 4px rgba(0,0,0,0.04); }
.att-stat .label { font-size:11px; color:var(--muted); font-weight:600; text-transform:uppercase; }
.att-stat .value { font-size:24px; font-weight:800; color:var(--navy); margin-top:4px; }
.att-stat.success .value { color:var(--green); }
.att-stat.warning .value { color:var(--orange); }
.att-stat.info .value { color:var(--blue); }

/* Tabs */
.att-tabs { display:flex; gap:4px; background:var(--bg); padding:3px; border-radius:10px; margin-bottom:14px; border:1px solid var(--border); }
.att-tab { flex:1; padding:8px 12px; border:none; background:transparent; border-radius:8px; cursor:pointer; font-weight:600; font-size:12px; color:var(--muted); transition:all 0.15s; }
.att-tab.active { background:var(--gold); color:var(--navy); font-weight:800; }

/* Table */
.att-table-wrap { background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 6px rgba(0,0,0,0.06); border:1px solid var(--border); }
.att-table { width:100%; border-collapse:collapse; }
.att-table th { background:var(--bg); color:var(--navy); padding:11px 14px; text-align:left; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; border-bottom:2px solid var(--gold); }
.att-table td { padding:11px 14px; border-bottom:1px solid #f1f5f9; font-size:12px; color:var(--navy); }
.att-table tr:last-child td { border-bottom:none; }
.att-table tr:hover { background:#fafbfc; }

/* Status Badge */
.s-badge { padding:3px 9px; border-radius:5px; font-size:10px; font-weight:700; text-transform:uppercase; display:inline-flex; align-items:center; gap:3px; }
.s-present { background:#dcfce7; color:#166534; }
.s-late { background:#ffedd5; color:#9a3412; }
.s-absent { background:#fee2e2; color:#991b1b; }
.s-leave { background:#e0e7ff; color:#3730a3; }
.s-holiday, .s-half_day { background:#f3f4f6; color:#374151; }
.s-notyet { background:#f1f5f9; color:#94a3b8; }

/* Action btns */
.act-btn { padding:4px 10px; border-radius:5px; font-size:11px; font-weight:600; border:none; cursor:pointer; transition:all 0.15s; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.act-btn:hover { opacity:0.85; }
.act-btn-edit { background:#eff6ff; color:var(--blue); }
.act-btn-pin { background:#fef3c7; color:#92400e; }
.act-btn-del { background:#fef2f2; color:var(--red); }

/* QR URL section */
.att-url-card { background:linear-gradient(135deg, var(--navy), var(--navy-light)); border-radius:12px; padding:18px; color:#fff; margin-bottom:16px; }
.att-url-card h3 { font-size:14px; margin:0 0 10px; color:var(--gold); }
.att-url-input { background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:8px; padding:10px 12px; color:#fff; font-size:12px; width:100%; font-family:monospace; }
.att-url-btn { margin-top:8px; padding:8px 16px; background:var(--gold); color:var(--navy); border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; }

/* Forms */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.form-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
.form-group { margin-bottom:10px; }
.form-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; margin-bottom:4px; display:block; }
.form-input, .form-select { width:100%; padding:8px 10px; border:1.5px solid var(--border); border-radius:7px; font-size:12px; color:var(--navy); }
.form-input:focus, .form-select:focus { border-color:var(--gold); outline:none; }

/* Map */
#adminMap { width:100%; height:260px; border-radius:10px; overflow:hidden; border:1px solid var(--border); margin-bottom:12px; }

/* Alert */
.att-alert { padding:10px 14px; border-radius:8px; font-size:13px; font-weight:600; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.att-alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.att-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

/* Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9990; display:none; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:24px; max-width:440px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.25); border-top:4px solid var(--gold); }
.modal-title { font-size:15px; font-weight:700; color:var(--navy); margin-bottom:16px; }
.modal-actions { display:flex; gap:8px; margin-top:16px; justify-content:flex-end; }
.btn-save { padding:9px 20px; background:var(--navy); color:#fff; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
.btn-cancel { padding:9px 16px; background:#f1f5f9; color:var(--muted); border:1px solid var(--border); border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; }

@media(max-width:768px) {
    .att-stats { grid-template-columns: repeat(2,1fr); }
    .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
}
</style>

<?php if ($msg): ?>
<div class="att-alert att-alert-<?php echo $msgType; ?>" style="margin-bottom:12px;">
    <?php echo $msgType === 'success' ? '✅' : '❌'; ?> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="att-container">

    <!-- Header -->
    <div class="att-header">
        <div>
            <h1>📍 Absensi Karyawan</h1>
            <p>Kelola absensi, verifikasi GPS, dan pengaturan lokasi kantor</p>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="<?php echo htmlspecialchars($absenUrl); ?>" target="_blank" class="act-btn" style="background:var(--navy); color:#fff; padding:10px 16px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none;">
                📱 Buka Halaman Absen
            </a>
            <button onclick="openManualModal()" class="act-btn" style="background:var(--gold); color:var(--navy); padding:10px 16px; border-radius:8px; font-size:12px; font-weight:700;">
                ➕ Input Manual
            </button>
        </div>
    </div>

    <!-- QR Link Card -->
    <div class="att-url-card">
        <h3>📲 Link Absen untuk Karyawan</h3>
        <input type="text" class="att-url-input" value="<?php echo htmlspecialchars($absenUrl); ?>" readonly id="absenUrlInput">
        <button class="att-url-btn" onclick="copyAbsenUrl()">📋 Salin Link</button>
        <span style="font-size:11px; color:rgba(255,255,255,0.6); margin-left:8px;">Bagikan link ini kepada seluruh karyawan</span>
    </div>

    <!-- Stats -->
    <div class="att-stats">
        <div class="att-stat">
            <div class="label">Total Karyawan</div>
            <div class="value"><?php echo $todayStats['total']; ?></div>
        </div>
        <div class="att-stat success">
            <div class="label">Hadir Hari Ini</div>
            <div class="value"><?php echo $todayStats['present']; ?></div>
        </div>
        <div class="att-stat warning">
            <div class="label">Terlambat</div>
            <div class="value"><?php echo $todayStats['late']; ?></div>
        </div>
        <div class="att-stat info">
            <div class="label">Belum Absen</div>
            <div class="value"><?php echo max(0, $todayStats['total'] - $todayStats['present']); ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="att-tabs">
        <button class="att-tab active" id="tabDaily" onclick="switchTab('daily')">📋 Harian</button>
        <button class="att-tab" id="tabMonthly" onclick="switchTab('monthly')">📅 Bulanan</button>
        <button class="att-tab" id="tabSettings" onclick="switchTab('settings')">⚙️ Pengaturan</button>
        <button class="att-tab" id="tabPins" onclick="switchTab('pins')">�️ Data Wajah</button>
    </div>

    <!-- ── DAILY TAB ── -->
    <div id="tabPanelDaily">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                <input type="hidden" name="tab" value="daily">
                <input type="date" name="date" value="<?php echo $viewDate; ?>" class="form-input" style="width:150px;">
                <button type="submit" style="padding:8px 16px; background:var(--navy); color:#fff; border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer;">Tampilkan</button>
            </form>
            <span style="font-size:12px; color:var(--muted);"><?php echo date('l, d F Y', strtotime($viewDate)); ?></span>
        </div>

        <div class="att-table-wrap">
            <table class="att-table">
                <thead><tr>
                    <th>Karyawan</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Jam Kerja</th>
                    <th>GPS (m)</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr></thead>
                <tbody>
                <?php
                // Show all employees, filled with attendance or blank
                $attById = [];
                foreach ($dailyAtt as $a) $attById[$a['employee_id']] = $a;

                foreach ($employees as $emp):
                    $a = $attById[$emp['id']] ?? null;
                    $status = $a ? $a['status'] : 'notyet';
                    $labels = ['present'=>'Hadir','late'=>'Terlambat','absent'=>'Absen','leave'=>'Izin','holiday'=>'Libur','half_day'=>'1/2 Hari','notyet'=>'Belum'];
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                        <div style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($emp['employee_code']); ?> • <?php echo htmlspecialchars($emp['position']); ?></div>
                    </td>
                    <td><?php echo $a && $a['check_in_time'] ? '<strong>'.substr($a['check_in_time'],0,5).'</strong>' : '<span style="color:#ccc;">—</span>'; ?></td>
                    <td><?php echo $a && $a['check_out_time'] ? '<strong>'.substr($a['check_out_time'],0,5).'</strong>' : '<span style="color:#ccc;">—</span>'; ?></td>
                    <td><?php echo $a && $a['work_hours'] ? number_format($a['work_hours'],1).'j' : '—'; ?></td>
                    <td>
                        <?php if ($a && $a['check_in_distance_m']): ?>
                            <span style="font-size:11px; color:<?php echo $a['is_outside_radius'] ? 'var(--red)' : 'var(--green)'; ?>">
                                <?php echo $a['check_in_distance_m']; ?>m <?php echo $a['is_outside_radius'] ? '⚠️' : '✅'; ?>
                            </span>
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <td><span class="s-badge s-<?php echo $status; ?>"><?php echo $labels[$status] ?? $status; ?></span></td>
                    <td>
                        <?php if ($a): ?>
                        <button class="act-btn act-btn-edit" onclick='openEditModal(<?php echo json_encode($a); ?>)'>✏️ Edit</button>
                        <?php else: ?>
                        <button class="act-btn" style="background:#f0fdf4; color:var(--green);" onclick="quickManualAdd(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['full_name']); ?>', '<?php echo $viewDate; ?>')">➕</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── MONTHLY TAB ── -->
    <div id="tabPanelMonthly" style="display:none;">
        <div style="display:flex; gap:8px; margin-bottom:12px; align-items:center;">
            <form method="GET" style="display:flex; gap:8px;">
                <input type="hidden" name="tab" value="monthly">
                <input type="month" name="month" value="<?php echo $viewMonth; ?>" class="form-input" style="width:150px;">
                <button type="submit" style="padding:8px 16px; background:var(--navy); color:#fff; border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer;">Tampilkan</button>
            </form>
        </div>
        <div class="att-table-wrap">
            <table class="att-table">
                <thead><tr>
                    <th>Karyawan</th>
                    <th style="text-align:center;">Hadir</th>
                    <th style="text-align:center;">Terlambat</th>
                    <th style="text-align:center;">Absen</th>
                    <th style="text-align:center;">Izin</th>
                    <th style="text-align:right;">Total Jam</th>
                    <th style="text-align:right;">Avg/hari</th>
                </tr></thead>
                <tbody>
                <?php foreach ($monthlyAtt as $row): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
                        <div style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($row['employee_code']); ?></div>
                    </td>
                    <td style="text-align:center;"><strong style="color:var(--green);"><?php echo $row['present']; ?></strong></td>
                    <td style="text-align:center;"><span style="color:var(--orange);"><?php echo $row['late']; ?></span></td>
                    <td style="text-align:center;"><span style="color:var(--red);"><?php echo $row['absent']; ?></span></td>
                    <td style="text-align:center;"><span style="color:#6366f1;"><?php echo $row['leave']; ?></span></td>
                    <td style="text-align:right; font-weight:700;"><?php echo $row['total_hours'] ?? 0; ?>j</td>
                    <td style="text-align:right;"><?php echo $row['avg_hours'] ?? 0; ?>j</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── SETTINGS TAB ── -->
    <div id="tabPanelSettings" style="display:none;">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
            <div>
                <div style="background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:14px;">
                    <h3 style="font-size:14px; font-weight:700; color:var(--navy); margin:0 0 14px;">📍 Lokasi Kantor</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_config">
                        <div class="form-group">
                            <label class="form-label">Nama Lokasi</label>
                            <input type="text" name="office_name" class="form-input" value="<?php echo htmlspecialchars($config['office_name'] ?? 'Kantor'); ?>">
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Latitude</label>
                                <input type="text" name="office_lat" id="inputLat" class="form-input" value="<?php echo $config['office_lat'] ?? '-6.2'; ?>" step="any">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Longitude</label>
                                <input type="text" name="office_lng" id="inputLng" class="form-input" value="<?php echo $config['office_lng'] ?? '106.82'; ?>" step="any">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Radius Absen (meter)</label>
                            <input type="number" name="allowed_radius_m" class="form-input" value="<?php echo $config['allowed_radius_m'] ?? 200; ?>" min="10" max="5000">
                            <div style="font-size:11px; color:var(--muted); margin-top:4px;">Karyawan harus berada dalam radius ini untuk absen.</div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Batas Masuk (Awal)</label>
                                <input type="time" name="checkin_start" class="form-input" value="<?php echo $config['checkin_start'] ?? '07:00'; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Batas Masuk (Terlambat)</label>
                                <input type="time" name="checkin_end" class="form-input" value="<?php echo $config['checkin_end'] ?? '10:00'; ?>">
                                <div style="font-size:10px; color:var(--muted);">Check-in setelah jam ini = Terlambat</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-Out Awal Boleh (jam)</label>
                            <input type="time" name="checkout_start" class="form-input" value="<?php echo $config['checkout_start'] ?? '16:00'; ?>">
                        </div>
                        <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="allow_outside" id="allowOut" <?php echo ($config['allow_outside'] ?? 0) ? 'checked' : ''; ?>>
                            <label for="allowOut" style="font-size:12px; color:var(--navy);">Izinkan absen di luar radius (dengan tanda peringatan)</label>
                        </div>
                        <button type="submit" class="btn-save" style="width:100%;">💾 Simpan Pengaturan</button>
                    </form>
                </div>
            </div>
            <div>
                <div style="background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px;">
                    <h3 style="font-size:14px; font-weight:700; color:var(--navy); margin:0 0 10px;">🗺️ Klik Peta untuk Pilih Lokasi Kantor</h3>
                    <div id="adminMap"></div>
                    <div style="font-size:11px; color:var(--muted);">Klik pada peta untuk mengambil koordinat secara otomatis, lalu simpan pengaturan.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── FACE DATA TAB ── -->
    <div id="tabPanelPins" style="display:none;">
        <div style="background:#e0f2fe; border:1px solid #38bdf8; border-radius:8px; padding:12px 14px; margin-bottom:14px; font-size:12px; color:#0c4a6e;">
            👁️ Karyawan absen menggunakan <strong>scan wajah</strong> dari HP. Jika wajah bermasalah, reset data wajah di sini — karyawan akan diminta selfie ulang saat absen berikutnya.
        </div>
        <div class="att-table-wrap">
            <table class="att-table">
                <thead><tr>
                    <th>Kode</th><th>Nama</th><th>Jabatan</th><th>Status Wajah</th><th>Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($employees as $emp): ?>
                <tr>
                    <td><code style="font-size:10px; background:rgba(240,180,41,0.15); padding:2px 6px; border-radius:3px;"><?php echo htmlspecialchars($emp['employee_code']); ?></code></td>
                    <td><strong><?php echo htmlspecialchars($emp['full_name']); ?></strong></td>
                    <td style="font-size:11px; color:var(--muted);"><?php echo htmlspecialchars($emp['position']); ?></td>
                    <td><?php echo $emp['face_descriptor'] ? '<span style="color:var(--green); font-size:12px; font-weight:600;">✅ Wajah terdaftar</span>' : '<span style="color:var(--orange); font-size:12px; font-weight:600;">⚠️ Belum terdaftar (selfie saat absen pertama)</span>'; ?></td>
                    <td>
                        <?php if ($emp['face_descriptor']): ?>
                        <button class="act-btn act-btn-del" onclick="openFaceResetModal(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['full_name']); ?>')">🔄 Reset Wajah</button>
                        <?php else: ?>
                        <span style="font-size:11px; color:var(--muted);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /att-container -->

<!-- ═══ MODAL: EDIT ATTENDANCE ═══ -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-title">✏️ Edit Data Absen</div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_att">
            <input type="hidden" name="att_id" id="editAttId">
            <div style="font-size:13px; font-weight:600; color:var(--navy); margin-bottom:12px;" id="editEmpName"></div>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Check-In</label>
                    <input type="time" name="check_in_time" id="editCheckIn" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Check-Out</label>
                    <input type="time" name="check_out_time" id="editCheckOut" class="form-input">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" id="editStatus" class="form-select">
                    <option value="present">Hadir</option>
                    <option value="late">Terlambat</option>
                    <option value="absent">Absen</option>
                    <option value="leave">Izin</option>
                    <option value="holiday">Libur</option>
                    <option value="half_day">Setengah Hari</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Catatan</label>
                <input type="text" name="notes" id="editNotes" class="form-input" placeholder="Opsional">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Batal</button>
                <button type="submit" class="btn-save">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL: RESET FACE ═══ -->
<div class="modal-overlay" id="pinModal">
    <div class="modal-box">
        <div class="modal-title">🔄 Reset Data Wajah</div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_face">
            <input type="hidden" name="employee_id" id="pinEmpId">
            <div style="font-size:13px; color:var(--muted); margin-bottom:12px;" id="pinEmpName"></div>
            <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:12px; font-size:12px; color:#991b1b; margin-bottom:12px;">
                ⚠️ Setelah direset, karyawan ini harus <strong>selfie ulang</strong> saat absen berikutnya untuk mendaftarkan wajah baru.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('pinModal')">Batal</button>
                <button type="submit" class="btn-save" style="background:#dc2626;">🔄 Reset Wajah</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL: MANUAL ATTENDANCE ═══ -->
<div class="modal-overlay" id="manualModal">
    <div class="modal-box">
        <div class="modal-title">➕ Input Absen Manual</div>
        <form method="POST">
            <input type="hidden" name="action" value="manual_att">
            <div class="form-group">
                <label class="form-label">Karyawan</label>
                <select name="employee_id" id="manualEmpId" class="form-select" required>
                    <option value="">-- Pilih Karyawan --</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Tanggal</label>
                <input type="date" name="attendance_date" id="manualDate" class="form-input" value="<?php echo $viewDate; ?>" required>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Check-In</label>
                    <input type="time" name="check_in_time" class="form-input" value="08:00">
                </div>
                <div class="form-group">
                    <label class="form-label">Check-Out</label>
                    <input type="time" name="check_out_time" class="form-input" value="17:00">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="present">Hadir</option>
                    <option value="late">Terlambat</option>
                    <option value="absent">Absen</option>
                    <option value="leave">Izin</option>
                    <option value="holiday">Libur</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Catatan</label>
                <input type="text" name="notes" class="form-input" placeholder="Opsional">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('manualModal')">Batal</button>
                <button type="submit" class="btn-save">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ─ TABS ─
function switchTab(tab) {
    ['Daily','Monthly','Settings','Pins'].forEach(t => {
        document.getElementById('tabPanel'+t).style.display = 'none';
        document.getElementById('tab'+t).classList.remove('active');
    });
    const map = {daily:'Daily', monthly:'Monthly', settings:'Settings', pins:'Pins'};
    document.getElementById('tabPanel'+map[tab]).style.display = 'block';
    document.getElementById('tab'+map[tab]).classList.add('active');
    if (tab === 'settings') setTimeout(initAdminMap, 100);
    // persist tab in URL
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

// Restore tab from URL
const urlTab = new URLSearchParams(window.location.search).get('tab');
if (urlTab) switchTab(urlTab);

// ─ ADMIN MAP ─
let adminMap = null, adminMarker = null, adminCircle = null;
function initAdminMap() {
    if (adminMap) { adminMap.invalidateSize(); return; }
    const lat = parseFloat(document.getElementById('inputLat').value) || -6.2;
    const lng = parseFloat(document.getElementById('inputLng').value) || 106.82;
    adminMap = L.map('adminMap').setView([lat, lng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(adminMap);
    adminMarker = L.marker([lat, lng], { draggable: true }).addTo(adminMap);
    const rad = parseInt('<?php echo $config['allowed_radius_m'] ?? 200; ?>') || 200;
    adminCircle = L.circle([lat, lng], { radius: rad, color: '#f0b429', fillOpacity: 0.1 }).addTo(adminMap);
    // Click to move marker
    adminMap.on('click', function(e) {
        adminMarker.setLatLng(e.latlng);
        adminCircle.setLatLng(e.latlng);
        document.getElementById('inputLat').value = e.latlng.lat.toFixed(7);
        document.getElementById('inputLng').value = e.latlng.lng.toFixed(7);
    });
    adminMarker.on('dragend', function() {
        const pos = adminMarker.getLatLng();
        adminCircle.setLatLng(pos);
        document.getElementById('inputLat').value = pos.lat.toFixed(7);
        document.getElementById('inputLng').value = pos.lng.toFixed(7);
    });
    document.querySelector('[name=allowed_radius_m]').addEventListener('input', function() {
        adminCircle.setRadius(parseInt(this.value) || 200);
    });
}

// ─ MODALS ─
function openEditModal(att) {
    document.getElementById('editAttId').value = att.id;
    document.getElementById('editEmpName').textContent = att.full_name + ' — ' + att.attendance_date;
    document.getElementById('editCheckIn').value = att.check_in_time ? att.check_in_time.substring(0,5) : '';
    document.getElementById('editCheckOut').value = att.check_out_time ? att.check_out_time.substring(0,5) : '';
    document.getElementById('editStatus').value = att.status || 'present';
    document.getElementById('editNotes').value = att.notes || '';
    document.getElementById('editModal').classList.add('open');
}
function openFaceResetModal(id, name) {
    document.getElementById('pinEmpId').value = id;
    document.getElementById('pinEmpName').textContent = 'Karyawan: ' + name;
    document.getElementById('pinModal').classList.add('open');
}
function openManualModal() { document.getElementById('manualModal').classList.add('open'); }
function quickManualAdd(id, name, date) {
    document.getElementById('manualEmpId').value = id;
    document.getElementById('manualDate').value = date;
    document.getElementById('manualModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ─ COPY URL ─
function copyAbsenUrl() {
    const el = document.getElementById('absenUrlInput');
    el.select();
    navigator.clipboard.writeText(el.value).then(() => {
        const btn = event.target;
        btn.textContent = '✅ Tersalin!';
        setTimeout(() => btn.textContent = '📋 Salin Link', 2000);
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
