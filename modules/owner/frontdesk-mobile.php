<?php
/**
 * FRONTDESK MOBILE DASHBOARD
 * Mobile-optimized view for owner monitoring
 * Clean, Compact, Modern - Light Theme
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/business_helper.php';

// Auth check
$role = $_SESSION['role'] ?? null;
if (!$role && isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        $authDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $roleStmt = $authDb->prepare("SELECT r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $roleStmt->execute([$_SESSION['user_id'] ?? 0]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $role = $roleRow['role_code'];
            $_SESSION['role'] = $role;
        }
    } catch (Exception $e) {}
}

if (!$role || !in_array($role, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ../../login.php');
    exit;
}

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

// Multi-business: get active business config
require_once __DIR__ . '/../../includes/business_access.php';
$allBusinesses = getUserAvailableBusinesses();
$activeBusinessId = getActiveBusinessId();

// Auto-switch if current business not in user's allowed list
if (!empty($allBusinesses) && !isset($allBusinesses[$activeBusinessId])) {
    $firstAllowed = array_key_first($allBusinesses);
    setActiveBusinessId($firstAllowed);
    $activeBusinessId = $firstAllowed;
}

$activeConfig = getActiveBusinessConfig();

// Database config - connect to BUSINESS database
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$businessDbName = getDbName($activeConfig['database'] ?? 'adf_narayana_hotel');
$businessName = $activeConfig['name'] ?? 'Unknown Business';
$businessIcon = $activeConfig['theme']['icon'] ?? '🏢';
$enabledModules = $activeConfig['enabled_modules'] ?? [];
$hasLogo = !empty($activeConfig['logo']);
$logoFile = $activeBusinessId . '_logo.png';

// Get today's date
$today = date('Y-m-d');
$thisMonth = date('Y-m');

// Initialize default values
$stats = [
    'checkins' => 0,
    'checkouts' => 0,
    'available' => 0,
    'occupied' => 0,
    'total_rooms' => 0,
    'occupancy' => 0,
    'today_revenue' => 0,
    'inhouse_revenue' => 0,
    'month_revenue' => 0
];
$inHouseGuests = [];
$todayArrivals = [];
$todayDepartures = [];
$roomStatusMap = [
    'available' => 0,
    'occupied' => 0,
    'maintenance' => 0,
    'cleaning' => 0
];
$error = null;

try {
    // Connect to business database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if rooms table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'rooms'");
    $hasTables = $tableCheck->rowCount() > 0;
    
    if ($hasTables) {
        // Today's check-ins
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM bookings 
            WHERE DATE(check_in_date) = ? 
            AND status IN ('confirmed', 'checked_in')
        ");
        $stmt->execute([$today]);
        $stats['checkins'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Today's check-outs
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM bookings 
            WHERE DATE(check_out_date) = ? 
            AND status = 'checked_in'
        ");
        $stmt->execute([$today]);
        $stats['checkouts'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Room counts - use same logic as frontdesk dashboard
        $stmt = $pdo->query("SELECT COUNT(*) FROM rooms");
        $stats['total_rooms'] = (int)$stmt->fetchColumn();
        
        // Current occupancy - count from bookings where status = 'checked_in' (same as frontdesk)
        $stmt = $pdo->query("SELECT COUNT(DISTINCT room_id) FROM bookings WHERE status = 'checked_in'");
        $stats['occupied'] = (int)$stmt->fetchColumn();
        
        // Available = total - occupied
        $stats['available'] = $stats['total_rooms'] - $stats['occupied'];

        // Occupancy rate
        $stats['occupancy'] = $stats['total_rooms'] > 0 ? round(($stats['occupied'] / $stats['total_rooms']) * 100) : 0;

        // Today's revenue (from booking_payments)
        // FIXED: Only count payments from checked_in/checked_out bookings
        // OTA payments should only count when guest has checked in
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(bp.amount), 0) as total
                FROM booking_payments bp
                JOIN bookings b ON bp.booking_id = b.id
                WHERE DATE(bp.payment_date) = ?
                AND b.status IN ('checked_in', 'checked_out')
            ");
            $stmt->execute([$today]);
            $stats['today_revenue'] = (float)$stmt->fetchColumn();
        } catch (Exception $e) {
            $stats['today_revenue'] = 0;
        }

        // In-House Revenue (total paid from CHECKED-IN guests only)
        // FIXED: Only count checked_in, not confirmed (OTA belum masuk kas sampai check-in)
        try {
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(bp.amount), 0) as total
                FROM booking_payments bp
                JOIN bookings b ON bp.booking_id = b.id
                WHERE b.status = 'checked_in'
            ");
            $stats['inhouse_revenue'] = (float)$stmt->fetchColumn();
            
            // Fallback: If booking_payments empty, use bookings.paid_amount
            if ($stats['inhouse_revenue'] == 0) {
                $stmt = $pdo->query("
                    SELECT COALESCE(SUM(paid_amount), 0) as total
                    FROM bookings
                    WHERE status = 'checked_in'
                ");
                $stats['inhouse_revenue'] = (float)$stmt->fetchColumn();
            }
        } catch (Exception $e) {
            $stats['inhouse_revenue'] = 0;
        }

        // Monthly revenue - only from checked_in or checked_out bookings
        // FIXED: confirmed (belum check-in) tidak termasuk
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(bp.amount), 0) as total
                FROM booking_payments bp
                JOIN bookings b ON bp.booking_id = b.id
                WHERE DATE_FORMAT(bp.payment_date, '%Y-%m') = ?
                AND b.status IN ('checked_in', 'checked_out')
            ");
            $stmt->execute([$thisMonth]);
            $stats['month_revenue'] = (float)$stmt->fetchColumn();
            
            // Fallback: use bookings.paid_amount for this month
            if ($stats['month_revenue'] == 0) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(paid_amount), 0) as total
                    FROM bookings
                    WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
                    AND status IN ('checked_in', 'checked_out')
                ");
                $stmt->execute([$thisMonth]);
                $stats['month_revenue'] = (float)$stmt->fetchColumn();
            }
        } catch (Exception $e) {
            $stats['month_revenue'] = 0;
        }

        // In-house guests list
        $stmt = $pdo->query("
            SELECT b.id, g.guest_name, g.phone as guest_phone, b.check_in_date, b.check_out_date, 
                   r.room_number, rt.type_name as room_type, b.final_price as total_amount
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE b.status = 'checked_in'
            ORDER BY b.check_out_date ASC
            LIMIT 10
        ");
        $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Today's arrivals
        $stmt = $pdo->prepare("
            SELECT b.id, g.guest_name, b.check_in_date, b.check_out_date, 
                   r.room_number, rt.type_name as room_type, b.status
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE DATE(b.check_in_date) = ? AND b.status IN ('confirmed', 'checked_in')
            ORDER BY b.check_in_date ASC
            LIMIT 5
        ");
        $stmt->execute([$today]);
        $todayArrivals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Today's departures
        $stmt = $pdo->prepare("
            SELECT b.id, g.guest_name, b.check_out_date, 
                   r.room_number, rt.type_name as room_type, b.status
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE DATE(b.check_out_date) = ? AND b.status = 'checked_in'
            ORDER BY b.check_out_date ASC
            LIMIT 5
        ");
        $stmt->execute([$today]);
        $todayDepartures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Room status breakdown
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM rooms GROUP BY status");
        $roomStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roomStatus as $rs) {
            $roomStatusMap[$rs['status']] = (int)$rs['count'];
        }

        // This month's reservations (all statuses except cancelled)
        $stmt = $pdo->prepare("
            SELECT b.id, g.guest_name, b.check_in_date, b.check_out_date,
                   r.room_number, rt.type_name as room_type,
                   b.status, b.final_price, b.paid_amount,
                   b.booking_source
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE DATE_FORMAT(b.check_in_date, '%Y-%m') = ?
              AND b.status NOT IN ('cancelled')
            ORDER BY b.check_in_date ASC
            LIMIT 50
        ");
        $stmt->execute([$thisMonth]);
        $monthReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Format currency
function rp($num) {
    if ($num >= 1000000) {
        return 'Rp ' . number_format($num / 1000000, 1, ',', '.') . 'M';
    } else {
        return 'Rp ' . number_format($num, 0, ',', '.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Frontdesk Monitor - Owner</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 80px;
        }
        
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 16px;
            border-radius: 20px;
            margin-bottom: 16px;
            text-align: center;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
        }
        
        .header-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .header-logo {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            margin: 0 auto 10px;
            background: white;
            padding: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .header-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .header-subtitle {
            font-size: 12px;
            opacity: 0.9;
        }
        
        .header-date {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 8px;
        }
        
        /* Occupancy Section with Pie Chart - 2028 Digital Luxury */
        .occupancy-section {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 20px;
            padding: 18px;
            margin-bottom: 16px;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.08), 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            gap: 18px;
            border: 1px solid rgba(99, 102, 241, 0.08);
        }
        
        .pie-chart-container {
            position: relative;
            width: 110px;
            height: 110px;
            flex-shrink: 0;
            filter: drop-shadow(0 4px 12px rgba(99, 102, 241, 0.2));
        }
        
        .pie-chart-container canvas {
            width: 110px;
            height: 110px;
        }
        
        .pie-center-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            line-height: 1.1;
        }
        
        .pie-percent {
            font-size: 18px;
            font-weight: 800;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        
        .pie-label {
            font-size: 7px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .occupancy-details {
            flex: 1;
        }
        
        .occupancy-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 10px;
        }
        
        .occ-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            padding: 4px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .occ-row:last-child { border-bottom: none; }
        
        .occ-label { color: var(--text-muted); }
        .occ-value { font-weight: 600; color: var(--text); }
        .occ-value.success { color: var(--success); }
        .occ-value.danger { color: var(--danger); }
        .occ-value.warning { color: var(--warning); }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .stat-card {
            background: var(--card);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-color);
        }
        
        .stat-card.checkin { --accent-color: var(--success); }
        .stat-card.checkout { --accent-color: var(--warning); }
        .stat-card.available { --accent-color: var(--info); }
        .stat-card.occupied { --accent-color: var(--danger); }
        
        .stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: var(--text);
        }
        
        .stat-hint {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        /* Revenue Section */
        .revenue-section {
            background: var(--card);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .revenue-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .revenue-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        
        .revenue-item {
            text-align: center;
            padding: 10px 8px;
            background: var(--bg);
            border-radius: 12px;
        }
        
        .revenue-label {
            font-size: 9px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        
        .revenue-value {
            font-size: 14px;
            font-weight: 700;
            color: var(--success);
        }
        
        /* Section Title */
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title .badge {
            background: var(--primary);
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        /* Guest List */
        .guest-list {
            background: var(--card);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .guest-item {
            display: flex;
            align-items: center;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
        }
        
        .guest-item:last-child { border-bottom: none; }
        
        .guest-room {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 13px;
            margin-right: 12px;
        }
        
        .guest-info { flex: 1; }
        
        .guest-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .guest-detail {
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .guest-checkout {
            text-align: right;
        }
        
        .checkout-date {
            font-size: 11px;
            color: var(--text-muted);
        }
        
        .checkout-label {
            font-size: 9px;
            color: var(--warning);
            font-weight: 600;
        }
        
        /* ── Reservasi Bulan Ini ─────────────────────────────────── */
        .res-table-wrap { background: var(--card); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 16px; }
        .res-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .res-table thead tr { background: linear-gradient(90deg, #6366f1, #818cf8); }
        .res-table th { color: #fff; padding: 7px 8px; font-size: 10px; font-weight: 600; letter-spacing: 0.04em; text-align: left; }
        .res-table th.r { text-align: right; }
        .res-table td { padding: 7px 8px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
        .res-table tbody tr:last-child td { border-bottom: none; }
        .res-table tbody tr:nth-child(even) td { background: #f8faff; }
        .res-status { display: inline-block; padding: 2px 6px; border-radius: 8px; font-size: 9px; font-weight: 700; letter-spacing: 0.03em; }
        .res-s-confirmed  { background: #dbeafe; color: #1d4ed8; }
        .res-s-checked_in { background: #dcfce7; color: #15803d; }
        .res-s-checked_out{ background: #f1f5f9; color: #475569; }
        .res-s-pending    { background: #fef9c3; color: #a16207; }
        .res-empty { text-align: center; padding: 20px; color: var(--text-muted); font-size: 12px; }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: var(--text-muted);
        }
        
        .empty-icon {
            font-size: 40px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        .empty-text {
            font-size: 13px;
        }
        
        /* Error State */
        .error-card {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        
        .error-title {
            color: var(--danger);
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .error-text {
            font-size: 12px;
            color: #991b1b;
        }
        
        /* Footer Nav */
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0 14px;
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 16px rgba(0,0,0,0.06);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 10px;
            color: var(--text-muted);
            transition: color 0.2s;
            padding: 4px 12px;
        }
        
        .nav-item.active { color: var(--primary); }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 2px;
        }

        /* Arrival/Departure cards */
        .movement-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .movement-card {
            background: var(--card);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }

        .movement-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .movement-icon {
            font-size: 16px;
        }

        .movement-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .movement-list {
            font-size: 11px;
        }

        .movement-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
        }

        .movement-item:last-child { border-bottom: none; }

        .movement-name {
            color: var(--text);
            font-weight: 500;
        }

        .movement-room {
            color: var(--primary);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-logo">
                <img src="<?= $basePath ?>/uploads/logos/<?= htmlspecialchars($logoFile) ?>" alt="Logo" onerror="this.parentElement.style.display='none'">
            </div>
            <div class="header-title">Frontdesk Monitor</div>
            <div class="header-subtitle"><?= htmlspecialchars($businessName) ?></div>
            <div class="header-date"><?= date('l, d F Y') ?></div>
        </div>
        
        <?php if ($error): ?>
        <div class="error-card">
            <div class="error-title">⚠️ Connection Error</div>
            <div class="error-text"><?= htmlspecialchars($error) ?></div>
        </div>
        <?php else: ?>
        
        <!-- Occupancy with Pie Chart - 2028 Digital Luxury -->
        <div class="occupancy-section">
            <div class="pie-chart-container">
                <canvas id="occupancyPie" width="110" height="110"></canvas>
                <div class="pie-center-text">
                    <div class="pie-percent"><?= $stats['occupancy'] ?>%</div>
                    <div class="pie-label">Occ</div>
                </div>
            </div>
            <div class="occupancy-details">
                <div class="occupancy-title">Room Status</div>
                <div class="occ-row">
                    <span class="occ-label">Available</span>
                    <span class="occ-value success"><?= $stats['available'] ?> rooms</span>
                </div>
                <div class="occ-row">
                    <span class="occ-label">Occupied</span>
                    <span class="occ-value danger"><?= $stats['occupied'] ?> rooms</span>
                </div>
                <div class="occ-row">
                    <span class="occ-label">Total</span>
                    <span class="occ-value"><?= $stats['total_rooms'] ?> rooms</span>
                </div>
            </div>
        </div>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card checkin">
                <div class="stat-label">Check-In Today</div>
                <div class="stat-value"><?= $stats['checkins'] ?></div>
                <div class="stat-hint">Expected arrivals</div>
            </div>
            <div class="stat-card checkout">
                <div class="stat-label">Check-Out Today</div>
                <div class="stat-value"><?= $stats['checkouts'] ?></div>
                <div class="stat-hint">Expected departures</div>
            </div>
        </div>
        
        <!-- Revenue Section -->
        <div class="revenue-section">
            <div class="revenue-title">💰 Revenue</div>
            <div class="revenue-grid">
                <div class="revenue-item">
                    <div class="revenue-label">Today</div>
                    <div class="revenue-value"><?= rp($stats['today_revenue']) ?></div>
                </div>
                <div class="revenue-item">
                    <div class="revenue-label">In-House</div>
                    <div class="revenue-value" style="color: #10b981;"><?= rp($stats['inhouse_revenue']) ?></div>
                </div>
                <div class="revenue-item">
                    <div class="revenue-label">This Month</div>
                    <div class="revenue-value"><?= rp($stats['month_revenue']) ?></div>
                </div>
            </div>
        </div>

        <!-- Arrivals & Departures -->
        <div class="movement-grid">
            <div class="movement-card">
                <div class="movement-header">
                    <span class="movement-icon">🛬</span>
                    <span class="movement-title">Arrivals</span>
                </div>
                <div class="movement-list">
                    <?php if (empty($todayArrivals)): ?>
                        <div style="color: var(--text-muted); font-size: 11px;">No arrivals today</div>
                    <?php else: ?>
                        <?php foreach ($todayArrivals as $arr): ?>
                        <div class="movement-item">
                            <span class="movement-name"><?= htmlspecialchars(substr($arr['guest_name'] ?? '-', 0, 12)) ?></span>
                            <span class="movement-room"><?= htmlspecialchars($arr['room_number'] ?? '-') ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="movement-card">
                <div class="movement-header">
                    <span class="movement-icon">🛫</span>
                    <span class="movement-title">Departures</span>
                </div>
                <div class="movement-list">
                    <?php if (empty($todayDepartures)): ?>
                        <div style="color: var(--text-muted); font-size: 11px;">No departures today</div>
                    <?php else: ?>
                        <?php foreach ($todayDepartures as $dep): ?>
                        <div class="movement-item">
                            <span class="movement-name"><?= htmlspecialchars(substr($dep['guest_name'] ?? '-', 0, 12)) ?></span>
                            <span class="movement-room"><?= htmlspecialchars($dep['room_number'] ?? '-') ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- In-House Guests -->
        <div class="section-title">
            🛏️ In-House Guests
            <span class="badge"><?= count($inHouseGuests) ?></span>
        </div>
        
        <div class="guest-list">
            <?php if (empty($inHouseGuests)): ?>
            <div class="empty-state">
                <div class="empty-icon">🏨</div>
                <div class="empty-text">No guests currently checked in</div>
            </div>
            <?php else: ?>
                <?php foreach ($inHouseGuests as $guest): ?>
                <div class="guest-item">
                    <div class="guest-room"><?= htmlspecialchars($guest['room_number'] ?? '-') ?></div>
                    <div class="guest-info">
                        <div class="guest-name"><?= htmlspecialchars($guest['guest_name'] ?? 'Guest') ?></div>
                        <div class="guest-detail"><?= htmlspecialchars($guest['room_type'] ?? '-') ?></div>
                    </div>
                    <div class="guest-checkout">
                        <div class="checkout-date"><?= date('d M', strtotime($guest['check_out_date'])) ?></div>
                        <div class="checkout-label">Check-out</div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>

        <!-- ══ Reservasi Bulan Ini ══════════════════════════════════════════════ -->
        <?php
            $monthReservations = $monthReservations ?? [];
            $monthName = date('F Y');
            $statusLabel = [
                'confirmed'   => 'Confirmed',
                'checked_in'  => 'In-House',
                'checked_out' => 'Checked-Out',
                'pending'     => 'Pending',
            ];
            $statusClass = [
                'confirmed'   => 'res-s-confirmed',
                'checked_in'  => 'res-s-checked_in',
                'checked_out' => 'res-s-checked_out',
                'pending'     => 'res-s-pending',
            ];
        ?>
        <div class="section-title">
            📅 Reservasi Bulan Ini
            <span class="badge"><?= count($monthReservations) ?></span>
        </div>

        <div class="res-table-wrap">
            <?php if (empty($monthReservations)): ?>
            <div class="res-empty">Belum ada reservasi bulan <?= $monthName ?></div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="res-table">
                <thead>
                    <tr>
                        <th>Tamu</th>
                        <th>Kamar</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                        <th class="r">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($monthReservations as $res): ?>
                <tr>
                    <td style="max-width:90px">
                        <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:88px"><?= htmlspecialchars($res['guest_name'] ?? '-') ?></div>
                        <?php if (!empty($res['booking_source'])): ?>
                        <div style="font-size:9px;color:var(--text-muted)"><?= htmlspecialchars($res['booking_source']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:700;color:var(--primary)"><?= htmlspecialchars($res['room_number'] ?? '-') ?><div style="font-size:9px;color:var(--text-muted);font-weight:400"><?= htmlspecialchars(substr($res['room_type'] ?? '', 0, 8)) ?></div></td>
                    <td style="white-space:nowrap"><?= date('d M', strtotime($res['check_in_date'])) ?></td>
                    <td style="white-space:nowrap"><?= date('d M', strtotime($res['check_out_date'])) ?></td>
                    <td><span class="res-status <?= $statusClass[$res['status']] ?? '' ?>"><?= $statusLabel[$res['status']] ?? ucfirst($res['status']) ?></span></td>
                    <td style="text-align:right;font-weight:600;white-space:nowrap"><?= rp((float)$res['final_price']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        
    <?php
    require_once __DIR__ . '/../../includes/owner_footer_nav.php';
    $activeConfig = getActiveBusinessConfig();
    renderOwnerFooterNav('frontdesk', $basePath, $activeConfig['enabled_modules'] ?? []);
    ?>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var canvas = document.getElementById('occupancyPie');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        
        var occupied = <?= (int)$stats['occupied'] ?>;
        var available = <?= (int)$stats['available'] ?>;
        var maintenance = <?= (int)($roomStatusMap['maintenance'] ?? 0) ?>;
        var cleaning = <?= (int)($roomStatusMap['cleaning'] ?? 0) ?>;
        var total = occupied + available + maintenance + cleaning;
        if (total === 0) { available = 1; total = 1; }
        
        var cx = 55, cy = 55, r = 50, innerR = 32;
        var startAngle = -Math.PI / 2;
        
        // Modern gradient colors
        var gradOccupied = ctx.createLinearGradient(0, 0, 110, 110);
        gradOccupied.addColorStop(0, '#ef4444');
        gradOccupied.addColorStop(1, '#f87171');
        
        var gradAvailable = ctx.createLinearGradient(0, 0, 110, 110);
        gradAvailable.addColorStop(0, '#10b981');
        gradAvailable.addColorStop(1, '#34d399');
        
        var gradMaint = ctx.createLinearGradient(0, 0, 110, 110);
        gradMaint.addColorStop(0, '#f59e0b');
        gradMaint.addColorStop(1, '#fbbf24');
        
        var gradClean = ctx.createLinearGradient(0, 0, 110, 110);
        gradClean.addColorStop(0, '#3b82f6');
        gradClean.addColorStop(1, '#60a5fa');
        
        // Draw segments with gradients
        var segments = [
            { value: occupied, color: gradOccupied },
            { value: available, color: gradAvailable },
            { value: maintenance, color: gradMaint },
            { value: cleaning, color: gradClean }
        ];
        
        var currentAngle = startAngle;
        segments.forEach(function(seg) {
            if (seg.value > 0) {
                var angle = (seg.value / total) * 2 * Math.PI;
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, currentAngle, currentAngle + angle);
                ctx.closePath();
                ctx.fillStyle = seg.color;
                ctx.fill();
                currentAngle += angle;
            }
        });
        
        // Inner donut hole with subtle gradient
        var innerGrad = ctx.createRadialGradient(cx, cy, 0, cx, cy, innerR);
        innerGrad.addColorStop(0, '#ffffff');
        innerGrad.addColorStop(1, '#f8fafc');
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.fillStyle = innerGrad;
        ctx.fill();
        
        // Subtle inner shadow ring
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(99, 102, 241, 0.1)';
        ctx.lineWidth = 1;
        ctx.stroke();
    });
    </script>
</body>
</html>
