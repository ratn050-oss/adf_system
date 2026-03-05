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
body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef1f5;color:#1e293b;font-size:13px}
.page{width:100%;max-width:760px;margin:18px auto;background:#fff;box-shadow:0 2px 20px rgba(0,0,0,0.09);position:relative;overflow:hidden;border-radius:6px}

/* ── Header ───────────────────────────────────────────────────────── */
.inv-head{
    display:grid;
    grid-template-columns:auto 1fr auto;
    align-items:center;
    gap:1rem;
    padding:1.1rem 1.75rem 1rem;
    border-bottom:2px solid #1e3a5f;
    background:#fff;
}
.logo-wrap img{height:46px;width:auto;object-fit:contain;display:block}
.logo-placeholder{width:46px;height:46px;background:#1e3a5f;border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-weight:900;font-size:1.1rem;letter-spacing:0.05em}
.company-block{}
.company-name{font-size:1.05rem;font-weight:800;color:#1e3a5f;letter-spacing:0.02em;line-height:1.2}
.company-sub{font-size:0.7rem;color:#64748b;line-height:1.55;margin-top:0.2rem}
.company-sub .website{color:#6366f1;font-style:italic}
.inv-ref{text-align:right}
.inv-ref .inv-word{font-size:0.65rem;font-weight:700;letter-spacing:0.2em;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:0.3rem}
.inv-ref .inv-num{font-size:0.82rem;font-weight:800;color:#fff;background:#1e3a5f;padding:0.3rem 0.85rem;border-radius:20px;display:inline-block;letter-spacing:0.03em}
.inv-ref .inv-date{font-size:0.68rem;color:#94a3b8;display:block;margin-top:0.3rem}

/* ── Status stripe ────────────────────────────────────────────────── */
.status-stripe{padding:0.3rem 1.75rem;font-size:0.68rem;font-weight:700;letter-spacing:0.12em;color:white;display:flex;align-items:center;justify-content:center;gap:0.4rem}
.st-paid{background:#059669}.st-partial{background:#d97706}.st-unpaid{background:#dc2626}

/* ── Body ─────────────────────────────────────────────────────────── */
.inv-body{padding:1.4rem 1.75rem}

/* ── Guest + Invoice meta two-col ────────────────────────────────── */
.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.4rem}
.meta-box{}
.meta-label{font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.45rem;padding-bottom:0.3rem;border-bottom:1px solid #f1f5f9}
.meta-row{display:flex;justify-content:space-between;align-items:baseline;gap:0.5rem;padding:0.22rem 0;font-size:0.78rem;border-bottom:1px dashed #f8fafc}
.meta-row:last-child{border-bottom:none}
.meta-row .mk{color:#64748b;font-size:0.73rem}
.meta-row .mv{font-weight:600;color:#1e293b;text-align:right}
.meta-row .mv.highlight{color:#1e3a5f;font-weight:800}

/* ── Divider ────────────────────────────────────────────────────────*/
.section-head{display:flex;align-items:center;gap:0.5rem;margin-bottom:0.6rem}
.section-head span{font-size:0.6rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap}
.section-head::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* ── Items table ─────────────────────────────────────────────────── */
.inv-table{width:100%;border-collapse:collapse;margin-bottom:1.25rem;font-size:0.78rem}
.inv-table thead tr{background:#1e3a5f}
.inv-table th{color:#fff;padding:0.5rem 0.7rem;font-size:0.65rem;font-weight:600;letter-spacing:0.06em;text-align:left}
.inv-table th.r{text-align:right}
.inv-table td{padding:0.55rem 0.7rem;border-bottom:1px solid #f1f5f9;vertical-align:top;color:#374151}
.inv-table td.r{text-align:right;font-weight:600;color:#1e293b}
.inv-table tbody tr:last-child td{border-bottom:none}
.inv-table tbody tr:nth-child(even) td{background:#fafbff}
.svc-pill{display:inline-flex;align-items:center;gap:0.25rem;background:#eef2ff;color:#4338ca;padding:0.12rem 0.5rem;border-radius:10px;font-size:0.68rem;font-weight:700;white-space:nowrap}

/* ── Totals ──────────────────────────────────────────────────────── */
.totals-wrap{display:flex;justify-content:flex-end;margin-bottom:1.25rem}
.totals-box{width:270px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:0.78rem}
.t-row{display:flex;justify-content:space-between;align-items:center;padding:0.45rem 0.9rem;border-bottom:1px solid #f1f5f9}
.t-row:last-child{border-bottom:none}
.t-row .tk{color:#64748b}
.t-row .tv{font-weight:600;color:#1e293b}
.t-row.t-tax{background:#fffbeb}
.t-row.t-tax .tk{color:#92400e}
.t-row.t-tax .tv{color:#b45309;font-weight:700}
.t-row.t-grand{background:#1e3a5f;padding:0.6rem 0.9rem}
.t-row.t-grand .tk{color:rgba(255,255,255,0.75);font-size:0.68rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase}
.t-row.t-grand .tv{color:#fff;font-size:1rem;font-weight:900}
.t-row.t-paid .tv{color:#059669;font-weight:700}
.t-row.t-balance{background:#fef3c7}
.t-row.t-balance .tk{color:#92400e;font-weight:700}
.t-row.t-balance .tv{color:#b45309;font-weight:800}

/* ── Notes ────────────────────────────────────────────────────────── */
.notes-box{background:#f8fafc;border-left:3px solid #6366f1;padding:0.6rem 0.9rem;border-radius:0 5px 5px 0;margin-bottom:1.25rem;font-size:0.78rem;color:#374151}
.notes-box strong{display:block;font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.25rem}

/* ── Footer ───────────────────────────────────────────────────────── */
.inv-foot{background:#f8fafc;border-top:1px solid #e2e8f0;padding:0.85rem 1.75rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
.inv-foot .foot-left{font-size:0.7rem;color:#64748b;line-height:1.55}
.inv-foot .foot-left strong{color:#1e3a5f;font-size:0.75rem;display:block}
.inv-foot .foot-right{text-align:right;font-size:0.68rem;color:#94a3b8}
.inv-foot .foot-right .web{color:#6366f1;font-style:italic;font-size:0.7rem}

/* ── Watermark ────────────────────────────────────────────────────── */
.watermark{position:absolute;top:44%;left:50%;transform:translate(-50%,-50%) rotate(-28deg);font-size:5rem;font-weight:900;pointer-events:none;z-index:10;letter-spacing:0.12em;white-space:nowrap;user-select:none}
.wm-unpaid{color:rgba(220,38,38,0.07)}
.wm-paid{color:rgba(5,150,105,0.07)}
.wm-partial{color:rgba(217,119,6,0.07)}

/* ── No-print bar ─────────────────────────────────────────────────── */
.no-print{background:#1e3a5f;padding:0.6rem 1.75rem;display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap}
.btn-print{background:#fff;color:#1e3a5f;border:none;border-radius:5px;padding:0.4rem 1rem;font-weight:700;cursor:pointer;font-size:0.8rem}
.btn-back{background:rgba(255,255,255,0.12);color:rgba(255,255,255,0.85);border:1px solid rgba(255,255,255,0.3);border-radius:5px;padding:0.4rem 1rem;font-weight:600;cursor:pointer;font-size:0.8rem;text-decoration:none;display:inline-block}
.btn-process{background:#10b981;color:white;border:none;border-radius:5px;padding:0.4rem 1.1rem;font-weight:700;cursor:pointer;font-size:0.8rem;display:flex;align-items:center;gap:0.35rem}
.btn-process:disabled{opacity:0.55;cursor:not-allowed}

@media print{
    body{background:white}
    .no-print{display:none!important}
    .page{box-shadow:none;margin:0;max-width:none;overflow:visible;border-radius:0}
    .inv-table tbody tr:nth-child(even) td{background:transparent!important}
    .inv-head,.inv-table thead tr,.status-stripe,.t-row.t-grand,.watermark{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    @page{margin:8mm 10mm}
}
</style>
</head>
<body>

<!-- Actions bar -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
    <a class="btn-back" href="javascript:history.back()">← Kembali</a>
    <?php if (!$isProcessed): ?>
    <button class="btn-process" id="btnProcess" onclick="processInvoice(<?php echo $inv['id']; ?>)">
        ✅ Proses Invoice
        <span style="font-size:0.72rem;opacity:0.85">
        <?php echo (float)$inv['paid_amount'] > 0 ? '(Rp '.number_format($inv['paid_amount'],0,',','.').' → Buku Kas)' : '(Belum ada pembayaran)'; ?>
        </span>
    </button>
    <?php else: ?>
    <span style="color:#6ee7b7;font-size:0.78rem;font-weight:600">✓ Sudah diproses &amp; dicatat di Buku Kas</span>
    <?php endif; ?>
    <span style="color:rgba(255,255,255,0.45);font-size:0.72rem;margin-left:auto"><?php echo htmlspecialchars($inv['invoice_number']); ?></span>
</div>

<div class="page">

<?php if (!$isProcessed): ?>
<div class="watermark wm-unpaid">UNPAID</div>
<?php elseif ($inv['payment_status'] === 'paid'): ?>
<div class="watermark wm-paid">PAID</div>
<?php elseif ($inv['payment_status'] === 'partial'): ?>
<div class="watermark wm-partial">PARTIAL</div>
<?php endif; ?>

    <!-- ── Header ── -->
    <div class="inv-head">
        <div class="logo-wrap">
            <?php if ($companyLogo): ?>
            <img src="<?php echo htmlspecialchars($companyLogo); ?>" alt="Logo">
            <?php else: ?>
            <div class="logo-placeholder"><?php echo mb_substr($companyName,0,1); ?></div>
            <?php endif; ?>
        </div>
        <div class="company-block">
            <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="company-sub">
                <?php if ($companyAddress): ?><?php echo htmlspecialchars($companyAddress); ?><?php endif; ?>
                <?php if ($companyPhone): ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
                <?php if ($companyEmail): ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($companyEmail); ?><?php endif; ?>
                <?php if ($companyWebsite): ?><br><span class="website"><?php echo htmlspecialchars($companyWebsite); ?></span><?php endif; ?>
            </div>
        </div>
        <div class="inv-ref">
            <span class="inv-word">Invoice</span>
            <span class="inv-num"><?php echo htmlspecialchars($inv['invoice_number']); ?></span>
            <span class="inv-date"><?php echo date('d F Y', strtotime($inv['created_at'])); ?></span>
        </div>
    </div>

    <!-- ── Status stripe ── -->
    <?php
    $stripeClass = ['paid'=>'st-paid','partial'=>'st-partial','unpaid'=>'st-unpaid'][$inv['payment_status']] ?? 'st-unpaid';
    $stripeText  = ['paid'=>'✓ LUNAS','partial'=>'⚡ DIBAYAR SEBAGIAN — MASIH ADA SISA','unpaid'=>'✕ BELUM DIBAYAR'][$inv['payment_status']] ?? 'BELUM DIBAYAR';
    ?>
    <div class="status-stripe <?php echo $stripeClass; ?>"><?php echo $stripeText; ?></div>

    <div class="inv-body">

        <!-- ── Meta grid ── -->
        <div class="meta-grid">
            <div class="meta-box">
                <div class="meta-label">Tagihan Kepada</div>
                <div class="meta-row"><span class="mk">Nama Tamu</span><span class="mv highlight"><?php echo htmlspecialchars($inv['guest_name']); ?></span></div>
                <?php if ($inv['guest_phone']): ?>
                <div class="meta-row"><span class="mk">Telepon</span><span class="mv"><?php echo htmlspecialchars($inv['guest_phone']); ?></span></div>
                <?php endif; ?>
                <?php if ($inv['room_number']): ?>
                <div class="meta-row"><span class="mk">Kamar</span><span class="mv"><?php echo htmlspecialchars($inv['room_number']); ?></span></div>
                <?php endif; ?>
            </div>
            <div class="meta-box">
                <div class="meta-label">Detail Invoice</div>
                <div class="meta-row"><span class="mk">No. Invoice</span><span class="mv highlight"><?php echo htmlspecialchars($inv['invoice_number']); ?></span></div>
                <div class="meta-row"><span class="mk">Tanggal</span><span class="mv"><?php echo date('d M Y', strtotime($inv['created_at'])); ?></span></div>
                <div class="meta-row"><span class="mk">Metode Bayar</span><span class="mv"><?php echo ucfirst($inv['payment_method']); ?></span></div>
                <div class="meta-row"><span class="mk">Status</span><span class="mv"><?php echo ucfirst($inv['status']); ?></span></div>
            </div>
        </div>

        <!-- ── Items ── -->
        <div class="section-head"><span>Layanan / Services</span></div>
        <table class="inv-table">
            <thead>
                <tr>
                    <th style="width:28px">#</th>
                    <th style="min-width:110px">Tipe</th>
                    <th>Deskripsi</th>
                    <th class="r" style="width:42px">Qty</th>
                    <th class="r" style="width:105px">Harga Satuan</th>
                    <th class="r" style="width:105px">Jumlah</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowNo = 1; foreach ($items as $item):
                $svcInfo = $serviceLabels[$item['service_type']] ?? ['label'=>$item['service_type'],'icon'=>''];
            ?>
            <tr>
                <td style="color:#cbd5e1;font-size:0.7rem;text-align:center"><?php echo $rowNo++; ?></td>
                <td><span class="svc-pill"><?php echo $svcInfo['icon']; ?> <?php echo $svcInfo['label']; ?></span></td>
                <td>
                    <?php echo htmlspecialchars($item['description'] ?? ''); ?>
                    <?php if ($item['start_datetime']): ?>
                    <div style="font-size:0.68rem;color:#94a3b8;margin-top:2px">
                        <?php echo date('d M Y', strtotime($item['start_datetime'])); ?>
                        <?php if ($item['end_datetime']): ?> – <?php echo date('d M Y', strtotime($item['end_datetime'])); ?><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="r" style="color:#64748b"><?php echo rtrim(rtrim(number_format($item['quantity'],2),'0'),'.'); ?></td>
                <td class="r" style="color:#64748b">Rp <?php echo number_format($item['unit_price'],0,',','.'); ?></td>
                <td class="r">Rp <?php echo number_format($item['total_price'],0,',','.'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ── Totals ── -->
        <div class="totals-wrap">
            <div class="totals-box">
                <?php if ($taxRate > 0): ?>
                <div class="t-row">
                    <span class="tk">Subtotal</span>
                    <span class="tv">Rp <?php echo number_format($subtotal,0,',','.'); ?></span>
                </div>
                <div class="t-row t-tax">
                    <span class="tk">PPN <?php echo rtrim(rtrim(number_format($taxRate,2),'0'),'.'); ?>%</span>
                    <span class="tv">Rp <?php echo number_format($taxAmount,0,',','.'); ?></span>
                </div>
                <?php endif; ?>
                <div class="t-row t-grand">
                    <span class="tk">Grand Total</span>
                    <span class="tv">Rp <?php echo number_format($inv['total'],0,',','.'); ?></span>
                </div>
                <div class="t-row t-paid">
                    <span class="tk"><?php echo ((float)$inv['paid_amount'] < (float)$inv['total'] && (float)$inv['paid_amount'] > 0) ? 'DP / Down Payment' : 'Dibayar'; ?></span>
                    <span class="tv">Rp <?php echo number_format($inv['paid_amount'],0,',','.'); ?></span>
                </div>
                <?php $balance = $inv['total'] - $inv['paid_amount']; if ($balance > 0): ?>
                <div class="t-row t-balance">
                    <span class="tk">Sisa Tagihan</span>
                    <span class="tv">Rp <?php echo number_format($balance,0,',','.'); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Notes ── -->
        <?php if ($inv['notes']): ?>
        <div class="notes-box">
            <strong>Catatan</strong>
            <?php echo nl2br(htmlspecialchars($inv['notes'])); ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── Footer ── -->
    <div class="inv-foot">
        <div class="foot-left">
            <strong><?php echo htmlspecialchars($companyName); ?></strong>
            <?php echo htmlspecialchars($companyAddress); ?>
            <?php if ($companyPhone || $companyEmail): ?>
            <br><?php echo htmlspecialchars(implode('  ·  ', array_filter([$companyPhone, $companyEmail]))); ?>
            <?php endif; ?>
        </div>
        <div class="foot-right">
            <span class="web"><?php echo htmlspecialchars($companyWebsite); ?></span><br>
            Terima kasih atas kepercayaan Anda.<br>
            <span style="font-size:0.65rem">Dokumen ini dicetak <?php echo date('d M Y H:i'); ?></span>
        </div>
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
