<?php
/**
 * Staff Portal API
 * Handles: register, login, get data, attendance, breakfast request
 * Public API — staff auth via session
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

// ── Resolve Business ──
$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
if (!$bizSlug || !file_exists($bizFile)) {
    echo json_encode(['success' => false, 'message' => 'Invalid business']); exit;
}
$bizConfig = require $bizFile;
if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizConfig['business_id']);
$db = Database::switchDatabase($bizConfig['database']);
$pdo = $db->getConnection();

// Auto-create staff_accounts table
$pdo->exec("CREATE TABLE IF NOT EXISTS `staff_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `last_login` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp (employee_id),
    UNIQUE KEY uk_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Session
session_name('staff_portal_' . md5($bizSlug));
session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ══════════════════════════════════════
// REGISTER
// ══════════════════════════════════════
if ($action === 'register') {
    $empInput = trim($_POST['employee_code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$empInput || !$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Semua field wajib diisi']); exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password minimal 6 karakter']); exit;
    }

    // Build employee_code from number input: "1" -> try EMP-001, EMP-01, EMP-1, or exact match
    $emp = null;
    if (ctype_digit($empInput)) {
        $variations = [
            'EMP-' . str_pad($empInput, 3, '0', STR_PAD_LEFT),
            'EMP-' . str_pad($empInput, 2, '0', STR_PAD_LEFT),
            'EMP-' . $empInput,
        ];
        foreach ($variations as $code) {
            $emp = $db->fetchOne("SELECT id, full_name FROM payroll_employees WHERE employee_code = ? AND is_active = 1", [$code]);
            if ($emp) break;
        }
    }
    if (!$emp) {
        $emp = $db->fetchOne("SELECT id, full_name FROM payroll_employees WHERE employee_code = ? AND is_active = 1", [strtoupper($empInput)]);
    }
    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Kode karyawan tidak ditemukan. Hubungi admin.']); exit;
    }

    // Check if already registered
    $exists = $db->fetchOne("SELECT id FROM staff_accounts WHERE employee_id = ?", [$emp['id']]);
    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'Karyawan sudah terdaftar. Gunakan login.']); exit;
    }
    $emailExists = $db->fetchOne("SELECT id FROM staff_accounts WHERE email = ?", [$email]);
    if ($emailExists) {
        echo json_encode(['success' => false, 'message' => 'Email sudah terdaftar.']); exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO staff_accounts (employee_id, email, password_hash) VALUES (?, ?, ?)")
        ->execute([$emp['id'], $email, $hash]);

    echo json_encode(['success' => true, 'message' => 'Registrasi berhasil! Silakan login.']);
    exit;
}

// ══════════════════════════════════════
// LOGIN
// ══════════════════════════════════════
if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Username & password wajib diisi']); exit;
    }

    $account = $db->fetchOne("SELECT sa.*, pe.full_name, pe.employee_code, pe.position, pe.department 
        FROM staff_accounts sa 
        JOIN payroll_employees pe ON pe.id = sa.employee_id 
        WHERE sa.email = ?", [$email]);

    if (!$account || !password_verify($password, $account['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Username atau password salah']); exit;
    }

    // Update last login
    $pdo->prepare("UPDATE staff_accounts SET last_login = NOW() WHERE id = ?")->execute([$account['id']]);

    $_SESSION['staff_id'] = $account['id'];
    $_SESSION['employee_id'] = $account['employee_id'];
    $_SESSION['staff_name'] = $account['full_name'];
    $_SESSION['staff_code'] = $account['employee_code'];
    $_SESSION['staff_position'] = $account['position'];
    $_SESSION['staff_logged_in'] = true;

    echo json_encode(['success' => true, 'message' => 'Login berhasil', 'name' => $account['full_name']]);
    exit;
}

// ══════════════════════════════════════
// LOGOUT
// ══════════════════════════════════════
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]); exit;
}

// ══════════════════════════════════════
// AUTH CHECK — all below require login
// ══════════════════════════════════════
if (empty($_SESSION['staff_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated', 'auth' => false]); exit;
}

$empId = (int)$_SESSION['employee_id'];

// ── GET PROFILE ──
if ($action === 'profile') {
    $emp = $db->fetchOne("SELECT id, employee_code, full_name, position, department, phone, join_date FROM payroll_employees WHERE id = ?", [$empId]);
    echo json_encode(['success' => true, 'data' => $emp]); exit;
}

// ── ATTENDANCE TODAY ──
if ($action === 'attendance_today') {
    $today = date('Y-m-d');
    $att = $db->fetchOne("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$empId, $today]);
    echo json_encode(['success' => true, 'data' => $att]); exit;
}

// ── ATTENDANCE HISTORY (current month) ──
if ($action === 'attendance_history') {
    $month = $_GET['month'] ?? date('Y-m');
    $rows = $db->fetchAll("SELECT attendance_date, check_in_time, check_out_time, scan_3, scan_4, work_hours, shift_1_hours, shift_2_hours, status, notes FROM payroll_attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? ORDER BY attendance_date DESC", [$empId, $month]);
    
    // Summary
    $totalHours = 0; $totalRegular = 0; $totalOT = 0; $present = 0; $late = 0;
    foreach ($rows as $r) {
        $wh = (float)($r['work_hours'] ?? 0);
        $totalHours += $wh;
        $totalRegular += min($wh, 8);
        if ($wh > 8) {
            $ot = $wh - 8;
            $totalOT += floor($ot / 0.75) * 0.75;
        }
        if ($r['status'] === 'present' || $r['status'] === 'late') $present++;
        if ($r['status'] === 'late') $late++;
    }
    
    echo json_encode(['success' => true, 'data' => $rows, 'summary' => [
        'total_hours' => round($totalHours, 1),
        'regular_hours' => round($totalRegular, 1),
        'overtime_hours' => round($totalOT, 1),
        'days_present' => $present,
        'days_late' => $late,
        'target' => 200
    ]]); exit;
}

// ── ROOM OCCUPANCY ──
if ($action === 'occupancy') {
    $today = date('Y-m-d');
    try {
        $totalRooms = $db->fetchOne("SELECT COUNT(*) as c FROM rooms")['c'] ?? 0;
        $occupied = $db->fetchOne("SELECT COUNT(DISTINCT room_id) as c FROM bookings WHERE status = 'checked_in'")['c'] ?? 0;
        $available = max(0, $totalRooms - $occupied);
        $rate = $totalRooms > 0 ? round($occupied / $totalRooms * 100, 1) : 0;
        
        // Room list with status
        $rooms = $db->fetchAll("SELECT r.id, r.room_number, r.room_type, r.floor,
            CASE WHEN b.id IS NOT NULL THEN 'occupied' ELSE 'available' END as status,
            b.guest_name, b.check_in_date, b.check_out_date
            FROM rooms r
            LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'
            ORDER BY r.room_number") ?: [];

        // Arrivals today
        $arrivals = $db->fetchOne("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_in_date) = ? AND status IN ('confirmed','checked_in')", [$today])['c'] ?? 0;
        $departures = $db->fetchOne("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_out_date) = ? AND status = 'checked_in'", [$today])['c'] ?? 0;

        echo json_encode(['success' => true, 'data' => [
            'total_rooms' => $totalRooms, 'occupied' => $occupied, 'available' => $available,
            'occupancy_rate' => $rate, 'rooms' => $rooms,
            'arrivals_today' => $arrivals, 'departures_today' => $departures
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => ['total_rooms' => 0, 'occupied' => 0, 'available' => 0, 'occupancy_rate' => 0, 'rooms' => [], 'arrivals_today' => 0, 'departures_today' => 0]]);
    }
    exit;
}

// ── BREAKFAST REQUEST ──
if ($action === 'breakfast_menu') {
    try {
        $menus = $db->fetchAll("SELECT id, menu_name, category, is_free FROM breakfast_menus WHERE is_available = 1 ORDER BY category, menu_name") ?: [];
        echo json_encode(['success' => true, 'data' => $menus]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => []]);
    }
    exit;
}

if ($action === 'breakfast_submit') {
    $menuId = (int)($_POST['menu_id'] ?? 0);
    $date = $_POST['date'] ?? date('Y-m-d');
    $staffName = $_SESSION['staff_name'] ?? 'Staff';
    
    if ($menuId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Pilih menu']); exit;
    }
    
    try {
        $menu = $db->fetchOne("SELECT menu_name FROM breakfast_menus WHERE id = ?", [$menuId]);
        if (!$menu) {
            echo json_encode(['success' => false, 'message' => 'Menu tidak ditemukan']); exit;
        }

        // Check if already ordered today
        $existing = $db->fetchOne("SELECT id FROM breakfast_orders WHERE guest_name = ? AND breakfast_date = ?", [$staffName, $date]);
        if ($existing) {
            $pdo->prepare("UPDATE breakfast_orders SET menu_name = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$menu['menu_name'], $existing['id']]);
            echo json_encode(['success' => true, 'message' => 'Pesanan breakfast diperbarui: ' . $menu['menu_name']]);
        } else {
            $pdo->prepare("INSERT INTO breakfast_orders (guest_name, breakfast_date, menu_name, room_number, status) VALUES (?, ?, ?, ?, 'pending')")
                ->execute([$staffName, $date, $menu['menu_name'], 'STAFF']);
            echo json_encode(['success' => true, 'message' => 'Pesanan breakfast berhasil: ' . $menu['menu_name']]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'breakfast_today') {
    $today = date('Y-m-d');
    $staffName = $_SESSION['staff_name'] ?? '';
    $order = $db->fetchOne("SELECT * FROM breakfast_orders WHERE guest_name = ? AND breakfast_date = ?", [$staffName, $today]);
    echo json_encode(['success' => true, 'data' => $order]); exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
