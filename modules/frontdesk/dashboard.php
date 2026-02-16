<?php
/**
 * FRONT DESK DASHBOARD - Occupancy & Analytics
 * Premium dashboard dengan Chart.js & glasmorphism
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================
// SECURITY & AUTHENTICATION
// ============================================
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Verify permission
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Front Desk Dashboard - Occupancy & Analytics';

// ============================================
// GET COMPREHENSIVE STATISTICS
// ============================================
try {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    // ==========================================
    // AUTO-CHECKOUT OVERDUE BOOKINGS
    // Bookings with check_out_date < today that are still 'checked_in'
    // ==========================================
    $overdueBookings = $db->fetchAll("
        SELECT b.id, b.room_id, b.booking_code, g.guest_name, r.room_number
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        AND DATE(b.check_out_date) < ?
    ", [$today]);

    if (!empty($overdueBookings)) {
        foreach ($overdueBookings as $overdue) {
            // Update booking status to checked_out
            $db->query("
                UPDATE bookings 
                SET status = 'checked_out',
                    actual_checkout_time = check_out_date,
                    updated_at = NOW()
                WHERE id = ?
            ", [$overdue['id']]);

            // Update room status to available
            $db->query("
                UPDATE rooms 
                SET status = 'available',
                    current_guest_id = NULL,
                    updated_at = NOW()
                WHERE id = ? AND status = 'occupied'
            ", [$overdue['room_id']]);
        }
        error_log("Auto-checkout: " . count($overdueBookings) . " overdue bookings checked out");
    }

    // ==========================================
    // AUTO-SYNC BOOKING PAYMENTS TO CASHBOOK
    // Sync any booking_payments not yet recorded in cash_book
    // ==========================================
    try {
        // Get master database name (handles hosting vs local)
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $businessId = $_SESSION['business_id'] ?? 1;

        // Get all booking payments from last 30 days
        $recentPayments = $db->fetchAll("
            SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                   b.booking_code, b.booking_source, b.final_price,
                   g.guest_name, r.room_number
            FROM booking_payments bp
            JOIN bookings b ON bp.booking_id = b.id
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE bp.payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY bp.id ASC
        ");

        $syncCount = 0;
        foreach ($recentPayments as $payment) {
            // Check if this payment is already in cash_book (by booking_code in description)
            $existing = $db->fetchOne("
                SELECT id FROM cash_book 
                WHERE description LIKE ? 
                AND ABS(amount - ?) < 1
                AND transaction_type = 'income'
                LIMIT 1
            ", ['%' . $payment['booking_code'] . '%', $payment['amount']]);

            if ($existing) continue; // Already synced

            // Calculate OTA fee if applicable
            $netAmount = (float)$payment['amount'];
            $otaFeePercent = 0;
            if (in_array(strtolower($payment['payment_method']), ['ota', 'agoda', 'booking'])) {
                $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ota_fee_other_ota'");
                $feeStmt->execute();
                $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
                if ($feeQuery) {
                    $otaFeePercent = (float)($feeQuery['setting_value'] ?? 0);
                    if ($otaFeePercent > 0) {
                        $netAmount = $payment['amount'] - ($payment['amount'] * $otaFeePercent / 100);
                    }
                }
            }

            // Find cash account
            $accountType = ($payment['payment_method'] === 'cash') ? 'cash' : 'bank';
            $accountStmt = $masterDb->prepare("
                SELECT id, account_name, current_balance FROM cash_accounts 
                WHERE business_id = ? AND account_type = ? AND is_active = 1 
                ORDER BY is_default_account DESC LIMIT 1
            ");
            $accountStmt->execute([$businessId, $accountType]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) continue; // No account found

            // Find division (Hotel/Frontdesk)
            $division = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%frontdesk%' ORDER BY id ASC LIMIT 1");
            if (!$division) $division = $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
            $divisionId = $division['id'] ?? 1;

            // Find category (Room Rental/Room Sell)
            $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id ASC LIMIT 1");
            if (!$category) $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
            $categoryId = $category['id'] ?? 1;

            // Build description
            $guestName = $payment['guest_name'] ?? 'Guest';
            $roomNum = $payment['room_number'] ?? '';
            $desc = "Pembayaran Reservasi - {$guestName}";
            if ($roomNum) $desc .= " (Room {$roomNum})";
            $desc .= " - {$payment['booking_code']}";

            // Check if fully paid
            $totalPaid = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id = ?", [$payment['booking_id']]);
            $desc .= ((float)$totalPaid['total'] >= (float)$payment['final_price']) ? ' [LUNAS]' : ' [CICILAN]';

            // Insert into cash_book (same pattern as add-booking-payment.php)
            $cashBookInsert = $db->getConnection()->prepare("
                INSERT INTO cash_book (
                    transaction_date, transaction_time, division_id, category_id,
                    description, transaction_type, amount, payment_method,
                    cash_account_id, created_by, created_at
                ) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())
            ");
            $cashBookInsert->execute([
                $payment['payment_date'], $payment['payment_date'],
                $divisionId, $categoryId, $desc, $netAmount,
                $payment['payment_method'], $account['id'],
                $currentUser['id']
            ]);

            $transactionId = $db->getConnection()->lastInsertId();

            // Insert into master cash_account_transactions
            $masterTransInsert = $masterDb->prepare("
                INSERT INTO cash_account_transactions (
                    cash_account_id, transaction_id, transaction_date,
                    description, amount, transaction_type,
                    reference_number, created_by, created_at
                ) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())
            ");
            $masterTransInsert->execute([
                $account['id'], $transactionId, $payment['payment_date'],
                $desc, $netAmount, $payment['booking_code'], $currentUser['id']
            ]);

            // Update cash account balance
            $updateBal = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $updateBal->execute([$netAmount, $account['id']]);

            $syncCount++;
        }

        if ($syncCount > 0) {
            error_log("Cashbook auto-sync: {$syncCount} payments synced to cashbook");
        }
    } catch (Exception $syncError) {
        error_log("Cashbook sync error: " . $syncError->getMessage());
    }

    // 1. Total In-House Guests (checked in, currently staying)
    // Count ALL checked_in bookings (after auto-checkout, only current ones remain)
    $inHouseResult = $db->fetchOne("
        SELECT COUNT(DISTINCT b.guest_id) as count 
        FROM bookings b
        WHERE b.status = 'checked_in'
    ");
    $stats['in_house'] = $inHouseResult['count'] ?? 0;

    // 2. Total Check-out Today
    $checkoutTodayResult = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE DATE(check_out_date) = ?
        AND status = 'checked_in'
    ", [$today]);
    $stats['checkout_today'] = $checkoutTodayResult['count'] ?? 0;

    // 3. Total Arrival Today
    $arrivalTodayResult = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE DATE(check_in_date) = ?
        AND status IN ('confirmed', 'checked_in')
    ", [$today]);
    $stats['arrival_today'] = $arrivalTodayResult['count'] ?? 0;

    // 4. Predicted Arrivals Tomorrow
    $arrivalTomorrowResult = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE DATE(check_in_date) = ?
        AND status = 'confirmed'
    ", [$tomorrow]);
    $stats['predicted_tomorrow'] = $arrivalTomorrowResult['count'] ?? 0;

    // 5. Occupancy Data (for Pie Chart)
    $totalRoomsResult = $db->fetchOne("SELECT COUNT(*) as count FROM rooms");
    $stats['total_rooms'] = max(1, $totalRoomsResult['count'] ?? 0);

    // Count occupied rooms - All checked_in bookings (overdue already auto-checked-out)
    $occupiedRoomsResult = $db->fetchOne("
        SELECT COUNT(DISTINCT b.room_id) as count 
        FROM bookings b
        WHERE b.status = 'checked_in'
    ");
    $stats['occupied_rooms'] = $occupiedRoomsResult['count'] ?? 0;
    $stats['available_rooms'] = max(0, $stats['total_rooms'] - $stats['occupied_rooms']);
    $stats['occupancy_rate'] = ($stats['total_rooms'] > 0) 
        ? round(($stats['occupied_rooms'] / $stats['total_rooms']) * 100, 1) 
        : 0;

    // 6. Today's Revenue
    $revenueResult = $db->fetchOne("
        SELECT COALESCE(SUM(bp.amount), 0) as total
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        WHERE DATE(bp.payment_date) = ?
    ", [$today]);
    $stats['revenue_today'] = $revenueResult['total'] ?? 0;

    // 7. Expected Revenue / Potential Revenue (Sum of final_price of all active guests)
    // Fix: Use final_price instead of total_price (which might be 0)
    // Fix: Count revenue from ALL active bookings, not just those checking out today
    $expectedResult = $db->fetchOne("
        SELECT COALESCE(SUM(b.final_price), 0) as total
        FROM bookings b
        WHERE b.status IN ('checked_in', 'confirmed')
        AND (
            (DATE(b.check_in_date) <= ? AND DATE(b.check_out_date) > ?)
            OR b.status = 'checked_in'
        )
    ", [$today, $today]);
    $stats['expected_revenue'] = $expectedResult['total'] ?? 0;
    
    // OTA Revenue Today - More robust check
    // If 'ota' is missed, maybe it's just in the allowed sources but payment_method is anything
    // But since we enforced 'ota' as payment_method in JS, it should work.
    // However, if manual payment inserted 'edc', it won't be counted here.
    // Let's broaden the search or just rely on 'ota'.
    $otaRevenueResult = $db->fetchOne("
        SELECT COALESCE(SUM(bp.amount), 0) as total
        FROM booking_payments bp
        WHERE DATE(bp.payment_date) = ?
        AND (LOWER(bp.payment_method) = 'ota' OR LOWER(bp.payment_method) = 'agoda' OR LOWER(bp.payment_method) = 'booking')
    ", [$today]);
    $stats['ota_revenue_today'] = $otaRevenueResult['total'] ?? 0;

    // 8. Guest Data for Today
    // Fix: Show ALL checked_in guests regardless of dates
    $guestsTodayResult = $db->fetchAll("
        SELECT 
            b.id,
            g.guest_name,
            b.room_id,
            r.room_number,
            b.check_in_date,
            b.check_out_date,
            b.status
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
        LIMIT 20
    ");
    $stats['guests_today'] = $guestsTodayResult;

    // 9. Checkout Guests Today - Detail list
    $checkoutGuestsResult = $db->fetchAll("
        SELECT 
            b.id,
            g.guest_name,
            g.phone,
            b.room_id,
            r.room_number,
            rt.type_name as room_type,
            b.check_in_date,
            b.check_out_date,
            b.final_price,
            b.status,
            COALESCE((SELECT SUM(amount) FROM booking_payments WHERE booking_id = b.id), 0) as paid_amount
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE DATE(b.check_out_date) = ?
        AND b.status = 'checked_in'
        ORDER BY r.room_number ASC
        LIMIT 10
    ", [$today]);
    $stats['checkout_guests'] = $checkoutGuestsResult;

} catch (Exception $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
    $stats = [
        'in_house' => 0, 'checkout_today' => 0, 'arrival_today' => 0,
        'predicted_tomorrow' => 0, 'total_rooms' => 0, 'occupied_rooms' => 0,
        'available_rooms' => 0, 'occupancy_rate' => 0, 'revenue_today' => 0,
        'expected_revenue' => 0, 'guests_today' => [], 'checkout_guests' => []
    ];
}

include '../../includes/header.php';
?>

<style>
/* ============================================
   PREMIUM 2028 VIBE - GLASSMORPHISM DESIGN
   ============================================ */

