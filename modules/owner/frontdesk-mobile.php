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
        
        // Available & occupancy - will be recalculated below after room status map is loaded
        $stats['available'] = $stats['total_rooms'] - $stats['occupied'];
        $stats['occupancy'] = $stats['total_rooms'] > 0 ? round(($stats['occupied'] / $stats['total_rooms']) * 100) : 0;

        // Revenue from cash_book (source of truth - matches Buku Kas Besar in system)
        // Exclude owner capital/modal entries for accurate business revenue
        $hasCashBook = false;
        try {
            $cbCheck = $pdo->query("SHOW TABLES LIKE 'cash_book'");
            $hasCashBook = $cbCheck->rowCount() > 0;
        } catch (Exception $e) {}

        if ($hasCashBook) {
            // Build exclusion clause for modal/capital entries
            $revenueExclude = "";
            try {
                // Check if cash_account_id column exists
                $colCheck = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                if ($colCheck && $colCheck->rowCount() > 0) {
                    // Get master DB connection for cash_accounts
                    $masterPdoRev = new PDO(
                        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    // Get business ID from master
                    $bizStmt = $masterPdoRev->prepare("SELECT id FROM businesses WHERE database_name = ? LIMIT 1");
                    $bizStmt->execute([$activeConfig['database'] ?? '']);
                    $bizRow = $bizStmt->fetch(PDO::FETCH_ASSOC);
                    $numBizId = $bizRow ? (int)$bizRow['id'] : null;
                    
                    if ($numBizId) {
                        $capStmt = $masterPdoRev->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
                        $capStmt->execute([$numBizId]);
                        $capitalAccIds = $capStmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($capitalAccIds)) {
                            $revenueExclude .= " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', array_map('intval', $capitalAccIds)) . "))";
                        }
                    }
                }
                
                // Exclude modal/transfer categories
                $catStmt = $pdo->query("SELECT id FROM categories WHERE LOWER(category_name) LIKE '%modal%' OR LOWER(category_name) LIKE '%transfer%dana%' OR LOWER(category_name) LIKE '%capital%'");
                $excludeCats = $catStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($excludeCats)) {
                    $revenueExclude .= " AND (category_id IS NULL OR category_id NOT IN (" . implode(',', array_map('intval', $excludeCats)) . "))";
                }
                
                // Exclude by description patterns
                $revenueExclude .= " AND (LOWER(description) NOT LIKE '%modal operasional%' AND LOWER(description) NOT LIKE '%transfer dana%' AND LOWER(description) NOT LIKE '%setoran modal%')";
            } catch (Exception $e) {
                // If exclusion logic fails, continue without exclusions
            }

            // Today's revenue from cash_book
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'" . $revenueExclude);
                $stmt->execute([$today]);
                $stats['today_revenue'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['today_revenue'] = 0;
            }

            // This month's revenue from cash_book
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $revenueExclude);
                $stmt->execute([$thisMonth]);
                $stats['month_revenue'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['month_revenue'] = 0;
            }
        }

        // In-House Revenue (total from currently checked-in guests)
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

        // Room status breakdown from rooms table
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM rooms GROUP BY status");
        $roomStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roomStatus as $rs) {
            $roomStatusMap[$rs['status']] = (int)$rs['count'];
        }

        // Fix: Sync room counts - occupied from bookings overrides rooms.status
        // rooms.status may be stale, bookings is source of truth
        $maint = ($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0) + ($roomStatusMap['blocked'] ?? 0);
        $stats['available'] = max(0, $stats['total_rooms'] - $stats['occupied'] - $maint);
        $stats['occupancy'] = $stats['total_rooms'] > 0 ? round(($stats['occupied'] / $stats['total_rooms']) * 100) : 0;

        // ── Calendar Timeline Data (CloudBeds style) ──
        $calRooms = [];
        $calBookings = [];
        try {
            // All rooms with type info
            $stmt = $pdo->query("
                SELECT r.id, r.room_number, r.status, r.floor_number,
                       COALESCE(rt.type_name, 'Standard') as room_type
                FROM rooms r
                LEFT JOIN room_types rt ON r.room_type_id = rt.id
                ORDER BY rt.type_name, r.floor_number, r.room_number
            ");
            $calRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Bookings spanning -7 to +30 days
            $calStart = date('Y-m-d', strtotime('-7 days'));
            $calEnd = date('Y-m-d', strtotime('+30 days'));
            $stmt = $pdo->prepare("
                SELECT b.id, b.booking_code, b.room_id, b.check_in_date, b.check_out_date,
                       b.status, b.room_price, b.booking_source, b.payment_status,
                       b.total_nights, b.final_price,
                       g.guest_name, g.phone as guest_phone
                FROM bookings b
                LEFT JOIN guests g ON b.guest_id = g.id
                WHERE b.check_in_date < ? AND b.check_out_date > ?
                AND b.status IN ('pending', 'confirmed', 'checked_in', 'checked_out')
                ORDER BY b.check_in_date
            ");
            $stmt->execute([$calEnd, $calStart]);
            $calBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Attach current guest to room
            foreach ($calRooms as &$cr) {
                foreach ($calBookings as $cb) {
                    if ($cb['room_id'] == $cr['id'] && $cb['status'] === 'checked_in') {
                        $cr['guest_name'] = $cb['guest_name'];
                        break;
                    }
                }
            }
            unset($cr);
        } catch (Exception $e) {}
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
        
        /* ── Room Status Grid ──────────────────────────── */
        .room-status-wrap { background: var(--card); border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 14px; }
        .room-status-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 6px; }
        .room-status-label { font-size: 12px; font-weight: 700; color: var(--text); }
        .room-status-summary { display: flex; gap: 6px; flex-wrap: wrap; }
        .room-status-pill { display: flex; align-items: center; gap: 4px; font-size: 9px; font-weight: 700; padding: 3px 8px; border-radius: 10px; }
        .rsp-available { background: #dcfce7; color: #15803d; }
        .rsp-occupied { background: #fee2e2; color: #dc2626; }
        .rsp-maintenance { background: #f3f4f6; color: #6b7280; }
        .room-grid-owner { display: grid; grid-template-columns: repeat(auto-fill, minmax(66px, 1fr)); gap: 6px; }
        .room-box-owner { border-radius: 10px; padding: 8px 6px; text-align: center; border: 1.5px solid var(--border); position: relative; transition: all 0.2s; }
        .room-box-owner.rb-avail { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-color: #86efac; }
        .room-box-owner.rb-occ { background: linear-gradient(135deg, #fef2f2, #fee2e2); border-color: #fca5a5; }
        .room-box-owner.rb-maint { background: #f9fafb; border-color: #d1d5db; opacity: 0.6; }
        .rb-number { font-size: 15px; font-weight: 900; color: #1e293b; line-height: 1; }
        .rb-type { font-size: 7px; font-weight: 700; text-transform: uppercase; color: #6366f1; letter-spacing: 0.5px; margin-top: 2px; }
        .rb-guest { font-size: 7px; font-weight: 600; color: #dc2626; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 58px; margin-inline: auto; }
        .rb-status-dot { width: 6px; height: 6px; border-radius: 50%; position: absolute; top: 4px; right: 4px; }
        .rb-dot-avail { background: #22c55e; }
        .rb-dot-occ { background: #ef4444; }

        /* ── Calendar Timeline (CloudBeds) ────────────── */
        .cal-card-owner { background: var(--card); border-radius: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 14px; }
        .cal-nav-owner { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; gap: 8px; background: linear-gradient(135deg, #6366f1, #818cf8); }
        .cal-nav-btn { background: rgba(255,255,255,0.2); color: #fff; border: none; border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700; cursor: pointer; transition: 0.15s; backdrop-filter: blur(4px); }
        .cal-nav-btn:active { opacity: 0.7; transform: scale(0.96); }
        .cal-nav-period { font-size: 11px; font-weight: 800; color: #fff; letter-spacing: 0.3px; text-align: center; }
        .cal-scroll-owner { overflow-x: auto; -webkit-overflow-scrolling: touch; cursor: grab; }
        .cal-scroll-owner:active { cursor: grabbing; }
        .cal-grid-owner { display: grid; gap: 0; width: fit-content; min-width: fit-content; }
        .cgo-hdr-room { background: linear-gradient(135deg, #f1f5f9, #fff); border-right: 2px solid #e2e8f0; border-bottom: 2px solid #cbd5e1; padding: 4px; font-weight: 800; text-align: center; position: sticky; left: 0; z-index: 40; font-size: 9px; color: #475569; letter-spacing: 0.8px; text-transform: uppercase; display: flex; align-items: center; justify-content: center; min-width: 64px; max-width: 64px; box-shadow: 2px 0 6px rgba(0,0,0,0.04); }
        .cgo-hdr-date { background: linear-gradient(180deg, #f8fafc, #f1f5f9); border-right: 1px solid #e2e8f0; border-bottom: 2px solid #cbd5e1; padding: 3px 2px; text-align: center; font-weight: 700; font-size: 9px; color: #334155; min-width: 80px; }
        .cgo-hdr-date.cgo-today { background: rgba(99,102,241,0.12) !important; }
        .cgo-hdr-day { font-size: 8px; text-transform: uppercase; font-weight: 600; color: #64748b; letter-spacing: 0.3px; }
        .cgo-hdr-num { font-size: 12px; font-weight: 900; color: #1e293b; margin-left: 2px; }
        .cgo-hdr-date.cgo-today .cgo-hdr-num { color: #6366f1; }
        .cgo-type-hdr { background: linear-gradient(135deg, #eef2ff, #e0e7ff); border-right: 2px solid #a5b4fc; border-bottom: 1px solid #c7d2fe; padding: 3px 6px; font-weight: 800; color: #4338ca; position: sticky; left: 0; z-index: 30; display: flex; align-items: center; font-size: 9px; gap: 4px; min-width: 64px; max-width: 64px; box-shadow: 2px 0 6px rgba(0,0,0,0.04); }
        .cgo-type-price { background: linear-gradient(135deg, #eef2ff, #e0e7ff); border-right: 1px solid #c7d2fe; border-bottom: 1px solid #a5b4fc; }
        .cgo-room { background: linear-gradient(135deg, #f8fafc, #fff); border-right: 2px solid #e2e8f0; border-bottom: 1px solid #f1f5f9; padding: 2px 4px; font-weight: 700; color: #334155; position: sticky; left: 0; z-index: 30; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; min-width: 64px; max-width: 64px; box-shadow: 2px 0 6px rgba(0,0,0,0.04); }
        .cgo-room-type { font-size: 7px; font-weight: 600; color: #6366f1; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1; }
        .cgo-room-num { font-size: 12px; color: #1e293b; font-weight: 900; line-height: 1; }
        .cgo-cell { border-right: 0.5px solid rgba(51,65,85,0.12); border-bottom: 0.5px solid rgba(51,65,85,0.12); min-width: 80px; min-height: 28px; position: relative; background: transparent; }
        .cgo-cell.cgo-today { background: rgba(99,102,241,0.05) !important; }
        .bbar-wrap-o { position: absolute; top: 2px; left: 50%; height: 24px; display: flex; align-items: center; overflow: visible; z-index: 10; margin-left: 4px; cursor: pointer; }
        .bbar-o { width: 100%; height: 22px; padding: 0 6px; display: flex; align-items: center; justify-content: center; text-align: center; box-shadow: 0 2px 6px rgba(0,0,0,0.15), 0 1px 2px rgba(0,0,0,0.1); font-weight: 700; font-size: 9px; line-height: 1.1; position: relative; border-radius: 3px; white-space: nowrap; transform: skewX(-20deg); color: #fff !important; transition: all 0.2s; overflow: hidden; }
        .bbar-o > span { transform: skewX(20deg); color: #fff !important; text-shadow: 0 1px 3px rgba(0,0,0,0.6); font-weight: 800; font-size: 8px; }
        .bbar-o::before { content: ''; position: absolute; left: -6px; top: 50%; transform: translateY(-50%); width: 0; height: 0; border-top: 10px solid transparent; border-bottom: 10px solid transparent; border-right: 4px solid; border-right-color: inherit; }
        .bbar-o::after { content: ''; position: absolute; right: -6px; top: 50%; transform: translateY(-50%); width: 0; height: 0; border-top: 10px solid transparent; border-bottom: 10px solid transparent; border-left: 4px solid; border-left-color: inherit; }
        .bbar-o:active { transform: skewX(-20deg) scaleY(1.1); }
        .bbar-o.bs-confirmed { background: linear-gradient(135deg, #06b6d4, #22d3ee) !important; border-color: #06b6d4; }
        .bbar-o.bs-pending { background: linear-gradient(135deg, #0ea5e9, #38bdf8) !important; border-color: #0ea5e9; }
        .bbar-o.bs-checked-in { background: linear-gradient(135deg, #16a34a, #22c55e) !important; border-color: #16a34a; }
        .bbar-o.bs-checked-out { background: linear-gradient(135deg, #9ca3af, #d1d5db) !important; border-color: #9ca3af; opacity: 0.4; }
        .bbar-o.bs-checked-out > span { color: #6b7280 !important; text-shadow: none !important; }
        .cgo-ftr-room { background: linear-gradient(135deg, #f1f5f9, #fff); border-right: 2px solid #e2e8f0; border-top: 2px solid #cbd5e1; padding: 4px; font-weight: 800; text-align: center; position: sticky; left: 0; z-index: 40; font-size: 9px; color: #475569; letter-spacing: 0.8px; text-transform: uppercase; display: flex; align-items: center; justify-content: center; min-width: 64px; max-width: 64px; box-shadow: 2px 0 6px rgba(0,0,0,0.04); }
        .cgo-ftr-date { background: linear-gradient(180deg, #f8fafc, #f1f5f9); border-right: 1px solid #e2e8f0; border-top: 2px solid #cbd5e1; padding: 3px 2px; text-align: center; font-weight: 700; font-size: 9px; color: #334155; }
        .cgo-ftr-date.cgo-today { background: rgba(99,102,241,0.12) !important; }
        .cal-legend-owner { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px 12px 10px; border-top: 1px solid var(--border); justify-content: center; }
        .cal-legend-item-o { display: flex; align-items: center; gap: 4px; font-size: 8px; color: var(--text-muted); font-weight: 500; }
        .cal-legend-dot-o { width: 12px; height: 7px; border-radius: 3px; transform: skewX(-20deg); }
        /* Booking popup */
        .cal-popup-overlay-o { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 999; display: none; }
        .cal-popup-o { position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%); background: #fff; border-radius: 16px; padding: 18px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); z-index: 1000; width: 300px; max-width: 90vw; display: none; }

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
                <?php $maintTotal = ($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0); if ($maintTotal > 0): ?>
                <div class="occ-row">
                    <span class="occ-label">Maintenance</span>
                    <span class="occ-value warning"><?= $maintTotal ?> rooms</span>
                </div>
                <?php endif; ?>
                <div class="occ-row">
                    <span class="occ-label">Total</span>
                    <span class="occ-value"><?= $stats['total_rooms'] ?> rooms</span>
                </div>
            </div>
        </div>

        <!-- Room Status Grid (below pie chart) -->
        <?php if (!empty($calRooms)): ?>
        <div class="room-status-wrap">
            <div class="room-status-header">
                <div class="room-status-label">🏨 Status Kamar</div>
                <div class="room-status-summary">
                    <span class="room-status-pill rsp-available">✓ <?= $stats['available'] ?> Available</span>
                    <span class="room-status-pill rsp-occupied">● <?= $stats['occupied'] ?> Occupied</span>
                    <?php $maintCount = ($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0); if ($maintCount > 0): ?>
                    <span class="room-status-pill rsp-maintenance">🔧 <?= $maintCount ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="room-grid-owner">
                <?php foreach ($calRooms as $cr):
                    $isOcc = ($cr['status'] === 'occupied') || !empty($cr['guest_name']);
                    $isMaint = in_array($cr['status'], ['maintenance', 'cleaning', 'blocked']);
                    $cls = $isOcc ? 'rb-occ' : ($isMaint ? 'rb-maint' : 'rb-avail');
                    $dotCls = $isOcc ? 'rb-dot-occ' : 'rb-dot-avail';
                ?>
                <div class="room-box-owner <?= $cls ?>">
                    <div class="rb-status-dot <?= $dotCls ?>"></div>
                    <div class="rb-number"><?= htmlspecialchars($cr['room_number']) ?></div>
                    <div class="rb-type"><?= htmlspecialchars(substr($cr['room_type'], 0, 8)) ?></div>
                    <?php if ($isOcc && !empty($cr['guest_name'])): ?>
                    <div class="rb-guest"><?= htmlspecialchars(substr($cr['guest_name'], 0, 12)) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
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

        <!-- Booking Calendar Timeline (above In-House Guests) -->
        <?php if (!empty($calRooms)): ?>
        <div class="section-title">
            📅 Booking Calendar
            <span class="badge"><?= count($calBookings) ?></span>
        </div>
        <div class="cal-card-owner">
            <div class="cal-nav-owner">
                <button class="cal-nav-btn" onclick="ownerCalNav(-14)">◀ Prev</button>
                <span class="cal-nav-period" id="ownerCalPeriod"></span>
                <button class="cal-nav-btn" onclick="ownerCalNav(14)">Next ▶</button>
            </div>
            <div class="cal-scroll-owner" id="ownerCalScroll">
                <div id="ownerCalGrid"></div>
            </div>
            <div class="cal-legend-owner">
                <div class="cal-legend-item-o"><div class="cal-legend-dot-o" style="background:linear-gradient(135deg,#06b6d4,#22d3ee);"></div>Confirmed</div>
                <div class="cal-legend-item-o"><div class="cal-legend-dot-o" style="background:linear-gradient(135deg,#0ea5e9,#38bdf8);"></div>Pending</div>
                <div class="cal-legend-item-o"><div class="cal-legend-dot-o" style="background:linear-gradient(135deg,#16a34a,#22c55e);"></div>Checked In</div>
                <div class="cal-legend-item-o"><div class="cal-legend-dot-o" style="background:linear-gradient(135deg,#9ca3af,#d1d5db);"></div>Checked Out</div>
            </div>
        </div>
        <!-- Booking Popup -->
        <div class="cal-popup-overlay-o" id="ownerCalOverlay" onclick="closeOwnerCalPopup()"></div>
        <div class="cal-popup-o" id="ownerCalPopup"></div>
        <?php endif; ?>

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

        
    <?php
    require_once __DIR__ . '/../../includes/owner_footer_nav.php';
    $activeConfig = getActiveBusinessConfig();
    renderOwnerFooterNav('frontdesk', $basePath, $activeConfig['enabled_modules'] ?? []);
    ?>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ── Pie Chart ──
        var canvas = document.getElementById('occupancyPie');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        
        var occupied = <?= (int)$stats['occupied'] ?>;
        var available = <?= (int)$stats['available'] ?>;
        var maintenance = <?= (int)(($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0)) ?>;
        var total = occupied + available + maintenance;
        if (total === 0) { available = 1; total = 1; }
        
        var cx = 55, cy = 55, r = 50, innerR = 32;
        var startAngle = -Math.PI / 2;
        
        var gradOccupied = ctx.createLinearGradient(0, 0, 110, 110);
        gradOccupied.addColorStop(0, '#ef4444'); gradOccupied.addColorStop(1, '#f87171');
        var gradAvailable = ctx.createLinearGradient(0, 0, 110, 110);
        gradAvailable.addColorStop(0, '#10b981'); gradAvailable.addColorStop(1, '#34d399');
        var gradMaint = ctx.createLinearGradient(0, 0, 110, 110);
        gradMaint.addColorStop(0, '#f59e0b'); gradMaint.addColorStop(1, '#fbbf24');
        
        var segments = [
            { value: occupied, color: gradOccupied },
            { value: available, color: gradAvailable },
            { value: maintenance, color: gradMaint }
        ];
        var currentAngle = startAngle;
        segments.forEach(function(seg) {
            if (seg.value > 0) {
                var angle = (seg.value / total) * 2 * Math.PI;
                ctx.beginPath(); ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, currentAngle, currentAngle + angle);
                ctx.closePath(); ctx.fillStyle = seg.color; ctx.fill();
                currentAngle += angle;
            }
        });
        var innerGrad = ctx.createRadialGradient(cx, cy, 0, cx, cy, innerR);
        innerGrad.addColorStop(0, '#ffffff'); innerGrad.addColorStop(1, '#f8fafc');
        ctx.beginPath(); ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.fillStyle = innerGrad; ctx.fill();
        ctx.beginPath(); ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(99, 102, 241, 0.1)'; ctx.lineWidth = 1; ctx.stroke();
    });

    // ── CloudBeds Booking Calendar ──
    <?php if (!empty($calRooms)): ?>
    (function() {
        var COL_W = 80;
        var calRooms = <?= json_encode($calRooms, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var calBookings = <?= json_encode($calBookings, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var calOffset = 0;

        function renderOwnerCal() {
            var today = new Date(); today.setHours(0,0,0,0);
            var start = new Date(today);
            start.setDate(start.getDate() + calOffset - 3);
            var days = 14, dates = [], todayStr = today.toISOString().split('T')[0];
            for (var i = 0; i < days; i++) {
                var dt = new Date(start); dt.setDate(dt.getDate() + i);
                dates.push(dt.toISOString().split('T')[0]);
            }
            var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
            var startD = new Date(dates[0] + 'T00:00:00'), endD = new Date(dates[dates.length-1] + 'T00:00:00');
            var periodEl = document.getElementById('ownerCalPeriod');
            if (periodEl) periodEl.textContent = startD.getDate() + ' ' + months[startD.getMonth()] + ' — ' + endD.getDate() + ' ' + months[endD.getMonth()] + ' ' + endD.getFullYear();

            var roomsByType = {};
            calRooms.forEach(function(r) {
                var t = r.room_type || 'Standard';
                if (!roomsByType[t]) roomsByType[t] = [];
                roomsByType[t].push(r);
            });

            var bookingMap = {};
            calBookings.forEach(function(b) {
                if (!bookingMap[b.room_id]) bookingMap[b.room_id] = [];
                var bStart = b.check_in_date, bEnd = b.check_out_date;
                var startCol = -1, endCol = -1;
                for (var i = 0; i < dates.length; i++) {
                    if (dates[i] >= bStart && startCol < 0) startCol = i;
                    if (dates[i] < bEnd) endCol = i;
                }
                if (bStart < dates[0]) startCol = 0;
                if (endCol < 0 && bEnd > dates[0]) endCol = dates.length - 1;
                if (startCol >= 0 && endCol >= startCol) {
                    var copy = {}; for (var k in b) copy[k] = b[k];
                    copy.startCol = startCol; copy.span = endCol - startCol + 1;
                    bookingMap[b.room_id].push(copy);
                }
            });

            var dayNames = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
            var g = '<div class="cal-grid-owner" style="grid-template-columns:64px repeat(' + days + ',' + COL_W + 'px);">';
            g += '<div class="cgo-hdr-room">ROOMS</div>';
            dates.forEach(function(dt) {
                var dd = new Date(dt + 'T00:00:00'), isTd = dt === todayStr;
                g += '<div class="cgo-hdr-date' + (isTd ? ' cgo-today' : '') + '"><span class="cgo-hdr-day">' + dayNames[dd.getDay()] + '</span> <span class="cgo-hdr-num">' + dd.getDate() + '</span></div>';
            });

            Object.keys(roomsByType).forEach(function(typeName) {
                g += '<div class="cgo-type-hdr">📂 ' + typeName.substring(0, 10) + '</div>';
                for (var i = 0; i < days; i++) g += '<div class="cgo-type-price"></div>';

                roomsByType[typeName].forEach(function(room) {
                    var tShort = (room.room_type || '').toUpperCase().substring(0, 6);
                    g += '<div class="cgo-room"><span class="cgo-room-type">' + tShort + '</span><span class="cgo-room-num">' + room.room_number + '</span></div>';
                    var roomBookings = bookingMap[room.id] || [];
                    for (var i = 0; i < days; i++) {
                        var isTd = dates[i] === todayStr;
                        g += '<div class="cgo-cell' + (isTd ? ' cgo-today' : '') + '">';
                        roomBookings.forEach(function(rb) {
                            if (rb.startCol === i) {
                                var barW = (rb.span * COL_W) - 10;
                                var sCls = 'bs-' + (rb.status || '').replace('_', '-');
                                var isCI = rb.status === 'checked_in';
                                var icon = isCI ? '✓ ' : '';
                                var name = (rb.guest_name || 'Guest').substring(0, 10);
                                var code = (rb.booking_code || '').substring(0, 6);
                                var bData = encodeURIComponent(JSON.stringify({
                                    booking_code: rb.booking_code, guest_name: rb.guest_name,
                                    check_in_date: rb.check_in_date, check_out_date: rb.check_out_date,
                                    status: rb.status, booking_source: rb.booking_source,
                                    payment_status: rb.payment_status, room_price: rb.room_price,
                                    final_price: rb.final_price, total_nights: rb.total_nights,
                                    guest_phone: rb.guest_phone
                                }));
                                g += '<div class="bbar-wrap-o" style="width:' + barW + 'px;" onclick="showOwnerBooking(\'' + bData + '\')">'
                                   + '<div class="bbar-o ' + sCls + '"><span>' + icon + name + ' • ' + code + '</span></div></div>';
                            }
                        });
                        g += '</div>';
                    }
                });
            });

            g += '<div class="cgo-ftr-room">ROOMS</div>';
            dates.forEach(function(dt) {
                var dd = new Date(dt + 'T00:00:00'), isTd = dt === todayStr;
                g += '<div class="cgo-ftr-date' + (isTd ? ' cgo-today' : '') + '"><span class="cgo-hdr-day">' + dayNames[dd.getDay()] + '</span> <span class="cgo-hdr-num">' + dd.getDate() + '</span></div>';
            });
            g += '</div>';
            document.getElementById('ownerCalGrid').innerHTML = g;

            var todayIdx = dates.indexOf(todayStr);
            if (todayIdx > 1) {
                var scrollEl = document.getElementById('ownerCalScroll');
                setTimeout(function() { scrollEl.scrollLeft = Math.max(0, (todayIdx - 1) * COL_W); }, 100);
            }
        }

        window.ownerCalNav = function(d) { calOffset += d; renderOwnerCal(); };

        window.showOwnerBooking = function(encoded) {
            var b = JSON.parse(decodeURIComponent(encoded));
            var statusMap = {'pending':'⏳ Pending','confirmed':'✅ Confirmed','checked_in':'🏨 Checked In','checked_out':'🚪 Checked Out'};
            var sourceMap = {'walk_in':'Walk In','agoda':'Agoda','booking':'Booking.com','traveloka':'Traveloka','airbnb':'Airbnb','tiket':'Tiket.com','phone':'Telepon','ota':'OTA','online':'Online'};
            var payMap = {'unpaid':'❌ Belum Bayar','partial':'⚠️ Sebagian','paid':'✅ Lunas'};
            var rp = function(n) { return 'Rp ' + Number(n||0).toLocaleString('id-ID'); };
            document.getElementById('ownerCalPopup').innerHTML =
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
                    '<div style="font-weight:800;font-size:14px;color:#1e293b;">📋 Detail Booking</div>' +
                    '<button onclick="closeOwnerCalPopup()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#64748b;">✕</button>' +
                '</div>' +
                '<div style="font-size:12px;line-height:2.2;color:#334155;">' +
                    '<div><strong>Kode:</strong> ' + (b.booking_code||'-') + '</div>' +
                    '<div><strong>Tamu:</strong> ' + (b.guest_name||'-') + '</div>' +
                    '<div><strong>Telepon:</strong> ' + (b.guest_phone||'-') + '</div>' +
                    '<div><strong>Check-in:</strong> ' + (b.check_in_date||'-') + '</div>' +
                    '<div><strong>Check-out:</strong> ' + (b.check_out_date||'-') + ' (' + (b.total_nights||'-') + ' malam)</div>' +
                    '<div><strong>Status:</strong> ' + (statusMap[b.status]||b.status) + '</div>' +
                    '<div><strong>Sumber:</strong> ' + (sourceMap[b.booking_source]||b.booking_source||'-') + '</div>' +
                    '<div><strong>Harga:</strong> ' + rp(b.final_price) + '</div>' +
                    '<div><strong>Pembayaran:</strong> ' + (payMap[b.payment_status]||b.payment_status||'-') + '</div>' +
                '</div>';
            document.getElementById('ownerCalPopup').style.display = 'block';
            document.getElementById('ownerCalOverlay').style.display = 'block';
        };

        window.closeOwnerCalPopup = function() {
            document.getElementById('ownerCalPopup').style.display = 'none';
            document.getElementById('ownerCalOverlay').style.display = 'none';
        };

        // Drag to scroll
        var scrollEl = document.getElementById('ownerCalScroll');
        if (scrollEl) {
            var isDown = false, startX, scrollLeft;
            scrollEl.addEventListener('mousedown', function(e) { isDown = true; startX = e.pageX - scrollEl.offsetLeft; scrollLeft = scrollEl.scrollLeft; });
            scrollEl.addEventListener('mouseleave', function() { isDown = false; });
            scrollEl.addEventListener('mouseup', function() { isDown = false; });
            scrollEl.addEventListener('mousemove', function(e) { if (!isDown) return; e.preventDefault(); var x = e.pageX - scrollEl.offsetLeft; scrollEl.scrollLeft = scrollLeft - (x - startX); });
        }

        renderOwnerCal();
    })();
    <?php endif; ?>
    </script>
</body>
</html>
