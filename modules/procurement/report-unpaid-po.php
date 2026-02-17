<?php
/**
 * Laporan PO Belum Dibayar (Unpaid Purchase Orders)
 * Untuk pengajuan pembayaran tagihan ke Owner
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Laporan PO Belum Dibayar';

// Get filters
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01', strtotime('-3 months'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'due_date';

// Get suppliers for filter
$suppliers = $db->fetchAll("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");

// Get unpaid POs - cek dari purchases_header (invoice) bukan PO header
$where = "ph.payment_status = 'unpaid' OR (ph.payment_status = 'partial' AND ph.paid_amount < ph.grand_total)";
$params = [];

if ($supplier_id > 0) {
    $where .= " AND ph.supplier_id = :supplier_id";
    $params['supplier_id'] = $supplier_id;
}
if ($date_from) {
    $where .= " AND ph.invoice_date >= :date_from";
    $params['date_from'] = $date_from;
}
if ($date_to) {
    $where .= " AND ph.invoice_date <= :date_to";
    $params['date_to'] = $date_to;
}

$order_by = match($sort_by) {
    'amount_desc' => 'ph.grand_total DESC',
    'amount_asc' => 'ph.grand_total ASC',
    'oldest' => 'ph.invoice_date ASC',
    'newest' => 'ph.invoice_date DESC',
    'supplier' => 's.supplier_name ASC, ph.invoice_date ASC',
    default => 'COALESCE(ph.due_date, ph.invoice_date) ASC'
};

$unpaidInvoices = $db->fetchAll("
    SELECT 
        ph.id,
        ph.invoice_number,
        ph.po_id,
        ph.invoice_date,
        ph.due_date,
        ph.total_amount,
        ph.discount_amount,
        ph.tax_amount,
        ph.grand_total,
        ph.paid_amount,
        (ph.grand_total - COALESCE(ph.paid_amount, 0)) as remaining,
        ph.payment_status,
        ph.notes,
        s.supplier_name,
        s.contact_person,
        s.phone,
        s.bank_name,
        s.bank_account_number,
        s.bank_account_name,
        po.po_number,
        DATEDIFF(CURDATE(), COALESCE(ph.due_date, ph.invoice_date)) as days_overdue
    FROM purchases_header ph
    JOIN suppliers s ON ph.supplier_id = s.id
    LEFT JOIN purchase_orders_header po ON ph.po_id = po.id
    WHERE {$where}
    ORDER BY {$order_by}
", $params);

// Calculate totals
$totalUnpaid = 0;
$totalPaid = 0;
$totalRemaining = 0;
$countOverdue = 0;

foreach ($unpaidInvoices as $inv) {
    $totalUnpaid += $inv['grand_total'];
    $totalPaid += $inv['paid_amount'] ?? 0;
    $totalRemaining += $inv['remaining'];
    if ($inv['days_overdue'] > 0) $countOverdue++;
}

// Group by supplier for summary
$supplierSummary = [];
foreach ($unpaidInvoices as $inv) {
    $sname = $inv['supplier_name'];
    if (!isset($supplierSummary[$sname])) {
        $supplierSummary[$sname] = ['count' => 0, 'total' => 0];
    }
    $supplierSummary[$sname]['count']++;
    $supplierSummary[$sname]['total'] += $inv['remaining'];
}
arsort($supplierSummary);

// Get business info for header
$businessInfo = $db->fetchOne("SELECT * FROM business_settings WHERE id = 1");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= BUSINESS_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --danger: #dc2626;
            --warning: #f59e0b;
            --success: #10b981;
        }
        
        body { 
            background: #f8fafc; 
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        .report-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Print Header */
        .print-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3730a3 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        
        .print-header::before {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }
        
        .print-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }
        
        .print-header .subtitle {
            opacity: 0.9;
            font-size: 0.95rem;
            margin-top: 8px;
        }
        
        .print-header .report-date {
            position: absolute;
            top: 30px; right: 30px;
            text-align: right;
            font-size: 0.85rem;
        }
        
        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary);
        }
        
        .summary-card.danger { border-left-color: var(--danger); }
        .summary-card.warning { border-left-color: var(--warning); }
        .summary-card.success { border-left-color: var(--success); }
        
        .summary-card .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 8px;
        }
        
        .summary-card .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .summary-card.danger .value { color: var(--danger); }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        /* Table */
        .invoice-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .invoice-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        
        .invoice-table th {
            background: #f1f5f9;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        
        .invoice-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }
        
        .invoice-table tr:hover {
            background: #f8fafc;
        }
        
        .invoice-table .text-right { text-align: right; }
        .invoice-table .text-center { text-align: center; }
        
        .amount { 
            font-family: 'Consolas', monospace; 
            font-weight: 600;
        }
        
        .amount.large {
            font-size: 1rem;
            color: var(--danger);
        }
        
        /* Status Badges */
        .badge-overdue {
            background: #fef2f2;
            color: #dc2626;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-due-soon {
            background: #fffbeb;
            color: #d97706;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-normal {
            background: #f0fdf4;
            color: #16a34a;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* Supplier Info */
        .supplier-info {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 4px;
        }
        
        .bank-info {
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 0.75rem;
        }
        
        /* Summary by Supplier */
        .supplier-summary {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .supplier-summary h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1e293b;
        }
        
        .supplier-summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        /* Print Styles */
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .report-container { max-width: 100%; padding: 0; }
            .print-header { 
                background: #1e3a8a !important; 
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .summary-card { 
                break-inside: avoid;
                border-left-width: 4px !important;
            }
            .invoice-table { box-shadow: none; }
            .invoice-table th { 
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
            }
            @page { margin: 1cm; }
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59,130,246,0.3);
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .btn-pdf:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239,68,68,0.3);
        }
        
        .btn-back {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-back:hover {
            background: #e2e8f0;
        }
        
        /* Total Footer */
        .total-footer {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            padding: 20px;
            border-radius: 0 0 12px 12px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }
        
        .total-row.grand {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--danger);
            border-top: 2px solid #fecaca;
            padding-top: 16px;
            margin-top: 8px;
        }
        
        /* Signature Section */
        .signature-section {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 30px;
            text-align: center;
        }
        
        .signature-box {
            padding: 20px;
        }
        
        .signature-box .title {
            font-weight: 600;
            color: #475569;
            margin-bottom: 60px;
        }
        
        .signature-box .line {
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
            font-size: 0.875rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="report-container">
        <!-- Action Buttons -->
        <div class="action-buttons no-print">
            <a href="purchase-orders.php" class="btn-action btn-back">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
            <button onclick="window.print()" class="btn-action btn-print">
                <i class="fas fa-print"></i> Print Laporan
            </button>
            <button onclick="savePDF()" class="btn-action btn-pdf">
                <i class="fas fa-file-pdf"></i> Save PDF
            </button>
        </div>
        
        <!-- Print Header -->
        <div class="print-header">
            <div class="report-date">
                <div>Dicetak: <?= date('d/m/Y H:i') ?></div>
                <div>Periode: <?= date('d/m/Y', strtotime($date_from)) ?> - <?= date('d/m/Y', strtotime($date_to)) ?></div>
            </div>
            <h1><i class="fas fa-file-invoice-dollar me-2"></i><?= BUSINESS_NAME ?></h1>
            <div class="subtitle">
                <strong>LAPORAN TAGIHAN BELUM DIBAYAR</strong><br>
                Pengajuan Pembayaran ke Owner
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section no-print">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Supplier</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">Semua Supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $supplier_id == $s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Dari Tanggal</label>
                    <input type="date" name="date_from" value="<?= $date_from ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="<?= $date_to ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Urutkan</label>
                    <select name="sort_by" class="form-select">
                        <option value="due_date" <?= $sort_by == 'due_date' ? 'selected' : '' ?>>Jatuh Tempo</option>
                        <option value="oldest" <?= $sort_by == 'oldest' ? 'selected' : '' ?>>Tanggal (Lama)</option>
                        <option value="newest" <?= $sort_by == 'newest' ? 'selected' : '' ?>>Tanggal (Baru)</option>
                        <option value="amount_desc" <?= $sort_by == 'amount_desc' ? 'selected' : '' ?>>Nominal (Besar)</option>
                        <option value="supplier" <?= $sort_by == 'supplier' ? 'selected' : '' ?>>Supplier</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card danger">
                <div class="label">Total Tagihan</div>
                <div class="value">Rp <?= number_format($totalRemaining, 0, ',', '.') ?></div>
            </div>
            <div class="summary-card warning">
                <div class="label">Jumlah Invoice</div>
                <div class="value"><?= count($unpaidInvoices) ?> Invoice</div>
            </div>
            <div class="summary-card">
                <div class="label">Jumlah Supplier</div>
                <div class="value"><?= count($supplierSummary) ?> Supplier</div>
            </div>
            <div class="summary-card danger">
                <div class="label">Sudah Jatuh Tempo</div>
                <div class="value"><?= $countOverdue ?> Invoice</div>
            </div>
        </div>
        
        <!-- Invoice Table -->
        <div class="invoice-table">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">No</th>
                        <th>No. Invoice / PO</th>
                        <th>Supplier</th>
                        <th>Tanggal</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-right">Total Tagihan</th>
                        <th class="text-right">Sudah Dibayar</th>
                        <th class="text-right">Sisa Tagihan</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unpaidInvoices)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                                <p class="mb-0">Tidak ada tagihan yang belum dibayar.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($unpaidInvoices as $inv): ?>
                            <tr>
                                <td class="text-center"><?= $no++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                    <?php if ($inv['po_number']): ?>
                                        <br><small class="text-muted">PO: <?= htmlspecialchars($inv['po_number']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($inv['supplier_name']) ?></strong>
                                    <?php if ($inv['bank_account_number']): ?>
                                        <div class="bank-info">
                                            <i class="fas fa-university me-1"></i>
                                            <?= htmlspecialchars($inv['bank_name']) ?>: 
                                            <strong><?= htmlspecialchars($inv['bank_account_number']) ?></strong>
                                            <br>a.n. <?= htmlspecialchars($inv['bank_account_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                                <td>
                                    <?php if ($inv['due_date']): ?>
                                        <?= date('d/m/Y', strtotime($inv['due_date'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right amount">
                                    Rp <?= number_format($inv['grand_total'], 0, ',', '.') ?>
                                </td>
                                <td class="text-right amount text-success">
                                    <?php if ($inv['paid_amount'] > 0): ?>
                                        Rp <?= number_format($inv['paid_amount'], 0, ',', '.') ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-right amount large">
                                    Rp <?= number_format($inv['remaining'], 0, ',', '.') ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($inv['days_overdue'] > 7): ?>
                                        <span class="badge-overdue">
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            <?= $inv['days_overdue'] ?> hari
                                        </span>
                                    <?php elseif ($inv['days_overdue'] > 0): ?>
                                        <span class="badge-due-soon">
                                            Lewat <?= $inv['days_overdue'] ?> hari
                                        </span>
                                    <?php elseif ($inv['days_overdue'] >= -7): ?>
                                        <span class="badge-due-soon">
                                            <?= abs($inv['days_overdue']) ?> hari lagi
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-normal">
                                            <?= abs($inv['days_overdue']) ?> hari lagi
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if (!empty($unpaidInvoices)): ?>
                <div class="total-footer">
                    <div class="total-row">
                        <span>Total Tagihan (<?= count($unpaidInvoices) ?> invoice)</span>
                        <span class="amount">Rp <?= number_format($totalUnpaid, 0, ',', '.') ?></span>
                    </div>
                    <?php if ($totalPaid > 0): ?>
                        <div class="total-row">
                            <span>Sudah Dibayar</span>
                            <span class="amount text-success">- Rp <?= number_format($totalPaid, 0, ',', '.') ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="total-row grand">
                        <span><i class="fas fa-coins me-2"></i>TOTAL YANG HARUS DIBAYAR</span>
                        <span>Rp <?= number_format($totalRemaining, 0, ',', '.') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Summary by Supplier -->
        <?php if (!empty($supplierSummary)): ?>
            <div class="supplier-summary">
                <h3><i class="fas fa-building me-2"></i>Ringkasan per Supplier</h3>
                <?php foreach ($supplierSummary as $sname => $data): ?>
                    <div class="supplier-summary-item">
                        <span>
                            <strong><?= htmlspecialchars($sname) ?></strong>
                            <small class="text-muted">(<?= $data['count'] ?> invoice)</small>
                        </span>
                        <span class="amount" style="color: var(--danger);">
                            Rp <?= number_format($data['total'], 0, ',', '.') ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Signature Section (for print) -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="title">Dibuat Oleh</div>
                <div class="line"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></div>
            </div>
            <div class="signature-box">
                <div class="title">Disetujui Oleh</div>
                <div class="line">Manager</div>
            </div>
            <div class="signature-box">
                <div class="title">Diotorisasi Oleh</div>
                <div class="line">Owner</div>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="mt-4 p-3 bg-light rounded" style="font-size: 0.8rem; color: #64748b;">
            <strong>Catatan:</strong>
            <ul class="mb-0 mt-2">
                <li>Dokumen ini dibuat sebagai lampiran pengajuan pembayaran tagihan kepada Owner.</li>
                <li>Mohon segera melakukan pembayaran untuk tagihan yang sudah jatuh tempo.</li>
                <li>Pembayaran dapat dilakukan ke rekening masing-masing supplier yang tertera.</li>
            </ul>
        </div>
    </div>
    
    <script>
        // Save as PDF using browser print
        function savePDF() {
            // Set document title for PDF filename
            const originalTitle = document.title;
            document.title = 'Laporan_Tagihan_Belum_Dibayar_<?= date('Ymd') ?>';
            
            window.print();
            
            // Restore title after print dialog
            setTimeout(() => {
                document.title = originalTitle;
            }, 1000);
        }
    </script>
</body>
</html>