:root {
    --primary-gradient: linear-gradient(135deg, #6366f1, #8b5cf6);
    --success-gradient: linear-gradient(135deg, #10b981, #34d399);
    --warning-gradient: linear-gradient(135deg, #f59e0b, #fbbf24);
    --info-gradient: linear-gradient(135deg, #3b82f6, #60a5fa);
    --danger-gradient: linear-gradient(135deg, #ef4444, #f87171);
    
    --glass-bg: rgba(255, 255, 255, 0.75);
    --glass-border: rgba(255, 255, 255, 0.45);
    --glass-blur: 16px;
}

[data-theme="dark"] {
    --glass-bg: rgba(30, 41, 59, 0.75);
    --glass-border: rgba(71, 85, 105, 0.45);
}

.dashboard-container {
    max-width: 1800px;
    margin: 0 auto;
    padding: 1.25rem 1rem;
    background: linear-gradient(135deg, 
        rgba(99, 102, 241, 0.03) 0%, 
        rgba(139, 92, 246, 0.03) 50%,
        rgba(236, 72, 153, 0.03) 100%);
    position: relative;
    min-height: 100vh;
}

.dashboard-container::before {
    content: '';
    position: fixed;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: 
        radial-gradient(circle at 20% 30%, rgba(99, 102, 241, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(139, 92, 246, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(236, 72, 153, 0.05) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
    animation: gradientShift 15s ease-in-out infinite;
}

@keyframes gradientShift {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(5%, 5%) rotate(5deg); }
}

.dashboard-container > * {
    position: relative;
    z-index: 1;
}

/* ============================================
   PREMIUM HEADER
   ============================================ */

.dashboard-header {
    margin-bottom: 2rem;
    position: relative;
    z-index: 1;
}

.dashboard-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--glass-border), transparent);
    margin-bottom: 1rem;
}

.dashboard-header-content {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}

.dashboard-header h1 {
    font-size: 2.5rem;
    font-weight: 900;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    line-height: 1.2;
    filter: drop-shadow(0 2px 8px rgba(99, 102, 241, 0.2));
}

.dashboard-header h1::before {
    content: '📊';
    font-size: 2.5rem;
    -webkit-text-fill-color: initial;
    background: none;
    filter: drop-shadow(0 4px 12px rgba(99, 102, 241, 0.4));
}

.dashboard-header .subtitle {
    color: var(--text-secondary);
    margin-top: 0.5rem;
    font-size: 0.875rem;
    font-weight: 500;
    letter-spacing: 0.3px;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.btn-premium {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    padding: 0.65rem 1rem;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.75rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.25);
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}

.btn-premium::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.6s;
}

.btn-premium:hover::before {
    left: 100%;
}

.btn-premium:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
}

/* ============================================
   GLASSMORPHISM STAT CARDS - PREMIUM STYLE
   ============================================ */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 0.65rem;
    margin-bottom: 1rem;
}

.stat-card {
    background: var(--glass-bg);
    backdrop-filter: blur(var(--glass-blur));
    -webkit-backdrop-filter: blur(var(--glass-blur));
    border: 1.5px solid transparent;
    background-image: 
        linear-gradient(var(--glass-bg), var(--glass-bg)),
        linear-gradient(135deg, rgba(99, 102, 241, 0.3), rgba(139, 92, 246, 0.3));
    background-origin: border-box;
    background-clip: padding-box, border-box;
    border-radius: 12px;
    padding: 0.75rem;
    position: relative;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 
        0 2px 12px rgba(0, 0, 0, 0.06),
        0 4px 20px rgba(99, 102, 241, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.15);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, currentColor, transparent);
    opacity: 0.03;
    border-radius: 50%;
    pointer-events: none;
    transition: all 0.4s ease;
}

