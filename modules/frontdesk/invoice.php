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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            padding: 20px;
            background: #f5f5f5;
            color: #1f2937;
            line-height: 1.5;
        }
        
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        /* Header dengan Logo & Info Perusahaan */
        .invoice-header {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 2rem;
            padding: 1.5rem 2rem;
            border-bottom: 3px solid #6366f1;
        }
        
        .logo-section {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        
        .logo-section img {
            max-width: 180px;
            max-height: 80px;
            object-fit: contain;
            margin-bottom: 0.5rem;
        }
        
        .company-info {
            text-align: right;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .company-info h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 0.25rem;
            letter-spacing: -0.5px;
        }
        
        .company-info .tagline {
            font-size: 0.875rem;
            color: #6366f1;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        
        .company-info .address {
            font-size: 0.75rem;
            color: #6b7280;
            line-height: 1.4;
        }
        
        .company-info .contact {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        
        /* Invoice Title & Date */
        .invoice-title-bar {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .invoice-title-bar h2 {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        .invoice-title-bar .invoice-number {
            font-size: 1rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 6px;
        }
        
        .invoice-body {
            padding: 1.5rem 2rem;
        }
        
        /* Compact Section Styles */
        .section {
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .section:last-child {
            border-bottom: none;
        }
        
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: #6366f1;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem 1.5rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
        }
        
        .info-label {
            font-size: 0.688rem;
            color: #9ca3af;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .info-value {
            font-size: 0.875rem;
            color: #1f2937;
            font-weight: 600;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
        
        table th {
            background: #f9fafb;
            padding: 0.625rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        table td {
            padding: 0.625rem;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .price-table {
            margin-top: 1rem;
        }
        
        .price-table tr:last-child {
            background: #f0fdf4;
        }
        
        .price-table tr:last-child td {
            font-weight: 700;
            font-size: 1rem;
            color: #059669;
            border-bottom: none;
        }
        
        .total-row {
            background: #fef3c7 !important;
            font-weight: 700;
        }
        
        .paid-row {
            background: #dcfce7 !important;
            color: #059669;
        }
        
        .remaining-row {
            background: #fee2e2 !important;
            color: #dc2626;
            font-size: 1.1rem !important;
            font-weight: 800 !important;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .badge-paid {
            background: #dcfce7;
            color: #059669;
        }
        
        .badge-partial {
            background: #fef3c7;
            color: #d97706;
        }
        
        .badge-unpaid {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .footer-notes {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 6px;
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        .footer-notes p {
            margin: 0.25rem 0;
        }
        
        .print-button {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.875rem;
            margin-top: 1rem;
            box-shadow: 0 4px 6px rgba(99, 102, 241, 0.3);
            transition: all 0.3s;
        }
        
        .print-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(99, 102, 241, 0.4);
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .invoice-container {
                box-shadow: none;
            }
            
            .print-button {
                display: none;
            }
        }
        
        @page {
            margin: 15mm;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header with Logo & Company Info -->
        <div class="invoice-header">
            <div class="logo-section">
                <?php if (!empty($companySettings['logo'])): ?>
                    <?php $logoSrc = (!empty($companySettings['logo_is_url'])) ? $companySettings['logo'] : BASE_URL . '/uploads/logos/' . $companySettings['logo']; ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" 
                         alt="Company Logo"
                         onerror="this.style.display='none'">
                <?php else: ?>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #6366f1;">
                        <?php echo htmlspecialchars($companySettings['name']); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="company-info">
                <h1><?php echo htmlspecialchars($companySettings['name']); ?></h1>
                <?php if (!empty($companySettings['tagline'])): ?>
                    <div class="tagline"><?php echo htmlspecialchars($companySettings['tagline']); ?></div>
                <?php endif; ?>
                <?php if (!empty($companySettings['address'])): ?>
                    <div class="address"><?php echo nl2br(htmlspecialchars($companySettings['address'])); ?></div>
                <?php endif; ?>
                <div class="contact">
                    <?php if (!empty($companySettings['phone'])): ?>
                        📞 <?php echo htmlspecialchars($companySettings['phone']); ?>
                    <?php endif; ?>
                    <?php if (!empty($companySettings['email'])): ?>
                        <?php echo !empty($companySettings['phone']) ? ' • ' : ''; ?>
                        ✉️ <?php echo htmlspecialchars($companySettings['email']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Invoice Title Bar -->
        <div class="invoice-title-bar">
            <h2>INVOICE</h2>
            <div class="invoice-number">
                <?php if ($isMultiRoom): ?>
                    <?php echo htmlspecialchars($allBookings[0]['booking_code']); ?>
                    <?php if (count($allBookings) > 1): ?>
                        <span style="font-size: 0.75rem; opacity: 0.8;">+<?php echo count($allBookings) - 1; ?> rooms</span>
                    <?php endif; ?>
                <?php else: ?>
                    <?php echo htmlspecialchars($booking['booking_code']); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Body -->
        <div class="invoice-body">
            <!-- Booking Info -->
            <div class="section">
                <div class="section-title">📋 Booking Information</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Booking Code</div>
                        <div class="info-value">
                            <?php if ($isMultiRoom): ?>
                                <?php foreach ($allBookings as $i => $bk): ?>
                                    <?php echo htmlspecialchars($bk['booking_code']); ?><?php echo $i < count($allBookings) - 1 ? ', ' : ''; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php echo htmlspecialchars($booking['booking_code']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Booking Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($booking['created_at'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Booking Source</div>
                        <div class="info-value"><?php echo strtoupper($booking['booking_source'] ?? 'Walk-in'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Payment Status</div>
                        <div class="info-value">
                            <?php 
                                $overallStatus = 'unpaid';
                                if ($totalPaid >= $combinedFinalPrice) $overallStatus = 'paid';
                                elseif ($totalPaid > 0) $overallStatus = 'partial';
                            ?>
                            <span class="badge badge-<?php echo $overallStatus; ?>">
                                <?php echo strtoupper($overallStatus); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Guest Info -->
            <div class="section">
                <div class="section-title">👤 Guest Information</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Guest Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($booking['phone'] ?? '-'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($booking['email'] ?? '-'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">ID Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($booking['id_card_number'] ?? '-'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Stay Details -->
            <div class="section">
                <div class="section-title">🏨 Stay Details</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Room<?php echo $isMultiRoom ? 's' : ''; ?></div>
                        <div class="info-value">
                            <?php if ($isMultiRoom): ?>
                                <?php foreach ($allBookings as $bk): ?>
                                    <div><?php echo htmlspecialchars($bk['room_number']); ?> - <?php echo htmlspecialchars($bk['room_type']); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php echo htmlspecialchars($booking['room_number']); ?> - <?php echo htmlspecialchars($booking['room_type']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Guests</div>
                        <div class="info-value"><?php echo $booking['adults']; ?> Adult<?php echo $booking['adults'] > 1 ? 's' : ''; ?><?php echo $booking['children'] > 0 ? ', ' . $booking['children'] . ' Child' : ''; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Check-in</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($booking['check_in_date'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Check-out</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($booking['check_out_date'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Total Nights</div>
                        <div class="info-value"><?php echo $booking['total_nights']; ?> Night<?php echo $booking['total_nights'] > 1 ? 's' : ''; ?></div>
                    </div>
                    <?php if (!$isMultiRoom): ?>
                    <div class="info-item">
                        <div class="info-label">Room Rate/Night</div>
                        <div class="info-value">Rp <?php echo number_format($booking['room_price'], 0, ',', '.'); ?></div>
                    </div>
                    <?php else: ?>
                    <div class="info-item">
                        <div class="info-label">Total Rooms</div>
                        <div class="info-value"><?php echo count($allBookings); ?> Rooms</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Price Breakdown -->
            <div class="section">
                <div class="section-title">💰 Price Breakdown</div>
                <table class="price-table">
                    <?php if ($isMultiRoom): ?>
                        <?php foreach ($allBookings as $bk): ?>
                        <tr>
                            <td>Room <?php echo htmlspecialchars($bk['room_number']); ?> - <?php echo htmlspecialchars($bk['room_type']); ?> (<?php echo $bk['total_nights']; ?> nights × Rp <?php echo number_format($bk['room_price'], 0, ',', '.'); ?>)</td>
                            <td style="text-align: right; font-weight: 600;">Rp <?php echo number_format($bk['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td>Room <?php echo htmlspecialchars($booking['room_number']); ?> (<?php echo $booking['total_nights']; ?> nights × Rp <?php echo number_format($booking['room_price'], 0, ',', '.'); ?>)</td>
                        <td style="text-align: right; font-weight: 600;">Rp <?php echo number_format($booking['total_price'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($combinedDiscount > 0): ?>
                    <tr>
                        <td>Discount</td>
                        <td style="text-align: right; font-weight: 600; color: #dc2626;">-Rp <?php echo number_format($combinedDiscount, 0, ',', '.'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td style="text-align: right;"><strong>Rp <?php echo number_format($combinedFinalPrice, 0, ',', '.'); ?></strong></td>
                    </tr>
                    <tr class="paid-row">
                        <td><strong>PAID</strong></td>
                        <td style="text-align: right;"><strong>Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></strong></td>
                    </tr>
                    <?php if ($remaining > 0): ?>
                    <tr class="remaining-row">
                        <td><strong>REMAINING BALANCE</strong></td>
                        <td style="text-align: right;"><strong>Rp <?php echo number_format($remaining, 0, ',', '.'); ?></strong></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- Payment History -->
            <?php if (!empty($payments)): ?>
            <div class="section">
                <div class="section-title">💳 Payment History</div>
                <table>
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
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?php echo date('d M Y H:i', strtotime($payment['payment_date'])); ?></td>
                            <?php if ($isMultiRoom): ?><td><?php echo htmlspecialchars($payment['room_number'] ?? '-'); ?></td><?php endif; ?>
                            <td><?php echo strtoupper($payment['payment_method']); ?></td>
                            <td style="font-weight: 600;">Rp <?php echo number_format($payment['amount'], 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($payment['notes'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Special Request -->
            <?php if (!empty($booking['special_request'])): ?>
            <div class="section">
                <div class="section-title">📝 Special Request</div>
                <div style="padding: 0.625rem; background: #f9fafb; border-radius: 6px; font-size: 0.875rem;">
                    <?php echo nl2br(htmlspecialchars($booking['special_request'])); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="footer-notes">
                <p style="font-weight: 600; color: #374151; margin-bottom: 0.5rem;">
                    Thank you for choosing <?php echo htmlspecialchars($companySettings['name']); ?>!
                </p>
                <?php if (!empty($companySettings['phone'])): ?>
                <p>For inquiries, please contact: <?php echo htmlspecialchars($companySettings['phone']); ?></p>
                <?php endif; ?>
                <?php if (!empty($companySettings['website'])): ?>
                <p>Visit us: <?php echo htmlspecialchars($companySettings['website']); ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Print Button -->
            <div style="text-align: center;">
                <button class="print-button" onclick="window.print()">🖨️ Print Invoice</button>
            </div>
        </div>
    </div>
</body>
</html>
