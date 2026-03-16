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

// Auto-create leave_requests table
$pdo->exec("CREATE TABLE IF NOT EXISTS `leave_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `leave_type` VARCHAR(50) NOT NULL DEFAULT 'cuti',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` TEXT,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `approved_by` VARCHAR(100) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `admin_notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp (employee_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Session — close any existing session first, then start staff-specific one
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
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
    try {
        $pdo->prepare("INSERT INTO staff_accounts (employee_id, email, password_hash) VALUES (?, ?, ?)")
            ->execute([$emp['id'], $email, $hash]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage()]); exit;
    }

    echo json_encode(['success' => true, 'message' => 'Registrasi berhasil! Silakan login.', 'name' => $emp['full_name']]);
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
        WHERE LOWER(sa.email) = LOWER(?)", [$email]);

    if (!$account) {
        echo json_encode(['success' => false, 'message' => 'Username tidak ditemukan']); exit;
    }
    if (!password_verify($password, $account['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Password salah']); exit;
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
        
        // Room list with type info
        $rooms = $db->fetchAll("SELECT r.id, r.room_number, r.floor_number,
            COALESCE(rt.type_name, 'Standard') as room_type,
            CASE WHEN b.id IS NOT NULL THEN 'occupied' ELSE 'available' END as status,
            g.guest_name, b.check_in_date, b.check_out_date
            FROM rooms r
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'
            LEFT JOIN guests g ON b.guest_id = g.id
            ORDER BY rt.type_name ASC, r.room_number ASC") ?: [];

        // Arrivals today
        $arrivals = $db->fetchOne("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_in_date) = ? AND status IN ('confirmed','checked_in')", [$today])['c'] ?? 0;
        $departures = $db->fetchOne("SELECT COUNT(*) as c FROM bookings WHERE DATE(check_out_date) = ? AND status = 'checked_in'", [$today])['c'] ?? 0;

        // Calendar bookings (14 days from start_date param or today)
        $startDate = $_GET['start'] ?? $today;
        $endDate = date('Y-m-d', strtotime($startDate . ' +13 days'));
        $bookings = $db->fetchAll("SELECT b.id, b.booking_code, b.room_id, b.check_in_date, b.check_out_date,
            b.status, b.booking_source, b.payment_status, g.guest_name
            FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id
            WHERE b.check_in_date <= ? AND b.check_out_date > ?
            AND b.status IN ('pending','confirmed','checked_in','checked_out')
            ORDER BY b.check_in_date ASC", [$endDate, $startDate]) ?: [];

        echo json_encode(['success' => true, 'data' => [
            'total_rooms' => $totalRooms, 'occupied' => $occupied, 'available' => $available,
            'occupancy_rate' => $rate, 'rooms' => $rooms,
            'arrivals_today' => $arrivals, 'departures_today' => $departures,
            'bookings' => $bookings, 'calendar_start' => $startDate, 'calendar_end' => $endDate
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => ['total_rooms' => 0, 'occupied' => 0, 'available' => 0, 'occupancy_rate' => 0, 'rooms' => [], 'arrivals_today' => 0, 'departures_today' => 0, 'bookings' => []]]);
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

// ── ALL TODAY'S BREAKFAST ORDERS (Staff Monitor) ──
if ($action === 'breakfast_orders') {
    $today = date('Y-m-d');
    try {
        $orders = $db->fetchAll("
            SELECT bo.id, bo.guest_name, bo.room_number, bo.total_pax, bo.breakfast_time,
                   bo.breakfast_date, bo.location, bo.menu_items, bo.special_requests,
                   bo.total_price, bo.order_status, bo.created_at
            FROM breakfast_orders bo
            WHERE bo.breakfast_date = ?
            AND bo.id = (SELECT MAX(bo2.id) FROM breakfast_orders bo2 
                WHERE bo2.guest_name = bo.guest_name AND bo2.breakfast_date = bo.breakfast_date)
            ORDER BY bo.breakfast_time ASC, bo.id ASC
        ", [$today]) ?: [];

        // Decode JSON fields
        foreach ($orders as &$o) {
            $o['menu_items'] = json_decode($o['menu_items'] ?? '[]', true) ?: [];
            $rooms = json_decode($o['room_number'] ?? '[]', true);
            $o['room_display'] = is_array($rooms) ? implode(', ', $rooms) : ($o['room_number'] ?? '-');
        }

        // Stats
        $totalOrders = count($orders);
        $totalPax = array_sum(array_column($orders, 'total_pax'));
        $statusCounts = ['pending' => 0, 'preparing' => 0, 'served' => 0, 'completed' => 0];
        foreach ($orders as $o) {
            $s = $o['order_status'] ?? 'pending';
            if (isset($statusCounts[$s])) $statusCounts[$s]++;
        }

        echo json_encode(['success' => true, 'data' => [
            'orders' => $orders,
            'stats' => [
                'total_orders' => $totalOrders,
                'total_pax' => $totalPax,
                'status' => $statusCounts
            ]
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'data' => ['orders' => [], 'stats' => ['total_orders' => 0, 'total_pax' => 0, 'status' => []]]]);
    }
    exit;
}

// ══════════════════════════════════════
// LEAVE / CUTI
// ══════════════════════════════════════
if ($action === 'leave_submit') {
    $type = trim($_POST['leave_type'] ?? 'cuti');
    $start = $_POST['start_date'] ?? '';
    $end = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Tanggal mulai dan selesai wajib diisi']); exit;
    }
    if ($start > $end) {
        echo json_encode(['success' => false, 'message' => 'Tanggal selesai harus setelah tanggal mulai']); exit;
    }
    if (!$reason) {
        echo json_encode(['success' => false, 'message' => 'Alasan wajib diisi']); exit;
    }
    $allowedTypes = ['cuti', 'sakit', 'izin', 'cuti_khusus'];
    if (!in_array($type, $allowedTypes)) $type = 'cuti';

    // Check overlapping
    $overlap = $db->fetchOne("SELECT id FROM leave_requests WHERE employee_id = ? AND status != 'rejected' AND start_date <= ? AND end_date >= ?", [$empId, $end, $start]);
    if ($overlap) {
        echo json_encode(['success' => false, 'message' => 'Sudah ada pengajuan cuti di tanggal tersebut']); exit;
    }

    try {
        $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)")
            ->execute([$empId, $type, $start, $end, $reason]);
        echo json_encode(['success' => true, 'message' => 'Pengajuan cuti berhasil dikirim!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'leave_history') {
    $rows = $db->fetchAll("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 50", [$empId]) ?: [];
    // Count stats
    $year = date('Y');
    $stats = $db->fetchOne("SELECT 
        COUNT(CASE WHEN status = 'approved' AND leave_type = 'cuti' AND YEAR(start_date) = ? THEN 1 END) as cuti_used,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
        FROM leave_requests WHERE employee_id = ?", [$year, $empId]) ?: [];
    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]); exit;
}

if ($action === 'notifications') {
    // Get recent leave status changes (approved/rejected in last 30 days)
    $notifs = $db->fetchAll("SELECT id, leave_type, start_date, end_date, status, admin_notes, approved_at 
        FROM leave_requests 
        WHERE employee_id = ? AND status IN ('approved','rejected') AND approved_at IS NOT NULL AND approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY approved_at DESC LIMIT 20", [$empId]) ?: [];
    echo json_encode(['success' => true, 'data' => $notifs]); exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
