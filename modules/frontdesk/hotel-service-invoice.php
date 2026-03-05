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
// Logo from settings
$companyLogo = null;
$logoKey = $settings['company_logo'] ?? '';
if ($logoKey) {
    $logoFile = basename($logoKey);
    $logoPhysical = rtrim(defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/../..', '/') . '/uploads/logos/' . $logoFile;
    if (file_exists($logoPhysical)) {
        $companyLogo = BASE_URL . '/uploads/logos/' . $logoFile;
    }
}
$companyWebsite = $settings['company_website'] ?? 'www.narayanakarimunjawa.com';

// Processed status
$isProcessed = (bool)($inv['cashbook_synced'] ?? 0);

$serviceLabels = [
    'motor_rental'  => ['label' => 'Motor Rental',  'icon' => '🏍️'],
    'laundry'       => ['label' => 'Laundry',        'icon' => '👕'],
    'service'       => ['label' => 'Service',        'icon' => '🔧'],
    'airport_drop'  => ['label' => 'Airport Drop',   'icon' => '✈️'],
    'harbor_drop'   => ['label' => 'Harbor Drop',    'icon' => '⚓'],
    'narayana_trip' => ['label' => 'Narayana Trip',  'icon' => '🚤'],
    'lain_lain'     => ['label' => 'Lain-lain',      'icon' => '📦'],
];

// PPN / tax info
$taxRate   = (float)($inv['tax_rate']   ?? 0);
$taxAmount = (float)($inv['tax_amount'] ?? 0);
$subtotal  = $taxRate > 0 ? round($inv['total'] - $taxAmount, 2) : $inv['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?php echo htmlspecialchars($inv['invoice_number']); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;color:#1a1a2e;font-size:13px}
.page{width:100%;max-width:780px;margin:20px auto;background:white;box-shadow:0 4px 24px rgba(0,0,0,0.10);position:relative;overflow:hidden;border-radius:4px}
/* Header — Light */
.inv-head{background:white;border-bottom:3px solid #1e3a5f;padding:1.75rem 2.5rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1.5rem}
.inv-head .logo-wrap{flex-shrink:0}
.inv-head .logo-wrap img{height:64px;width:auto;object-fit:contain}
.inv-head .company-block{flex:1}
.inv-head .company-name{font-size:1.5rem;font-weight:900;color:#1e3a5f;letter-spacing:0.03em;margin-bottom:0.15rem}
.inv-head .company-website{font-size:0.78rem;color:#6366f1;font-style:italic;margin-bottom:0.25rem}
.inv-head .company-sub{font-size:0.75rem;color:#475569;line-height:1.6}
.inv-head .inv-title{text-align:right;flex-shrink:0}
.inv-head .inv-title .word{font-size:2rem;font-weight:900;letter-spacing:0.15em;color:#1e3a5f;opacity:0.07;display:block;line-height:1}
.inv-head .inv-title .num{font-size:0.9rem;font-weight:700;background:#1e3a5f;color:white;padding:0.3rem 0.9rem;border-radius:20px;display:inline-block;margin-top:0.5rem}
/* Status banner */
.status-banner{padding:0.5rem 2.5rem;font-size:0.78rem;font-weight:700;letter-spacing:0.05em;color:white;text-align:center}
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
.inv-foot strong{color:#1e3a5f;display:block;margin-bottom:0.15rem;font-size:0.8rem}
.inv-foot .web{color:#6366f1;font-style:italic;display:block;margin-bottom:0.25rem}
/* Watermark */
.watermark{position:absolute;top:42%;left:50%;transform:translate(-50%,-50%) rotate(-28deg);font-size:5.5rem;font-weight:900;pointer-events:none;z-index:10;letter-spacing:0.1em;white-space:nowrap;user-select:none}
.wm-unpaid{color:rgba(239,68,68,0.10)}
.wm-paid{color:rgba(16,185,129,0.10)}
.wm-partial{color:rgba(245,158,11,0.10)}
/* No-print actions bar */
.no-print{background:#1e3a5f;padding:0.75rem 2.5rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap}
.btn-print{background:white;color:#1e3a5f;border:none;border-radius:6px;padding:0.5rem 1.25rem;font-weight:700;cursor:pointer;font-size:0.85rem}
.btn-back{background:rgba(255,255,255,0.15);color:white;border:1px solid rgba(255,255,255,0.4);border-radius:6px;padding:0.5rem 1.25rem;font-weight:600;cursor:pointer;font-size:0.85rem;text-decoration:none;display:inline-block}
.btn-process{background:#10b981;color:white;border:none;border-radius:6px;padding:0.5rem 1.4rem;font-weight:700;cursor:pointer;font-size:0.85rem;display:flex;align-items:center;gap:0.4rem}
.btn-process:disabled{opacity:0.6;cursor:not-allowed}
@media print{
    body{background:white}
    .no-print{display:none!important}
    .page{box-shadow:none;margin:0;max-width:none;overflow:visible;border-radius:0}
    .inv-table tr:hover td{background:transparent!important}
    .inv-head,.inv-table th,.status-banner,.totals-row.grand,.watermark{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    @page{margin:10mm 12mm}
}
</style>
</head>
<body>

<!-- Actions bar -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
    <a class="btn-back" href="javascript:history.back()">← Back</a>
    <?php if (!$isProcessed): ?>
    <button class="btn-process" id="btnProcess" onclick="processInvoice(<?php echo $inv['id']; ?>)">
        ✅ Process Invoice
        <?php if ((float)$inv['paid_amount'] > 0): ?>
        <span style="font-size:0.75rem;opacity:0.85">(Rp <?php echo number_format($inv['paid_amount'],0,',','.'); ?> → Buku Kas)</span>
        <?php else: ?>
        <span style="font-size:0.75rem;opacity:0.85">(No payment yet)</span>
        <?php endif; ?>
    </button>
    <?php else: ?>
    <span style="color:#86efac;font-size:0.82rem;font-weight:600">✓ Processed &amp; recorded in Buku Kas</span>
    <?php endif; ?>
    <span style="color:rgba(255,255,255,0.6);font-size:0.78rem;margin-left:auto"><?php echo htmlspecialchars($inv['invoice_number']); ?></span>
</div>

<div class="page">

<?php
// Watermark logic
if (!$isProcessed): ?>
<div class="watermark wm-unpaid">UNPAID</div>
<?php elseif ($inv['payment_status'] === 'paid'): ?>
<div class="watermark wm-paid">PAID</div>
<?php elseif ($inv['payment_status'] === 'partial'): ?>
<div class="watermark wm-partial">PARTIAL</div>
<?php endif; ?>

    <!-- Header -->
    <div class="inv-head">
        <?php if ($companyLogo): ?>
        <div class="logo-wrap"><img src="<?php echo htmlspecialchars($companyLogo); ?>" alt="Logo"></div>
        <?php endif; ?>
        <div class="company-block">
            <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="company-website"><?php echo htmlspecialchars($companyWebsite); ?></div>
            <div class="company-sub">
                <?php echo nl2br(htmlspecialchars($companyAddress)); ?>
                <?php if ($companyPhone): ?><br>📞 <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
                <?php if ($companyEmail): ?><br>✉️ <?php echo htmlspecialchars($companyEmail); ?><?php endif; ?>
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
                <?php if ($taxRate > 0): ?>
                <div class="totals-row">
                    <span style="color:#64748b">Subtotal</span>
                    <span>Rp <?php echo number_format($subtotal,0,',','.'); ?></span>
                </div>
                <div class="totals-row" style="background:#fff8e1">
                    <span style="color:#92400e">PPN (<?php echo rtrim(rtrim(number_format($taxRate,2),'0'),'.'); ?>%)</span>
                    <span style="color:#92400e;font-weight:700">Rp <?php echo number_format($taxAmount,0,',','.'); ?></span>
                </div>
                <?php endif; ?>
                <div class="totals-row grand">
                    <span>GRAND TOTAL</span>
                    <span>Rp <?php echo number_format($inv['total'],0,',','.'); ?></span>
                </div>
                <div class="totals-row">
                    <span><?php echo (float)$inv['paid_amount'] < (float)$inv['total'] && (float)$inv['paid_amount'] > 0 ? 'DP / Down Payment' : 'Paid'; ?></span>
                    <span style="color:#10b981;font-weight:700">Rp <?php echo number_format($inv['paid_amount'],0,',','.'); ?></span>
                </div>
                <?php $balance = $inv['total'] - $inv['paid_amount']; if ($balance > 0): ?>
                <div class="totals-row balance">
                    <span>Sisa / Balance Due</span>
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
        <span class="web"><?php echo htmlspecialchars($companyWebsite); ?></span>
        Thank you for choosing our services. We look forward to serving you again!<br>
        <?php if ($companyPhone||$companyEmail): ?>
        Contact: <?php echo htmlspecialchars(implode(' | ', array_filter([$companyPhone, $companyEmail]))); ?>
        <?php endif; ?>
    </div>
</div><!-- /page -->

<script>
function processInvoice(id) {
    const btn = document.getElementById('btnProcess');
    if (!btn) return;
    if (!confirm('Process this invoice? Payment will be recorded in Buku Kas.')) return;
    btn.disabled = true;
    btn.textContent = '⏳ Processing...';
    const fd = new FormData();
    fd.append('action', 'process_invoice');
    fd.append('id', id);
    fetch('<?php echo BASE_URL; ?>/modules/frontdesk/hotel-services.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                btn.textContent = '✓ Done!';
                setTimeout(() => location.reload(), 800);
            } else {
                alert('Error: ' + (d.message || 'Unknown error'));
                btn.disabled = false;
                btn.textContent = '✅ Process Invoice';
            }
        })
        .catch(() => { alert('Network error'); btn.disabled = false; });
}
</script>

</body>
</html>
