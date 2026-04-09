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
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #e8e4df;
            color: #2c2c2c;
            line-height: 1.55;
            padding: 24px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .invoice-page {
            max-width: 794px;
            margin: 0 auto;
            background: #fff;
            position: relative;
            overflow: hidden;
        }

        /* â”€â”€ Watermark â”€â”€ */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-family: 'Playfair Display', serif;
            font-size: 140px;
            font-weight: 800;
            opacity: 0.035;
            pointer-events: none;
            z-index: 1;
            white-space: nowrap;
            letter-spacing: 20px;
        }

        .wm-paid {
            color: #16a34a;
        }

        .wm-unpaid {
            color: #dc2626;
        }

        .wm-partial {
            color: #ca8a04;
        }

        /* â”€â”€ Top Border Pattern â”€â”€ */
        .top-border {
            height: 6px;
            background: repeating-linear-gradient(90deg, #1a1a2e 0px, #1a1a2e 30px, #c9a84c 30px, #c9a84c 32px);
        }

        /* â”€â”€ Header â”€â”€ */
        .header {
            padding: 28px 36px 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 2;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            width: 64px;
            height: 64px;
            border-radius: 10px;
            object-fit: cover;
            border: 2px solid #f0ece6;
        }

        .brand-text h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: #1a1a2e;
            letter-spacing: 0.5px;
        }

        .brand-text .sub {
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 3.5px;
            color: #c9a84c;
            margin-top: 1px;
        }

        .header-right {
            text-align: right;
        }

        .header-right .inv-label {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 800;
            color: #1a1a2e;
            letter-spacing: 6px;
            line-height: 1;
        }

        .header-right .inv-meta {
            font-size: 0.72rem;
            color: #888;
            margin-top: 6px;
            line-height: 1.5;
        }

        .header-right .inv-meta strong {
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
            font-size: 0.78rem;
        }

        /* â”€â”€ Gold Separator â”€â”€ */
        .sep-gold {
            height: 1px;
            margin: 0 36px;
            background: linear-gradient(90deg, #c9a84c, #e8dcc8 50%, #c9a84c);
        }

        /* â”€â”€ Status Bar â”€â”€ */
        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 36px;
            position: relative;
            z-index: 2;
        }

        .status-bar .hotel-contact {
            font-size: 0.68rem;
            color: #999;
            line-height: 1.6;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 18px;
            font-weight: 800;
            font-size: 0.7rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            border: 2.5px solid;
            border-radius: 4px;
        }

        .badge-paid {
            color: #16a34a;
            border-color: #16a34a;
            background: rgba(22, 163, 74, 0.04);
        }

        .badge-unpaid {
            color: #dc2626;
            border-color: #dc2626;
            background: rgba(220, 38, 38, 0.04);
        }

        .badge-partial {
            color: #ca8a04;
            border-color: #ca8a04;
            background: rgba(202, 138, 4, 0.04);
        }

        /* â”€â”€ Body â”€â”€ */
        .body {
            padding: 6px 36px 28px;
            position: relative;
            z-index: 2;
        }

        /* â”€â”€ Info Cards â”€â”€ */
        .info-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin: 14px 0 20px;
        }

        .info-card {
            border: 1px solid #f0ece6;
            border-radius: 8px;
            padding: 14px 16px;
        }

        .info-card .card-title {
            font-size: 0.58rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #c9a84c;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #f5f1eb;
        }

        .info-card .row {
            display: flex;
            justify-content: space-between;
            padding: 2.5px 0;
            font-size: 0.78rem;
        }

        .info-card .row .lbl {
            color: #999;
            font-weight: 400;
        }

        .info-card .row .val {
            color: #2c2c2c;
            font-weight: 600;
            text-align: right;
            max-width: 60%;
        }

        /* â”€â”€ Room Table â”€â”€ */
        .tbl-room {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0 6px;
            font-size: 0.78rem;
        }

        .tbl-room thead th {
            background: #1a1a2e;
            color: #e8dcc8;
            padding: 9px 14px;
            font-weight: 600;
            font-size: 0.64rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
        }

        .tbl-room thead th:last-child,
        .tbl-room thead th:nth-child(3),
        .tbl-room thead th:nth-child(4) {
            text-align: right;
        }

        .tbl-room tbody td {
            padding: 9px 14px;
            border-bottom: 1px solid #f5f1eb;
        }

        .tbl-room tbody td:last-child,
        .tbl-room tbody td:nth-child(3),
        .tbl-room tbody td:nth-child(4) {
            text-align: right;
        }

        .tbl-room tbody td:last-child {
            font-weight: 700;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
        }

        .tbl-room tbody td:nth-child(4) {
            font-family: 'Courier New', monospace;
        }

        .tbl-room tbody tr:nth-child(even) {
            background: #fdfcfa;
        }

        /* â”€â”€ Summary â”€â”€ */
        .summary-wrap {
            display: flex;
            justify-content: flex-end;
            margin-top: 4px;
        }

        .summary {
            width: 300px;
        }

        .sum-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 0.8rem;
            border-bottom: 1px solid #f5f1eb;
        }

        .sum-row .sl {
            color: #888;
        }

        .sum-row .sv {
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }

        .sum-row.disc .sv {
            color: #dc2626;
        }

        .sum-total {
            display: flex;
            justify-content: space-between;
            padding: 9px 0 5px;
            margin-top: 4px;
            border-top: 2px solid #1a1a2e;
            font-size: 0.95rem;
        }

        .sum-total .sl {
            font-weight: 800;
            color: #1a1a2e;
        }

        .sum-total .sv {
            font-weight: 800;
            font-family: 'Courier New', monospace;
            color: #1a1a2e;
        }

        .sum-paid {
            display: flex;
            justify-content: space-between;
            padding: 6px 10px;
            margin-top: 6px;
            background: #f0fdf4;
            border-radius: 5px;
            font-size: 0.82rem;
        }

        .sum-paid .sl {
            color: #16a34a;
            font-weight: 600;
        }

        .sum-paid .sv {
            color: #16a34a;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }

        .sum-due {
            display: flex;
            justify-content: space-between;
            padding: 6px 10px;
            margin-top: 4px;
            background: #fef2f2;
            border-radius: 5px;
            font-size: 0.88rem;
        }

        .sum-due .sl {
            color: #dc2626;
            font-weight: 700;
        }

        .sum-due .sv {
            color: #dc2626;
            font-weight: 800;
            font-family: 'Courier New', monospace;
        }

        /* â”€â”€ Payment History â”€â”€ */
        .pay-section {
            margin-top: 22px;
        }

        .sec-title {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #c9a84c;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #f0ece6;
        }

        .tbl-pay {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.74rem;
        }

        .tbl-pay th {
            background: #faf8f5;
            padding: 7px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.62rem;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #f0ece6;
        }

        .tbl-pay td {
            padding: 7px 12px;
            border-bottom: 1px solid #f8f5f0;
        }

        /* â”€â”€ Note â”€â”€ */
        .note-box {
            margin-top: 18px;
            padding: 10px 14px;
            background: #fefbf3;
            border-left: 3px solid #c9a84c;
            border-radius: 0 6px 6px 0;
            font-size: 0.78rem;
            color: #7c6a3a;
        }

        .note-box strong {
            color: #5c4e2e;
        }

        /* Bank Account */
        .bank-info {
            margin-top: 22px;
            padding: 14px 18px;
            background: #f8f9fb;
            border: 1px solid #e8e4df;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .bank-info .bank-icon {
            width: 40px;
            height: 40px;
            background: #1a1a2e;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #c9a84c;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .bank-info .bank-details {
            font-size: 0.78rem;
            line-height: 1.6;
        }

        .bank-info .bank-details .bank-label {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #c9a84c;
            margin-bottom: 2px;
        }

        .bank-info .bank-details .bank-number {
            font-family: 'Courier New', monospace;
            font-size: 1rem;
            font-weight: 700;
            color: #1a1a2e;
            letter-spacing: 1px;
        }

        .bank-info .bank-details .bank-holder {
            color: #666;
            font-size: 0.72rem;
        }

        /* â”€â”€ Footer â”€â”€ */
        .footer {
            margin-top: 28px;
            text-align: center;
            padding-top: 18px;
            border-top: 1px solid #f0ece6;
        }

        .footer .ty {
            font-family: 'Playfair Display', serif;
            font-size: 1rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 4px;
        }

        .footer .fc {
            font-size: 0.65rem;
            color: #aaa;
            line-height: 1.7;
        }

        .footer-bar {
            margin-top: 18px;
            height: 3px;
            background: linear-gradient(90deg, transparent 5%, #c9a84c 30%, #1a1a2e 50%, #c9a84c 70%, transparent 95%);
        }

        /* â”€â”€ Bottom Border â”€â”€ */
        .bottom-border {
            height: 6px;
            background: repeating-linear-gradient(90deg, #1a1a2e 0px, #1a1a2e 30px, #c9a84c 30px, #c9a84c 32px);
        }

        /* â”€â”€ Action Bar â”€â”€ */
        .actions {
            text-align: center;
            padding: 18px 0;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .btn {
            padding: 10px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-dark {
            background: #1a1a2e;
            color: #c9a84c;
        }

        .btn-dark:hover {
            background: #2d2d5e;
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(26, 26, 46, 0.25);
        }

        .btn-light {
            background: #f5f1eb;
            color: #1a1a2e;
            border: 1px solid #e0dbd3;
        }

        .btn-light:hover {
            background: #ebe6de;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .invoice-page {
                box-shadow: none;
            }

            .actions {
                display: none !important;
            }

            .top-border,
            .bottom-border {
                -webkit-print-color-adjust: exact;
            }
        }

        @page {
            margin: 8mm;
            size: A4;
        }
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
                    <div style="width:64px;height:64px;border-radius:10px;background:#1a1a2e;display:flex;align-items:center;justify-content:center;color:#c9a84c;font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:800;">N</div>
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
                        <br><span style="color:#c9a84c;">+ <?php echo count($allBookings) - 1; ?> room<?php echo count($allBookings) - 1 > 1 ? 's' : ''; ?></span>
                    <?php endif; ?>
                    <br><?php echo date('d F Y', strtotime($booking['created_at'])); ?>
                </div>
            </div>
        </div>

        <div class="sep-gold"></div>

        <!-- Status Bar -->
        <div class="status-bar">
            <div class="hotel-contact">
                Jl. Kasimo Jatikerep Karimunjawa Jepara Jawatengah 59455<br>
                Tel: 081222228590 · narayanahotelkarimunjawa@gmail.com
            </div>
            <span class="status-badge badge-<?php echo strtolower($overallStatus); ?>"><?php echo $overallStatus; ?></span>
        </div>

        <div style="height:1px;margin:0 36px;background:#f0ece6;"></div>

        <!-- Body -->
        <div class="body">
            <!-- Guest & Stay Info -->
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
                        <th>Type</th>
                        <th style="width:12%">Nights</th>
                        <th style="width:22%">Rate / Night</th>
                        <th style="width:22%">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allBookings as $bk): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($bk['room_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($bk['room_type']); ?></td>
                            <td><?php echo $bk['total_nights']; ?></td>
                            <td>Rp <?php echo number_format($bk['room_price'], 0, ',', '.'); ?></td>
                            <td>Rp <?php echo number_format($bk['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!empty($allExtras)): ?>
                        <?php foreach ($allExtras as $ex): ?>
                            <tr style="background:#fefbf3;">
                                <td colspan="2" style="padding-left:20px; color:#7c6a3a;">
                                    <?php if ($isMultiRoom): ?>
                                        <span style="color:#999;font-size:0.65rem;">Rm <?php echo htmlspecialchars($ex['room_number']); ?> ·</span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($ex['item_name']); ?>
                                </td>
                                <td style="color:#7c6a3a;"><?php echo $ex['quantity']; ?>x</td>
                                <td style="color:#7c6a3a;">Rp <?php echo number_format($ex['unit_price'], 0, ',', '.'); ?></td>
                                <td style="color:#7c6a3a;">Rp <?php echo number_format($ex['total_price'], 0, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                        <div class="sum-row" style="color:#6366f1;">
                            <span class="sl">Extras</span>
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
                <div class="bank-icon">🏦</div>
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
                    081222228590 · narayanahotelkarimunjawa@gmail.com · www.narayanakarimunjawa.com
                </div>
                <div class="footer-bar"></div>
            </div>
        </div>

        <div class="bottom-border"></div>
    </div>

    <!-- Actions -->
    <div class="actions">
        <button class="btn btn-dark" onclick="savePDF()">ðŸ“¥ Save as PDF</button>
        <button class="btn btn-light" onclick="window.print()">ðŸ–¨ï¸ Print</button>
    </div>

    <script>
        function savePDF() {
            document.title = 'Invoice_<?php echo $isMultiRoom ? preg_replace('/[^a-zA-Z0-9]/', '_', $booking['guest_name']) : $booking['booking_code']; ?>_<?php echo date('Ymd'); ?>';
            window.print();
        }
        <?php if ($isPdf): ?>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        <?php endif; ?>
    </script>
</body>

</html>