<?php
/**
 * Diagnostic: Check Imania booking + cash_book state
 * Delete after use!
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) { die('Login dulu'); }

$db = Database::getInstance();
echo "<pre style='font-family:monospace; font-size:13px; background:#111; color:#0f0; padding:20px;'>";

// 1. Find BK-20260304-8573 booking
echo "=== BOOKING: BK-20260304-8573 ===\n";
$bk = $db->fetchOne("SELECT id, booking_code, status, payment_status, final_price, paid_amount, check_in_date, check_out_date, booking_source FROM bookings WHERE booking_code = 'BK-20260304-8573'");
if ($bk) {
    foreach ($bk as $k => $v) echo str_pad($k, 20) . ": " . $v . "\n";
} else {
    echo "BOOKING NOT FOUND BY CODE\n";
    // Try partial match
    $bks = $db->fetchAll("SELECT id, booking_code, status, payment_status, final_price, paid_amount, check_in_date, check_out_date FROM bookings WHERE booking_code LIKE '%8573%' OR booking_code LIKE '%20260304%'");
    echo "Partial search results: " . count($bks) . "\n";
    foreach ($bks as $b) echo "  " . json_encode($b) . "\n";
}

// 2. Check booking_payments for this booking
echo "\n=== BOOKING_PAYMENTS for this booking ===\n";
if ($bk) {
    $payments = $db->fetchAll("SELECT * FROM booking_payments WHERE booking_id = ?", [$bk['id']]);
    echo "Count: " . count($payments) . "\n";
    foreach ($payments as $p) echo "  " . json_encode($p) . "\n";
} else {
    echo "Booking ID unknown, can't check\n";
}

// 3. Check cash_book entries matching BK-20260304-8573
echo "\n=== CASH_BOOK matching BK-20260304-8573 ===\n";
$cb = $db->fetchAll("SELECT id, transaction_date, transaction_type, amount, description, payment_method FROM cash_book WHERE description LIKE '%BK-20260304-8573%'");
echo "Count: " . count($cb) . "\n";
foreach ($cb as $c) echo "  " . json_encode($c) . "\n";

// 4. Show all checked_in bookings
echo "\n=== ALL CHECKED_IN bookings ===\n";
$checked = $db->fetchAll("SELECT id, booking_code, status, payment_status, paid_amount, final_price, check_in_date FROM bookings WHERE status = 'checked_in'");
echo "Count: " . count($checked) . "\n";
foreach ($checked as $c) echo "  " . json_encode($c) . "\n";

// 5. Full cash_book for today + yesterday
echo "\n=== CASH_BOOK income last 5 days ===\n";
$recent = $db->fetchAll("SELECT id, transaction_date, amount, description, payment_method FROM cash_book WHERE transaction_type='income' AND transaction_date >= DATE_SUB(NOW(), INTERVAL 5 DAY) ORDER BY transaction_date DESC");
echo "Count: " . count($recent) . "\n";
foreach ($recent as $r) echo "  " . json_encode($r) . "\n";

// 6. Room Revenue calculation trace
echo "\n=== ROOM REVENUE CALCULATION TRACE ===\n";
$roomBookings = $db->fetchAll("SELECT booking_code, paid_amount FROM bookings WHERE status = 'checked_in' OR (status = 'checked_out' AND DATE_FORMAT(check_out_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m'))");
echo "Qualifying bookings: " . count($roomBookings) . "\n";
$total = 0;
foreach ($roomBookings as $b) {
    $cbRes = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as t FROM cash_book WHERE transaction_type='income' AND description LIKE ?", ['%' . $b['booking_code'] . '%']);
    $cbAmt = $cbRes['t'] ?? 0;
    $used = ($cbAmt > 0) ? $cbAmt : ($b['paid_amount'] ?? 0);
    $total += $used;
    echo "  booking_code=" . ($b['booking_code'] ?: 'EMPTY') . " | paid_amount=" . $b['paid_amount'] . " | cashbook_match=" . $cbAmt . " | USED=" . $used . "\n";
}
echo "TOTAL Room Revenue = Rp " . number_format($total, 0, ',', '.') . "\n";

echo "\n=== BOOKING_PAYMENTS table exists? ===\n";
$tables = $db->fetchAll("SHOW TABLES LIKE 'booking_payments'");
echo count($tables) > 0 ? "YES, exists\n" : "NO - table missing!\n";

echo "</pre>";
