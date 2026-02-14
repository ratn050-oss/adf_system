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

// Get booking details
$booking = $db->fetchOne("
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
    WHERE b.id = ?
", [$bookingId]);

if (!$booking) {
    die('Booking not found');
}

// Get payment history
$payments = $db->fetchAll("
    SELECT 
        amount, payment_method, payment_date, notes
    FROM booking_payments
    WHERE booking_id = ?
    ORDER BY payment_date ASC
", [$bookingId]);

// Calculate totals
$totalPaid = 0;
foreach ($payments as $payment) {
    $totalPaid += $payment['amount'];
}

// Fallback to paid_amount if no payment records
if ($totalPaid == 0 && $booking['paid_amount'] > 0) {
    $totalPaid = $booking['paid_amount'];
}

$remaining = $booking['final_price'] - $totalPaid;

// Get business info
$businessId = $_SESSION['business_id'] ?? 1;
$business = $db->fetchOne("SELECT * FROM businesses WHERE id = ?", [$businessId]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $booking['booking_code']; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            padding: 20px;
            background: white;
            color: #1f2937;
        }
        
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .invoice-header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .invoice-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .invoice-header .business-name {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .invoice-body {
            padding: 2rem;
        }
        
        .section {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .section:last-child {
            border-bottom: none;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #6366f1;
            margin-bottom: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .info-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 1rem;
            color: #1f2937;
            font-weight: 600;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }
        
        table th {
            background: #f9fafb;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        
        table td {
            padding: 0.75rem;
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
            font-size: 1.1rem;
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
            font-size: 1.2rem !important;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 600;
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
        
        .print-button {
            background: #6366f1;
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            margin-top: 1rem;
        }
        
        .print-button:hover {
            background: #4f46e5;
        }
        
        @media print {
            body {
                padding: 0;
            }
            
            .invoice-container {
                border: none;
                border-radius: 0;
            }
            
            .print-button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <h1>INVOICE</h1>
            <div class="business-name"><?php echo htmlspecialchars($business['business_name'] ?? 'Hotel'); ?></div>
            <div style="font-size: 0.9rem; margin-top: 0.5rem; opacity: 0.9;">
                <?php echo htmlspecialchars($business['address'] ?? ''); ?>
            </div>
        </div>
        
        <!-- Body -->
        <div class="invoice-body">
            <!-- Booking Info -->
            <div class="section">
                <div class="section-title">Booking Information</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Booking Code</div>
                        <div class="info-value"><?php echo htmlspecialchars($booking['booking_code']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Booking Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($booking['created_at'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="badge badge-<?php echo $booking['payment_status']; ?>">
                                <?php echo strtoupper($booking['payment_status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Guest Info -->
            <div class="section">
                <div class="section-title">Guest Information</div>
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
                <div class="section-title">Stay Details</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Room</div>
                        <div class="info-value"><?php echo htmlspecialchars($booking['room_number']); ?> - <?php echo htmlspecialchars($booking['room_type']); ?></div>
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
                    <div class="info-item">
                        <div class="info-label">Room Rate/Night</div>
                        <div class="info-value">Rp <?php echo number_format($booking['room_price'], 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Price Breakdown -->
            <div class="section">
                <div class="section-title">Price Breakdown</div>
                <table class="price-table">
                    <tr>
                        <td>Room Price (<?php echo $booking['total_nights']; ?> nights × Rp <?php echo number_format($booking['room_price'], 0, ',', '.'); ?>)</td>
                        <td style="text-align: right; font-weight: 600;">Rp <?php echo number_format($booking['total_price'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php if ($booking['discount'] > 0): ?>
                    <tr>
                        <td>Discount</td>
                        <td style="text-align: right; font-weight: 600; color: #dc2626;">-Rp <?php echo number_format($booking['discount'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td style="text-align: right;"><strong>Rp <?php echo number_format($booking['final_price'], 0, ',', '.'); ?></strong></td>
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
                <div class="section-title">Payment History</div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?php echo date('d M Y H:i', strtotime($payment['payment_date'])); ?></td>
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
                <div class="section-title">Special Request</div>
                <div style="padding: 0.75rem; background: #f9fafb; border-radius: 6px;">
                    <?php echo nl2br(htmlspecialchars($booking['special_request'])); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div style="text-align: center; padding-top: 1rem; color: #6b7280; font-size: 0.875rem;">
                <p>Thank you for choosing <?php echo htmlspecialchars($business['business_name'] ?? 'our hotel'); ?>!</p>
                <p style="margin-top: 0.5rem;">For inquiries, please contact: <?php echo htmlspecialchars($business['phone'] ?? ''); ?></p>
            </div>
            
            <!-- Print Button -->
            <div style="text-align: center;">
                <button class="print-button" onclick="window.print()">🖨️ Print Invoice</button>
            </div>
        </div>
    </div>
</body>
</html>
