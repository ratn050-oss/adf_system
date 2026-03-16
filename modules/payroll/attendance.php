<?php
/**
 * Payroll Attendance Dashboard - Redesigned
 * Tabs: Dashboard Harian | Absen GPS | Fingerprint | Manual | Reset
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/CloudinaryHelper.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$_pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Absensi Karyawan';
$baseUrl = defined('BASE_URL') ? BASE_URL : '';

// ══════════════════════════════════════════════
// AUTO-CREATE TABLES & COLUMNS (idempotent)
// ══════════════════════════════════════════════

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
    `fingerspot_cloud_id` VARCHAR(50) DEFAULT NULL,
    `fingerspot_enabled` TINYINT(1) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$_pdo->exec("INSERT IGNORE INTO `payroll_attendance_config` (`id`) VALUES (1)");

// Ensure columns exist
$autoColumns = [
    ['payroll_attendance_config', 'fingerspot_cloud_id', "VARCHAR(50) DEFAULT NULL"],
    ['payroll_attendance_config', 'fingerspot_enabled', "TINYINT(1) DEFAULT 0"],
    ['payroll_attendance_config', 'app_logo', "VARCHAR(255) DEFAULT NULL"],
    ['payroll_employees', 'attendance_pin', "VARCHAR(6) DEFAULT NULL"],
    ['payroll_employees', 'finger_id', "VARCHAR(20) DEFAULT NULL"],
    ['payroll_employees', 'monthly_target_hours', "INT DEFAULT 200"],
    ['payroll_employees', 'face_descriptor', "TEXT DEFAULT NULL"],
];
foreach ($autoColumns as [$tbl, $col, $def]) {
    try { $_pdo->query("SELECT `$col` FROM `$tbl` LIMIT 0"); } catch (PDOException $e) {
        $_pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
    }
}

// Attendance table
try { $_pdo->query("SELECT 1 FROM payroll_attendance LIMIT 0"); } catch (PDOException $e) {
    $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `attendance_date` DATE NOT NULL,
        `check_in_time` TIME DEFAULT NULL,
        `check_in_lat` DECIMAL(10,7) DEFAULT NULL, `check_in_lng` DECIMAL(10,7) DEFAULT NULL,
        `check_in_distance_m` INT DEFAULT NULL, `check_in_address` VARCHAR(255) DEFAULT NULL, `check_in_device` VARCHAR(200) DEFAULT NULL,
        `check_out_time` TIME DEFAULT NULL,
        `check_out_lat` DECIMAL(10,7) DEFAULT NULL, `check_out_lng` DECIMAL(10,7) DEFAULT NULL,
        `check_out_distance_m` INT DEFAULT NULL, `check_out_device` VARCHAR(200) DEFAULT NULL,
        `scan_3` TIME DEFAULT NULL, `scan_4` TIME DEFAULT NULL,
        `work_hours` DECIMAL(5,2) DEFAULT NULL,
        `shift_1_hours` DECIMAL(5,2) DEFAULT NULL, `shift_2_hours` DECIMAL(5,2) DEFAULT NULL,
        `status` ENUM('present','late','absent','leave','holiday','half_day') NOT NULL DEFAULT 'present',
        `is_outside_radius` TINYINT(1) DEFAULT 0,
        `notes` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_attendance` (`employee_id`, `attendance_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Split shift columns
$shiftCols = ['scan_3' => 'TIME DEFAULT NULL', 'scan_4' => 'TIME DEFAULT NULL', 'shift_1_hours' => 'DECIMAL(5,2) DEFAULT NULL', 'shift_2_hours' => 'DECIMAL(5,2) DEFAULT NULL'];
foreach ($shiftCols as $col => $def) {
    try { $_pdo->query("SELECT `$col` FROM payroll_attendance LIMIT 0"); } catch (PDOException $e) {
        $_pdo->exec("ALTER TABLE payroll_attendance ADD COLUMN `$col` $def");
    }
}

// Locations table
try { $_pdo->query("SELECT 1 FROM payroll_attendance_locations LIMIT 0"); } catch (PDOException $e) {
    $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance_locations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `location_name` VARCHAR(100) NOT NULL, `address` VARCHAR(255) DEFAULT NULL,
        `lat` DECIMAL(10,7) NOT NULL DEFAULT 0, `lng` DECIMAL(10,7) NOT NULL DEFAULT 0,
        `radius_m` INT NOT NULL DEFAULT 200, `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Fingerprint log table
try { $_pdo->query("SELECT 1 FROM fingerprint_log LIMIT 0"); } catch (PDOException $e) {
    $_pdo->exec("CREATE TABLE IF NOT EXISTS `fingerprint_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `cloud_id` VARCHAR(50) NOT NULL, `type` VARCHAR(32) DEFAULT 'attlog',
        `pin` VARCHAR(20) DEFAULT NULL, `scan_time` DATETIME DEFAULT NULL,
        `verify_method` VARCHAR(30) DEFAULT NULL, `status_scan` VARCHAR(30) DEFAULT NULL,
        `employee_id` INT DEFAULT NULL, `processed` TINYINT(1) DEFAULT 0,
        `process_result` VARCHAR(255) DEFAULT NULL, `raw_data` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cloud (cloud_id), INDEX idx_pin (pin), INDEX idx_scan (scan_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ══════════════════════════════════════════════
// POST ACTIONS
// ══════════════════════════════════════════════
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Save GPS/Time settings ──
    if ($action === 'save_config') {
        $ciStart = $_POST['checkin_start'] ?? '07:00';
        $ciEnd   = $_POST['checkin_end'] ?? '10:00';
        $coStart = $_POST['checkout_start'] ?? '16:00';
        $allowOut = isset($_POST['allow_outside']) ? 1 : 0;
        $db->query("UPDATE payroll_attendance_config SET checkin_start=?, checkin_end=?, checkout_start=?, allow_outside=?, updated_by=? WHERE id=1",
            [$ciStart, $ciEnd, $coStart, $allowOut, $currentUser['id']]);
        $msg = 'Pengaturan waktu berhasil disimpan.'; $msgType = 'success';
    }

    // ── Save logo ──
    if ($action === 'save_logo') {
        if (!empty($_FILES['logo_file']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','svg','webp'])) {
                $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower(defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'biz'));
                $filename = 'logo_' . $slug . '.' . $ext;
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload($_FILES['logo_file'], 'uploads/attendance_logos', $filename, 'attendance', 'attendance_logo_' . $slug);
                if ($uploadResult['success']) {
                    $_pdo->prepare("UPDATE payroll_attendance_config SET app_logo=? WHERE id=1")->execute([$uploadResult['path']]);
                    $msg = '✅ Logo berhasil disimpan.'; $msgType = 'success';
                }
            } else { $msg = '❌ Format tidak didukung.'; $msgType = 'error'; }
        } elseif (!empty($_POST['remove_logo'])) {
            $_pdo->prepare("UPDATE payroll_attendance_config SET app_logo=NULL WHERE id=1")->execute();
            $msg = 'Logo dihapus.'; $msgType = 'success';
        }
    }

    // ── Location CRUD ──
    if ($action === 'add_location' || $action === 'edit_location') {
        $locName   = trim(htmlspecialchars($_POST['loc_name'] ?? 'Lokasi'));
        $locAddr   = trim(htmlspecialchars($_POST['loc_address'] ?? ''));
        $locLat    = (float)($_POST['loc_lat'] ?? 0);
        $locLng    = (float)($_POST['loc_lng'] ?? 0);
        $locRad    = max(10, min(10000, (int)($_POST['loc_radius'] ?? 200)));
        $locActive = isset($_POST['loc_active']) ? 1 : 0;
        try {
            if ($action === 'add_location') {
                $_pdo->prepare("INSERT INTO payroll_attendance_locations (location_name, address, lat, lng, radius_m, is_active) VALUES (?,?,?,?,?,1)")
                    ->execute([$locName, $locAddr, $locLat, $locLng, $locRad]);
                $msg = "✅ Lokasi '{$locName}' ditambahkan."; $msgType = 'success';
            } else {
                $locId = (int)($_POST['loc_id'] ?? 0);
                $_pdo->prepare("UPDATE payroll_attendance_locations SET location_name=?, address=?, lat=?, lng=?, radius_m=?, is_active=? WHERE id=?")
                    ->execute([$locName, $locAddr, $locLat, $locLng, $locRad, $locActive, $locId]);
                $msg = "✅ Lokasi diperbarui."; $msgType = 'success';
            }
        } catch (Exception $e) { $msg = '❌ Error: ' . $e->getMessage(); $msgType = 'error'; }
    }
    if ($action === 'delete_location') {
        $_pdo->prepare("DELETE FROM payroll_attendance_locations WHERE id=?")->execute([(int)($_POST['loc_id'] ?? 0)]);
        $msg = '✅ Lokasi dihapus.'; $msgType = 'success';
    }

    // ── Fingerspot config ──
    if ($action === 'save_fingerspot') {
        $fpCloudId = trim($_POST['fingerspot_cloud_id'] ?? '');
        $fpEnabled = isset($_POST['fingerspot_enabled']) ? 1 : 0;
        $_pdo->prepare("UPDATE payroll_attendance_config SET fingerspot_cloud_id=?, fingerspot_enabled=?, updated_by=? WHERE id=1")
            ->execute([$fpCloudId ?: null, $fpEnabled, $currentUser['id']]);
        $msg = '✅ Pengaturan Fingerspot disimpan.'; $msgType = 'success';
    }

    // ── Edit attendance ──
    if ($action === 'edit_att') {
        $attId = (int)$_POST['att_id'];
        $status = $_POST['status'] ?? 'present';
        $s1 = !empty($_POST['scan_1']) ? $_POST['scan_1'] . ':00' : null;
        $s2 = !empty($_POST['scan_2']) ? $_POST['scan_2'] . ':00' : null;
        $s3 = !empty($_POST['scan_3']) ? $_POST['scan_3'] . ':00' : null;
        $s4 = !empty($_POST['scan_4']) ? $_POST['scan_4'] . ':00' : null;
        $notes = trim($_POST['notes'] ?? '');
        $sh1 = null; $sh2 = null;
        if ($s1 && $s2) { $t1 = strtotime("2000-01-01 $s1"); $t2 = strtotime("2000-01-01 $s2"); $sh1 = ($t2>$t1)?round(($t2-$t1)/3600,2):null; }
        if ($s3 && $s4) { $t3 = strtotime("2000-01-01 $s3"); $t4 = strtotime("2000-01-01 $s4"); $sh2 = ($t4>$t3)?round(($t4-$t3)/3600,2):null; }
        $wh = round(($sh1 ?? 0) + ($sh2 ?? 0), 2) ?: null;
        $db->query("UPDATE payroll_attendance SET status=?, check_in_time=?, check_out_time=?, scan_3=?, scan_4=?, work_hours=?, shift_1_hours=?, shift_2_hours=?, notes=? WHERE id=?",
            [$status, $s1, $s2, $s3, $s4, $wh, $sh1, $sh2, $notes, $attId]);
        $msg = 'Data absen diperbarui.'; $msgType = 'success';
    }

    // ── Delete attendance ──
    if ($action === 'delete_att') {
        $attId = (int)$_POST['att_id'];
        if ($attId > 0) { $db->query("DELETE FROM payroll_attendance WHERE id = ?", [$attId]); $msg = 'Record dihapus.'; $msgType = 'success'; }
    }

    // ── Manual attendance ──
    if ($action === 'manual_att') {
        $empId = (int)$_POST['employee_id'];
        $date = $_POST['attendance_date'];
        $status = $_POST['status'] ?? 'present';
        $s1 = !empty($_POST['scan_1']) ? $_POST['scan_1'] . ':00' : null;
        $s2 = !empty($_POST['scan_2']) ? $_POST['scan_2'] . ':00' : null;
        $s3 = !empty($_POST['scan_3']) ? $_POST['scan_3'] . ':00' : null;
        $s4 = !empty($_POST['scan_4']) ? $_POST['scan_4'] . ':00' : null;
        $notes = trim($_POST['notes'] ?? '');
        $sh1 = null; $sh2 = null;
        if ($s1 && $s2) { $t1 = strtotime("$date $s1"); $t2 = strtotime("$date $s2"); $sh1 = ($t2>$t1)?round(($t2-$t1)/3600,2):null; }
        if ($s3 && $s4) { $t3 = strtotime("$date $s3"); $t4 = strtotime("$date $s4"); $sh2 = ($t4>$t3)?round(($t4-$t3)/3600,2):null; }
        $wh = round(($sh1 ?? 0) + ($sh2 ?? 0), 2) ?: null;
        try {
            $db->query("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, check_out_time, scan_3, scan_4, work_hours, shift_1_hours, shift_2_hours, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE check_in_time=VALUES(check_in_time), check_out_time=VALUES(check_out_time), scan_3=VALUES(scan_3), scan_4=VALUES(scan_4), work_hours=VALUES(work_hours), shift_1_hours=VALUES(shift_1_hours), shift_2_hours=VALUES(shift_2_hours), status=VALUES(status), notes=VALUES(notes)",
                [$empId, $date, $s1, $s2, $s3, $s4, $wh, $sh1, $sh2, $status, $notes]);
            $msg = 'Absen manual berhasil.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Error: ' . $e->getMessage(); $msgType = 'error'; }
    }

    // ── Reset: Face ──
    if ($action === 'reset_face') {
        $empId = (int)$_POST['employee_id'];
        $db->query("UPDATE payroll_employees SET face_descriptor = NULL WHERE id = ?", [$empId]);
        $msg = '✅ Data wajah direset. Karyawan perlu selfie ulang.'; $msgType = 'success';
    }

    // ── Reset: Attendance by date range ──
    if ($action === 'reset_attendance_range') {
        $fromDate = $_POST['reset_from'] ?? '';
        $toDate = $_POST['reset_to'] ?? '';
        $resetEmpId = (int)($_POST['reset_employee_id'] ?? 0);
        if ($fromDate && $toDate) {
            if ($resetEmpId > 0) {
                $db->query("DELETE FROM payroll_attendance WHERE attendance_date BETWEEN ? AND ? AND employee_id = ?", [$fromDate, $toDate, $resetEmpId]);
            } else {
                $db->query("DELETE FROM payroll_attendance WHERE attendance_date BETWEEN ? AND ?", [$fromDate, $toDate]);
            }
            $msg = '✅ Data absen periode ' . $fromDate . ' s/d ' . $toDate . ' berhasil dihapus.'; $msgType = 'success';
        }
    }

    // ── Reset: All face data ──
    if ($action === 'reset_all_faces') {
        $db->query("UPDATE payroll_employees SET face_descriptor = NULL WHERE is_active = 1");
        $msg = '✅ Semua data wajah direset.'; $msgType = 'success';
    }

    // ── Reset: Fingerprint log ──
    if ($action === 'reset_fingerprint_log') {
        $_pdo->exec("TRUNCATE TABLE fingerprint_log");
        $msg = '✅ Log fingerprint dihapus.'; $msgType = 'success';
    }

    // ── Reset: All attendance data ──
    if ($action === 'reset_all_attendance') {
        $confirmCode = trim($_POST['confirm_code'] ?? '');
        if ($confirmCode === 'HAPUS-SEMUA') {
            $_pdo->exec("TRUNCATE TABLE payroll_attendance");
            $msg = '✅ Semua data absensi berhasil dihapus.'; $msgType = 'success';
        } else {
            $msg = '❌ Kode konfirmasi salah. Ketik HAPUS-SEMUA untuk mengkonfirmasi.'; $msgType = 'error';
        }
    }

    // ── Reset: Specific employee data ──
    if ($action === 'reset_employee_data') {
        $resetEmpId = (int)($_POST['employee_id'] ?? 0);
        $resetWhat = $_POST['reset_type'] ?? '';
        if ($resetEmpId > 0) {
            if ($resetWhat === 'face') {
                $db->query("UPDATE payroll_employees SET face_descriptor = NULL WHERE id = ?", [$resetEmpId]);
                $msg = '✅ Data wajah karyawan direset.'; $msgType = 'success';
            } elseif ($resetWhat === 'finger') {
                $db->query("UPDATE payroll_employees SET finger_id = NULL WHERE id = ?", [$resetEmpId]);
                $msg = '✅ Finger ID karyawan direset.'; $msgType = 'success';
            } elseif ($resetWhat === 'attendance') {
                $db->query("DELETE FROM payroll_attendance WHERE employee_id = ?", [$resetEmpId]);
                $msg = '✅ Semua data absen karyawan dihapus.'; $msgType = 'success';
            } elseif ($resetWhat === 'all') {
                $db->query("UPDATE payroll_employees SET face_descriptor = NULL, finger_id = NULL WHERE id = ?", [$resetEmpId]);
                $db->query("DELETE FROM payroll_attendance WHERE employee_id = ?", [$resetEmpId]);
                $msg = '✅ Semua data karyawan direset (wajah, finger, absen).'; $msgType = 'success';
            }
        }
    }
}

// ══════════════════════════════════════════════
// FETCH DATA
// ══════════════════════════════════════════════
$config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1") ?: [];
$locations = $db->fetchAll("SELECT * FROM payroll_attendance_locations ORDER BY id") ?: [];
$employees = $db->fetchAll("SELECT id, employee_code, full_name, position, face_descriptor, finger_id FROM payroll_employees WHERE is_active = 1 ORDER BY full_name") ?: [];

$viewDate = $_GET['date'] ?? date('Y-m-d');

// Daily attendance
$dailyAtt = $db->fetchAll("
    SELECT a.*, e.full_name, e.employee_code, e.position
    FROM payroll_attendance a
    JOIN payroll_employees e ON e.id = a.employee_id
    WHERE a.attendance_date = ?
    ORDER BY e.full_name
", [$viewDate]) ?: [];

// Today stats
$todayStats = ['total' => count($employees), 'present' => 0, 'late' => 0, 'total_hours' => 0, 'regular_hours' => 0, 'overtime_hours' => 0, 'ot_count' => 0];
foreach ($dailyAtt as $a) {
    if ($a['check_in_time']) $todayStats['present']++;
    if ($a['status'] === 'late') $todayStats['late']++;
    $wh = (float)($a['work_hours'] ?? 0);
    $todayStats['total_hours'] += $wh;
    $todayStats['regular_hours'] += min($wh, 8);
    if ($wh > 8) {
        $ot = $wh - 8;
        $otU = floor($ot / 0.75);
        $todayStats['overtime_hours'] += $otU * 0.75;
        if ($otU > 0) $todayStats['ot_count']++;
    }
}

$absenUrl = $baseUrl . '/modules/payroll/absen.php?b=' . ACTIVE_BUSINESS_ID;
$staffPortalUrl = $baseUrl . '/modules/payroll/staff-portal.php?b=' . $bizSlug;

// Fingerspot data
$fpConfig = $db->fetchOne("SELECT fingerspot_cloud_id, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1") ?: [];
$fpEnabled = (int)($fpConfig['fingerspot_enabled'] ?? 0);
$fpCloudId = $fpConfig['fingerspot_cloud_id'] ?? '';
$bizSlug = defined('ACTIVE_BUSINESS_ID') ? strtolower(str_replace('_', '-', ACTIVE_BUSINESS_ID)) : 'narayana-hotel';
$webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'adfsystem.online') . str_replace('/modules/payroll/attendance.php', '', $_SERVER['SCRIPT_NAME']) . '/api/fingerprint-webhook.php?b=' . urlencode($bizSlug);

// Webhook logs
$fpLogs = [];
try { $fpLogs = $db->fetchAll("SELECT fl.*, pe.full_name as emp_name FROM fingerprint_log fl LEFT JOIN payroll_employees pe ON fl.employee_id = pe.id ORDER BY fl.created_at DESC LIMIT 20") ?: []; } catch (Exception $e) {}

// Reset stats
$resetStats = [
    'total_records' => 0, 'face_registered' => 0, 'finger_registered' => 0, 'log_count' => 0
];
try {
    $r = $_pdo->query("SELECT COUNT(*) as c FROM payroll_attendance")->fetch(PDO::FETCH_ASSOC);
    $resetStats['total_records'] = (int)($r['c'] ?? 0);
    $resetStats['face_registered'] = count(array_filter($employees, fn($e) => !empty($e['face_descriptor'])));
    $resetStats['finger_registered'] = count(array_filter($employees, fn($e) => !empty($e['finger_id'])));
    $r2 = $_pdo->query("SELECT COUNT(*) as c FROM fingerprint_log")->fetch(PDO::FETCH_ASSOC);
    $resetStats['log_count'] = (int)($r2['c'] ?? 0);
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<style>
:root { --navy:#0d1f3c; --navy-light:#1a3a5c; --gold:#f0b429; --green:#059669; --orange:#ea580c; --red:#dc2626; --blue:#2563eb; --purple:#7c3aed; --bg:#f8fafc; --card:#fff; --border:#e2e8f0; --muted:#64748b; }
.att-wrap { max-width:100%; font-family:'Inter',sans-serif; }

/* Header */
.att-head { background:#fff; padding:14px 18px; border-radius:12px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; border:1px solid var(--border); border-left:4px solid var(--gold); box-shadow:0 2px 8px rgba(0,0,0,.06); }
.att-head h1 { font-size:17px; font-weight:700; color:var(--navy); margin:0 0 2px; }
.att-head p { font-size:11px; margin:0; color:var(--muted); }

/* Stats Row */
.st-row { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:14px; }
.st-card { background:#fff; padding:14px 16px; border-radius:10px; border:1px solid var(--border); border-top:3px solid var(--border); }
.st-card .lb { font-size:10px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
.st-card .vl { font-size:26px; font-weight:800; color:var(--navy); margin-top:4px; line-height:1; }
.st-card .sb { font-size:10px; color:var(--muted); margin-top:3px; }

/* Tabs */
.att-tabs { display:flex; gap:3px; background:var(--bg); padding:3px; border-radius:10px; margin-bottom:14px; border:1px solid var(--border); overflow-x:auto; }
.att-tab { padding:9px 14px; border:none; background:transparent; border-radius:8px; cursor:pointer; font-weight:600; font-size:12px; color:var(--muted); transition:all .15s; white-space:nowrap; }
.att-tab.active { background:var(--gold); color:var(--navy); font-weight:800; }

/* Table */
.tbl-wrap { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 10px rgba(0,0,0,.06); border:1px solid var(--border); margin-bottom:14px; }
.tbl { width:100%; border-collapse:collapse; }
.tbl th { background:#f8fafc; color:#475569; padding:10px 12px; text-align:left; font-weight:700; font-size:10px; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid var(--gold); white-space:nowrap; }
.tbl td { padding:11px 12px; border-bottom:1px solid #f1f5f9; font-size:12px; color:#1e293b; vertical-align:middle; }
.tbl tr:hover td { background:#fafbfd; }

/* Badges */
.badge { padding:3px 8px; border-radius:5px; font-size:10px; font-weight:700; text-transform:uppercase; display:inline-flex; align-items:center; gap:3px; }
.b-present { background:#dcfce7; color:#166534; } .b-late { background:#ffedd5; color:#9a3412; }
.b-absent { background:#fee2e2; color:#991b1b; } .b-leave { background:#e0e7ff; color:#3730a3; }
.b-notyet { background:#f1f5f9; color:#94a3b8; } .b-holiday,.b-half_day { background:#f3f4f6; color:#374151; }

/* Buttons */
.btn { padding:6px 12px; border-radius:7px; font-size:11px; font-weight:600; border:none; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:4px; text-decoration:none; }
.btn:hover { opacity:.85; }
.btn-primary { background:var(--navy); color:#fff; }
.btn-gold { background:var(--gold); color:var(--navy); }
.btn-edit { background:#eff6ff; color:var(--blue); }
.btn-del { background:#fef2f2; color:var(--red); border:1px solid #fca5a5; }
.btn-green { background:#d1fae5; color:#065f46; }
.btn-purple { background:#ede9fe; color:var(--purple); }
.btn-danger { background:var(--red); color:#fff; }
.btn-sm { padding:4px 8px; font-size:10px; }

/* URL bar */
.url-bar { background:#fff; border:1px solid var(--border); border-radius:10px; padding:8px 12px; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.url-bar input { flex:1; border:1.5px solid var(--border); border-radius:6px; padding:6px 8px; font-size:11px; font-family:monospace; background:#f8fafc; }

/* Forms */
.fg { margin-bottom:10px; }
.fl { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; margin-bottom:3px; display:block; letter-spacing:.3px; }
.fi { width:100%; padding:7px 9px; border:1.5px solid var(--border); border-radius:6px; font-size:12px; color:var(--navy); box-sizing:border-box; }
.fi:focus { border-color:var(--gold); outline:none; }
.fgrid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }

/* Cards */
.card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:16px; margin-bottom:14px; }
.card-title { font-size:14px; font-weight:700; color:var(--navy); margin:0 0 12px; }

/* Reset section */
.reset-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:12px; }
.reset-card.danger { border-color:#fca5a5; background:#fffbfb; }
.reset-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }

/* Alert */
.att-alert { padding:10px 14px; border-radius:8px; font-size:12px; font-weight:600; margin-bottom:12px; }
.att-alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.att-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

/* Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9990; display:none; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:22px; max-width:440px; width:92%; box-shadow:0 20px 60px rgba(0,0,0,.25); border-top:4px solid var(--gold); max-height:90vh; overflow-y:auto; }
.modal-title { font-size:14px; font-weight:700; color:var(--navy); margin-bottom:14px; }
.modal-actions { display:flex; gap:8px; margin-top:14px; justify-content:flex-end; }

/* Dash = empty cell */
.dash { color:#d1d5db; }

@media(max-width:768px) {
    .st-row { grid-template-columns:repeat(2,1fr); }
    .fgrid { grid-template-columns:1fr; }
    .att-tabs { flex-wrap:nowrap; overflow-x:auto; }
}
</style>

<?php if ($msg): ?>
<div class="att-alert att-alert-<?php echo $msgType; ?>"><?php echo $msgType === 'success' ? '✅' : '❌'; ?> <?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<div class="att-wrap">

    <!-- Header -->
    <div class="att-head">
        <div>
            <h1>📋 Absensi Karyawan</h1>
            <p>Dashboard harian, GPS, Fingerprint, Manual & Reset</p>
        </div>
        <div style="display:flex; gap:6px;">
            <a href="<?php echo htmlspecialchars($absenUrl); ?>" target="_blank" class="btn btn-primary">📱 Halaman Absen</a>
            <a href="<?php echo htmlspecialchars($staffPortalUrl); ?>" target="_blank" class="btn btn-purple">👤 Staff Portal</a>
            <button onclick="openManualModal()" class="btn btn-gold">➕ Input Manual</button>
        </div>
    </div>

    <!-- URL bar -->
    <div class="url-bar">
        <span style="font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase;">📲 Absen</span>
        <input type="text" value="<?php echo htmlspecialchars($absenUrl); ?>" readonly id="absenUrlInput">
        <button onclick="copyUrl('absenUrlInput')" class="btn btn-primary btn-sm">📋 Salin</button>
    </div>
    <div class="url-bar">
        <span style="font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase;">👤 Portal</span>
        <input type="text" value="<?php echo htmlspecialchars($staffPortalUrl); ?>" readonly id="portalUrlInput">
        <button onclick="copyUrl('portalUrlInput')" class="btn btn-purple btn-sm">📋 Salin</button>
    </div>

    <!-- Stats -->
    <div class="st-row">
        <div class="st-card" style="border-top-color:var(--green);">
            <div class="lb">Hadir</div>
            <div class="vl" style="color:var(--green);"><?php echo $todayStats['present']; ?>/<?php echo $todayStats['total']; ?></div>
            <div class="sb"><?php echo $todayStats['total'] > 0 ? round($todayStats['present']/$todayStats['total']*100) : 0; ?>% kehadiran</div>
        </div>
        <div class="st-card" style="border-top-color:var(--orange);">
            <div class="lb">Terlambat</div>
            <div class="vl" style="color:var(--orange);"><?php echo $todayStats['late']; ?></div>
            <div class="sb">dari yang hadir</div>
        </div>
        <div class="st-card" style="border-top-color:var(--navy);">
            <div class="lb">Total Jam</div>
            <div class="vl"><?php echo number_format($todayStats['total_hours'],1); ?></div>
            <div class="sb"><?php echo number_format($todayStats['regular_hours'],1); ?>j reguler</div>
        </div>
        <div class="st-card" style="border-top-color:var(--purple);">
            <div class="lb">🔥 Lembur</div>
            <div class="vl" style="color:var(--purple);"><?php echo number_format($todayStats['overtime_hours'],1); ?>j</div>
            <div class="sb"><?php echo $todayStats['ot_count']; ?> staff lembur</div>
        </div>
        <div class="st-card" style="border-top-color:#94a3b8;">
            <div class="lb">Belum Absen</div>
            <div class="vl" style="color:#94a3b8;"><?php echo max(0, $todayStats['total'] - $todayStats['present']); ?></div>
            <div class="sb">perlu perhatian</div>
        </div>
    </div>

    <!-- ═══ TABS ═══ -->
    <div class="att-tabs">
        <button class="att-tab active" data-tab="dashboard">📊 Dashboard Harian</button>
        <button class="att-tab" data-tab="gps">📍 Absen GPS</button>
        <button class="att-tab" data-tab="fingerprint">🔐 Fingerprint</button>
        <button class="att-tab" data-tab="manual">✋ Manual</button>
        <button class="att-tab" data-tab="reset">🔄 Reset</button>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!-- TAB: DASHBOARD HARIAN                  -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-dashboard">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
            <form method="GET" style="display:flex; gap:6px; align-items:center;">
                <input type="date" name="date" value="<?php echo $viewDate; ?>" class="fi" style="width:150px;">
                <button type="submit" class="btn btn-gold">Tampilkan</button>
            </form>
            <span style="font-size:12px; color:var(--muted);"><?php echo date('l, d F Y', strtotime($viewDate)); ?></span>
        </div>

        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr>
                    <th>Karyawan</th>
                    <th style="text-align:center;">Scan 1<br><span style="font-weight:400;font-size:9px;text-transform:none;">Masuk</span></th>
                    <th style="text-align:center;">Scan 2<br><span style="font-weight:400;font-size:9px;text-transform:none;">Pulang</span></th>
                    <th style="text-align:center;">Scan 3<br><span style="font-weight:400;font-size:9px;text-transform:none;">Masuk Shift 2</span></th>
                    <th style="text-align:center;">Scan 4<br><span style="font-weight:400;font-size:9px;text-transform:none;">Pulang Shift 2</span></th>
                    <th style="text-align:center;">Total<br><span style="font-weight:400;font-size:9px;text-transform:none;">Jam</span></th>
                    <th style="text-align:center;">Lembur<br><span style="font-weight:400;font-size:9px;text-transform:none;">>45 menit</span></th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr></thead>
                <tbody>
                <?php
                $attById = [];
                foreach ($dailyAtt as $a) $attById[$a['employee_id']] = $a;
                $dash = '<span class="dash">—</span>';

                foreach ($employees as $emp):
                    $a = $attById[$emp['id']] ?? null;
                    $status = $a ? $a['status'] : 'notyet';
                    $statusLabels = ['present'=>'Hadir','late'=>'Terlambat','absent'=>'Absen','leave'=>'Izin','holiday'=>'Libur','half_day'=>'½ Hari','notyet'=>'Belum'];
                    $s1 = $a && $a['check_in_time'] ? substr($a['check_in_time'],0,5) : null;
                    $s2 = $a && $a['check_out_time'] ? substr($a['check_out_time'],0,5) : null;
                    $s3 = $a && !empty($a['scan_3']) ? substr($a['scan_3'],0,5) : null;
                    $s4 = $a && !empty($a['scan_4']) ? substr($a['scan_4'],0,5) : null;
                    $wh = (float)($a['work_hours'] ?? 0);
                    $otRaw = max($wh - 8, 0);
                    $otUnits = floor($otRaw / 0.75);
                    $otCounted = $otUnits * 0.75;
                ?>
                <tr>
                    <td>
                        <strong style="font-size:12px;"><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                        <div style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($emp['employee_code']); ?> · <?php echo htmlspecialchars($emp['position']); ?></div>
                    </td>
                    <td style="text-align:center; font-weight:700; color:var(--green);"><?php echo $s1 ?: $dash; ?></td>
                    <td style="text-align:center; font-weight:700; color:var(--navy);"><?php echo $s2 ?: $dash; ?></td>
                    <td style="text-align:center; font-weight:700; color:var(--green);"><?php echo $s3 ?: $dash; ?></td>
                    <td style="text-align:center; font-weight:700; color:var(--navy);"><?php echo $s4 ?: $dash; ?></td>
                    <td style="text-align:center;">
                        <?php if ($wh > 0): ?>
                            <strong style="font-size:14px;"><?php echo number_format($wh,1); ?></strong><span style="font-size:10px;color:var(--muted);"> jam</span>
                        <?php else: echo $dash; endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($otCounted > 0): ?>
                            <span style="background:#ede9fe; color:var(--purple); padding:2px 8px; border-radius:4px; font-weight:700; font-size:12px;">+<?php echo number_format($otCounted,1); ?>j</span>
                            <div style="font-size:9px; color:var(--muted);"><?php echo $otUnits; ?>×45m</div>
                        <?php elseif ($wh > 0): ?>
                            <span style="color:#d1d5db; font-size:11px;">0</span>
                        <?php else: echo $dash; endif; ?>
                    </td>
                    <td><span class="badge b-<?php echo $status; ?>"><?php echo $statusLabels[$status] ?? $status; ?></span></td>
                    <td style="white-space:nowrap;">
                        <?php if ($a): ?>
                        <button class="btn btn-edit btn-sm" onclick='openEditModal(<?php echo json_encode($a); ?>)'>✏️</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus absen <?php echo htmlspecialchars($emp['full_name']); ?>?')">
                            <input type="hidden" name="action" value="delete_att">
                            <input type="hidden" name="att_id" value="<?php echo $a['id']; ?>">
                            <button type="submit" class="btn btn-del btn-sm">🗑</button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-green btn-sm" onclick="quickManualAdd(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>', '<?php echo $viewDate; ?>')">➕</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Legend -->
        <div style="padding:10px 14px; background:#f8fafc; border-radius:8px; border:1px solid var(--border); display:flex; gap:16px; flex-wrap:wrap; font-size:11px; color:var(--muted);">
            <span>📌 <strong>Reguler:</strong> max 8 jam/hari</span>
            <span>🔥 <strong>Lembur:</strong> >8 jam, per kelipatan 45 menit</span>
            <span>🕐 <strong>Scan:</strong> 1=Masuk, 2=Pulang, 3=Masuk Shift2, 4=Pulang Shift2</span>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!-- TAB: ABSEN GPS (Settings & Locations)  -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-gps" style="display:none;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:start;">
            <div>
                <!-- Logo -->
                <div class="card">
                    <div class="card-title">🖼️ Logo Aplikasi Absen</div>
                    <?php if (!empty($config['app_logo'])):
                        $logoUrl = (strpos($config['app_logo'], 'http') === 0) ? $config['app_logo'] : $baseUrl . '/' . htmlspecialchars($config['app_logo']);
                    ?>
                    <div style="margin-bottom:10px; display:flex; align-items:center; gap:10px;">
                        <img src="<?php echo $logoUrl; ?>" style="height:50px; max-width:140px; object-fit:contain; border-radius:6px; border:1px solid #eee; padding:3px;">
                        <form method="POST" action="?tab=gps" style="margin:0;">
                            <input type="hidden" name="action" value="save_logo"><input type="hidden" name="remove_logo" value="1">
                            <button type="submit" class="btn btn-del btn-sm">🗑️ Hapus</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <p style="font-size:11px; color:var(--muted); margin:0 0 8px;">Belum ada logo. Upload untuk ditampilkan di halaman absen.</p>
                    <?php endif; ?>
                    <form method="POST" action="?tab=gps" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_logo">
                        <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.svg,.webp" class="fi" style="padding:5px; font-size:11px;">
                        <div style="font-size:9px; color:var(--muted); margin:3px 0 6px;">PNG, JPG, SVG, WebP · Rekomendasi: 200×60 px</div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">📤 Upload Logo</button>
                    </form>
                </div>

                <!-- Time Settings -->
                <div class="card">
                    <div class="card-title">🕐 Pengaturan Waktu</div>
                    <form method="POST" action="?tab=gps">
                        <input type="hidden" name="action" value="save_config">
                        <div class="fgrid">
                            <div class="fg"><label class="fl">Jam Masuk (Awal)</label><input type="time" name="checkin_start" class="fi" value="<?php echo $config['checkin_start'] ?? '07:00'; ?>"></div>
                            <div class="fg"><label class="fl">Batas Terlambat</label><input type="time" name="checkin_end" class="fi" value="<?php echo $config['checkin_end'] ?? '10:00'; ?>"><div style="font-size:9px;color:var(--muted);margin-top:2px;">Setelah jam ini = Terlambat</div></div>
                        </div>
                        <div class="fg"><label class="fl">Checkout Mulai Jam</label><input type="time" name="checkout_start" class="fi" value="<?php echo $config['checkout_start'] ?? '16:00'; ?>"></div>
                        <div class="fg" style="display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="allow_outside" id="allowOut" <?php echo ($config['allow_outside'] ?? 0) ? 'checked' : ''; ?>>
                            <label for="allowOut" style="font-size:11px;">Izinkan absen di luar radius</label>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">💾 Simpan Waktu</button>
                    </form>
                </div>

                <!-- Locations -->
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <div class="card-title" style="margin:0;">📍 Lokasi Proyek</div>
                        <button onclick="openLocModal()" class="btn btn-gold btn-sm">➕ Tambah</button>
                    </div>
                    <?php if (empty($locations)): ?>
                    <div style="text-align:center; padding:20px; color:var(--muted); font-size:12px;">Belum ada lokasi.</div>
                    <?php else: ?>
                    <?php foreach ($locations as $loc): ?>
                    <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:10px 12px; margin-bottom:6px; <?php echo $loc['is_active'] ? '' : 'opacity:.5;'; ?>">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div>
                                <div style="font-size:12px; font-weight:700; color:var(--navy);"><?php echo $loc['is_active'] ? '🟢' : '⚫'; ?> <?php echo htmlspecialchars($loc['location_name']); ?></div>
                                <?php if ($loc['address']): ?><div style="font-size:10px; color:var(--muted); margin-top:1px;"><?php echo htmlspecialchars($loc['address']); ?></div><?php endif; ?>
                                <div style="font-size:10px; color:var(--muted); margin-top:2px; font-family:monospace;"><?php echo number_format((float)$loc['lat'],7); ?>, <?php echo number_format((float)$loc['lng'],7); ?> · <?php echo $loc['radius_m']; ?>m</div>
                            </div>
                            <div style="display:flex; gap:3px;">
                                <button class="btn btn-edit btn-sm" onclick='openLocModal(<?php echo json_encode($loc); ?>)'>✏️</button>
                                <form method="POST" action="?tab=gps" style="display:inline;" onsubmit="return confirm('Hapus lokasi?')">
                                    <input type="hidden" name="action" value="delete_location">
                                    <input type="hidden" name="loc_id" value="<?php echo $loc['id']; ?>">
                                    <button type="submit" class="btn btn-del btn-sm">🗑</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Map -->
            <div class="card" style="position:sticky; top:10px;">
                <div class="card-title">🗺️ Peta Lokasi</div>
                <div id="adminMap" style="height:350px; border-radius:8px; border:1px solid var(--border);"></div>
                <div style="font-size:10px; color:var(--muted); margin-top:4px;">Semua lokasi aktif ditampilkan di peta.</div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!-- TAB: FINGERPRINT                       -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-fingerprint" style="display:none;">

        <!-- Fingerspot Settings -->
        <div class="card">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                <div class="reset-icon" style="background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff;">🔐</div>
                <div style="flex:1;">
                    <div class="card-title" style="margin:0;">Fingerspot.io Integration</div>
                    <div style="font-size:10px; color:var(--muted);">Revo N830 via Fingerspot.io cloud</div>
                </div>
                <?php if ($fpEnabled): ?>
                <span style="background:#d1fae5; color:#065f46; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700;">✅ Aktif</span>
                <?php else: ?>
                <span style="background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700;">⏸ Non-aktif</span>
                <?php endif; ?>
            </div>
            <form method="POST" action="?tab=fingerprint">
                <input type="hidden" name="action" value="save_fingerspot">
                <div class="fg">
                    <label class="fl">Cloud ID Mesin</label>
                    <input type="text" name="fingerspot_cloud_id" class="fi" value="<?php echo htmlspecialchars($fpCloudId); ?>" placeholder="Cloud ID dari Fingerspot.io">
                    <div style="font-size:9px; color:var(--muted); margin-top:2px;">Lihat di dashboard Fingerspot.io → Device → Cloud ID</div>
                </div>
                <div class="fg" style="display:flex; align-items:center; gap:6px; margin-bottom:12px;">
                    <input type="checkbox" name="fingerspot_enabled" id="fpOn" <?php echo $fpEnabled ? 'checked' : ''; ?>>
                    <label for="fpOn" style="font-size:11px; font-weight:600;">Aktifkan integrasi Fingerspot</label>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">💾 Simpan Fingerspot</button>
            </form>
        </div>

        <!-- Webhook URL -->
        <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1px solid #93c5fd; border-radius:10px; padding:14px; margin-bottom:14px;">
            <div style="font-size:11px; font-weight:700; color:#1e40af; margin-bottom:6px;">🔗 Webhook URL</div>
            <div style="background:#fff; border:1px solid #bfdbfe; border-radius:6px; padding:8px; font-family:monospace; font-size:10px; color:#1e3a5f; word-break:break-all; cursor:pointer;" onclick="copyWebhookUrl(this)"><?php echo htmlspecialchars($webhookUrl); ?></div>
            <div style="font-size:9px; color:#3b82f6; margin-top:4px;">📋 Klik untuk copy → Paste di Fingerspot.io → Developer → Webhook</div>
        </div>

        <!-- PIN Mapping -->
        <div class="card">
            <div class="card-title">👥 Mapping Karyawan ↔ PIN Mesin</div>
            <div style="font-size:10px; color:var(--muted); margin-bottom:10px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:6px 8px;">
                ⚠️ Pastikan Finger ID sama dengan PIN di mesin. Atur di <a href="employees.php" style="color:var(--blue); font-weight:700;">Data Karyawan</a>.
            </div>
            <div class="tbl-wrap" style="margin-bottom:0;">
                <table class="tbl">
                    <thead><tr><th>Kode</th><th>Nama</th><th>Jabatan</th><th style="text-align:center;">Finger ID</th><th style="text-align:center;">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $fpe): ?>
                    <tr>
                        <td><code style="font-size:10px; background:rgba(99,102,241,.1); padding:2px 5px; border-radius:3px;"><?php echo htmlspecialchars($fpe['employee_code']); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($fpe['full_name']); ?></strong></td>
                        <td style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($fpe['position']); ?></td>
                        <td style="text-align:center;">
                            <?php if (!empty($fpe['finger_id'])): ?>
                            <span style="background:#eff6ff; color:#1e40af; padding:2px 8px; border-radius:5px; font-size:11px; font-weight:700; font-family:monospace;">PIN <?php echo htmlspecialchars($fpe['finger_id']); ?></span>
                            <?php else: ?><span style="color:#94a3b8; font-size:10px;">— Belum</span><?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if (!empty($fpe['finger_id'])): ?>
                            <span style="background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:10px; font-size:9px; font-weight:700;">✅ Ready</span>
                            <?php else: ?>
                            <span style="background:#fee2e2; color:#991b1b; padding:2px 6px; border-radius:10px; font-size:9px; font-weight:700;">⚠️ Setup</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Webhook Log -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <div class="card-title" style="margin:0;">📜 Webhook Log</div>
                <span style="font-size:10px; color:var(--muted);">20 terbaru</span>
            </div>
            <?php if (empty($fpLogs)): ?>
            <div style="text-align:center; padding:24px; color:var(--muted); font-size:12px;">📭 Belum ada log. Log muncul setelah mesin mengirim data.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="tbl" style="font-size:10px;">
                    <thead><tr><th>Waktu</th><th>Cloud ID</th><th>PIN</th><th>Karyawan</th><th>Scan</th><th>Status</th><th>Hasil</th></tr></thead>
                    <tbody>
                    <?php foreach ($fpLogs as $log): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                        <td><code style="font-size:9px;"><?php echo htmlspecialchars($log['cloud_id'] ?? '-'); ?></code></td>
                        <td><code><?php echo htmlspecialchars($log['pin'] ?? '-'); ?></code></td>
                        <td><?php echo htmlspecialchars($log['emp_name'] ?? '-'); ?></td>
                        <td style="white-space:nowrap;"><?php echo $log['scan_time'] ? date('d/m H:i', strtotime($log['scan_time'])) : '-'; ?></td>
                        <td><?php echo $log['processed'] ? '<span style="color:var(--green); font-weight:700;">✅</span>' : '<span style="color:var(--red); font-weight:700;">❌</span>'; ?></td>
                        <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($log['process_result'] ?? ''); ?>">
                            <?php echo htmlspecialchars($log['process_result'] ?? '-'); ?>
                            <?php if (!empty($log['raw_data'])): ?>
                            <button onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'" style="background:none;border:none;cursor:pointer;font-size:9px;padding:0 2px;">📋</button>
                            <pre style="display:none; font-size:8px; background:#f1f5f9; padding:4px; border-radius:3px; margin-top:3px; white-space:pre-wrap; word-break:break-all; max-width:250px;"><?php echo htmlspecialchars(substr($log['raw_data'], 0, 500)); ?></pre>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!-- TAB: MANUAL (Face data + Manual input) -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-manual" style="display:none;">
        <div style="background:#e0f2fe; border:1px solid #38bdf8; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:11px; color:#0c4a6e;">
            👁️ Karyawan absen via <strong>scan wajah</strong> dari HP. Jika wajah bermasalah, reset di sini.<br>
            ✋ Untuk input manual, klik tombol <strong>➕ Input Manual</strong> di header.
        </div>

        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Kode</th><th>Nama</th><th>Jabatan</th><th>Status Wajah</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($employees as $emp): ?>
                <tr>
                    <td><code style="font-size:10px; background:rgba(240,180,41,.15); padding:2px 5px; border-radius:3px;"><?php echo htmlspecialchars($emp['employee_code']); ?></code></td>
                    <td><strong><?php echo htmlspecialchars($emp['full_name']); ?></strong></td>
                    <td style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($emp['position']); ?></td>
                    <td><?php echo !empty($emp['face_descriptor']) ? '<span style="color:var(--green); font-size:11px; font-weight:600;">✅ Terdaftar</span>' : '<span style="color:var(--orange); font-size:11px; font-weight:600;">⚠️ Belum (selfie saat absen pertama)</span>'; ?></td>
                    <td>
                        <?php if (!empty($emp['face_descriptor'])): ?>
                        <button class="btn btn-del btn-sm" onclick="openFaceResetModal(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['full_name']); ?>')">🔄 Reset Wajah</button>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!-- TAB: RESET                              -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-reset" style="display:none;">

        <!-- Stats overview -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
            <div class="st-card" style="border-top-color:var(--blue);">
                <div class="lb">Total Record Absen</div>
                <div class="vl" style="color:var(--blue); font-size:22px;"><?php echo number_format($resetStats['total_records']); ?></div>
            </div>
            <div class="st-card" style="border-top-color:var(--green);">
                <div class="lb">Wajah Terdaftar</div>
                <div class="vl" style="color:var(--green); font-size:22px;"><?php echo $resetStats['face_registered']; ?>/<?php echo count($employees); ?></div>
            </div>
            <div class="st-card" style="border-top-color:var(--purple);">
                <div class="lb">Finger ID Terdaftar</div>
                <div class="vl" style="color:var(--purple); font-size:22px;"><?php echo $resetStats['finger_registered']; ?>/<?php echo count($employees); ?></div>
            </div>
            <div class="st-card" style="border-top-color:var(--orange);">
                <div class="lb">Log Webhook</div>
                <div class="vl" style="color:var(--orange); font-size:22px;"><?php echo number_format($resetStats['log_count']); ?></div>
            </div>
        </div>

        <!-- 1) Reset by Date Range -->
        <div class="reset-card">
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <div class="reset-icon" style="background:#eff6ff; color:var(--blue);">📅</div>
                <div style="flex:1;">
                    <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Reset Absen per Periode</h3>
                    <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Hapus data absensi pada rentang tanggal tertentu. Bisa pilih per karyawan atau semua.</p>
                    <form method="POST" action="?tab=reset" onsubmit="return confirm('Yakin hapus data absen periode ini?')">
                        <input type="hidden" name="action" value="reset_attendance_range">
                        <div class="fgrid" style="margin-bottom:8px;">
                            <div class="fg"><label class="fl">Dari Tanggal</label><input type="date" name="reset_from" class="fi" required></div>
                            <div class="fg"><label class="fl">Sampai Tanggal</label><input type="date" name="reset_to" class="fi" required></div>
                        </div>
                        <div class="fg">
                            <label class="fl">Karyawan (kosong = semua)</label>
                            <select name="reset_employee_id" class="fi">
                                <option value="0">— Semua Karyawan —</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">🗑 Hapus Data Periode</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 2) Reset per Employee -->
        <div class="reset-card">
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <div class="reset-icon" style="background:#fefce8; color:var(--orange);">👤</div>
                <div style="flex:1;">
                    <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Reset Data Staff</h3>
                    <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Pilih karyawan dan jenis data yang ingin direset.</p>
                    <form method="POST" action="?tab=reset" onsubmit="return confirm('Yakin reset data ini?')">
                        <input type="hidden" name="action" value="reset_employee_data">
                        <div class="fgrid" style="margin-bottom:8px;">
                            <div class="fg">
                                <label class="fl">Karyawan</label>
                                <select name="employee_id" class="fi" required>
                                    <option value="">— Pilih —</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fg">
                                <label class="fl">Jenis Reset</label>
                                <select name="reset_type" class="fi" required>
                                    <option value="face">🔄 Reset Wajah Saja</option>
                                    <option value="finger">🔄 Reset Finger ID Saja</option>
                                    <option value="attendance">🗑 Hapus Semua Absen</option>
                                    <option value="all">⚠️ Reset Semua (Wajah + Finger + Absen)</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">🔄 Reset Data Staff</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 3) Reset All Faces -->
        <div class="reset-card">
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <div class="reset-icon" style="background:#fef2f2; color:var(--red);">👁️</div>
                <div style="flex:1;">
                    <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Reset Semua Data Wajah</h3>
                    <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Reset data wajah seluruh karyawan. Mereka perlu selfie ulang saat absen berikutnya.</p>
                    <form method="POST" action="?tab=reset" onsubmit="return confirm('Reset SEMUA data wajah karyawan?')">
                        <input type="hidden" name="action" value="reset_all_faces">
                        <button type="submit" class="btn" style="background:#fef2f2; color:var(--red); border:1px solid #fca5a5;">👁️ Reset Semua Wajah (<?php echo $resetStats['face_registered']; ?> terdaftar)</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 4) Reset Fingerprint Log -->
        <div class="reset-card">
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <div class="reset-icon" style="background:#ede9fe; color:var(--purple);">📜</div>
                <div style="flex:1;">
                    <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Hapus Log Fingerprint</h3>
                    <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Bersihkan tabel log webhook fingerprint. Data absen tetap aman.</p>
                    <form method="POST" action="?tab=reset" onsubmit="return confirm('Hapus semua log fingerprint?')">
                        <input type="hidden" name="action" value="reset_fingerprint_log">
                        <button type="submit" class="btn btn-purple">📜 Hapus Log (<?php echo number_format($resetStats['log_count']); ?> records)</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 5) DANGER: Reset ALL Attendance -->
        <div class="reset-card danger">
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <div class="reset-icon" style="background:var(--red); color:#fff;">⚠️</div>
                <div style="flex:1;">
                    <h3 style="font-size:13px; font-weight:700; color:var(--red); margin:0 0 4px;">⚠️ Reset Semua Data Absensi</h3>
                    <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Hapus SELURUH data absensi. Tindakan ini <strong>tidak bisa dibatalkan</strong>. Ketik <code>HAPUS-SEMUA</code> untuk mengkonfirmasi.</p>
                    <form method="POST" action="?tab=reset" onsubmit="return this.confirm_code.value==='HAPUS-SEMUA' || (alert('Ketik HAPUS-SEMUA untuk konfirmasi'), false)">
                        <input type="hidden" name="action" value="reset_all_attendance">
                        <div class="fg">
                            <label class="fl">Kode Konfirmasi</label>
                            <input type="text" name="confirm_code" class="fi" placeholder="Ketik: HAPUS-SEMUA" autocomplete="off" style="border-color:#fca5a5;">
                        </div>
                        <button type="submit" class="btn btn-danger">🗑 Hapus Semua Absensi (<?php echo number_format($resetStats['total_records']); ?> records)</button>
                    </form>
                </div>
            </div>
        </div>

    </div>

</div><!-- /att-wrap -->

<!-- ═══ MODALS ═══ -->

<!-- Edit Attendance -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-title">✏️ Edit Data Absen</div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_att">
            <input type="hidden" name="att_id" id="editAttId">
            <div style="font-size:12px; font-weight:600; color:var(--navy); margin-bottom:10px;" id="editEmpName"></div>
            <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#f0f9ff; border-radius:5px; border-left:3px solid var(--blue);">🔄 Shift 1</div>
            <div class="fgrid">
                <div class="fg"><label class="fl">Scan 1 (Masuk)</label><input type="time" name="scan_1" id="editScan1" class="fi"></div>
                <div class="fg"><label class="fl">Scan 2 (Pulang)</label><input type="time" name="scan_2" id="editScan2" class="fi"></div>
            </div>
            <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#fefce8; border-radius:5px; border-left:3px solid var(--orange);">🌙 Shift 2</div>
            <div class="fgrid">
                <div class="fg"><label class="fl">Scan 3 (Masuk)</label><input type="time" name="scan_3" id="editScan3" class="fi"></div>
                <div class="fg"><label class="fl">Scan 4 (Pulang)</label><input type="time" name="scan_4" id="editScan4" class="fi"></div>
            </div>
            <div class="fg"><label class="fl">Status</label>
                <select name="status" id="editStatus" class="fi">
                    <option value="present">Hadir</option><option value="late">Terlambat</option>
                    <option value="absent">Absen</option><option value="leave">Izin</option>
                    <option value="holiday">Libur</option><option value="half_day">½ Hari</option>
                </select>
            </div>
            <div class="fg"><label class="fl">Catatan</label><input type="text" name="notes" id="editNotes" class="fi" placeholder="Opsional"></div>
            <div class="modal-actions">
                <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('editModal')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Manual Attendance -->
<div class="modal-overlay" id="manualModal">
    <div class="modal-box">
        <div class="modal-title">➕ Input Absen Manual</div>
        <form method="POST">
            <input type="hidden" name="action" value="manual_att">
            <div class="fg"><label class="fl">Karyawan</label>
                <select name="employee_id" id="manualEmpId" class="fi" required>
                    <option value="">— Pilih —</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg"><label class="fl">Tanggal</label><input type="date" name="attendance_date" id="manualDate" class="fi" value="<?php echo $viewDate; ?>" required></div>
            <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#f0f9ff; border-radius:5px; border-left:3px solid var(--blue);">🔄 Shift 1</div>
            <div class="fgrid">
                <div class="fg"><label class="fl">Scan 1 (Masuk)</label><input type="time" name="scan_1" class="fi" value="07:00"></div>
                <div class="fg"><label class="fl">Scan 2 (Pulang)</label><input type="time" name="scan_2" class="fi" value="11:00"></div>
            </div>
            <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#fefce8; border-radius:5px; border-left:3px solid var(--orange);">🌙 Shift 2</div>
            <div class="fgrid">
                <div class="fg"><label class="fl">Scan 3 (Masuk)</label><input type="time" name="scan_3" class="fi"></div>
                <div class="fg"><label class="fl">Scan 4 (Pulang)</label><input type="time" name="scan_4" class="fi"></div>
            </div>
            <div class="fg"><label class="fl">Status</label>
                <select name="status" class="fi"><option value="present">Hadir</option><option value="late">Terlambat</option><option value="absent">Absen</option><option value="leave">Izin</option><option value="holiday">Libur</option></select>
            </div>
            <div class="fg"><label class="fl">Catatan</label><input type="text" name="notes" class="fi" placeholder="Opsional"></div>
            <div class="modal-actions">
                <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('manualModal')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Face Modal -->
<div class="modal-overlay" id="faceModal">
    <div class="modal-box">
        <div class="modal-title">🔄 Reset Data Wajah</div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_face">
            <input type="hidden" name="employee_id" id="faceEmpId">
            <div style="font-size:12px; color:var(--muted); margin-bottom:10px;" id="faceEmpName"></div>
            <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:10px; font-size:11px; color:#991b1b; margin-bottom:10px;">
                ⚠️ Karyawan harus <strong>selfie ulang</strong> saat absen berikutnya.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('faceModal')">Batal</button>
                <button type="submit" class="btn btn-danger">🔄 Reset</button>
            </div>
        </form>
    </div>
</div>

<!-- Location Modal -->
<div class="modal-overlay" id="locModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-title" id="locModalTitle">📍 Tambah Lokasi</div>
        <form method="POST" action="?tab=gps" id="locForm" onsubmit="return validateLocForm()">
            <input type="hidden" name="action" id="locFormAction" value="add_location">
            <input type="hidden" name="loc_id" id="locFormId" value="">
            <div class="fg"><label class="fl">Nama Lokasi</label><input type="text" name="loc_name" id="locName" class="fi" placeholder="mis: Proyek PLN Semarang" required></div>
            <div class="fg"><label class="fl">Alamat (opsional)</label><input type="text" name="loc_address" id="locAddress" class="fi" placeholder="Alamat lengkap"></div>
            <div class="fgrid">
                <div class="fg"><label class="fl">Latitude</label><input type="text" name="loc_lat" id="locLat" class="fi" placeholder="-6.2" required readonly style="background:#f8fafc;"></div>
                <div class="fg"><label class="fl">Longitude</label><input type="text" name="loc_lng" id="locLng" class="fi" placeholder="106.8" required readonly style="background:#f8fafc;"></div>
            </div>
            <div style="font-size:10px; color:var(--blue); background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:6px 8px; margin-bottom:8px;">
                📌 Klik peta untuk menentukan titik lokasi.
            </div>
            <div id="locPickerMap" style="height:180px; border-radius:6px; border:1px solid var(--border); margin-bottom:8px;"></div>
            <div style="display:flex; gap:6px; margin-bottom:8px;">
                <button type="button" onclick="useMyGPS()" class="btn btn-edit btn-sm">📍 Lokasi Saya</button>
                <span id="locGpsStatus" style="font-size:10px; color:var(--muted); line-height:2.2;"></span>
            </div>
            <div class="fg"><label class="fl">Radius (meter)</label><input type="number" name="loc_radius" id="locRadius" class="fi" value="200" min="10" max="10000"></div>
            <div class="fg" id="locActiveGroup" style="display:none;">
                <label style="display:flex; align-items:center; gap:6px; font-size:11px;">
                    <input type="checkbox" name="loc_active" id="locActive"> Lokasi aktif
                </label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('locModal')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ─ TABS ─
document.querySelectorAll('.att-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.att-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
        btn.classList.add('active');
        const tab = btn.dataset.tab;
        document.getElementById('panel-' + tab).style.display = 'block';
        if (tab === 'gps') setTimeout(initAdminMap, 100);
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    });
});

// Restore tab from URL
const urlTab = new URLSearchParams(window.location.search).get('tab');
if (urlTab) {
    document.querySelectorAll('.att-tab').forEach(b => {
        b.classList.remove('active');
        if (b.dataset.tab === urlTab) b.classList.add('active');
    });
    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
    const panel = document.getElementById('panel-' + urlTab);
    if (panel) panel.style.display = 'block';
    if (urlTab === 'gps') setTimeout(initAdminMap, 200);
}

// ─ COPY URL ─
function copyUrl(inputId) {
    const el = document.getElementById(inputId || 'absenUrlInput');
    el.select();
    navigator.clipboard.writeText(el.value).then(() => {
        event.target.textContent = '✅ OK!';
        setTimeout(() => event.target.textContent = '📋 Salin', 1500);
    });
}
function copyWebhookUrl(el) {
    const text = el.innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        el.innerHTML = '✅ Copied!';
        setTimeout(() => { el.innerHTML = text; }, 1500);
    });
}

// ─ ADMIN MAP ─
let adminMap = null;
const allLocations = <?php echo json_encode(array_values($locations)); ?>;
function initAdminMap() {
    if (adminMap) { adminMap.invalidateSize(); return; }
    const center = allLocations.length ? [allLocations[0].lat, allLocations[0].lng] : [-5.8, 110.4];
    adminMap = L.map('adminMap').setView(center, 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(adminMap);
    const bounds = [];
    allLocations.forEach(loc => {
        const ll = [parseFloat(loc.lat), parseFloat(loc.lng)];
        bounds.push(ll);
        L.circle(ll, { radius: parseInt(loc.radius_m), color: loc.is_active ? '#f0b429' : '#94a3b8', fillOpacity: .15, weight: 2 }).addTo(adminMap).bindTooltip(loc.location_name);
        L.marker(ll).addTo(adminMap).bindPopup('<b>' + loc.location_name + '</b><br>Radius: ' + loc.radius_m + 'm');
    });
    if (bounds.length > 1) adminMap.fitBounds(bounds, { padding: [30, 30] });
}

// ─ LOCATION PICKER ─
let locPickerMap = null, locPickerMarker = null, locPickerCircle = null;
function initLocPickerMap(lat, lng, radius) {
    lat = lat || -5.8; lng = lng || 110.4; radius = radius || 200;
    if (!locPickerMap) {
        locPickerMap = L.map('locPickerMap').setView([lat, lng], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(locPickerMap);
        locPickerMarker = L.marker([lat, lng], { draggable: true }).addTo(locPickerMap);
        locPickerCircle = L.circle([lat, lng], { radius, color: '#f0b429', fillOpacity: .15 }).addTo(locPickerMap);
        locPickerMap.on('click', e => {
            locPickerMarker.setLatLng(e.latlng); locPickerCircle.setLatLng(e.latlng);
            document.getElementById('locLat').value = e.latlng.lat.toFixed(7);
            document.getElementById('locLng').value = e.latlng.lng.toFixed(7);
        });
        locPickerMarker.on('dragend', () => {
            const pos = locPickerMarker.getLatLng(); locPickerCircle.setLatLng(pos);
            document.getElementById('locLat').value = pos.lat.toFixed(7);
            document.getElementById('locLng').value = pos.lng.toFixed(7);
        });
        document.getElementById('locRadius').addEventListener('input', function() { locPickerCircle.setRadius(parseInt(this.value) || 200); });
    } else {
        locPickerMap.setView([lat, lng], 16);
        locPickerMarker.setLatLng([lat, lng]); locPickerCircle.setLatLng([lat, lng]).setRadius(radius);
        locPickerMap.invalidateSize();
    }
    document.getElementById('locLat').value = lat; document.getElementById('locLng').value = lng;
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
        document.getElementById('locModalTitle').textContent = '📍 Tambah Lokasi';
        document.getElementById('locFormAction').value = 'add_location';
        document.getElementById('locFormId').value = '';
        document.getElementById('locForm').reset();
        document.getElementById('locRadius').value = 200;
        document.getElementById('locActiveGroup').style.display = 'none';
        document.getElementById('locModal').classList.add('open');
        const fl = allLocations[0];
        setTimeout(() => initLocPickerMap(fl ? parseFloat(fl.lat) : -5.8, fl ? parseFloat(fl.lng) : 110.4, 200), 100);
    }
}

function validateLocForm() {
    const lat = document.getElementById('locLat').value.trim();
    const lng = document.getElementById('locLng').value.trim();
    if (!lat || !lng || (parseFloat(lat) === 0 && parseFloat(lng) === 0)) {
        document.getElementById('locGpsStatus').textContent = '❌ Tentukan titik dulu — klik peta atau GPS.';
        return false;
    }
    return true;
}

function useMyGPS() {
    const s = document.getElementById('locGpsStatus');
    s.textContent = '📡 Mengambil GPS...';
    navigator.geolocation.getCurrentPosition(pos => {
        const lat = pos.coords.latitude, lng = pos.coords.longitude;
        document.getElementById('locLat').value = lat.toFixed(7);
        document.getElementById('locLng').value = lng.toFixed(7);
        if (locPickerMarker) {
            locPickerMarker.setLatLng([lat, lng]); locPickerCircle.setLatLng([lat, lng]);
            locPickerMap.setView([lat, lng], 17);
        }
        s.textContent = '✅ ±' + Math.round(pos.coords.accuracy) + 'm';
    }, err => { s.textContent = '❌ ' + err.message; }, { enableHighAccuracy: true });
}

// ─ MODALS ─
function openEditModal(att) {
    document.getElementById('editAttId').value = att.id;
    document.getElementById('editEmpName').textContent = att.full_name + ' — ' + att.attendance_date;
    document.getElementById('editScan1').value = att.check_in_time ? att.check_in_time.substring(0,5) : '';
    document.getElementById('editScan2').value = att.check_out_time ? att.check_out_time.substring(0,5) : '';
    document.getElementById('editScan3').value = att.scan_3 ? att.scan_3.substring(0,5) : '';
    document.getElementById('editScan4').value = att.scan_4 ? att.scan_4.substring(0,5) : '';
    document.getElementById('editStatus').value = att.status || 'present';
    document.getElementById('editNotes').value = att.notes || '';
    document.getElementById('editModal').classList.add('open');
}

function openFaceResetModal(id, name) {
    document.getElementById('faceEmpId').value = id;
    document.getElementById('faceEmpName').textContent = 'Karyawan: ' + name;
    document.getElementById('faceModal').classList.add('open');
}

function openManualModal() { document.getElementById('manualModal').classList.add('open'); }
function quickManualAdd(id, name, date) {
    document.getElementById('manualEmpId').value = id;
    document.getElementById('manualDate').value = date;
    document.getElementById('manualModal').classList.add('open');
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
</script>

<?php include '../../includes/footer.php'; ?>