.stat-card:hover {
    transform: translateY(-6px) scale(1.02);
    box-shadow: 
        0 12px 32px rgba(0, 0, 0, 0.12),
        0 16px 48px rgba(99, 102, 241, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.3);
    background-image: 
        linear-gradient(var(--glass-bg), var(--glass-bg)),
        linear-gradient(135deg, rgba(99, 102, 241, 0.5), rgba(139, 92, 246, 0.5));
}

.stat-card:hover::before {
    top: 0;
    right: 0;
}

.stat-icon-wrapper {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    margin-bottom: 0.45rem;
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    position: relative;
    overflow: hidden;
}

.stat-icon-wrapper::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.2));
    pointer-events: none;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    line-height: 1;
    margin-bottom: 0.25rem;
    letter-spacing: -0.3px;
}

.stat-label {
    color: var(--text-secondary);
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    position: relative;
    line-height: 1.2;
}

/* ============================================
   PREMIUM CHART CARDS
   ============================================ */

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.chart-card {
    background: var(--glass-bg);
    backdrop-filter: blur(var(--glass-blur));
    -webkit-backdrop-filter: blur(var(--glass-blur));
    border: 1.5px solid transparent;
    background-image: 
        linear-gradient(var(--glass-bg), var(--glass-bg)),
        linear-gradient(135deg, rgba(99, 102, 241, 0.4), rgba(139, 92, 246, 0.4));
    background-origin: border-box;
    background-clip: padding-box, border-box;
    border-radius: 14px;
    padding: 1rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 
        0 4px 20px rgba(0, 0, 0, 0.08),
        0 6px 30px rgba(99, 102, 241, 0.12),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.chart-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.06), transparent 60%);
    pointer-events: none;
    animation: chartGlow 6s ease-in-out infinite;
}

