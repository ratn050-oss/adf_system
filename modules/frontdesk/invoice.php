<?php

/**
 * INVOICE / BILL for Booking
 * Print-friendly invoice page
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$bookingId = (int)($_GET['booking_id'] ?? 0);

if ($bookingId === 0) {
    die('Invalid Booking ID');
}

// Get primary booking details
$booking = $db->fetchOne("
    SELECT 
        b.id, b.booking_code, b.check_in_date, b.check_out_date,
        b.room_price, b.total_price, b.final_price, b.discount,
        b.status, b.payment_status, b.booking_source, b.total_nights,
        b.paid_amount, b.special_request, b.adults, b.children,
        b.created_at, b.group_id,
        g.guest_name, g.phone, g.email, g.id_card_number,
        r.room_number,
        rt.type_name as room_type
    FROM bookings b
    LEFT JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    WHERE b.id = ?
", [$bookingId]);

if (!$booking) {
    die('Booking not found');
}

// Find related bookings - prefer group_id, fallback to fuzzy matching for old bookings
$relatedBookings = [];
if (!empty($booking['group_id'])) {
    // Use group_id for reliable matching
    $relatedBookings = $db->fetchAll("
        SELECT 
            b.id, b.booking_code, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            b.created_at, b.group_id,
            g.guest_name, g.phone, g.email, g.id_card_number,
            r.room_number,
            rt.type_name as room_type
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.group_id = ?
        AND b.status != 'cancelled'
        ORDER BY r.room_number ASC
    ", [$booking['group_id']]);
} else {
    // Fallback: fuzzy matching for old bookings without group_id
    $relatedBookings = $db->fetchAll("
        SELECT 
            b.id, b.booking_code, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            b.created_at,
            g.guest_name, g.phone, g.email, g.id_card_number,
            r.room_number,
            rt.type_name as room_type
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE g.guest_name = ?
        AND b.check_in_date = ?
        AND b.check_out_date = ?
        AND b.booking_source = ?
        AND b.status != 'cancelled'
        AND ABS(TIMESTAMPDIFF(MINUTE, b.created_at, ?)) <= 5
        ORDER BY r.room_number ASC
    ", [
        $booking['guest_name'],
        $booking['check_in_date'],
        $booking['check_out_date'],
        $booking['booking_source'],
        $booking['created_at']
    ]);
}

// If no related found or only 1, use single booking
$allBookings = (!empty($relatedBookings) && count($relatedBookings) > 1) ? $relatedBookings : [$booking];
$isMultiRoom = count($allBookings) > 1;

// Collect all booking IDs for payment query
$allBookingIds = array_column($allBookings, 'id');
$placeholders = implode(',', array_fill(0, count($allBookingIds), '?'));

// Get payment history for all related bookings
$payments = $db->fetchAll("
    SELECT 
        bp.booking_id, bp.amount, bp.payment_method, bp.payment_date, bp.notes,
        bk.booking_code, r.room_number
    FROM booking_payments bp
    LEFT JOIN bookings bk ON bp.booking_id = bk.id
    LEFT JOIN rooms r ON bk.room_id = r.id
    WHERE bp.booking_id IN ($placeholders)
    ORDER BY bp.payment_date ASC
", $allBookingIds);

// Get extras for all related bookings
$allExtras = $db->fetchAll("
    SELECT be.*, r.room_number
    FROM booking_extras be
    LEFT JOIN bookings bk ON be.booking_id = bk.id
    LEFT JOIN rooms r ON bk.room_id = r.id
    WHERE be.booking_id IN ($placeholders)
    ORDER BY r.room_number ASC, be.created_at ASC
", $allBookingIds);

$combinedExtrasTotal = 0;
foreach ($allExtras as $ex) {
    $combinedExtrasTotal += $ex['total_price'];
}

// Calculate combined totals
$totalPaid = 0;
foreach ($payments as $payment) {
    $totalPaid += $payment['amount'];
}

// Fallback: sum paid_amount from all bookings if no payment records
if ($totalPaid == 0) {
    foreach ($allBookings as $bk) {
        $totalPaid += $bk['paid_amount'];
    }
}

$combinedFinalPrice = 0;
$combinedTotalPrice = 0;
$combinedDiscount = 0;
foreach ($allBookings as $bk) {
    $combinedFinalPrice += $bk['final_price'];
    $combinedTotalPrice += $bk['total_price'];
    $combinedDiscount += $bk['discount'];
}

$remaining = $combinedFinalPrice - $totalPaid;
$isPdf = isset($_GET['pdf']);

// Determine payment status
$overallStatus = 'UNPAID';
if ($totalPaid >= $combinedFinalPrice && $combinedFinalPrice > 0) $overallStatus = 'PAID';
elseif ($totalPaid > 0) $overallStatus = 'PARTIAL';

// Get business info
$businessId = $_SESSION['business_id'] ?? 1;
$business = $db->fetchOne("SELECT * FROM businesses WHERE id = ?", [$businessId]);

// Get invoice logo from PDF settings (Settings > Pengaturan Laporan PDF)
$invoiceLogoRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_logo_' . ACTIVE_BUSINESS_ID]
);
$logoUrl = $invoiceLogoRow['setting_value'] ?? null;
// Fallback to company logo if no invoice logo
if (empty($logoUrl)) {
    $logoUrl = getBusinessLogo();
}

// Get company settings from master DB
$masterDb = Database::getInstance();
$settingsQuery = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'";
$settingsResult = $masterDb->fetchAll($settingsQuery);
$companySettings = [];
foreach ($settingsResult as $setting) {
    if (strpos($setting['setting_key'], 'company_logo_') === 0) continue; // skip logo keys
    $key = str_replace('company_', '', $setting['setting_key']);
    $companySettings[$key] = $setting['setting_value'];
}

// Fallback for company name
if (empty($companySettings['name'])) {
    $companySettings['name'] = $business['business_name'] ?? 'Narayana Hotel';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $isMultiRoom ? $booking['guest_name'] . ' (' . count($allBookings) . ' rooms)' : $booking['booking_code']; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #f0f0f0; color: #333; line-height: 1.5; padding: 20px;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .invoice-page {
            max-width: 794px; margin: 0 auto; background: #fff;
            position: relative; overflow: hidden; border: 1px solid #e0e0e0;
        }
        .watermark {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-35deg);
            font-size: 100px; font-weight: 700; opacity: 0.04;
            pointer-events: none; z-index: 1; white-space: nowrap; letter-spacing: 12px;
        }
        .wm-paid { color: #16a34a; } .wm-unpaid { color: #dc2626; } .wm-partial { color: #ca8a04; }
        .top-border { height: 4px; background: #2c3e50; }
        .header {
            padding: 24px 32px 16px; display: flex; justify-content: space-between;
            align-items: flex-start; position: relative; z-index: 2;
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-logo { width: 52px; height: 52px; border-radius: 6px; object-fit: cover; border: 1px solid #e5e5e5; }
        .brand-text h1 { font-size: 1.2rem; font-weight: 700; color: #2c3e50; }
        .brand-text .sub { font-size: 0.65rem; font-weight: 500; text-transform: uppercase; letter-spacing: 2px; color: #7f8c8d; margin-top: 1px; }
        .header-right { text-align: right; }
        .header-right .inv-label { font-size: 1.5rem; font-weight: 700; color: #2c3e50; letter-spacing: 4px; }
        .header-right .inv-meta { font-size: 0.72rem; color: #999; margin-top: 4px; line-height: 1.5; }
        .header-right .inv-meta strong { color: #333; font-family: 'Courier New', monospace; font-size: 0.75rem; }
        .sep-line { height: 1px; margin: 0 32px; background: #e5e5e5; }
        .status-bar { display: flex; justify-content: space-between; align-items: center; padding: 10px 32px; position: relative; z-index: 2; }
        .status-bar .hotel-contact { font-size: 0.68rem; color: #999; line-height: 1.5; }
        .status-badge { display: inline-block; padding: 4px 14px; font-weight: 700; font-size: 0.65rem; letter-spacing: 2px; text-transform: uppercase; border: 1.5px solid; border-radius: 3px; }
        .badge-paid { color: #16a34a; border-color: #16a34a; background: #f0fdf4; }
        .badge-unpaid { color: #dc2626; border-color: #dc2626; background: #fef2f2; }
        .badge-partial { color: #ca8a04; border-color: #ca8a04; background: #fefce8; }
        .body { padding: 4px 32px 24px; position: relative; z-index: 2; }
        .info-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 12px 0 18px; }
        .info-card { border: 1px solid #e5e5e5; border-radius: 6px; padding: 12px 14px; }
        .info-card .card-title { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #7f8c8d; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid #f0f0f0; }
        .info-card .row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 0.78rem; }
        .info-card .row .lbl { color: #999; }
        .info-card .row .val { color: #333; font-weight: 600; text-align: right; max-width: 60%; }
        .tbl-room { width: 100%; border-collapse: collapse; margin: 14px 0 6px; font-size: 0.78rem; }
        .tbl-room thead th { background: #f7f7f7; color: #555; padding: 8px 12px; font-weight: 600; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; border-top: 2px solid #2c3e50; border-bottom: 1px solid #ddd; }
        .tbl-room thead th:last-child, .tbl-room thead th:nth-child(3), .tbl-room thead th:nth-child(4) { text-align: right; }
        .tbl-room tbody td { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; }
        .tbl-room tbody td:last-child, .tbl-room tbody td:nth-child(3), .tbl-room tbody td:nth-child(4) { text-align: right; }
        .tbl-room tbody td:last-child { font-weight: 600; font-family: 'Courier New', monospace; font-size: 0.8rem; }
        .tbl-room tbody td:nth-child(4) { font-family: 'Courier New', monospace; }
        .summary-wrap { display: flex; justify-content: flex-end; margin-top: 4px; }
        .summary { width: 280px; }
        .sum-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.8rem; border-bottom: 1px solid #f0f0f0; }
        .sum-row .sl { color: #777; }
        .sum-row .sv { font-weight: 600; font-family: 'Courier New', monospace; }
        .sum-row.disc .sv { color: #dc2626; }
        .sum-total { display: flex; justify-content: space-between; padding: 8px 0 4px; margin-top: 4px; border-top: 2px solid #2c3e50; font-size: 0.95rem; }
        .sum-total .sl { font-weight: 700; color: #2c3e50; }
        .sum-total .sv { font-weight: 700; font-family: 'Courier New', monospace; color: #2c3e50; }
        .sum-paid { display: flex; justify-content: space-between; padding: 5px 8px; margin-top: 6px; background: #f0fdf4; border-radius: 4px; font-size: 0.8rem; }
        .sum-paid .sl { color: #16a34a; font-weight: 600; }
        .sum-paid .sv { color: #16a34a; font-weight: 700; font-family: 'Courier New', monospace; }
        .sum-due { display: flex; justify-content: space-between; padding: 5px 8px; margin-top: 3px; background: #fef2f2; border-radius: 4px; font-size: 0.85rem; }
        .sum-due .sl { color: #dc2626; font-weight: 700; }
        .sum-due .sv { color: #dc2626; font-weight: 700; font-family: 'Courier New', monospace; }
        .pay-section { margin-top: 18px; }
        .sec-title { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #7f8c8d; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #e5e5e5; }
        .tbl-pay { width: 100%; border-collapse: collapse; font-size: 0.74rem; }
        .tbl-pay th { background: #f7f7f7; padding: 6px 10px; text-align: left; font-weight: 600; font-size: 0.62rem; color: #999; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e5e5; }
        .tbl-pay td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; }
        .note-box { margin-top: 14px; padding: 8px 12px; background: #f9f9f9; border-left: 3px solid #7f8c8d; border-radius: 0 4px 4px 0; font-size: 0.78rem; color: #555; }
        .note-box strong { color: #333; }
        .bank-info { margin-top: 18px; padding: 12px 16px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 6px; display: flex; align-items: center; gap: 12px; }
        .bank-info .bank-icon { width: 36px; height: 36px; background: #2c3e50; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1rem; flex-shrink: 0; }
        .bank-info .bank-details { font-size: 0.78rem; line-height: 1.5; }
        .bank-info .bank-details .bank-label { font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #7f8c8d; margin-bottom: 1px; }
        .bank-info .bank-details .bank-number { font-family: 'Courier New', monospace; font-size: 0.95rem; font-weight: 700; color: #2c3e50; letter-spacing: 1px; }
        .bank-info .bank-details .bank-holder { color: #777; font-size: 0.72rem; }
        .footer { margin-top: 24px; text-align: center; padding-top: 14px; border-top: 1px solid #e5e5e5; }
        .footer .ty { font-size: 0.85rem; font-weight: 600; color: #2c3e50; margin-bottom: 3px; }
        .footer .fc { font-size: 0.62rem; color: #aaa; line-height: 1.6; }
        .bottom-border { height: 4px; background: #2c3e50; }
        .actions { text-align: center; padding: 16px 0; display: flex; justify-content: center; gap: 10px; }
        .btn { padding: 9px 24px; border: none; border-radius: 6px; font-weight: 600; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .btn-dark { background: #2c3e50; color: #fff; }
        .btn-dark:hover { background: #34495e; }
        .btn-light { background: #f5f5f5; color: #333; border: 1px solid #ddd; }
        .btn-light:hover { background: #eee; }
        @media print {
            body { padding: 0; background: #fff; }
            .invoice-page { box-shadow: none; border: none; }
            .actions { display: none !important; }
        }
        @page { margin: 8mm; size: A4; }
    </style>
</head>
<body>
    <div class="invoice-page" id="invoiceContent">
        <div class="watermark wm-<?php echo strtolower($overallStatus); ?>"><?php echo $overallStatus; ?></div>
        <div class="top-border"></div>

        <!-- Header -->
        <div class="header">
            <div class="brand">
                <?php if ($logoUrl): ?>
                    <img class="brand-logo" src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($companySettings['name']); ?>">
                <?php else: ?>
                    <div style="width:52px;height:52px;border-radius:6px;background:#2c3e50;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;">N</div>
                <?php endif; ?>
                <div class="brand-text">
                    <h1><?php echo htmlspecialchars($companySettings['name']); ?></h1>
                    <?php if (!empty($companySettings['tagline'])): ?>
                        <div class="sub"><?php echo htmlspecialchars($companySettings['tagline']); ?></div>
                    <?php else: ?>
                        <div class="sub">Karimunjawa Island</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="header-right">
                <div class="inv-label">INVOICE</div>
                <div class="inv-meta">
                    No. <strong><?php echo htmlspecialchars($allBookings[0]['booking_code']); ?></strong>
                    <?php if ($isMultiRoom && count($allBookings) > 1): ?>
                        <br><span style="color:#7f8c8d;">+ <?php echo count($allBookings) - 1; ?> room<?php echo count($allBookings) - 1 > 1 ? 's' : ''; ?></span>
                    <?php endif; ?>
                    <br><?php echo date('d F Y', strtotime($booking['created_at'])); ?>
                </div>
            </div>
        </div>

        <div class="sep-line"></div>

        <!-- Status Bar -->
        <div class="status-bar">
            <div class="hotel-contact">
                Jl. Kasimo Jatikerep Karimunjawa Jepara Jawatengah 59455<br>
                Tel: 081222228590 &middot; narayanahotelkarimunjawa@gmail.com
            </div>
            <span class="status-badge badge-<?php echo strtolower($overallStatus); ?>"><?php echo $overallStatus; ?></span>
        </div>

        <div class="sep-line"></div>

        <!-- Body -->
        <div class="body">
            <div class="info-cards">
                <div class="info-card">
                    <div class="card-title">Bill To</div>
                    <div class="row"><span class="lbl">Name</span><span class="val"><?php echo htmlspecialchars($booking['guest_name']); ?></span></div>
                    <div class="row"><span class="lbl">Phone</span><span class="val"><?php echo htmlspecialchars($booking['phone'] ?? '-'); ?></span></div>
                    <div class="row"><span class="lbl">Email</span><span class="val"><?php echo htmlspecialchars($booking['email'] ?? '-'); ?></span></div>
                    <?php if (!empty($booking['id_card_number'])): ?>
                        <div class="row"><span class="lbl">ID No.</span><span class="val"><?php echo htmlspecialchars($booking['id_card_number']); ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="info-card">
                    <div class="card-title">Reservation</div>
                    <div class="row"><span class="lbl">Check-in</span><span class="val"><?php echo date('D, d M Y', strtotime($booking['check_in_date'])); ?></span></div>
                    <div class="row"><span class="lbl">Check-out</span><span class="val"><?php echo date('D, d M Y', strtotime($booking['check_out_date'])); ?></span></div>
                    <div class="row"><span class="lbl">Duration</span><span class="val"><?php echo $booking['total_nights']; ?> Night<?php echo $booking['total_nights'] > 1 ? 's' : ''; ?></span></div>
                    <div class="row"><span class="lbl">Guests</span><span class="val"><?php echo ($booking['adults'] ?? 1); ?> Adult<?php echo ($booking['adults'] ?? 1) > 1 ? 's' : ''; ?><?php echo ($booking['children'] ?? 0) > 0 ? ', ' . $booking['children'] . ' Child' . ($booking['children'] > 1 ? 'ren' : '') : ''; ?></span></div>
                    <div class="row"><span class="lbl">Source</span><span class="val"><?php echo ucwords(str_replace('_', ' ', $booking['booking_source'] ?? 'Walk-in')); ?></span></div>
                </div>
            </div>

            <!-- Room & Price Table -->
            <table class="tbl-room">
                <thead>
                    <tr>
                        <th style="width:15%">Room</th>
                        <th>Description</th>
                        <th style="width:10%">Qty</th>
                        <th style="width:22%">Rate</th>
                        <th style="width:22%">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allBookings as $bk): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($bk['room_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($bk['room_type']); ?> &mdash; <?php echo $bk['total_nights']; ?> night<?php echo $bk['total_nights'] > 1 ? 's' : ''; ?></td>
                            <td><?php echo $bk['total_nights']; ?></td>
                            <td>Rp <?php echo number_format($bk['room_price'], 0, ',', '.'); ?></td>
                            <td>Rp <?php echo number_format($bk['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($allExtras as $ex): ?>
                        <tr>
                            <td><?php echo $isMultiRoom ? htmlspecialchars($ex['room_number']) : ''; ?></td>
                            <td><?php echo htmlspecialchars($ex['item_name']); ?></td>
                            <td><?php echo $ex['quantity']; ?></td>
                            <td>Rp <?php echo number_format($ex['unit_price'], 0, ',', '.'); ?></td>
                            <td>Rp <?php echo number_format($ex['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Summary -->
            <div class="summary-wrap">
                <div class="summary">
                    <div class="sum-row">
                        <span class="sl">Subtotal</span>
                        <span class="sv">Rp <?php echo number_format($combinedTotalPrice, 0, ',', '.'); ?></span>
                    </div>
                    <?php if ($combinedDiscount > 0): ?>
                        <div class="sum-row disc">
                            <span class="sl">Discount</span>
                            <span class="sv">- Rp <?php echo number_format($combinedDiscount, 0, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($combinedExtrasTotal > 0): ?>
                        <?php
                        // Build label from actual extra item names
                        $extraNames = array_unique(array_column($allExtras, 'item_name'));
                        $extrasLabel = implode(', ', $extraNames);
                        ?>
                        <div class="sum-row">
                            <span class="sl"><?php echo htmlspecialchars($extrasLabel); ?></span>
                            <span class="sv">+ Rp <?php echo number_format($combinedExtrasTotal, 0, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="sum-total">
                        <span class="sl">TOTAL</span>
                        <span class="sv">Rp <?php echo number_format($combinedFinalPrice, 0, ',', '.'); ?></span>
                    </div>
                    <div class="sum-paid">
                        <span class="sl">Amount Paid</span>
                        <span class="sv">Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></span>
                    </div>
                    <?php if ($remaining > 0): ?>
                        <div class="sum-due">
                            <span class="sl">Balance Due</span>
                            <span class="sv">Rp <?php echo number_format($remaining, 0, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment History -->
            <?php if (!empty($payments)): ?>
                <div class="pay-section">
                    <div class="sec-title">Payment History</div>
                    <table class="tbl-pay">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <?php if ($isMultiRoom): ?><th>Room</th><?php endif; ?>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?php echo date('d M Y, H:i', strtotime($p['payment_date'])); ?></td>
                                    <?php if ($isMultiRoom): ?><td><?php echo htmlspecialchars($p['room_number'] ?? '-'); ?></td><?php endif; ?>
                                    <td><?php echo strtoupper($p['payment_method']); ?></td>
                                    <td style="font-weight:600;">Rp <?php echo number_format($p['amount'], 0, ',', '.'); ?></td>
                                    <td style="color:#999;"><?php echo htmlspecialchars($p['notes'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Special Request -->
            <?php if (!empty($booking['special_request'])): ?>
                <div class="note-box">
                    <strong>Guest Note:</strong> <?php echo nl2br(htmlspecialchars($booking['special_request'])); ?>
                </div>
            <?php endif; ?>

            <!-- Bank Account -->
            <div class="bank-info">
                <div class="bank-icon">&#9889;</div>
                <div class="bank-details">
                    <div class="bank-label">Transfer Payment</div>
                    <div class="bank-number">1926663992</div>
                    <div class="bank-holder">a.n. Narayana Hotel Karimunjawa &mdash; BNI Jepara</div>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <div class="ty">Thank you for staying with us</div>
                <div class="fc">
                    <?php echo htmlspecialchars($companySettings['name']); ?><br>
                    Jl. Kasimo Jatikerep Karimunjawa Jepara Jawatengah 59455<br>
                    081222228590 &middot; narayanahotelkarimunjawa@gmail.com &middot; www.narayanakarimunjawa.com
                </div>
            </div>
        </div>

        <div class="bottom-border"></div>
    </div>

    <!-- Actions -->
    <div class="actions">
        <button class="btn btn-dark" onclick="savePDF()">Save as PDF</button>
        <button class="btn btn-light" onclick="window.print()">Print</button>
    </div>

    <script>
        function savePDF() {
            document.title = 'Invoice_<?php echo $isMultiRoom ? preg_replace('/[^a-zA-Z0-9]/', '_', $booking['guest_name']) : $booking['booking_code']; ?>_<?php echo date('Ymd'); ?>';
            window.print();
        }
        <?php if ($isPdf): ?>
            window.onload = function() { setTimeout(function() { window.print(); }, 500); };
        <?php endif; ?>
    </script>
</body>
</html>
