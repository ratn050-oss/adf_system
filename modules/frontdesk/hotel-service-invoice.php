<?php
/**
 * Hotel Service Invoice — Print-friendly
 * Motor Rental · Laundry · Service · Airport Drop · Harbor Drop
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

$db  = Database::getInstance();
$pdo = $db->getConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid Order ID');

// Fetch order
$stmt = $pdo->prepare("SELECT * FROM hotel_service_orders WHERE id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) die('Order not found');

// Service type labels
$serviceLabels = [
    'motor_rental'  => 'Motor Rental',
    'laundry'       => 'Laundry',
    'service'       => 'Service',
    'airport_drop'  => 'Airport Drop',
    'harbor_drop'   => 'Harbor Drop',
];

$serviceIcons = [
    'motor_rental'  => '🏍️',
    'laundry'       => '👕',
    'service'       => '🔧',
    'airport_drop'  => '✈️',
    'harbor_drop'   => '⚓',
];

// Company settings
$businessId  = $_SESSION['business_id'] ?? 1;
$business    = $db->fetchOne("SELECT * FROM businesses WHERE id = ?", [$businessId]);
$settingsResult = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'");
$co = [];
foreach ($settingsResult as $s) {
    if (strpos($s['setting_key'], 'company_logo_') === 0) {
        $bizCode = str_replace('company_logo_', '', $s['setting_key']);
        if ($bizCode === ($_SESSION['selected_business_id'] ?? '')) {
            $co['logo'] = $s['setting_value'];
        }
    } else {
        $co[str_replace('company_', '', $s['setting_key'])] = $s['setting_value'];
    }
}
if (empty($co['name'])) $co['name'] = $business['business_name'] ?? 'Narayana Hotel';

// Resolve logo path
$logoUrl = null;
if (!empty($co['logo'])) {
    $logoFile = BASE_PATH . '/uploads/logos/' . $co['logo'];
    if (file_exists($logoFile)) $logoUrl = BASE_URL . '/uploads/logos/' . $co['logo'] . '?v=' . time();
}

$remaining = $order['total_price'] - $order['paid_amount'];
$serviceName = $serviceLabels[$order['service_type']] ?? ucwords(str_replace('_',' ',$order['service_type']));
$serviceIcon = $serviceIcons[$order['service_type']] ?? '🛎️';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($order['order_number']); ?> — <?php echo htmlspecialchars($co['name']); ?></title>
    <style>
        @media print {
            body { background: white !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .invoice-container { box-shadow: none !important; }
            @page { margin: 12mm; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', -apple-system, Arial, sans-serif;
            background: #f0f4ff;
            padding: 24px 16px;
            color: #1f2937;
            font-size: 14px;
            line-height: 1.5;
        }
        .invoice-container {
            max-width: 760px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        }

        /* Header */
        .inv-header {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1.5rem;
            padding: 1.75rem 2rem 1.25rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .logo-wrap img { max-width: 160px; max-height: 70px; object-fit: contain; }
        .logo-wrap .fallback-name { font-size: 1.5rem; font-weight: 800; color: #1e1b4b; }
        .co-info { text-align: right; }
        .co-info .co-name { font-size: 1.35rem; font-weight: 800; color: #1f2937; }
        .co-info .co-sub  { font-size: 0.78rem; color: #6366f1; font-weight: 600; margin-bottom: 0.5rem; }
        .co-info .co-addr { font-size: 0.72rem; color: #6b7280; line-height: 1.5; }

        /* Title bar */
        .inv-title-bar {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 60%, #4c1d95 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .inv-title-bar .left .svc-label {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }
        .inv-title-bar .left .svc-sub {
            font-size: 0.78rem;
            opacity: 0.75;
            margin-top: 0.1rem;
        }
        .inv-title-bar .right { text-align: right; }
        .inv-title-bar .inv-number { font-size: 1rem; font-weight: 700; background: rgba(255,255,255,0.2); padding: 0.3rem 0.75rem; border-radius: 20px; }
        .inv-title-bar .inv-date { font-size: 0.72rem; opacity: 0.75; margin-top: 0.4rem; }

        /* Body */
        .inv-body { padding: 1.5rem 2rem; }

        /* Status badge */
        .pay-status-banner {
            border-radius: 8px;
            padding: 0.6rem 1rem;
            margin-bottom: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
        }
        .pay-status-banner.paid    { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .pay-status-banner.unpaid  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .pay-status-banner.partial { background: #fef9c3; color: #92400e; border: 1px solid #fde047; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.25rem; }
        .info-block .lbl { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; font-weight: 600; margin-bottom: 0.2rem; }
        .info-block .val { font-size: 0.9rem; font-weight: 600; color: #1f2937; }

        /* Service table */
        .svc-table { width: 100%; border-collapse: collapse; margin-bottom: 1.25rem; }
        .svc-table thead th {
            background: #f8fafc;
            padding: 0.6rem 0.85rem;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
        }
        .svc-table thead th:last-child { text-align: right; }
        .svc-table tbody td {
            padding: 0.75rem 0.85rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
            vertical-align: top;
        }
        .svc-table tbody td:last-child { text-align: right; font-weight: 600; }
        .svc-table .svc-icon-cell { font-size: 1.5rem; width: 50px; vertical-align: middle; }

        /* Totals */
        .totals-table { margin-left: auto; width: 280px; border-collapse: collapse; }
        .totals-table td { padding: 0.4rem 0.6rem; font-size: 0.88rem; }
        .totals-table .t-label { color: #6b7280; }
        .totals-table .t-val   { text-align: right; font-weight: 600; }
        .totals-table .total-row td { border-top: 2px solid #1e1b4b; font-size: 1rem; font-weight: 800; color: #1e1b4b; padding-top: 0.6rem; }
        .totals-table .paid-row td  { color: #15803d; font-weight: 700; }
        .totals-table .due-row  td  { color: #dc2626; font-weight: 800; font-size: 1.05rem; }

        /* Section titles */
        .sec-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #9ca3af; font-weight: 700; margin-bottom: 0.6rem; padding-bottom: 0.3rem; border-bottom: 1px solid #f1f5f9; }

        /* Notes */
        .notes-box { background: #f8fafc; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.83rem; color: #374151; }

        /* Footer */
        .inv-footer {
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.72rem;
            color: #9ca3af;
        }
        .inv-footer .thank-you { font-size: 0.85rem; font-weight: 600; color: #4338ca; }

        /* Print button */
        .print-area { text-align: center; margin-top: 1.5rem; }
        .print-btn {
            background: #1e1b4b; color: white; border: none;
            padding: 0.7rem 2rem; border-radius: 8px; font-size: 0.9rem;
            font-weight: 600; cursor: pointer; margin-right: 0.5rem;
        }
        .back-btn {
            background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb;
            padding: 0.7rem 1.5rem; border-radius: 8px; font-size: 0.9rem;
            font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;
        }

        /* Watermark for paid */
        .watermark {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-35deg);
            font-size: 5rem;
            font-weight: 900;
            opacity: 0.05;
            color: #16a34a;
            pointer-events: none;
            z-index: 0;
            white-space: nowrap;
        }
        .inv-body { position: relative; }
    </style>
</head>
<body>

<div class="invoice-container">

    <!-- Header -->
    <div class="inv-header">
        <div class="logo-wrap">
            <?php if ($logoUrl): ?>
            <img src="<?php echo $logoUrl; ?>" alt="<?php echo htmlspecialchars($co['name']); ?>">
            <?php else: ?>
            <div class="fallback-name"><?php echo htmlspecialchars($co['name']); ?></div>
            <?php endif; ?>
        </div>
        <div class="co-info">
            <div class="co-name"><?php echo htmlspecialchars($co['name']); ?></div>
            <?php if (!empty($co['tagline'])): ?>
            <div class="co-sub"><?php echo htmlspecialchars($co['tagline']); ?></div>
            <?php endif; ?>
            <div class="co-addr">
                <?php if (!empty($co['address'])): ?>
                    <?php echo nl2br(htmlspecialchars($co['address'])); ?><br>
                <?php endif; ?>
                <?php if (!empty($co['phone'])): ?>📞 <?php echo htmlspecialchars($co['phone']); ?><?php endif; ?>
                <?php if (!empty($co['email']) && !empty($co['phone'])): ?> &nbsp;·&nbsp; <?php endif; ?>
                <?php if (!empty($co['email'])): ?>✉️ <?php echo htmlspecialchars($co['email']); ?><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Title bar -->
    <div class="inv-title-bar">
        <div class="left">
            <div class="svc-label"><?php echo $serviceIcon; ?> <?php echo strtoupper($serviceName); ?> INVOICE</div>
            <div class="svc-sub">Hotel Additional Services</div>
        </div>
        <div class="right">
            <div class="inv-number"><?php echo htmlspecialchars($order['order_number']); ?></div>
            <div class="inv-date">Issued: <?php echo date('d F Y', strtotime($order['created_at'])); ?></div>
        </div>
    </div>

    <!-- Body -->
    <div class="inv-body">
        <?php if ($order['payment_status'] === 'paid'): ?>
        <div class="watermark">PAID</div>
        <?php endif; ?>

        <!-- Payment status banner -->
        <div class="pay-status-banner <?php echo $order['payment_status']; ?>">
            <span>
                <?php if ($order['payment_status'] === 'paid'): ?>✅ FULLY PAID
                <?php elseif ($order['payment_status'] === 'partial'): ?>⚠️ PARTIALLY PAID — Balance Due: Rp <?php echo number_format($remaining, 0, ',', '.'); ?>
                <?php else: ?>❌ PAYMENT PENDING — Amount Due: Rp <?php echo number_format($order['total_price'], 0, ',', '.'); ?>
                <?php endif; ?>
            </span>
            <span style="font-size:0.75rem;opacity:0.75"><?php echo strtoupper($order['payment_method']); ?></span>
        </div>

        <!-- Guest & Order info -->
        <div style="margin-bottom:1.25rem">
            <div class="sec-title">Guest &amp; Order Details</div>
            <div class="info-grid">
                <div class="info-block">
                    <div class="lbl">Guest Name</div>
                    <div class="val"><?php echo htmlspecialchars($order['guest_name']); ?></div>
                </div>
                <div class="info-block">
                    <div class="lbl">Order Number</div>
                    <div class="val"><?php echo htmlspecialchars($order['order_number']); ?></div>
                </div>
                <?php if ($order['guest_phone']): ?>
                <div class="info-block">
                    <div class="lbl">Phone</div>
                    <div class="val"><?php echo htmlspecialchars($order['guest_phone']); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-block">
                    <div class="lbl">Room</div>
                    <div class="val"><?php echo $order['room_number'] ? htmlspecialchars($order['room_number']) : '—'; ?></div>
                </div>
                <div class="info-block">
                    <div class="lbl">Order Date</div>
                    <div class="val"><?php echo date('d F Y, H:i', strtotime($order['created_at'])); ?></div>
                </div>
                <div class="info-block">
                    <div class="lbl">Status</div>
                    <div class="val"><?php echo ucwords(str_replace('_',' ',$order['status'])); ?></div>
                </div>
                <?php if ($order['start_datetime']): ?>
                <div class="info-block">
                    <div class="lbl">Start / Pickup</div>
                    <div class="val"><?php echo date('d M Y, H:i', strtotime($order['start_datetime'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($order['end_datetime']): ?>
                <div class="info-block">
                    <div class="lbl">End / Return</div>
                    <div class="val"><?php echo date('d M Y, H:i', strtotime($order['end_datetime'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Service line items -->
        <div style="margin-bottom:1.25rem">
            <div class="sec-title">Service Details</div>
            <table class="svc-table">
                <thead>
                    <tr>
                        <th style="width:50px"></th>
                        <th>Description</th>
                        <th style="width:80px;text-align:center">Qty</th>
                        <th style="width:120px;text-align:right">Unit Price</th>
                        <th style="width:130px;text-align:right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="svc-icon-cell"><?php echo $serviceIcon; ?></td>
                        <td>
                            <div style="font-weight:700;color:#1e1b4b"><?php echo $serviceName; ?></div>
                            <?php if ($order['description']): ?>
                            <div style="font-size:0.78rem;color:#6b7280;margin-top:0.2rem"><?php echo htmlspecialchars($order['description']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center"><?php echo rtrim(rtrim(number_format($order['quantity'], 2), '0'),'.')?></td>
                        <td style="text-align:right">Rp <?php echo number_format($order['unit_price'], 0, ',', '.'); ?></td>
                        <td style="text-align:right">Rp <?php echo number_format($order['total_price'], 0, ',', '.'); ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- Totals -->
            <table class="totals-table">
                <tr>
                    <td class="t-label">Subtotal</td>
                    <td class="t-val">Rp <?php echo number_format($order['total_price'], 0, ',', '.'); ?></td>
                </tr>
                <tr class="total-row">
                    <td>TOTAL</td>
                    <td>Rp <?php echo number_format($order['total_price'], 0, ',', '.'); ?></td>
                </tr>
                <tr class="paid-row">
                    <td>PAID</td>
                    <td>Rp <?php echo number_format($order['paid_amount'], 0, ',', '.'); ?></td>
                </tr>
                <?php if ($remaining > 0): ?>
                <tr class="due-row">
                    <td>BALANCE DUE</td>
                    <td>Rp <?php echo number_format($remaining, 0, ',', '.'); ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td colspan="2" style="text-align:right;padding-top:0.5rem;font-size:0.8rem;color:#16a34a;font-weight:600">✅ No Outstanding Balance</td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Notes -->
        <?php if ($order['notes']): ?>
        <div style="margin-bottom:1.25rem">
            <div class="sec-title">Notes</div>
            <div class="notes-box"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
        </div>
        <?php endif; ?>

    </div><!-- /inv-body -->

    <!-- Footer -->
    <div class="inv-footer">
        <div>
            <div class="thank-you">Thank you for choosing <?php echo htmlspecialchars($co['name']); ?>!</div>
            <?php if (!empty($co['phone'])): ?>
            <div style="margin-top:0.2rem">For inquiries: <?php echo htmlspecialchars($co['phone']); ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <div style="font-weight:600;color:#374151"><?php echo htmlspecialchars($order['order_number']); ?></div>
            <div>Printed: <?php echo date('d M Y H:i'); ?></div>
        </div>
    </div>

</div><!-- /invoice-container -->

<div class="print-area no-print">
    <button class="print-btn" onclick="window.print()">🖨️ Print Invoice</button>
    <a href="hotel-services.php" class="back-btn">← Back to Services</a>
</div>

</body>
</html>