@keyframes chartGlow {
    0%, 100% { opacity: 0.3; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(1.1); }
}

.chart-card:hover {
    border-color: rgba(99, 102, 241, 0.5);
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.12);
    transform: translateY(-4px);
}

.chart-card h3 {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 1rem 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    position: relative;
    z-index: 1;
}

.chart-card h3::before {
    font-size: 1.3rem;
    filter: drop-shadow(0 2px 8px rgba(99, 102, 241, 0.3));
}

.chart-container {
    position: relative;
    height: 280px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chart-container canvas {
    max-height: 100%;
    max-width: 100%;
}

/* ============================================
   PREMIUM REVENUE WIDGET
   ============================================ */

.revenue-widget {
    background: var(--glass-bg);
    backdrop-filter: blur(var(--glass-blur));
    -webkit-backdrop-filter: blur(var(--glass-blur));
    border: 2px solid transparent;
    background-image: 
        linear-gradient(var(--glass-bg), var(--glass-bg)),
        linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(59, 130, 246, 0.3));
    background-origin: border-box;
    background-clip: padding-box, border-box;
    border-radius: 20px;
    padding: 1.5rem;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    box-shadow: 
        0 8px 32px rgba(0, 0, 0, 0.1),
        0 12px 48px rgba(16, 185, 129, 0.12),
        inset 0 1px 0 rgba(255, 255, 255, 0.25);
}

.revenue-item {
    padding: 1rem;
    border-radius: 12px;
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    text-align: center;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.revenue-item::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, rgba(255, 255, 255, 0.1), transparent 70%);
    opacity: 0;
    transition: opacity 0.4s;
}

.revenue-item:hover::before {
    opacity: 1;
}

.revenue-item:hover {
    transform: translateY(-4px) scale(1.03);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.revenue-label {
    color: var(--text-secondary);
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 0.4rem;
}

.revenue-value {
    font-size: 1.35rem;
    font-weight: 950;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    margin-bottom: 0.2rem;
}

.revenue-actual {
    border-left: 3px solid #22c55e;
}

.revenue-expected {
    border-left: 3px solid #3b82f6;
}

/* ============================================
   PREMIUM REVENUE STATUS - LUXURY DESIGN
   ============================================ */

.revenue-premium-container {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 2px solid transparent;
    background-image: 
        linear-gradient(var(--glass-bg), var(--glass-bg)),
        linear-gradient(135deg, rgba(99, 102, 241, 0.4), rgba(236, 72, 153, 0.4));
    background-origin: border-box;
    background-clip: padding-box, border-box;
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    box-shadow: 
        0 10px 40px rgba(0, 0, 0, 0.12),
        0 20px 60px rgba(99, 102, 241, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.3);
    animation: fadeInUp 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.revenue-premium-container::before {
    content: '';
    position: absolute;
    top: -100%;
    left: -100%;
    width: 300%;
    height: 300%;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.15), transparent 40%);
    animation: revenueGlow 8s ease-in-out infinite;
    pointer-events: none;
}

@keyframes revenueGlow {
    0%, 100% { 
        transform: translate(0, 0) scale(1);
        opacity: 0.5;
    }
    50% { 
        transform: translate(10%, 10%) scale(1.2);
        opacity: 0.8;
    }
}

.revenue-header {
    text-align: center;
    margin-bottom: 2rem;
    position: relative;
    z-index: 1;
}

.revenue-title {
    font-size: 2rem;
    font-weight: 900;
    background: linear-gradient(135deg, #6366f1 0%, #ec4899 50%, #f59e0b 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0 0 0.5rem 0;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    filter: drop-shadow(0 4px 12px rgba(99, 102, 241, 0.3));
}

.revenue-icon {
    font-size: 2.5rem;
    -webkit-text-fill-color: initial;
    background: none;
    display: inline-block;
    animation: iconFloat 3s ease-in-out infinite;
}

@keyframes iconFloat {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-8px); }
}

.revenue-subtitle {
    color: var(--text-secondary);
    font-size: 0.9rem;
    font-weight: 500;
    margin: 0;
    letter-spacing: 0.3px;
}

.revenue-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    position: relative;
    z-index: 1;
}

.revenue-card {
    background: var(--glass-bg);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 2px solid var(--glass-border);
    border-radius: 20px;
    padding: 1.75rem;
    position: relative;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 
        0 4px 20px rgba(0, 0, 0, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.revenue-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, currentColor, transparent);
    opacity: 0.05;
    transition: all 0.6s ease;
    pointer-events: none;
}

.revenue-card:hover::before {
    top: 0;
    right: 0;
    opacity: 0.1;
}

.revenue-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 
        0 16px 48px rgba(0, 0, 0, 0.15),
        0 20px 60px currentColor,
        inset 0 1px 0 rgba(255, 255, 255, 0.4);
}

.revenue-card-actual {
    color: #22c55e;
    border-color: rgba(34, 197, 94, 0.3);
}

.revenue-card-actual:hover {
    border-color: rgba(34, 197, 94, 0.6);
    box-shadow: 
        0 16px 48px rgba(34, 197, 94, 0.25),
        inset 0 1px 0 rgba(255, 255, 255, 0.4);
}

.revenue-card-expected {
    color: #3b82f6;
    border-color: rgba(59, 130, 246, 0.3);
}

.revenue-card-expected:hover {
    border-color: rgba(59, 130, 246, 0.6);
    box-shadow: 
        0 16px 48px rgba(59, 130, 246, 0.25),
        inset 0 1px 0 rgba(255, 255, 255, 0.4);
}

