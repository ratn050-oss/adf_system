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

// Get company settings from master DB
$masterDb = Database::getInstance();
$settingsQuery = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'";
$settingsResult = $masterDb->fetchAll($settingsQuery);
$companySettings = [];
foreach ($settingsResult as $setting) {
    if (strpos($setting['setting_key'], 'company_logo_') === 0) {
        $bizId = str_replace('company_logo_', '', $setting['setting_key']);
        if ($bizId === ($_SESSION['selected_business_id'] ?? '')) {
            $companySettings['logo'] = $setting['setting_value'];
            $companySettings['logo_is_url'] = (strpos($setting['setting_value'], 'http') === 0);
        }
    } else {
        $key = str_replace('company_', '', $setting['setting_key']);
        $companySettings[$key] = $setting['setting_value'];
    }
}

// Fallback for company name
if (empty($companySettings['name'])) {
    $companySettings['name'] = $business['business_name'] ?? 'Hotel';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $isMultiRoom ? $booking['guest_name'] . ' (' . count($allBookings) . ' rooms)' : $booking['booking_code']; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            line-height: 1.6;
            padding: 20px;
        }
        
        .invoice-wrapper {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 2px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }
        
        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-35deg);
            font-size: 120px;
            font-weight: 800;
            letter-spacing: 15px;
            opacity: 0.04;
            pointer-events: none;
            z-index: 1;
            white-space: nowrap;
            text-transform: uppercase;
        }
        .watermark-paid { color: #059669; }
        .watermark-unpaid { color: #dc2626; }
        .watermark-partial { color: #d97706; }
        
        /* Gold accent top bar */
        .top-accent {
            height: 5px;
            background: linear-gradient(90deg, #1a1a2e 0%, #2d2d5e 35%, #c9a84c 50%, #2d2d5e 65%, #1a1a2e 100%);
        }
        
        /* Header */
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 30px 40px 20px;
            position: relative;
            z-index: 2;
        }
        
        .hotel-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .hotel-brand img {
            width: 70px;
            height: 70px;
            object-fit: contain;
            border-radius: 8px;
        }
        
        .hotel-brand .brand-text h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1a1a2e;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }
        
        .hotel-brand .brand-text .tagline {
            font-size: 0.7rem;
            color: #c9a84c;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-top: 2px;
        }
        
        .hotel-contact {
            text-align: right;
            font-size: 0.72rem;
            color: #64748b;
            line-height: 1.7;
        }
        
        /* Divider line */
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, #e2e8f0 20%, #e2e8f0 80%, transparent);
            margin: 0 40px;
        }
        
        .divider-gold {
            height: 2px;
            background: linear-gradient(90deg, transparent, #c9a84c 20%, #c9a84c 80%, transparent);
            margin: 0 40px;
        }
        
        /* Invoice title section */
        .invoice-title-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 40px;
            position: relative;
            z-index: 2;
        }
        
        .invoice-title-left h2 {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1a1a2e;
            letter-spacing: 4px;
            text-transform: uppercase;
        }
        
        .invoice-title-left .invoice-number {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 2px;
        }
        
        .invoice-title-left .invoice-number strong {
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
        }
        
        .status-stamp {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 800;
            font-size: 0.9rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 3px solid;
        }
        
        .stamp-paid { color: #059669; border-color: #059669; background: rgba(5,150,105,0.06); }
        .stamp-unpaid { color: #dc2626; border-color: #dc2626; background: rgba(220,38,38,0.06); }
        .stamp-partial { color: #d97706; border-color: #d97706; background: rgba(217,119,6,0.06); }
        
        /* Content body */
        .invoice-body {
            padding: 0 40px 30px;
            position: relative;
            z-index: 2;
        }
        
        /* Info grid sections */
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin: 20px 0;
        }
        
        .info-block {
            padding: 16px 20px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 3px solid #c9a84c;
        }
        
        .info-block-title {
            font-size: 0.65rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 10px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 0.82rem;
        }
        
        .info-row .label { color: #64748b; }
        .info-row .value { font-weight: 600; color: #1a1a2e; text-align: right; }
        
        /* Room table */
        .room-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 0.82rem;
        }
        
        .room-table thead th {
            background: #1a1a2e;
            color: #fff;
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        .room-table thead th:last-child { text-align: right; }
        
        .room-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .room-table tbody td:last-child {
            text-align: right;
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }
        
        .room-table tbody tr:nth-child(even) { background: #fafbfc; }
        
        /* Summary table */
        .summary-table {
            width: 320px;
            margin-left: auto;
            margin-top: 10px;
            font-size: 0.85rem;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .summary-row .s-label { color: #64748b; }
        .summary-row .s-value { font-weight: 600; font-family: 'Courier New', monospace; }
        
        .summary-row.discount .s-value { color: #dc2626; }
        
        .summary-total {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            margin-top: 5px;
            border-top: 2px solid #1a1a2e;
            font-size: 1rem;
        }
        
        .summary-total .s-label { font-weight: 800; color: #1a1a2e; }
        .summary-total .s-value { font-weight: 800; color: #1a1a2e; font-family: 'Courier New', monospace; }
        
        .summary-paid {
            display: flex;
            justify-content: space-between;
            padding: 7px 12px;
            margin-top: 8px;
            background: #f0fdf4;
            border-radius: 6px;
        }
        
        .summary-paid .s-label { color: #059669; font-weight: 600; }
        .summary-paid .s-value { color: #059669; font-weight: 700; font-family: 'Courier New', monospace; }
        
        .summary-remaining {
            display: flex;
            justify-content: space-between;
            padding: 7px 12px;
            margin-top: 4px;
            background: #fef2f2;
            border-radius: 6px;
        }
        
        .summary-remaining .s-label { color: #dc2626; font-weight: 700; }
        .summary-remaining .s-value { color: #dc2626; font-weight: 800; font-family: 'Courier New', monospace; font-size: 1.05rem; }
        
        /* Payment history */
        .payment-section { margin-top: 25px; }
        
        .section-heading {
            font-size: 0.7rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .payment-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.78rem;
        }
        
        .payment-table th {
            background: #f8fafc;
            padding: 8px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.68rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .payment-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        /* Special request */
        .special-request {
            margin-top: 20px;
            padding: 12px 16px;
            background: #fffbeb;
            border-left: 3px solid #c9a84c;
            border-radius: 0 6px 6px 0;
            font-size: 0.82rem;
            color: #78350f;
        }
        
        /* Footer */
        .invoice-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }
        
        .footer-thankyou {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 6px;
        }
        
        .footer-contact {
            font-size: 0.7rem;
            color: #94a3b8;
            line-height: 1.6;
        }
        
        .footer-line {
            height: 3px;
            background: linear-gradient(90deg, transparent, #c9a84c 30%, #c9a84c 70%, transparent);
            margin-top: 20px;
        }
        
        /* Bottom accent */
        .bottom-accent {
            height: 5px;
            background: linear-gradient(90deg, #1a1a2e 0%, #2d2d5e 35%, #c9a84c 50%, #2d2d5e 65%, #1a1a2e 100%);
        }
        
        /* Action buttons */
        .action-bar {
            text-align: center;
            padding: 20px 0;
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        
        .btn-action {
            padding: 10px 28px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #1a1a2e, #2d2d5e);
            color: #c9a84c;
        }
        
        .btn-pdf:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(26,26,46,0.3); }
        
        .btn-print {
            background: #f8fafc;
            color: #1a1a2e;
            border: 2px solid #e2e8f0;
        }
        
        .btn-print:hover { background: #e2e8f0; }
        
        @media print {
            body { padding: 0; background: white; }
            .invoice-wrapper { box-shadow: none; }
            .action-bar { display: none !important; }
        }
        
        @page { margin: 10mm; }
    </style>
</head>
<body>
    <div class="invoice-wrapper" id="invoiceContent">
        <!-- Watermark -->
        <div class="watermark watermark-<?php echo strtolower($overallStatus); ?>">
            <?php echo $overallStatus; ?>
        </div>
        
        <!-- Top gold accent -->
        <div class="top-accent"></div>
        
        <!-- Header -->
        <div class="invoice-header">
            <div class="hotel-brand">
                <?php if (!empty($companySettings['logo'])): ?>
                    <?php $logoSrc = (!empty($companySettings['logo_is_url'])) ? $companySettings['logo'] : BASE_URL . '/uploads/logos/' . $companySettings['logo']; ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Logo" onerror="this.style.display='none'">
                <?php endif; ?>
                <div class="brand-text">
                    <h1><?php echo htmlspecialchars($companySettings['name']); ?></h1>
                    <?php if (!empty($companySettings['tagline'])): ?>
                        <div class="tagline"><?php echo htmlspecialchars($companySettings['tagline']); ?></div>
                    <?php else: ?>
                        <div class="tagline">Karimunjawa Island</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hotel-contact">
                <?php if (!empty($companySettings['address'])): ?>
                    <?php echo nl2br(htmlspecialchars($companySettings['address'])); ?><br>
                <?php endif; ?>
                <?php if (!empty($companySettings['phone'])): ?>
                    Tel: <?php echo htmlspecialchars($companySettings['phone']); ?><br>
                <?php endif; ?>
                <?php if (!empty($companySettings['email'])): ?>
                    <?php echo htmlspecialchars($companySettings['email']); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="divider-gold"></div>
        
        <!-- Invoice Title -->
        <div class="invoice-title-section">
            <div class="invoice-title-left">
                <h2>INVOICE</h2>
                <div class="invoice-number">
                    No: <strong><?php echo htmlspecialchars($allBookings[0]['booking_code']); ?></strong>
                    <?php if ($isMultiRoom && count($allBookings) > 1): ?>
                        <span style="color: #94a3b8;">+ <?php echo count($allBookings) - 1; ?> more</span>
                    <?php endif; ?>
                    &nbsp;&middot;&nbsp; <?php echo date('d M Y', strtotime($booking['created_at'])); ?>
                </div>
            </div>
            <div class="status-stamp stamp-<?php echo strtolower($overallStatus); ?>">
                <?php echo $overallStatus; ?>
            </div>
        </div>
        
        <div class="divider"></div>
        
        <!-- Body -->
        <div class="invoice-body">
            <!-- Guest & Stay Info -->
            <div class="info-section">
                <div class="info-block">
                    <div class="info-block-title">Guest Information</div>
                    <div class="info-row">
                        <span class="label">Name</span>
                        <span class="value"><?php echo htmlspecialchars($booking['guest_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Phone</span>
                        <span class="value"><?php echo htmlspecialchars($booking['phone'] ?? '-'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Email</span>
                        <span class="value"><?php echo htmlspecialchars($booking['email'] ?? '-'); ?></span>
                    </div>
                    <?php if (!empty($booking['id_card_number'])): ?>
                    <div class="info-row">
                        <span class="label">ID Number</span>
                        <span class="value"><?php echo htmlspecialchars($booking['id_card_number']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="info-block">
                    <div class="info-block-title">Stay Details</div>
                    <div class="info-row">
                        <span class="label">Check-in</span>
                        <span class="value"><?php echo date('D, d M Y', strtotime($booking['check_in_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Check-out</span>
                        <span class="value"><?php echo date('D, d M Y', strtotime($booking['check_out_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Duration</span>
                        <span class="value"><?php echo $booking['total_nights']; ?> Night<?php echo $booking['total_nights'] > 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Guests</span>
                        <span class="value"><?php echo ($booking['adults'] ?? 1); ?> Adult<?php echo ($booking['adults'] ?? 1) > 1 ? 's' : ''; ?><?php echo ($booking['children'] ?? 0) > 0 ? ', ' . $booking['children'] . ' Child' . ($booking['children'] > 1 ? 'ren' : '') : ''; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Source</span>
                        <span class="value"><?php echo strtoupper($booking['booking_source'] ?? 'Walk-in'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Room & Price Table -->
            <table class="room-table">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Type</th>
                        <th>Nights</th>
                        <th>Rate / Night</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($isMultiRoom): ?>
                        <?php foreach ($allBookings as $bk): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($bk['room_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($bk['room_type']); ?></td>
                            <td><?php echo $bk['total_nights']; ?></td>
                            <td>Rp <?php echo number_format($bk['room_price'], 0, ',', '.'); ?></td>
                            <td>Rp <?php echo number_format($bk['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($booking['room_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($booking['room_type']); ?></td>
                        <td><?php echo $booking['total_nights']; ?></td>
                        <td>Rp <?php echo number_format($booking['room_price'], 0, ',', '.'); ?></td>
                        <td>Rp <?php echo number_format($booking['total_price'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Price Summary -->
            <div class="summary-table">
                <div class="summary-row">
                    <span class="s-label">Subtotal</span>
                    <span class="s-value">Rp <?php echo number_format($combinedTotalPrice, 0, ',', '.'); ?></span>
                </div>
                <?php if ($combinedDiscount > 0): ?>
                <div class="summary-row discount">
                    <span class="s-label">Discount</span>
                    <span class="s-value">- Rp <?php echo number_format($combinedDiscount, 0, ',', '.'); ?></span>
                </div>
                <?php endif; ?>
                <div class="summary-total">
                    <span class="s-label">TOTAL</span>
                    <span class="s-value">Rp <?php echo number_format($combinedFinalPrice, 0, ',', '.'); ?></span>
                </div>
                <div class="summary-paid">
                    <span class="s-label">Paid</span>
                    <span class="s-value">Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></span>
                </div>
                <?php if ($remaining > 0): ?>
                <div class="summary-remaining">
                    <span class="s-label">Balance Due</span>
                    <span class="s-value">Rp <?php echo number_format($remaining, 0, ',', '.'); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Payment History -->
            <?php if (!empty($payments)): ?>
            <div class="payment-section">
                <div class="section-heading">Payment History</div>
                <table class="payment-table">
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
                            <td style="color:#64748b;"><?php echo htmlspecialchars($p['notes'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Special Request -->
            <?php if (!empty($booking['special_request'])): ?>
            <div class="special-request">
                <strong>Note:</strong> <?php echo nl2br(htmlspecialchars($booking['special_request'])); ?>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="invoice-footer">
                <div class="footer-thankyou">Thank you for choosing <?php echo htmlspecialchars($companySettings['name']); ?></div>
                <div class="footer-contact">
                    <?php if (!empty($companySettings['address'])): ?>
                        <?php echo htmlspecialchars($companySettings['address']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($companySettings['phone'])): ?>
                        <?php echo htmlspecialchars($companySettings['phone']); ?>
                    <?php endif; ?>
                    <?php if (!empty($companySettings['email'])): ?>
                        &middot; <?php echo htmlspecialchars($companySettings['email']); ?>
                    <?php endif; ?>
                    <?php if (!empty($companySettings['website'])): ?>
                        &middot; <?php echo htmlspecialchars($companySettings['website']); ?>
                    <?php endif; ?>
                </div>
                <div class="footer-line"></div>
            </div>
        </div>
        
        <!-- Bottom accent -->
        <div class="bottom-accent"></div>
    </div>
    
    <!-- Action buttons (above print, hidden when printing) -->
    <div class="action-bar">
        <button class="btn-action btn-pdf" onclick="savePDF()">📥 Save as PDF</button>
        <button class="btn-action btn-print" onclick="window.print()">🖨️ Print</button>
    </div>
    
    <script>
    function savePDF() {
        // Use browser print dialog with "Save as PDF" option
        document.title = 'Invoice_<?php echo $isMultiRoom ? preg_replace('/[^a-zA-Z0-9]/', '_', $booking['guest_name']) : $booking['booking_code']; ?>_<?php echo date('Ymd'); ?>';
        window.print();
    }
    
    <?php if ($isPdf): ?>
    // Auto-trigger print/save when opened with ?pdf=1
    window.onload = function() {
        setTimeout(function() { window.print(); }, 500);
    };
    <?php endif; ?>
    </script>
</body>
</html>
