<?php
/**
 * Hotel Service Invoice — Print View
 * Reads from hotel_invoices + hotel_invoice_items tables
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db  = Database::getInstance();
$pdo = $db->getConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { die('Invalid invoice ID'); }

// Load invoice
$stmt = $pdo->prepare("SELECT * FROM hotel_invoices WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { die('Invoice not found'); }

// Load items
$istmt = $pdo->prepare("SELECT * FROM hotel_invoice_items WHERE invoice_id = ? ORDER BY id ASC");
$istmt->execute([$id]);
$items = $istmt->fetchAll(PDO::FETCH_ASSOC);

// Company settings
$settings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }
} catch (\Throwable $e) {}

$companyName    = $settings['company_name']    ?? 'Narayana Hotel Karimunjawa';
$companyAddress = $settings['company_address'] ?? 'Karimunjawa, Jepara, Central Java, Indonesia';
$companyPhone   = $settings['company_phone']   ?? '';
$companyEmail   = $settings['company_email']   ?? '';
$companyLogo    = null;
$logoKey        = $settings['company_logo']    ?? '';
if ($logoKey) {
    $logoPath = '../../uploads/logos/' . basename($logoKey);
    if (file_exists($logoPath)) $companyLogo = BASE_URL . '/uploads/logos/' . basename($logoKey);
}

$serviceLabels = [
    'motor_rental' => ['label' => 'Motor Rental', 'icon' => '🏍️'],
    'laundry'      => ['label' => 'Laundry',       'icon' => '👕'],
    'service'      => ['label' => 'Service',       'icon' => '🔧'],
    'airport_drop' => ['label' => 'Airport Drop',  'icon' => '✈️'],
    'harbor_drop'  => ['label' => 'Harbor Drop',   'icon' => '⚓'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?php echo htmlspecialchars($inv['invoice_number']); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f2f5;color:#1a1a2e;font-size:13px}
.page{width:100%;max-width:760px;margin:20px auto;background:white;box-shadow:0 4px 24px rgba(0,0,0,0.12);position:relative;overflow:hidden}
/* Header */
.inv-head{background:linear-gradient(135deg,#1e3a5f 0%,#0d2137 100%);color:white;padding:2rem 2.5rem;display:flex;justify-content:space-between;align-items:flex-start}
.inv-head .company-name{font-size:1.3rem;font-weight:800;letter-spacing:0.03em;margin-bottom:0.25rem}
.inv-head .company-sub{font-size:0.78rem;opacity:0.8;line-height:1.5}
.inv-head .inv-title{text-align:right}
.inv-head .inv-title .word{font-size:1.7rem;font-weight:900;letter-spacing:0.1em;opacity:0.15;display:block}
.inv-head .inv-title .num{font-size:0.95rem;font-weight:700;background:rgba(255,255,255,0.15);padding:0.3rem 0.75rem;border-radius:20px;display:inline-block;margin-top:0.5rem}
.inv-head .logo-wrap img{height:60px;width:auto;object-fit:contain;border-radius:6px}
/* Status banner */
.status-banner{padding:0.55rem 2.5rem;font-size:0.8rem;font-weight:700;letter-spacing:0.04em;color:white;text-align:center}
.st-paid{background:#10b981}.st-partial{background:#f59e0b}.st-unpaid{background:#ef4444}
/* Body */
.inv-body{padding:2rem 2.5rem}
/* Info grid */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.75rem}
.info-box h4{font-size:0.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.6rem;border-bottom:2px solid #f0f4ff;padding-bottom:0.35rem}
.info-row{display:flex;justify-content:space-between;gap:0.5rem;margin-bottom:0.3rem;font-size:0.8rem}
.info-row .lbl{color:#64748b;min-width:90px}
.info-row .val{font-weight:600;text-align:right}
/* Items table */
.items-section h4{font-size:0.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.75rem;border-bottom:2px solid #f0f4ff;padding-bottom:0.35rem}
.inv-table{width:100%;border-collapse:collapse;margin-bottom:1.25rem}
.inv-table th{background:#1e3a5f;color:white;padding:0.6rem 0.8rem;font-size:0.7rem;font-weight:600;letter-spacing:0.04em;text-align:left}
.inv-table th.r{text-align:right}
.inv-table td{padding:0.65rem 0.8rem;border-bottom:1px solid #f1f5f9;font-size:0.82rem;vertical-align:top}
.inv-table td.r{text-align:right;font-weight:600}
.inv-table tr:last-child td{border-bottom:none}
.inv-table tr:hover td{background:#fafbff}
.svc-type-label{display:inline-flex;align-items:center;gap:0.35rem;background:#ede9fe;color:#5b21b6;padding:0.15rem 0.55rem;border-radius:12px;font-size:0.72rem;font-weight:700}
/* Totals */
.totals-wrap{display:flex;justify-content:flex-end;margin-bottom:1.5rem}
.totals-box{min-width:280px;border:1px solid #e8edf5;border-radius:10px;overflow:hidden}
.totals-row{display:flex;justify-content:space-between;padding:0.55rem 1rem;font-size:0.82rem;border-bottom:1px solid #f1f5f9}
.totals-row:last-child{border-bottom:none}
.totals-row.grand{background:#1e3a5f;color:white;font-weight:700;font-size:0.9rem}
.totals-row.balance{background:#fff8e1;color:#92400e;font-weight:700}
/* Notes */
.notes-box{background:#f8fafc;border-left:3px solid #6366f1;padding:0.75rem 1rem;border-radius:0 6px 6px 0;margin-bottom:1.5rem;font-size:0.82rem;color:#374151}
.notes-box strong{display:block;font-size:0.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem}
/* Footer */
.inv-foot{background:#f8fafc;padding:1.25rem 2.5rem;text-align:center;font-size:0.75rem;color:#94a3b8;border-top:2px solid #e2e8f0}
.inv-foot strong{color:#1e3a5f;display:block;margin-bottom:0.25rem;font-size:0.8rem}
/* PAID watermark */
.paid-watermark{position:absolute;top:38%;left:50%;transform:translate(-50%,-50%) rotate(-25deg);font-size:7rem;font-weight:900;color:rgba(16,185,129,0.12);pointer-events:none;z-index:10;letter-spacing:0.1em;white-space:nowrap}
/* No-print */
.no-print{background:#1e3a5f;padding:0.75rem 2.5rem;display:flex;gap:0.75rem;align-items:center}
.btn-print{background:white;color:#1e3a5f;border:none;border-radius:6px;padding:0.5rem 1.25rem;font-weight:700;cursor:pointer;font-size:0.85rem}
.btn-back{background:rgba(255,255,255,0.15);color:white;border:1px solid rgba(255,255,255,0.4);border-radius:6px;padding:0.5rem 1.25rem;font-weight:600;cursor:pointer;font-size:0.85rem;text-decoration:none;display:inline-block}
@media print{
    body{background:white}
    .no-print{display:none!important}
    .page{box-shadow:none;margin:0;max-width:none;overflow:visible}
    .inv-table tr:hover td{background:transparent!important}
    .inv-table tr td{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .inv-head,.inv-table th,.status-banner,.totals-row.grand{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    @page{margin:10mm 12mm}
}
</style>
</head>
<body>

<!-- Actions bar -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Print Invoice</button>
    <a class="btn-back" href="javascript:history.back()">← Back</a>
    <span style="color:rgba(255,255,255,0.7);font-size:0.8rem;margin-left:auto"><?php echo htmlspecialchars($inv['invoice_number']); ?></span>
</div>

<div class="page">

<?php if ($inv['payment_status'] === 'paid'): ?>
<div class="paid-watermark">PAID</div>
<?php endif; ?>

    <!-- Header -->
    <div class="inv-head">
        <div style="display:flex;align-items:flex-start;gap:1rem">
            <?php if ($companyLogo): ?>
            <div class="logo-wrap"><img src="<?php echo htmlspecialchars($companyLogo); ?>" alt="Logo"></div>
            <?php endif; ?>
            <div>
                <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
                <div class="company-sub">
                    <?php echo htmlspecialchars($companyAddress); ?>
                    <?php if ($companyPhone): ?><br>📞 <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
                    <?php if ($companyEmail): ?><br>✉️ <?php echo htmlspecialchars($companyEmail); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="inv-title">
            <span class="word">INVOICE</span>
            <div class="num"><?php echo htmlspecialchars($inv['invoice_number']); ?></div>
        </div>
    </div>

    <!-- Status banner -->
    <?php
    $bannerClass = ['paid'=>'st-paid','partial'=>'st-partial','unpaid'=>'st-unpaid'][$inv['payment_status']] ?? 'st-unpaid';
    $bannerText  = ['paid'=>'✅ PAID IN FULL','partial'=>'⚡ PARTIALLY PAID — BALANCE DUE','unpaid'=>'❌ UNPAID'][$inv['payment_status']] ?? 'UNPAID';
    ?>
    <div class="status-banner <?php echo $bannerClass; ?>"><?php echo $bannerText; ?></div>

    <div class="inv-body">

        <!-- Info grid -->
        <div class="info-grid">
            <div class="info-box">
                <h4>Bill To</h4>
                <div class="info-row"><span class="lbl">Guest Name</span><span class="val"><?php echo htmlspecialchars($inv['guest_name']); ?></span></div>
                <?php if ($inv['guest_phone']): ?>
                <div class="info-row"><span class="lbl">Phone</span><span class="val"><?php echo htmlspecialchars($inv['guest_phone']); ?></span></div>
                <?php endif; ?>
                <?php if ($inv['room_number']): ?>
                <div class="info-row"><span class="lbl">Room</span><span class="val"><?php echo htmlspecialchars($inv['room_number']); ?></span></div>
                <?php endif; ?>
            </div>
            <div class="info-box">
                <h4>Invoice Details</h4>
                <div class="info-row"><span class="lbl">Invoice No.</span><span class="val" style="color:#1e3a5f;font-weight:800"><?php echo htmlspecialchars($inv['invoice_number']); ?></span></div>
                <div class="info-row"><span class="lbl">Date</span><span class="val"><?php echo date('d F Y', strtotime($inv['created_at'])); ?></span></div>
                <div class="info-row"><span class="lbl">Payment</span><span class="val"><?php echo ucfirst($inv['payment_method']); ?></span></div>
                <div class="info-row"><span class="lbl">Status</span><span class="val"><?php echo ucfirst($inv['status']); ?></span></div>
            </div>
        </div>

        <!-- Items table -->
        <div class="items-section">
            <h4>Services Provided</h4>
            <table class="inv-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Service Type</th>
                        <th>Description</th>
                        <th class="r">Qty</th>
                        <th class="r">Unit Price</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php $rowNo = 1; foreach ($items as $item):
                    $svcInfo = $serviceLabels[$item['service_type']] ?? ['label'=>$item['service_type'],'icon'=>''];
                ?>
                <tr>
                    <td style="color:#94a3b8;font-size:0.75rem"><?php echo $rowNo++; ?></td>
                    <td><span class="svc-type-label"><?php echo $svcInfo['icon']; ?> <?php echo $svcInfo['label']; ?></span></td>
                    <td style="color:#374151">
                        <?php echo htmlspecialchars($item['description'] ?? ''); ?>
                        <?php if ($item['start_datetime']): ?>
                        <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px">
                            From: <?php echo date('d M Y H:i', strtotime($item['start_datetime'])); ?>
                            <?php if ($item['end_datetime']): ?> — <?php echo date('d M Y H:i', strtotime($item['end_datetime'])); ?><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="r"><?php echo rtrim(rtrim(number_format($item['quantity'],2),'0'),'.'); ?></td>
                    <td class="r">Rp <?php echo number_format($item['unit_price'],0,',','.'); ?></td>
                    <td class="r">Rp <?php echo number_format($item['total_price'],0,',','.'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-wrap">
            <div class="totals-box">
                <div class="totals-row grand">
                    <span>TOTAL</span>
                    <span>Rp <?php echo number_format($inv['total'],0,',','.'); ?></span>
                </div>
                <div class="totals-row">
                    <span>Paid</span>
                    <span style="color:#10b981;font-weight:700">Rp <?php echo number_format($inv['paid_amount'],0,',','.'); ?></span>
                </div>
                <?php $balance = $inv['total'] - $inv['paid_amount']; if ($balance > 0): ?>
                <div class="totals-row balance">
                    <span>Balance Due</span>
                    <span>Rp <?php echo number_format($balance,0,',','.'); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notes -->
        <?php if ($inv['notes']): ?>
        <div class="notes-box">
            <strong>Notes</strong>
            <?php echo nl2br(htmlspecialchars($inv['notes'])); ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Footer -->
    <div class="inv-foot">
        <strong><?php echo htmlspecialchars($companyName); ?></strong>
        Thank you for choosing our services. We look forward to serving you again!<br>
        <?php if ($companyPhone||$companyEmail): ?>
        Contact: <?php echo htmlspecialchars(implode(' | ', array_filter([$companyPhone, $companyEmail]))); ?>
        <?php endif; ?>
    </div>
</div><!-- /page -->

</body>
</html>