.revenue-card-total {
    color: #f59e0b;
    border-color: rgba(245, 158, 11, 0.3);
}

.revenue-card-total:hover {
    border-color: rgba(245, 158, 11, 0.6);
    box-shadow: 
        0 16px 48px rgba(245, 158, 11, 0.25),
        inset 0 1px 0 rgba(255, 255, 255, 0.4);
}

.revenue-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.revenue-card-icon {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    background: var(--glass-bg);
    border: 2px solid currentColor;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 16px currentColor;
    transition: all 0.4s ease;
}

.revenue-card:hover .revenue-card-icon {
    transform: scale(1.1) rotate(5deg);
    box-shadow: 0 8px 24px currentColor;
}

.revenue-card-icon::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, transparent, currentColor);
    opacity: 0.1;
}

.revenue-card-badge {
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.revenue-badge-expected {
    background: rgba(59, 130, 246, 0.15);
    color: #3b82f6;
    border-color: rgba(59, 130, 246, 0.3);
}

.revenue-badge-total {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    border-color: rgba(245, 158, 11, 0.3);
}

.revenue-card-body {
    margin-bottom: 1.5rem;
}

.revenue-card-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-secondary);
    margin: 0 0 0.75rem 0;
}

.revenue-card-amount {
    font-size: 1.75rem;
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    margin: 0 0 0.5rem 0;
    letter-spacing: -0.5px;
    line-height: 1.2;
    word-break: break-all;
}

.revenue-card-desc {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin: 0;
    font-weight: 500;
}

.revenue-card-footer {
    margin-top: auto;
}

.revenue-progress-bar {
    width: 100%;
    height: 8px;
    background: rgba(0, 0, 0, 0.1);
    border-radius: 20px;
    overflow: hidden;
    position: relative;
}

[data-theme="dark"] .revenue-progress-bar {
    background: rgba(255, 255, 255, 0.1);
}

.revenue-progress-fill {
    height: 100%;
    border-radius: 20px;
    transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.revenue-progress-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
    animation: progressShine 2s infinite;
}

@keyframes progressShine {
    0% { left: -100%; }
    100% { left: 100%; }
}

.revenue-progress-actual {
    background: linear-gradient(90deg, #22c55e, #10b981);
    box-shadow: 0 2px 8px rgba(34, 197, 94, 0.4);
}

.revenue-progress-expected {
    background: linear-gradient(90deg, #3b82f6, #2563eb);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
}

.revenue-stats-mini {
    display: flex;
    gap: 1rem;
    justify-content: space-around;
}

.revenue-stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
}

.stat-mini-icon {
    font-size: 1.5rem;
}

.stat-mini-value {
    font-size: 1rem;
    font-weight: 800;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
}

/* ============================================
   GUESTS TABLE - PREMIUM STYLE
   ============================================ */

.guests-card {
    background: var(--glass-bg);
    backdrop-filter: blur(var(--glass-blur));
    -webkit-backdrop-filter: blur(var(--glass-blur));
    border: 2px solid transparent;
    background-image: 
        linear-gradient(var(--glass-bg), var(--glass-bg)),
        linear-gradient(135deg, rgba(99, 102, 241, 0.4), rgba(139, 92, 246, 0.4));
    background-origin: border-box;
    background-clip: padding-box, border-box;
    border-radius: 20px;
    padding: 1.75rem;
    overflow: hidden;
    box-shadow: 
        0 8px 32px rgba(0, 0, 0, 0.1),
        0 12px 48px rgba(99, 102, 241, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.25);
}

.guests-card h3 {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 1rem 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.guests-card h3::before {
    font-size: 1.3rem;
    filter: drop-shadow(0 2px 8px rgba(99, 102, 241, 0.3));
}
}

.guests-card h3 {
    font-size: 1rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 0.75rem 0;
}

.guests-table {
    width: 100%;
    border-collapse: collapse;
}

.guests-table thead tr {
    border-bottom: 2px solid var(--glass-border);
}

.guests-table th {
    padding: 0.75rem;
    text-align: left;
    font-weight: 700;
    color: var(--text-primary);
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.guests-table td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--glass-border);
    color: var(--text-secondary);
    font-size: 0.85rem;
}

.guests-table tbody tr {
    transition: all 0.3s ease;
}

.guests-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.08);
}

.room-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.75rem;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
}

.status-checked-in {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

/* ============================================
   EMPTY STATE
   ============================================ */

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--text-secondary);
}

/* ============================================
   ANIMATIONS
   ============================================ */

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.stat-card {
    animation: fadeInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.chart-card,
.guests-card {
    animation: fadeInUp 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }
.stat-card:nth-child(4) { animation-delay: 0.4s; }
.stat-card:nth-child(5) { animation-delay: 0.5s; }
.stat-card:nth-child(6) { animation-delay: 0.6s; }

/* ============================================
   RESPONSIVE DESIGN
   ============================================ */

@media (max-width: 1024px) {
    .dashboard-container {
        padding: 2rem 1.5rem;
    }

    .dashboard-header h1 {
        font-size: 2.5rem;
    }

    .charts-grid {
        grid-template-columns: 1fr;
    }

    /* Dashboard Grid - Stack on tablet */
    div[style*="grid-template-columns: 320px 1fr"] {
        grid-template-columns: 1fr !important;
    }

    .revenue-widget {
        grid-template-columns: 1fr;
    }

    .stat-value {
        font-size: 2.5rem;
    }

    .revenue-cards-grid {
        grid-template-columns: 1fr !important;
        gap: 0.75rem !important;
    }

    .revenue-title {
        font-size: 1.75rem;
    }

    .revenue-card-amount {
        font-size: 1.5rem;
    }
}

@media (max-width: 768px) {
    .dashboard-container {
        padding: 1.5rem 1rem;
    }

    .dashboard-header h1 {
        font-size: 2rem;
    }

    .dashboard-header-content {
        flex-direction: column;
        gap: 1.5rem;
    }

    .header-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
    }

    .chart-container {
        height: 280px;
    }

    .stat-card {
        padding: 1.75rem;
    }

    .stat-value {
        font-size: 2rem;
    }

    .guests-table {
        font-size: 0.85rem;
    }

    .guests-table th,
    .guests-table td {
        padding: 0.85rem;
    }

    .revenue-premium-container {
        padding: 1.5rem;
    }

    .revenue-title {
        font-size: 1.5rem;
    }

    .revenue-card {
        padding: 1.25rem;
    }

    .revenue-card-icon {
        width: 50px;
        height: 50px;
        font-size: 1.75rem;
    }

    .revenue-card-amount {
        font-size: 1.35rem;
    }
}

