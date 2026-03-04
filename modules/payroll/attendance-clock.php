<?php
/**
 * Payroll Attendance Clock API
 * Handles AJAX clock-in and clock-out requests from absen.php
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

// ── Resolve Business Context from ?b= param (public API — no session) ──
$_bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
if ($_bizSlug) {
    $_bizFile = __DIR__ . '/../../config/businesses/' . $_bizSlug . '.php';
    if (file_exists($_bizFile)) {
        $_bizCfg = require $_bizFile;
        if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $_bizCfg['business_id']);
        if (!defined('BUSINESS_TYPE'))      define('BUSINESS_TYPE',      $_bizCfg['business_type'] ?? 'other');
    }
}
// Auto-detect from first available business if still not set
if (!defined('ACTIVE_BUSINESS_ID')) {
    $_files = glob(__DIR__ . '/../../config/businesses/*.php');
    if ($_files) {
        $_bizCfg = require $_files[0];
        if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $_bizCfg['business_id']);
        if (!defined('BUSINESS_TYPE'))      define('BUSINESS_TYPE',      $_bizCfg['business_type'] ?? 'other');
    }
}

// Force connect to correct business DB directly (bypass session-based lookup)
// Helper: Haversine formula — returns distance in meters
function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000; // Earth radius in meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)*sin($dLat/2) +
         cos(deg2rad($lat1))*cos(deg2rad($lat2))*
         sin($dLng/2)*sin($dLng/2);
    return (int)(2 * $R * asin(sqrt($a)));
}

$db = isset($_bizCfg['database']) ? Database::switchDatabase($_bizCfg['database']) : Database::getInstance();
$action = $_POST['action'] ?? '';

// ── Auto-create tables if missing ──
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
        UNIQUE KEY `unique_attendance` (`employee_id`, `attendance_date`),
        INDEX `idx_date` (`attendance_date`),
        INDEX `idx_employee` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Add missing columns using raw PDO (db->query() swallows exceptions, so use getConnection() directly)
$pdo = $db->getConnection();
try {
    $pdo->query("SELECT attendance_pin FROM payroll_employees LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE payroll_employees ADD COLUMN `attendance_pin` VARCHAR(6) DEFAULT NULL");
}
try {
    $pdo->query("SELECT face_descriptor FROM payroll_employees LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE payroll_employees ADD COLUMN `face_descriptor` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON face descriptor from face-api.js'");
}

// ── GET EMPLOYEE by code or ID (no PIN — face will verify) ──
if ($action === 'get_employee') {
    $employee_code = strtoupper(trim($_POST['employee_code'] ?? ''));
    $employee_id   = (int)($_POST['employee_id'] ?? 0);

    if (!$employee_code && !$employee_id) {
        echo json_encode(['success' => false, 'message' => 'Data karyawan tidak ditemukan.']);
        exit;
    }

    if ($employee_id) {
        $emp = $db->fetchOne("SELECT id, employee_code, full_name, position, department, face_descriptor FROM payroll_employees WHERE id = ? AND is_active = 1", [$employee_id]);
    } else {
        $emp = $db->fetchOne("SELECT id, employee_code, full_name, position, department, face_descriptor FROM payroll_employees WHERE employee_code = ? AND is_active = 1", [$employee_code]);
    }
    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Kode karyawan tidak ditemukan atau tidak aktif.']);
        exit;
    }
    $today = date('Y-m-d');
    $attendance = $db->fetchOne("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$emp['id'], $today]);
    $config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1");
    echo json_encode([
        'success'   => true,
        'employee'  => [
            'id'         => $emp['id'],
            'code'       => $emp['employee_code'],
            'name'       => $emp['full_name'],
            'position'   => $emp['position'],
            'department' => $emp['department'],
            'has_face'   => !empty($emp['face_descriptor']),
            'face_descriptor' => $emp['face_descriptor'] ? json_decode($emp['face_descriptor'], true) : null,
        ],
        'today'  => $attendance,
        'config' => [
            'office_lat'    => (float)($config['office_lat'] ?? -6.2),
            'office_lng'    => (float)($config['office_lng'] ?? 106.82),
            'radius'        => (int)($config['allowed_radius_m'] ?? 200),
            'office_name'   => $config['office_name'] ?? 'Kantor',
            'checkin_end'   => $config['checkin_end'] ?? '10:00:00',
            'checkout_start'=> $config['checkout_start'] ?? '16:00:00',
            'allow_outside' => (bool)($config['allow_outside'] ?? false),
        ]
    ]);
    exit;
}

// ── REGISTER FACE DESCRIPTOR ──
if ($action === 'register_face') {
    $employee_id  = (int)($_POST['employee_id'] ?? 0);
    $descriptor   = trim($_POST['face_descriptor'] ?? '');
    if (!$employee_id || !$descriptor) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }
    // Validate it's a JSON array
    $arr = json_decode($descriptor, true);
    if (!is_array($arr) || count($arr) < 100) {
        echo json_encode(['success' => false, 'message' => 'Format descriptor wajah tidak valid.']);
        exit;
    }
    $db->query("UPDATE payroll_employees SET face_descriptor = ? WHERE id = ?", [$descriptor, $employee_id]);
    echo json_encode(['success' => true, 'message' => 'Wajah berhasil didaftarkan!']);
    exit;
}

// ── CLOCK IN ──
if ($action === 'checkin') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    $address = trim($_POST['address'] ?? '');
    $device = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    $today = date('Y-m-d');
    $now = date('H:i:s');

    if (!$employee_id || !$lat || !$lng) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }

    $config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1");
    $officeLat = (float)($config['office_lat'] ?? -6.2);
    $officeLng = (float)($config['office_lng'] ?? 106.82);
    $radius = (int)($config['allowed_radius_m'] ?? 200);
    $allowOutside = (bool)($config['allow_outside'] ?? false);
    $checkinEnd = $config['checkin_end'] ?? '10:00:00';

    $distance = haversineDistance($lat, $lng, $officeLat, $officeLng);
    $isOutside = $distance > $radius;

    if ($isOutside && !$allowOutside) {
        echo json_encode([
            'success' => false,
            'message' => "Anda berada di luar radius kantor ({$distance}m dari kantor, maks {$radius}m). Harap absen dari lokasi kantor.",
            'distance' => $distance
        ]);
        exit;
    }

    // Check already checked in today
    $existing = $db->fetchOne("SELECT id, check_in_time FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$employee_id, $today]);
    if ($existing && $existing['check_in_time']) {
        echo json_encode(['success' => false, 'message' => 'Anda sudah check-in hari ini pukul ' . substr($existing['check_in_time'], 0, 5) . '.']);
        exit;
    }

    // Determine status: late if after checkin_end
    $status = ($now > $checkinEnd) ? 'late' : 'present';

    try {
        if ($existing) {
            $db->query("UPDATE payroll_attendance SET check_in_time=?, check_in_lat=?, check_in_lng=?, check_in_distance_m=?, check_in_address=?, check_in_device=?, status=?, is_outside_radius=? WHERE id=?",
                [$now, $lat, $lng, $distance, $address, $device, $status, $isOutside ? 1 : 0, $existing['id']]);
        } else {
            $db->query("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, check_in_lat, check_in_lng, check_in_distance_m, check_in_address, check_in_device, status, is_outside_radius) VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$employee_id, $today, $now, $lat, $lng, $distance, $address, $device, $status, $isOutside ? 1 : 0]);
        }
        echo json_encode([
            'success' => true,
            'message' => 'Check-in berhasil! ' . ($status === 'late' ? '⚠️ Terlambat' : '✅ Tepat Waktu'),
            'time' => date('H:i'),
            'status' => $status,
            'distance' => $distance,
            'is_outside' => $isOutside
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// ── CLOCK OUT ──
if ($action === 'checkout') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    $address = trim($_POST['address'] ?? '');
    $device = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    $today = date('Y-m-d');
    $now = date('H:i:s');

    if (!$employee_id) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }

    $config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1");
    $officeLat = (float)($config['office_lat'] ?? -6.2);
    $officeLng = (float)($config['office_lng'] ?? 106.82);
    $radius = (int)($config['allowed_radius_m'] ?? 200);
    $allowOutside = (bool)($config['allow_outside'] ?? false);
    $checkoutStart = $config['checkout_start'] ?? '16:00:00';

    $distance = ($lat && $lng) ? haversineDistance($lat, $lng, $officeLat, $officeLng) : 9999;
    $isOutside = $distance > $radius;

    if ($isOutside && !$allowOutside) {
        echo json_encode([
            'success' => false,
            'message' => "Anda berada di luar radius kantor ({$distance}m). Harap check-out dari lokasi kantor.",
            'distance' => $distance
        ]);
        exit;
    }

    $attendance = $db->fetchOne("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$employee_id, $today]);

    if (!$attendance || !$attendance['check_in_time']) {
        echo json_encode(['success' => false, 'message' => 'Anda belum check-in hari ini.']);
        exit;
    }

    if ($attendance['check_out_time']) {
        echo json_encode(['success' => false, 'message' => 'Anda sudah check-out pukul ' . substr($attendance['check_out_time'], 0, 5) . '.']);
        exit;
    }

    // Calculate work hours
    $inTime = strtotime($today . ' ' . $attendance['check_in_time']);
    $outTime = strtotime($today . ' ' . $now);
    $workHours = round(($outTime - $inTime) / 3600, 2);

    try {
        $db->query("UPDATE payroll_attendance SET check_out_time=?, check_out_lat=?, check_out_lng=?, check_out_distance_m=?, check_out_device=?, work_hours=? WHERE id=?",
            [$now, $lat ?: null, $lng ?: null, $distance, $device, $workHours, $attendance['id']]);
        echo json_encode([
            'success' => true,
            'message' => "Check-out berhasil! Jam kerja: {$workHours} jam ✅",
            'time' => date('H:i'),
            'work_hours' => $workHours,
            'distance' => $distance
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// ── HISTORY ──
if ($action === 'history') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    if (!$employee_id) { echo json_encode(['success' => false, 'message' => 'Employee ID missing.']); exit; }

    $history = $db->fetchAll("SELECT * FROM payroll_attendance WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 30", [$employee_id]);
    $summary = $db->fetchOne("SELECT
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
        AVG(CASE WHEN work_hours > 0 THEN work_hours ELSE NULL END) as avg_hours
        FROM payroll_attendance
        WHERE employee_id = ? AND MONTH(attendance_date) = MONTH(NOW()) AND YEAR(attendance_date) = YEAR(NOW())", [$employee_id]);
    echo json_encode(['success' => true, 'history' => $history, 'summary' => $summary]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
