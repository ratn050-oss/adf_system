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

// Always ensure config table exists (idempotent — safe to run every time)
$_pdo = $db->getConnection();
$_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance_config` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `office_lat` DECIMAL(10,7) NOT NULL DEFAULT -6.2000000,
    `office_lng` DECIMAL(10,7) NOT NULL DEFAULT 106.8166700,
    `allowed_radius_m` INT NOT NULL DEFAULT 200,
    `office_name` VARCHAR(100) DEFAULT 'Kantor',
    `checkin_start` TIME DEFAULT '07:00:00',
    `checkin_end` TIME DEFAULT '10:00:00',
    `checkout_start` TIME DEFAULT '16:00:00',
    `allow_outside` TINYINT(1) DEFAULT 0,
    `app_logo` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$_pdo->exec("INSERT IGNORE INTO `payroll_attendance_config` (`id`) VALUES (1)");

try {
    $db->query("SELECT 1 FROM payroll_attendance LIMIT 1");
} catch (Exception $e) {
    $pdo = $db->getConnection();
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

// Auto-create multi-location table
try {
    $db->query("SELECT 1 FROM payroll_attendance_locations LIMIT 1");
} catch (Exception $e) {
    $pdo2 = $db->getConnection();
    $pdo2->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance_locations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `location_name` VARCHAR(100) NOT NULL,
        `address` VARCHAR(255) DEFAULT NULL,
        `lat` DECIMAL(10,7) NOT NULL,
        `lng` DECIMAL(10,7) NOT NULL,
        `radius_m` INT NOT NULL DEFAULT 200,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Migrate existing single-location config if already set
    $existCfg = $pdo2->query("SELECT * FROM payroll_attendance_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if ($existCfg && abs((float)$existCfg['office_lat']) > 0.01) {
        $pdo2->prepare("INSERT INTO payroll_attendance_locations (location_name, lat, lng, radius_m) VALUES (?,?,?,?)")
             ->execute([$existCfg['office_name'] ?? 'Kantor Utama', $existCfg['office_lat'], $existCfg['office_lng'], $existCfg['allowed_radius_m'] ?? 200]);
    }
}

// Add app_logo column if missing
try {
    $db->getConnection()->query("SELECT app_logo FROM payroll_attendance_config LIMIT 1");
} catch (PDOException $e) {
    $db->getConnection()->exec("ALTER TABLE payroll_attendance_config ADD COLUMN `app_logo` VARCHAR(255) DEFAULT NULL");
}

// ── Actions ──
$msg = '';
$msgType = '';

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $ciStart = $_POST['checkin_start'];
    $ciEnd   = $_POST['checkin_end'];
    $coStart = $_POST['checkout_start'];
    $allowOut = isset($_POST['allow_outside']) ? 1 : 0;
    $db->query("UPDATE payroll_attendance_config SET checkin_start=?, checkin_end=?, checkout_start=?, allow_outside=?, updated_by=? WHERE id=1",
        [$ciStart, $ciEnd, $coStart, $allowOut, $currentUser['id']]);
    $msg = 'Pengaturan waktu berhasil disimpan.';
    $msgType = 'success';
}

// Save logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_logo') {
    $uploadDir = __DIR__ . '/../../uploads/attendance_logos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (!empty($_FILES['logo_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','svg','webp'];
        if (in_array($ext, $allowed)) {
            $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower(defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'biz'));
            $filename = 'logo_' . $slug . '.' . $ext;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadDir . $filename)) {
                $logoPath = 'uploads/attendance_logos/' . $filename;
                $db->getConnection()->prepare("UPDATE payroll_attendance_config SET app_logo=? WHERE id=1")->execute([$logoPath]);
                $msg = '✅ Logo berhasil disimpan.';
                $msgType = 'success';
            }
        } else {
            $msg = '❌ Format file tidak didukung. Gunakan PNG, JPG, SVG, atau WebP.';
            $msgType = 'error';
        }
    } elseif (!empty($_POST['remove_logo'])) {
        $db->getConnection()->prepare("UPDATE payroll_attendance_config SET app_logo=NULL WHERE id=1")->execute();
        $msg = 'Logo dihapus.';
        $msgType = 'success';
    }
}

// Add / Edit / Delete location
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locAction = $_POST['action'] ?? '';
    if ($locAction === 'add_location' || $locAction === 'edit_location' || $locAction === 'delete_location') {
        // Ensure table exists (idempotent)
        $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance_locations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `location_name` VARCHAR(100) NOT NULL,
            `address` VARCHAR(255) DEFAULT NULL,
            `lat` DECIMAL(10,7) NOT NULL DEFAULT 0,
            `lng` DECIMAL(10,7) NOT NULL DEFAULT 0,
            `radius_m` INT NOT NULL DEFAULT 200,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($locAction === 'add_location' || $locAction === 'edit_location') {
        $locName   = trim(htmlspecialchars($_POST['loc_name'] ?? 'Lokasi'));
        $locAddr   = trim(htmlspecialchars($_POST['loc_address'] ?? ''));
        $locLat    = (float)($_POST['loc_lat'] ?? 0);
        $locLng    = (float)($_POST['loc_lng'] ?? 0);
        $locRad    = max(10, min(10000, (int)($_POST['loc_radius'] ?? 200)));
        $locActive = isset($_POST['loc_active']) ? 1 : 0;
        try {
            $pdo = $db->getConnection();
            if ($locAction === 'add_location') {
                $pdo->prepare("INSERT INTO payroll_attendance_locations (location_name, address, lat, lng, radius_m, is_active) VALUES (?,?,?,?,?,?)")
                    ->execute([$locName, $locAddr, $locLat, $locLng, $locRad, 1]);
                $msg = "✅ Lokasi '{$locName}' berhasil ditambahkan."; $msgType = 'success';
            } else {
                $locId = (int)($_POST['loc_id'] ?? 0);
                $pdo->prepare("UPDATE payroll_attendance_locations SET location_name=?, address=?, lat=?, lng=?, radius_m=?, is_active=? WHERE id=?")
                    ->execute([$locName, $locAddr, $locLat, $locLng, $locRad, $locActive, $locId]);
                $msg = "✅ Lokasi '{$locName}' berhasil diperbarui."; $msgType = 'success';
            }
        } catch (Exception $e) {
            $msg = '❌ Gagal menyimpan lokasi: ' . $e->getMessage(); $msgType = 'error';
        }
    }
    if ($locAction === 'delete_location') {
        $locId = (int)($_POST['loc_id'] ?? 0);
        try {
            $db->getConnection()->prepare("DELETE FROM payroll_attendance_locations WHERE id=?")->execute([$locId]);
            $msg = '✅ Lokasi berhasil dihapus.'; $msgType = 'success';
        } catch (Exception $e) {
            $msg = '❌ Gagal menghapus: ' . $e->getMessage(); $msgType = 'error';
        }
    }
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
$locations = $db->fetchAll("SELECT * FROM payroll_attendance_locations ORDER BY id") ?: [];

// Helper: find nearest registered location from GPS coords (Haversine)
function getAttendanceLocation(float $lat, float $lng, array $locs): ?array {
    if (abs($lat) < 0.001 || abs($lng) < 0.001 || empty($locs)) return null;
    $nearest = null; $minDist = PHP_INT_MAX;
    foreach ($locs as $loc) {
        $dlat = deg2rad($lat - (float)$loc['lat']);
        $dlng = deg2rad($lng - (float)$loc['lng']);
        $a = sin($dlat/2)**2 + cos(deg2rad($lat)) * cos(deg2rad((float)$loc['lat'])) * sin($dlng/2)**2;
        $dist = (int) round(6371000 * 2 * atan2(sqrt($a), sqrt(1-$a)));
        if ($dist < $minDist) { $minDist = $dist; $nearest = array_merge($loc, ['_dist' => $dist]); }
    }
    return $nearest;
}

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
.att-stat { background:#fff; padding:16px 18px; border-radius:12px; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,0.05); border-top:3px solid var(--border); }
.att-stat .label { font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
.att-stat .value { font-size:28px; font-weight:800; color:var(--navy); margin-top:6px; line-height:1; }
.att-stat .sub { font-size:11px; color:var(--muted); margin-top:4px; }
.att-stat.success { border-top-color:var(--green); } .att-stat.success .value { color:var(--green); }
.att-stat.warning { border-top-color:var(--orange); } .att-stat.warning .value { color:var(--orange); }
.att-stat.info { border-top-color:#c084fc; } .att-stat.info .value { color:#7c3aed; }

/* Tabs */
.att-tabs { display:flex; gap:4px; background:var(--bg); padding:3px; border-radius:10px; margin-bottom:14px; border:1px solid var(--border); }
.att-tab { flex:1; padding:8px 12px; border:none; background:transparent; border-radius:8px; cursor:pointer; font-weight:600; font-size:12px; color:var(--muted); transition:all 0.15s; }
.att-tab.active { background:var(--gold); color:var(--navy); font-weight:800; }

/* Table */
.att-table-wrap { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 10px rgba(0,0,0,0.06); border:1px solid var(--border); }
.att-table { width:100%; border-collapse:collapse; }
.att-table th { background:#f8fafc; color:#475569; padding:11px 14px; text-align:left; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid var(--gold); white-space:nowrap; }
.att-table td { padding:13px 14px; border-bottom:1px solid #f1f5f9; font-size:13px; color:#1e293b; vertical-align:middle; }
.att-table tr:last-child td { border-bottom:none; }
.att-table tr:hover td { background:#fafbfd; }
/* location badge in table */
.loc-pill { display:inline-flex; align-items:center; gap:5px; background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:700; white-space:nowrap; max-width:160px; overflow:hidden; text-overflow:ellipsis; }
.loc-pill.outside { background:#fff7ed; color:#9a3412; border-color:#fed7aa; }
.loc-pill.unknown { background:#f1f5f9; color:#64748b; border-color:#e2e8f0; }

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

/* URL bar */
.att-url-bar { background:#fff; border:1px solid var(--border); border-radius:10px; padding:10px 14px; margin-bottom:16px; display:flex; align-items:center; gap:10px; box-shadow:0 1px 4px rgba(0,0,0,0.04); }
.att-url-bar .url-label { font-size:11px; font-weight:700; color:var(--muted); white-space:nowrap; text-transform:uppercase; letter-spacing:0.4px; }
.att-url-bar input { flex:1; border:1.5px solid var(--border); border-radius:7px; padding:7px 10px; font-size:12px; color:var(--navy); font-family:monospace; background:#f8fafc; min-width:0; }
.att-url-bar button { padding:7px 14px; background:var(--navy); color:#fff; border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap; }

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
/* Location cards */
.loc-card { background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:12px 14px; }
.loc-card:hover { border-color:#f0b429; }
.loc-inactive { opacity:0.55; }
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

    <!-- URL bar -->
    <div class="att-url-bar">
        <span class="url-label">📲 Link Absen</span>
        <input type="text" value="<?php echo htmlspecialchars($absenUrl); ?>" readonly id="absenUrlInput">
        <button onclick="copyAbsenUrl()">📋 Salin Link</button>
    </div>

    <!-- Stats -->
    <div class="att-stats">
        <div class="att-stat">
            <div class="label">Total Karyawan</div>
            <div class="value"><?php echo $todayStats['total']; ?></div>
            <div class="sub">karyawan aktif</div>
        </div>
        <div class="att-stat success">
            <div class="label">Hadir Hari Ini</div>
            <div class="value"><?php echo $todayStats['present']; ?></div>
            <div class="sub"><?php echo $todayStats['total'] > 0 ? round($todayStats['present']/$todayStats['total']*100).'%' : '0%'; ?> kehadiran</div>
        </div>
        <div class="att-stat warning">
            <div class="label">Terlambat</div>
            <div class="value"><?php echo $todayStats['late']; ?></div>
            <div class="sub">dari yang hadir</div>
        </div>
        <div class="att-stat info">
            <div class="label">Belum Absen</div>
            <div class="value"><?php echo max(0, $todayStats['total'] - $todayStats['present']); ?></div>
            <div class="sub">perlu perhatian</div>
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
                <button type="submit" style="padding:8px 16px; background:var(--gold); color:var(--navy); border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer;">Tampilkan</button>
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
                    <th>Lokasi Absen</th>
                    <th>GPS</th>
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
                    <td style="font-weight:700;"><?php echo $a && $a['check_in_time'] ? substr($a['check_in_time'],0,5) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td style="font-weight:700; color:var(--muted);"><?php echo $a && $a['check_out_time'] ? '<span style="color:var(--navy)">'.substr($a['check_out_time'],0,5).'</span>' : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td><?php echo $a && $a['work_hours'] ? '<strong>'.number_format($a['work_hours'],1).'</strong><span style="font-size:11px;color:var(--muted);"> jam</span>' : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td>
                        <?php
                        if ($a && (abs((float)($a['check_in_lat'] ?? 0)) > 0.001)) {
                            $nearLoc = getAttendanceLocation((float)$a['check_in_lat'], (float)$a['check_in_lng'], $locations);
                            if ($nearLoc) {
                                $isOut = (bool)($a['is_outside_radius'] ?? false);
                                $pillClass = $isOut ? 'loc-pill outside' : 'loc-pill';
                                $distTxt = $nearLoc['_dist'] < 1000 ? $nearLoc['_dist'].'m' : round($nearLoc['_dist']/1000,1).'km';
                                echo '<span class="'.$pillClass.'" title="'.htmlspecialchars($nearLoc['location_name']).' — '.$distTxt.'">📍 '.htmlspecialchars(mb_substr($nearLoc['location_name'],0,22)).'</span>';
                            } else {
                                echo '<span class="loc-pill unknown">🌐 Luar Area</span>';
                            }
                        } else {
                            echo '<span style="color:#d1d5db;">—</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ($a && $a['check_in_distance_m']): ?>
                            <span style="font-size:12px; font-weight:600; color:<?php echo $a['is_outside_radius'] ? 'var(--red)' : 'var(--green)'; ?>">
                                <?php echo $a['check_in_distance_m']; ?>m <?php echo $a['is_outside_radius'] ? '⚠️' : '✅'; ?>
                            </span>
                        <?php else: echo '<span style="color:#d1d5db;">—</span>'; endif; ?>
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
                <button type="submit" style="padding:8px 16px; background:var(--gold); color:var(--navy); border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer;">Tampilkan</button>
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
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; align-items:start;">

            <!-- LEFT: Logo + Time settings + Locations list -->
            <div>
                <!-- Logo Upload -->
                <div style="background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:14px;">
                    <h3 style="font-size:14px; font-weight:700; color:var(--navy); margin:0 0 14px;">🖼️ Logo Aplikasi Absen</h3>
                    <?php if (!empty($config['app_logo'])): ?>
                    <div style="margin-bottom:12px; display:flex; align-items:center; gap:12px;">
                        <img src="<?php echo $baseUrl . '/' . htmlspecialchars($config['app_logo']); ?>" style="height:56px; max-width:160px; object-fit:contain; border-radius:8px; border:1px solid #eee; padding:4px; background:#fafafa;">
                        <form method="POST" action="?tab=settings" style="margin:0;">
                            <input type="hidden" name="action" value="save_logo">
                            <input type="hidden" name="remove_logo" value="1">
                            <button type="submit" style="font-size:11px; color:#e74c3c; background:none; border:1px solid #e74c3c; border-radius:6px; padding:4px 10px; cursor:pointer;">🗑️ Hapus</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div style="font-size:12px; color:var(--muted); margin-bottom:10px;">Belum ada logo. Upload logo untuk ditampilkan di halaman absen karyawan.</div>
                    <?php endif; ?>
                    <form method="POST" action="?tab=settings" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_logo">
                        <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.svg,.webp" class="form-input" style="padding:6px; font-size:12px;">
                        <div style="font-size:10px; color:var(--muted); margin:4px 0 8px;">Format: PNG, JPG, SVG, WebP — Rekomendasi ukuran: 200×60 px</div>
                        <button type="submit" class="btn-save" style="width:100%;">📤 Upload Logo</button>
                    </form>
                </div>

                <!-- Time Settings -->
                <div style="background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:14px;">
                    <h3 style="font-size:14px; font-weight:700; color:var(--navy); margin:0 0 14px;">🕐 Pengaturan Waktu Absen</h3>
                    <form method="POST" action="?tab=settings">
                        <input type="hidden" name="action" value="save_config">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Jam Masuk (Awal)</label>
                                <input type="time" name="checkin_start" class="form-input" value="<?php echo $config['checkin_start'] ?? '07:00'; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Batas Masuk (Terlambat)</label>
                                <input type="time" name="checkin_end" class="form-input" value="<?php echo $config['checkin_end'] ?? '10:00'; ?>">
                                <div style="font-size:10px; color:var(--muted); margin-top:3px;">Check-in setelah jam ini = Terlambat</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-Out Boleh Mulai Jam</label>
                            <input type="time" name="checkout_start" class="form-input" value="<?php echo $config['checkout_start'] ?? '16:00'; ?>">
                        </div>
                        <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="allow_outside" id="allowOut" <?php echo ($config['allow_outside'] ?? 0) ? 'checked' : ''; ?>>
                            <label for="allowOut" style="font-size:12px; color:var(--navy);">Izinkan absen di luar radius (⚠️ peringatan saja)</label>
                        </div>
                        <button type="submit" class="btn-save" style="width:100%;">💾 Simpan Waktu</button>
                    </form>
                </div>

                <!-- Locations List -->
                <div style="background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                        <h3 style="font-size:14px; font-weight:700; color:var(--navy); margin:0;">📍 Lokasi Proyek / Kantor</h3>
                        <button onclick="openLocModal()" class="btn-save" style="font-size:11px; padding:7px 14px; background:var(--gold); color:var(--navy);">➕ Tambah Lokasi</button>
                    </div>
                    <?php if (empty($locations)): ?>
                    <div style="text-align:center; padding:24px; color:var(--muted); font-size:13px;">
                        Belum ada lokasi. Klik <strong>➕ Tambah Lokasi</strong> untuk menambahkan.
                    </div>
                    <?php else: ?>
                    <div style="display:flex; flex-direction:column; gap:8px;" id="locList">
                        <?php foreach ($locations as $loc): ?>
                        <div class="loc-card <?php echo $loc['is_active'] ? '' : 'loc-inactive'; ?>" data-id="<?php echo $loc['id']; ?>">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <div style="flex:1;">
                                    <div style="font-size:13px; font-weight:700; color:var(--navy);">
                                        <?php echo $loc['is_active'] ? '🟢' : '⚫'; ?> <?php echo htmlspecialchars($loc['location_name']); ?>
                                    </div>
                                    <?php if ($loc['address']): ?>
                                    <div style="font-size:11px; color:var(--muted); margin-top:2px;"><?php echo htmlspecialchars($loc['address']); ?></div>
                                    <?php endif; ?>
                                    <div style="font-size:11px; color:var(--muted); margin-top:3px; font-family:monospace;">
                                        <?php echo number_format((float)$loc['lat'],7); ?>, <?php echo number_format((float)$loc['lng'],7); ?> · Radius: <?php echo $loc['radius_m']; ?>m
                                    </div>
                                </div>
                                <div style="display:flex; gap:4px; flex-shrink:0; margin-left:10px;">
                    <button class="act-btn act-btn-edit" onclick='openLocModal(<?php echo json_encode($loc); ?>)'>✏️</button>
                                    <form method="POST" action="?tab=settings" style="display:inline;" onsubmit="return confirm('Hapus lokasi ini?')">
                                        <input type="hidden" name="action" value="delete_location">
                                        <input type="hidden" name="loc_id" value="<?php echo $loc['id']; ?>">
                                        <button type="submit" class="act-btn act-btn-del">🗑</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Map preview -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px; position:sticky; top:10px;">
                <h3 style="font-size:14px; font-weight:700; color:var(--navy); margin:0 0 10px;">🗺️ Preview Semua Lokasi</h3>
                <div id="adminMap" style="height:320px;"></div>
                <div style="font-size:11px; color:var(--muted); margin-top:6px;">Semua lokasi aktif ditampilkan di peta. Klik ➕ Tambah Lokasi untuk setting koordinat via klik peta.</div>
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

<!-- ═══ MODAL: ADD / EDIT LOCATION ═══ -->
<div class="modal-overlay" id="locModal">
    <div class="modal-box" style="max-width:540px;">
        <div class="modal-title" id="locModalTitle">📍 Tambah Lokasi Baru</div>
        <form method="POST" action="?tab=settings" id="locForm" onsubmit="return validateLocForm()">
            <input type="hidden" name="action" id="locFormAction" value="add_location">
            <input type="hidden" name="loc_id" id="locFormId" value="">
            <div class="form-group">
                <label class="form-label">Nama Lokasi / Proyek</label>
                <input type="text" name="loc_name" id="locName" class="form-input" placeholder="mis: Proyek PLN Semarang" required>
            </div>
            <div class="form-group">
                <label class="form-label">Alamat (opsional)</label>
                <input type="text" name="loc_address" id="locAddress" class="form-input" placeholder="Alamat lengkap">
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Latitude</label>
                    <input type="text" name="loc_lat" id="locLat" class="form-input" placeholder="-6.2000000" required readonly style="background:#f8fafc;">
                </div>
                <div class="form-group">
                    <label class="form-label">Longitude</label>
                    <input type="text" name="loc_lng" id="locLng" class="form-input" placeholder="106.8166700" required readonly style="background:#f8fafc;">
                </div>
            </div>
            <div style="font-size:11px; color:#2563eb; background:#eff6ff; border:1px solid #bfdbfe; border-radius:7px; padding:8px 10px; margin-bottom:10px;">
                📌 <strong>Klik pada peta di bawah</strong> untuk menentukan titik lokasi, atau gunakan tombol "Gunakan Lokasi Saya".
            </div>
            <div id="locPickerMap" style="height:200px; border-radius:8px; border:1px solid var(--border); margin-bottom:10px;"></div>
            <div style="display:flex; gap:8px; margin-bottom:10px;">
                <button type="button" onclick="useMyGPS()" style="padding:7px 14px; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer;">📍 Gunakan Lokasi Saya</button>
                <span id="locGpsStatus" style="font-size:11px; color:var(--muted); line-height:2;"></span>
            </div>
            <div class="form-group">
                <label class="form-label">Radius Absen (meter)</label>
                <input type="number" name="loc_radius" id="locRadius" class="form-input" value="200" min="10" max="10000">
            </div>
            <div class="form-group" id="locActiveGroup" style="display:none;">
                <label style="display:flex; align-items:center; gap:8px; font-size:12px;">
                    <input type="checkbox" name="loc_active" id="locActive"> Lokasi aktif (karyawan bisa absen di sini)
                </label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('locModal')">Batal</button>
                <button type="submit" class="btn-save">💾 Simpan Lokasi</button>
            </div>
        </form>
    </div>
</div>

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

// ─ LOCATION FORM VALIDATION (plain POST to ?tab=settings) ─
function validateLocForm() {
    const lat = document.getElementById('locLat').value.trim();
    const lng = document.getElementById('locLng').value.trim();
    const name = document.getElementById('locName').value.trim();
    if (!name) { alert('Masukkan nama lokasi.'); return false; }
    if (!lat || !lng || (parseFloat(lat) === 0 && parseFloat(lng) === 0)) {
        const s = document.getElementById('locGpsStatus');
        s.textContent = '❌ Tentukan titik lokasi dulu — klik peta atau gunakan GPS.';
        s.style.color = '#dc2626';
        return false;
    }
    return true;
}

// ─ ADMIN MAP (overview) ─
let adminMap = null;
const allLocations = <?php echo json_encode(array_values($locations)); ?>;
function initAdminMap() {
    if (adminMap) { adminMap.invalidateSize(); return; }
    const center = allLocations.length
        ? [allLocations[0].lat, allLocations[0].lng]
        : [-6.2, 106.82];
    adminMap = L.map('adminMap').setView(center, 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(adminMap);
    const bounds = [];
    allLocations.forEach(loc => {
        const ll = [parseFloat(loc.lat), parseFloat(loc.lng)];
        bounds.push(ll);
        const color = loc.is_active ? '#f0b429' : '#94a3b8';
        L.circle(ll, { radius: parseInt(loc.radius_m), color, fillOpacity: 0.15, weight: 2 }).addTo(adminMap)
            .bindTooltip(loc.location_name);
        L.marker(ll).addTo(adminMap)
            .bindPopup(`<b>${loc.location_name}</b><br>Radius: ${loc.radius_m}m<br>${loc.is_active ? '🟢 Aktif' : '⚫ Nonaktif'}`);
    });
    if (bounds.length > 1) adminMap.fitBounds(bounds, { padding: [30, 30] });
}

// ─ LOCATION PICKER MAP ─
let locPickerMap = null, locPickerMarker = null, locPickerCircle = null;
function initLocPickerMap(lat, lng, radius) {
    lat = lat || -6.2; lng = lng || 106.82; radius = radius || 200;
    if (!locPickerMap) {
        locPickerMap = L.map('locPickerMap').setView([lat, lng], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(locPickerMap);
        locPickerMarker = L.marker([lat, lng], { draggable: true }).addTo(locPickerMap);
        locPickerCircle = L.circle([lat, lng], { radius, color: '#f0b429', fillOpacity: 0.15 }).addTo(locPickerMap);
        locPickerMap.on('click', function(e) {
            locPickerMarker.setLatLng(e.latlng); locPickerCircle.setLatLng(e.latlng);
            document.getElementById('locLat').value = e.latlng.lat.toFixed(7);
            document.getElementById('locLng').value = e.latlng.lng.toFixed(7);
        });
        locPickerMarker.on('dragend', function() {
            const pos = locPickerMarker.getLatLng();
            locPickerCircle.setLatLng(pos);
            document.getElementById('locLat').value = pos.lat.toFixed(7);
            document.getElementById('locLng').value = pos.lng.toFixed(7);
        });
        document.getElementById('locRadius').addEventListener('input', function() {
            locPickerCircle.setRadius(parseInt(this.value) || 200);
        });
    } else {
        locPickerMap.setView([lat, lng], 16);
        locPickerMarker.setLatLng([lat, lng]);
        locPickerCircle.setLatLng([lat, lng]).setRadius(radius);
        locPickerMap.invalidateSize();
    }
    document.getElementById('locLat').value = lat;
    document.getElementById('locLng').value = lng;
}

function openLocModal(loc) {
    if (loc) {
        document.getElementById('locModalTitle').textContent = '✏️ Edit Lokasi';
        document.getElementById('locFormAction').value = 'edit_location';
        document.getElementById('locFormId').value = loc.id;
        document.getElementById('locName').value = loc.location_name;
        document.getElementById('locAddress').value = loc.address || '';
        document.getElementById('locRadius').value = loc.radius_m;
        document.getElementById('locActive').checked = loc.is_active == 1;
        document.getElementById('locActiveGroup').style.display = 'block';
        document.getElementById('locModal').classList.add('open');
        setTimeout(() => initLocPickerMap(parseFloat(loc.lat), parseFloat(loc.lng), parseInt(loc.radius_m)), 100);
    } else {
        document.getElementById('locModalTitle').textContent = '📌 Tambah Lokasi Baru';
        document.getElementById('locFormAction').value = 'add_location';
        document.getElementById('locFormId').value = '';
        document.getElementById('locForm').reset();
        document.getElementById('locRadius').value = 200;
        document.getElementById('locActiveGroup').style.display = 'none';
        document.getElementById('locModal').classList.add('open');
        const firstLoc = allLocations[0];
        setTimeout(() => initLocPickerMap(
            firstLoc ? parseFloat(firstLoc.lat) : -6.2,
            firstLoc ? parseFloat(firstLoc.lng) : 106.82, 200
        ), 100);
    }
}

function useMyGPS() {
    const status = document.getElementById('locGpsStatus');
    status.textContent = '📡 Mengambil GPS...';
    navigator.geolocation.getCurrentPosition(pos => {
        const lat = pos.coords.latitude, lng = pos.coords.longitude;
        document.getElementById('locLat').value = lat.toFixed(7);
        document.getElementById('locLng').value = lng.toFixed(7);
        if (locPickerMarker) {
            locPickerMarker.setLatLng([lat, lng]);
            locPickerCircle.setLatLng([lat, lng]);
            locPickerMap.setView([lat, lng], 17);
        }
        status.textContent = '✅ ±' + Math.round(pos.coords.accuracy) + 'm akurasi';
    }, err => { status.textContent = '❌ ' + err.message; }, { enableHighAccuracy: true });
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