@media (max-width: 480px) {
    .dashboard-container {
        padding: 1rem;
    }

    .dashboard-header h1 {
        font-size: 1.75rem;
    }

    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .stat-card {
        padding: 1rem;
    }

    .stat-icon-wrapper {
        width: 40px;
        height: 40px;
        font-size: 1.25rem;
    }

    .stat-value {
        font-size: 1.5rem;
    }

    .revenue-widget {
        padding: 1rem;
    }

    .chart-card,
    .guests-card {
        padding: 1rem;
    }

    .chart-container {
        height: 200px;
    }

    .chart-card h3,
    .guests-card h3 {
        font-size: 0.9rem;
    }

    .revenue-premium-container {
        padding: 1rem;
        border-radius: 16px;
    }

    .revenue-header {
        margin-bottom: 1.25rem;
    }

    .revenue-title {
        font-size: 1.25rem;
    }

    .revenue-icon {
        font-size: 1.75rem;
    }

    .revenue-subtitle {
        font-size: 0.75rem;
    }

    .revenue-cards-grid {
        gap: 1rem;
    }

    .revenue-card {
        padding: 1rem;
        border-radius: 16px;
    }

    .revenue-card-icon {
        width: 45px;
        height: 45px;
        font-size: 1.5rem;
    }

    .revenue-card-badge {
        padding: 0.3rem 0.7rem;
        font-size: 0.6rem;
    }

    .revenue-card-amount {
        font-size: 1.15rem;
    }

    .revenue-card-desc {
        font-size: 0.7rem;
    }

    .stat-mini-icon {
        font-size: 1.25rem;
    }

    .stat-mini-value {
        font-size: 0.85rem;
    }
}
</style>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <div class="dashboard-header-content">
            <div>
                <h1>Front Desk Dashboard</h1>
                <p class="subtitle"><?php echo date('l, d F Y'); ?> • Real-time Occupancy & Analytics</p>
            </div>
            <div class="header-actions">
                <a href="reservasi.php" class="btn-premium">
                    <span>📋</span>
                    <span>List Reservasi</span>
                </a>
                <a href="in-house.php" class="btn-premium" style="background: linear-gradient(135deg, #f59e0b, #fbbf24);">
                    <span>🏨</span>
                    <span>Tamu In House</span>
                </a>
                <a href="calendar.php" class="btn-premium" style="background: linear-gradient(135deg, #10b981, #34d399);">
                    <span>📆</span>
                    <span>Calendar View</span>
                </a>
                <a href="settings.php" class="btn-premium" style="background: linear-gradient(135deg, #8b5cf6, #a855f7);">
                    <span>⚙️</span>
                    <span>Settings</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Compact Dashboard Grid - Clean Layout -->
    <div style="display: grid; grid-template-columns: 280px 1fr; gap: 0.75rem; margin-bottom: 0.75rem; align-items: stretch;">
        
        <!-- LEFT: Occupancy Pie Chart - Compact -->
        <div style="background: var(--glass-bg); backdrop-filter: blur(16px); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 12px; padding: 0.75rem; display: flex; flex-direction: column;">
            <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
                <span>🥧 Occupancy</span>
                <span style="font-size: 0.65rem; color: var(--text-secondary); background: var(--bg-tertiary); padding: 0.15rem 0.4rem; border-radius: 10px;"><?php echo $stats['total_rooms']; ?> Rooms</span>
            </div>
            
            <!-- Pie Chart Container -->
            <div style="position: relative; width: 160px; height: 160px; margin: 0 auto;">
                <canvas id="occupancyChart"></canvas>
                <!-- Center Percentage -->
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                    <div style="font-size: 1.75rem; font-weight: 900; background: linear-gradient(135deg, #6366f1, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; line-height: 1;">
                        <?php echo $stats['occupancy_rate']; ?>%
                    </div>
                    <div style="font-size: 0.6rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Occupied</div>
                </div>
            </div>
            
            <!-- Legend -->
            <div style="display: flex; justify-content: center; gap: 1rem; margin-top: 0.5rem; font-size: 0.7rem;">
                <span style="display: flex; align-items: center; gap: 0.25rem;">
                    <span style="width: 8px; height: 8px; background: #10b981; border-radius: 50%;"></span>
                    TERISI (<?php echo $stats['occupied_rooms']; ?>)
                </span>
                <span style="display: flex; align-items: center; gap: 0.25rem;">
                    <span style="width: 8px; height: 8px; background: #818cf8; border-radius: 50%;"></span>
                    KOSONG (<?php echo $stats['available_rooms']; ?>)
                </span>
            </div>
        </div>
        
        <!-- RIGHT: Revenue Overview - Compact Grid -->
        <div style="background: var(--glass-bg); backdrop-filter: blur(16px); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 12px; padding: 0.75rem;">
            <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                💎 Revenue Overview
                <span style="font-size: 0.6rem; color: var(--text-secondary); font-weight: 400;">Real-time tracking</span>
            </div>
            
            <!-- Revenue Cards - Compact 3 Column -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                
                <!-- Actual Revenue -->
                <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(52, 211, 153, 0.05)); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 10px; padding: 0.6rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem;">
                        <span style="font-size: 1.2rem;">💰</span>
                        <span style="font-size: 0.55rem; background: rgba(16, 185, 129, 0.15); color: #059669; padding: 0.15rem 0.35rem; border-radius: 8px; font-weight: 600;">TODAY</span>
                    </div>
                    <div style="font-size: 0.6rem; color: #059669; font-weight: 500; margin-bottom: 0.2rem;">Actual Revenue</div>
                    <div style="font-size: 1rem; font-weight: 800; color: #047857;">Rp <?php echo number_format($stats['revenue_today'], 0, ',', '.'); ?></div>
                    <div style="font-size: 0.55rem; color: var(--text-secondary); margin-top: 0.2rem;">Cash received today</div>
                </div>
                
                <!-- OTA Revenue -->
                <div style="background: linear-gradient(135deg, rgba(236, 72, 153, 0.08), rgba(219, 39, 119, 0.05)); border: 1px solid rgba(236, 72, 153, 0.2); border-radius: 10px; padding: 0.6rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem;">
                        <span style="font-size: 1.2rem;">🌐</span>
                        <span style="font-size: 0.55rem; background: rgba(236, 72, 153, 0.15); color: #db2777; padding: 0.15rem 0.35rem; border-radius: 8px; font-weight: 600;">OTA</span>
                    </div>
                    <div style="font-size: 0.6rem; color: #db2777; font-weight: 500; margin-bottom: 0.2rem;">OTA Income</div>
                    <div style="font-size: 1rem; font-weight: 800; color: #be185d;">Rp <?php echo number_format($stats['ota_revenue_today'], 0, ',', '.'); ?></div>
                    <div style="font-size: 0.55rem; color: var(--text-secondary); margin-top: 0.2rem;">Agoda, Booking, etc</div>
                </div>
                
                <!-- Expected Revenue -->
                <div style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.08), rgba(251, 191, 36, 0.05)); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 10px; padding: 0.6rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem;">
                        <span style="font-size: 1.2rem;">📊</span>
                        <span style="font-size: 0.55rem; background: rgba(245, 158, 11, 0.15); color: #d97706; padding: 0.15rem 0.35rem; border-radius: 8px; font-weight: 600;">PENDING</span>
                    </div>
                    <div style="font-size: 0.6rem; color: #d97706; font-weight: 500; margin-bottom: 0.2rem;">Expected Revenue</div>
                    <div style="font-size: 1rem; font-weight: 800; color: #b45309;">Rp <?php echo number_format($stats['expected_revenue'], 0, ',', '.'); ?></div>
                    <div style="font-size: 0.55rem; color: var(--text-secondary); margin-top: 0.2rem;">From active bookings</div>
                </div>
            </div>
        </div>
    </div>
    <!-- End Compact Dashboard Grid -->

    <!-- Checkout Guests Today - Detail Section -->
    <?php if (!empty($stats['checkout_guests'])): ?>
    <div class="checkout-section" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.08), rgba(251, 191, 36, 0.05)); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 14px; padding: 1rem; margin-bottom: 1rem;">
        <h3 style="font-size: 0.9rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; color: #b45309;">
            👋 Check-out Today
            <span style="font-size: 0.65rem; background: rgba(245, 158, 11, 0.15); color: #d97706; padding: 0.2rem 0.6rem; border-radius: 20px; font-weight: 600;">
                <?php echo count($stats['checkout_guests']); ?> Tamu
            </span>
        </h3>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                <thead>
                    <tr style="background: rgba(245, 158, 11, 0.1);">
                        <th style="padding: 0.6rem 0.75rem; text-align: left; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Tamu</th>
                        <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Room</th>
                        <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Tipe</th>
                        <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Check-out</th>
                        <th style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Total</th>
                        <th style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Dibayar</th>
                        <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['checkout_guests'] as $guest): 
                        $remaining = $guest['final_price'] - $guest['paid_amount'];
                        $isPaid = $remaining <= 0;
                    ?>
                    <tr style="border-bottom: 1px solid rgba(245, 158, 11, 0.1);">
                        <td style="padding: 0.6rem 0.75rem;">
                            <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($guest['guest_name']); ?></div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary);"><?php echo htmlspecialchars($guest['phone'] ?? '-'); ?></div>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; text-align: center;">
                            <span style="background: linear-gradient(135deg, #f59e0b, #fbbf24); color: white; padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem;">
                                <?php echo htmlspecialchars($guest['room_number']); ?>
                            </span>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; text-align: center; font-size: 0.75rem; color: var(--text-secondary);">
                            <?php echo htmlspecialchars($guest['room_type'] ?? '-'); ?>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; text-align: center; font-size: 0.75rem;">
                            <?php echo date('H:i', strtotime($guest['check_out_date'])); ?>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600;">
                            Rp <?php echo number_format($guest['final_price'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; text-align: right; color: #10b981; font-weight: 500;">
                            Rp <?php echo number_format($guest['paid_amount'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 0.6rem 0.75rem; text-align: center;">
                            <?php if ($isPaid): ?>
                                <span style="background: rgba(16, 185, 129, 0.1); color: #059669; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 600;">✅ LUNAS</span>
                            <?php else: ?>
                                <span style="background: rgba(239, 68, 68, 0.1); color: #dc2626; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 600;">⚠️ Rp <?php echo number_format($remaining, 0, ',', '.'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Widgets -->
    <div class="stats-grid">
        <!-- Total Rooms - NEW -->
        <div class="stat-card" style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(99, 102, 241, 0.1)); border: 2px solid rgba(139, 92, 246, 0.3);">
            <div class="stat-icon-wrapper">🏨</div>
            <div class="stat-value" style="color: #8b5cf6;">
                <?php echo $stats['total_rooms']; ?>
            </div>
            <div class="stat-label">Total Rooms</div>
        </div>

        <!-- In-House Guests - CLICKABLE -->
        <a href="in-house.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
            <div class="stat-icon-wrapper">👥</div>
            <div class="stat-value" style="color: #10b981;">
                <?php echo $stats['in_house']; ?>
            </div>
            <div class="stat-label">In-House Guests</div>
        </a>

        <!-- Check-out Today -->
        <div class="stat-card">
            <div class="stat-icon-wrapper">👋</div>
            <div class="stat-value" style="color: #f59e0b;">
                <?php echo $stats['checkout_today']; ?>
            </div>
            <div class="stat-label">Check-out Today</div>
        </div>

        <!-- Arrival Today -->
        <div class="stat-card">
            <div class="stat-icon-wrapper">➡️</div>
            <div class="stat-value" style="color: #3b82f6;">
                <?php echo $stats['arrival_today']; ?>
            </div>
            <div class="stat-label">Arrival Today</div>
        </div>

        <!-- Predicted Tomorrow -->
        <div class="stat-card">
            <div class="stat-icon-wrapper">🔮</div>
            <div class="stat-value" style="color: #8b5cf6;">
                <?php echo $stats['predicted_tomorrow']; ?>
            </div>
            <div class="stat-label">Predicted Tomorrow</div>
        </div>

        <!-- Occupancy Rate -->
        <div class="stat-card">
            <div class="stat-icon-wrapper">📈</div>
            <div class="stat-value" style="color: #ef4444;">
                <?php echo $stats['occupancy_rate']; ?>%
            </div>
            <div class="stat-label">Occupancy Rate</div>
        </div>

        <!-- Today's Revenue -->
        <div class="stat-card">
            <div class="stat-icon-wrapper">💰</div>
            <div class="stat-value" style="color: #22c55e; font-size: 1.5rem;">
                Rp <?php echo number_format($stats['revenue_today'], 0, ',', '.'); ?>
            </div>
            <div class="stat-label">Revenue Today</div>
        </div>
    </div>

    <!-- In-House Guests List -->
    <div class="guests-card" style="margin-top: 1.5rem;">
        <h3>🛎️ In-House Guests (<?php echo $stats['in_house']; ?>)</h3>
        <?php if (!empty($stats['guests_today'])): ?>
        <div style="overflow-x: auto;">
            <table class="guests-table">
                <thead>
                    <tr>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['guests_today'] as $guest): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($guest['guest_name']); ?></strong></td>
                        <td>
                            <span class="room-badge">
                                🚪 <?php echo htmlspecialchars($guest['room_number']); ?>
                            </span>
                        </td>
                        <td><?php echo date('d M, H:i', strtotime($guest['check_in_date'])); ?></td>
                        <td><?php echo date('d M, H:i', strtotime($guest['check_out_date'])); ?></td>
                        <td>
                            <span class="status-badge status-checked-in">
                                ✓ Checked In
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <p>🏖️ Tidak ada tamu yang sedang menginap hari ini</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Get chart color based on theme
function getChartColor() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark' || 
                   window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    return isDark ? '#e2e8f0' : '#1e293b';
}

// Premium Occupancy Pie Chart - Modern 2028 Design
const occupancyCtx = document.getElementById('occupancyChart');
if (occupancyCtx) {
    // Create modern gradients
    const ctx = occupancyCtx.getContext('2d');
    const gradient1 = ctx.createLinearGradient(0, 0, 0, 200);
    gradient1.addColorStop(0, 'rgba(16, 185, 129, 0.95)');
    gradient1.addColorStop(1, 'rgba(5, 150, 105, 0.95)');
    
    const gradient2 = ctx.createLinearGradient(0, 0, 0, 200);
    gradient2.addColorStop(0, 'rgba(129, 140, 248, 0.95)');
    gradient2.addColorStop(1, 'rgba(99, 102, 241, 0.95)');
    
    const occupancyChart = new Chart(occupancyCtx, {
        type: 'doughnut',
        data: {
            labels: ['TERISI', 'KOSONG'],
            datasets: [{
                data: [
                    <?php echo $stats['occupied_rooms']; ?>,
                    <?php echo $stats['available_rooms']; ?>
                ],
                backgroundColor: [gradient1, gradient2],
                borderColor: 'rgba(255, 255, 255, 0.9)',
                borderWidth: 2,
                hoverOffset: 8,
                hoverBorderWidth: 3,
                borderRadius: 6,
                spacing: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '70%',
            plugins: {
                legend: {
                    display: false  // Hide default legend, using custom HTML legend
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(15, 23, 42, 0.96)',
                    titleColor: '#ffffff',
                    bodyColor: '#e2e8f0',
                    borderColor: 'rgba(99, 102, 241, 0.6)',
                    borderWidth: 1,
                    padding: 10,
                    displayColors: true,
                    cornerRadius: 8,
                    titleFont: { size: 12, weight: '600' },
                    bodyFont: { size: 11, weight: '500' },
                    callbacks: {
                        label: function(context) {
                            let total = <?php echo $stats['total_rooms']; ?>;
                            let value = context.parsed;
                            let percentage = ((value / total) * 100).toFixed(1);
                            return ' ' + percentage + '% (' + value + ' rooms)';
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
