<?php
/**
 * BILLS MODULE - Laporan Pencairan Tagihan
 * Generate report for requesting fund disbursement from owner
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();
require_once __DIR__ . '/auto-migrate.php';

$pageTitle = 'Laporan Pencairan Tagihan';
$pageSubtitle = 'Ajukan pencairan dana untuk pembayaran tagihan';

// Get current month filter
$filterMonth = getGet('month', date('Y-m'));

// Get unpaid bills (pending + overdue)
$bills = $db->fetchAll(
    "SELECT 
        br.*,
        bt.bill_name,
        bt.bill_category,
        bt.vendor_name,
        bt.account_number,
        bt.is_fixed_amount,
        d.division_name
    FROM bill_records br
    JOIN bill_templates bt ON br.template_id = bt.id
    LEFT JOIN divisions d ON bt.division_id = d.id
    WHERE br.bill_period = :month AND br.status IN ('pending', 'overdue')
    ORDER BY br.due_date ASC, bt.bill_name ASC",
    ['month' => $filterMonth]
);

$categories = [
    'electricity' => ['label' => 'Listrik', 'icon' => '⚡', 'color' => '#f59e0b'],
    'tax' => ['label' => 'Pajak', 'icon' => '🏛️', 'color' => '#8b5cf6'],
    'wifi' => ['label' => 'WiFi/Internet', 'icon' => '📶', 'color' => '#3b82f6'],
    'vehicle' => ['label' => 'Kendaraan', 'icon' => '🏍️', 'color' => '#06b6d4'],
    'po' => ['label' => 'Tagihan PO', 'icon' => '📦', 'color' => '#f97316'],
    'receivable' => ['label' => 'Piutang', 'icon' => '💳', 'color' => '#ec4899'],
    'other' => ['label' => 'Lainnya', 'icon' => '📋', 'color' => '#64748b'],
];

// Get business info
$businessInfo = null;
try {
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : 'adf_system');
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname={$masterDbName};charset=utf8mb4", DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $businessId = $_SESSION['business_id'] ?? 1;
    $stmt = $masterDb->prepare("SELECT * FROM businesses WHERE id = ?");
    $stmt->execute([$businessId]);
    $businessInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback
}

include '../../includes/header.php';
?>

<style>
/* Laporan Styles */
.laporan-container {
    max-width: 900px;
    margin: 0 auto;
}

.laporan-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.laporan-filter {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.laporan-filter input[type="month"],
.laporan-filter select {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 0.75rem;
    padding: 0.45rem 0.65rem;
    border-radius: var(--radius-md);
    outline: none;
}

.laporan-filter .btn {
    padding: 0.45rem 0.85rem;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

/* Selection Table */
.select-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.78rem;
}

.select-table th {
    background: linear-gradient(135deg, var(--bg-secondary), var(--bg-tertiary));
    padding: 0.6rem 0.55rem;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--text-muted);
    border-bottom: 2px solid var(--bg-tertiary);
    text-align: left;
}

.select-table td {
    padding: 0.55rem;
    border-bottom: 1px solid var(--bg-tertiary);
    vertical-align: middle;
}

.select-table tbody tr {
    transition: var(--transition);
}

.select-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.04);
}

.select-table tbody tr.selected {
    background: rgba(99, 102, 241, 0.08);
}

.select-table tbody tr.row-overdue {
    background: rgba(239, 68, 68, 0.04);
}

.select-table .bill-check {
    width: 18px;
    height: 18px;
    accent-color: var(--primary-color);
    cursor: pointer;
}

.bill-cat-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-size: 0.62rem;
    font-weight: 700;
}

.bill-status-badge {
    display: inline-block;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
}

.bill-status-badge.pending { background: rgba(245, 158, 11, 0.15); color: #d97706; }
.bill-status-badge.overdue { background: rgba(239, 68, 68, 0.15); color: #dc2626; }

/* Summary Box */
.selection-summary {
    background: linear-gradient(135deg, var(--bg-secondary), var(--bg-tertiary));
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-lg);
    padding: 1rem 1.25rem;
    margin-top: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.summary-info {
    display: flex;
    gap: 2rem;
}

.summary-item {
    text-align: center;
}

.summary-label {
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 0.2rem;
}

.summary-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-heading);
}

.summary-value.total {
    color: var(--primary-color);
}

.summary-actions {
    display: flex;
    gap: 0.5rem;
}

.summary-actions .btn {
    padding: 0.55rem 1rem;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: var(--transition);
}

.summary-actions .btn-print {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
}

.summary-actions .btn-print:hover {
    box-shadow: var(--shadow-glow);
}

.summary-actions .btn-wa {
    background: #25d366;
    color: white;
}

/* Print Preview */
.print-preview {
    display: none;
}

/* Print Styles */
@media print {
    body * {
        visibility: hidden;
    }
    
    .print-area, .print-area * {
        visibility: visible;
    }
    
    .print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
        padding: 20px;
    }
    
    .no-print {
        display: none !important;
    }
}

/* Print Area Styles */
.print-area {
    background: white;
    color: #1a1a2e;
    padding: 1.5rem;
    border-radius: var(--radius-lg);
    margin-top: 1.25rem;
    border: 1px solid var(--bg-tertiary);
}

.print-header {
    text-align: center;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 1rem;
    margin-bottom: 1rem;
}

.print-logo {
    width: 60px;
    height: 60px;
    margin: 0 auto 0.5rem;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    font-weight: bold;
}

.print-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 0.25rem;
}

.print-subtitle {
    font-size: 0.75rem;
    color: #64748b;
}

.print-info {
    display: flex;
    justify-content: space-between;
    margin-bottom: 1rem;
    font-size: 0.75rem;
    color: #475569;
}

.print-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
    margin-bottom: 1rem;
}

.print-table th {
    background: #f1f5f9;
    padding: 0.5rem;
    text-align: left;
    font-weight: 600;
    border: 1px solid #e2e8f0;
    color: #1e293b;
}

.print-table td {
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
    color: #334155;
}

.print-table .text-right {
    text-align: right;
}

.print-table tfoot td {
    background: #f8fafc;
    font-weight: 700;
}

.print-footer {
    display: flex;
    justify-content: space-between;
    margin-top: 2rem;
    font-size: 0.72rem;
    color: #64748b;
}

.signature-box {
    text-align: center;
    min-width: 150px;
}

.signature-line {
    border-top: 1px solid #cbd5e1;
    margin-top: 3rem;
    padding-top: 0.5rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--text-muted);
}

.empty-state-icon {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .laporan-header { flex-direction: column; align-items: stretch; }
    .summary-info { flex-direction: column; gap: 0.75rem; }
    .selection-summary { flex-direction: column; text-align: center; }
}
</style>

<div class="laporan-container">
    <!-- Header -->
    <div class="card no-print" style="margin-bottom: 1.25rem;">
        <div class="laporan-header">
            <div>
                <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--text-heading); margin: 0;">
                    <i data-feather="file-text" style="width: 20px; height: 20px; display: inline; vertical-align: -3px;"></i>
                    Laporan Pencairan Tagihan
                </h2>
                <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0.2rem 0 0;">Pilih tagihan untuk diajukan pencairan dana ke owner</p>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <a href="<?= BASE_URL ?>/modules/bills/" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary); padding: 0.45rem 0.8rem; border-radius: var(--radius-md); font-size: 0.72rem; font-weight: 600; text-decoration: none;">
                    <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
                </a>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="card no-print" style="margin-bottom: 1.25rem;">
        <form method="GET" class="laporan-filter">
            <label style="font-size: 0.72rem; font-weight: 600; color: var(--text-secondary);">Periode:</label>
            <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>">
            <button type="submit" class="btn" style="background: var(--primary-color); color: white;">
                <i data-feather="filter" style="width: 13px; height: 13px;"></i> Filter
            </button>
        </form>
    </div>

    <?php if (empty($bills)): ?>
    <!-- Empty State -->
    <div class="card">
        <div class="empty-state">
            <div class="empty-state-icon">✅</div>
            <div style="font-size: 0.85rem; margin-bottom: 0.35rem;">Tidak ada tagihan yang perlu dibayar</div>
            <div style="font-size: 0.72rem;">Semua tagihan periode <?= date('F Y', strtotime($filterMonth . '-01')) ?> sudah lunas</div>
        </div>
    </div>
    <?php else: ?>
    <!-- Selection Table -->
    <div class="card no-print" style="padding: 0; overflow: hidden;">
        <div style="padding: 0.75rem 1rem; background: var(--bg-secondary); border-bottom: 1px solid var(--bg-tertiary); display: flex; align-items: center; justify-content: space-between;">
            <span style="font-size: 0.78rem; font-weight: 600; color: var(--text-heading);">
                Pilih Tagihan untuk Pencairan
            </span>
            <label style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; color: var(--text-secondary); cursor: pointer;">
                <input type="checkbox" id="selectAll" class="bill-check" onclick="toggleSelectAll(this)">
                Pilih Semua
            </label>
        </div>
        <div style="overflow-x: auto;">
            <table class="select-table">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">Pilih</th>
                        <th>Tagihan</th>
                        <th>Kategori</th>
                        <th>Jatuh Tempo</th>
                        <th>Status</th>
                        <th style="text-align: right;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bills as $bill): 
                        $catInfo = $categories[$bill['bill_category']] ?? $categories['other'];
                        $isOverdue = $bill['status'] === 'overdue';
                    ?>
                    <tr class="<?= $isOverdue ? 'row-overdue' : '' ?>" data-id="<?= $bill['id'] ?>" data-amount="<?= $bill['amount'] ?>">
                        <td style="text-align: center;">
                            <input type="checkbox" class="bill-check bill-item" 
                                   data-id="<?= $bill['id'] ?>" 
                                   data-name="<?= htmlspecialchars($bill['bill_name']) ?>"
                                   data-vendor="<?= htmlspecialchars($bill['vendor_name'] ?? '-') ?>"
                                   data-account="<?= htmlspecialchars($bill['account_number'] ?? '-') ?>"
                                   data-due="<?= date('d M Y', strtotime($bill['due_date'])) ?>"
                                   data-amount="<?= $bill['amount'] ?>"
                                   data-status="<?= $bill['status'] ?>"
                                   data-category="<?= $catInfo['label'] ?>"
                                   onchange="updateSelection()">
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-heading); font-size: 0.78rem;">
                                <?= htmlspecialchars($bill['bill_name']) ?>
                            </div>
                            <div style="font-size: 0.65rem; color: var(--text-muted);">
                                <?= htmlspecialchars($bill['vendor_name'] ?? '-') ?>
                                <?php if ($bill['account_number']): ?>
                                · No. <?= htmlspecialchars($bill['account_number']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="bill-cat-badge" style="background: <?= $catInfo['color'] ?>18; color: <?= $catInfo['color'] ?>;">
                                <?= $catInfo['icon'] ?> <?= $catInfo['label'] ?>
                            </span>
                        </td>
                        <td style="font-size: 0.75rem;">
                            <?= date('d M Y', strtotime($bill['due_date'])) ?>
                        </td>
                        <td>
                            <span class="bill-status-badge <?= $bill['status'] ?>">
                                <?= $bill['status'] === 'overdue' ? 'Lewat' : 'Pending' ?>
                            </span>
                        </td>
                        <td style="text-align: right; font-weight: 600; font-size: 0.78rem;">
                            <?= formatCurrency($bill['amount']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary -->
    <div class="selection-summary no-print">
        <div class="summary-info">
            <div class="summary-item">
                <div class="summary-label">Dipilih</div>
                <div class="summary-value" id="selectedCount">0 tagihan</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Total Pencairan</div>
                <div class="summary-value total" id="selectedTotal">Rp 0</div>
            </div>
        </div>
        <div class="summary-actions">
            <button type="button" class="btn btn-print" onclick="generateReport()" id="btnGenerate" disabled>
                <i data-feather="printer" style="width: 15px; height: 15px;"></i> Cetak Laporan
            </button>
            <button type="button" class="btn btn-wa" onclick="shareWhatsApp()" id="btnWa" disabled>
                <i data-feather="message-circle" style="width: 15px; height: 15px;"></i> Kirim WA
            </button>
        </div>
    </div>

    <!-- Print Area (hidden until generated) -->
    <div class="print-area" id="printArea" style="display: none;">
        <div class="print-header">
            <?php if ($businessInfo && !empty($businessInfo['logo'])): ?>
            <img src="<?= $businessInfo['logo'] ?>" alt="Logo" style="width: 60px; height: 60px; object-fit: contain; margin-bottom: 0.5rem;">
            <?php else: ?>
            <div class="print-logo"><?= substr($businessInfo['business_name'] ?? 'B', 0, 1) ?></div>
            <?php endif; ?>
            <h1 class="print-title"><?= htmlspecialchars($businessInfo['business_name'] ?? 'Business') ?></h1>
            <p class="print-subtitle"><?= htmlspecialchars($businessInfo['address'] ?? '') ?></p>
        </div>
        
        <div style="text-align: center; margin-bottom: 1rem;">
            <h2 style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0;">PENGAJUAN PENCAIRAN DANA</h2>
            <p style="font-size: 0.72rem; color: #64748b; margin: 0.25rem 0 0;">Pembayaran Tagihan Bulanan</p>
        </div>

        <div class="print-info">
            <div>
                <strong>Periode:</strong> <?= date('F Y', strtotime($filterMonth . '-01')) ?>
            </div>
            <div>
                <strong>Tanggal:</strong> <?= date('d M Y') ?>
            </div>
        </div>

        <table class="print-table">
            <thead>
                <tr>
                    <th style="width: 30px;">No</th>
                    <th>Tagihan</th>
                    <th>Kategori</th>
                    <th>Jatuh Tempo</th>
                    <th>No. Rekening/ID</th>
                    <th class="text-right">Nominal</th>
                </tr>
            </thead>
            <tbody id="printTableBody">
                <!-- Filled by JS -->
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="text-right"><strong>TOTAL PENCAIRAN</strong></td>
                    <td class="text-right" id="printTotal">Rp 0</td>
                </tr>
            </tfoot>
        </table>

        <div style="margin: 1.5rem 0; padding: 0.75rem; background: #f8fafc; border-radius: 6px; font-size: 0.72rem; color: #475569;">
            <strong>Catatan:</strong> Mohon pencairan dana untuk pembayaran tagihan di atas. Dana akan digunakan untuk operasional bisnis sesuai dengan tagihan yang tertera.
        </div>

        <div class="print-footer">
            <div class="signature-box">
                <div>Diajukan oleh,</div>
                <div class="signature-line"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff') ?></div>
            </div>
            <div class="signature-box">
                <div>Disetujui oleh,</div>
                <div class="signature-line">Owner</div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; font-size: 0.65rem; color: #94a3b8;">
            Dicetak dari ADF System — <?= date('d M Y H:i') ?> WIB
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let selectedBills = [];

function toggleSelectAll(checkbox) {
    const items = document.querySelectorAll('.bill-item');
    items.forEach(item => {
        item.checked = checkbox.checked;
    });
    updateSelection();
}

function updateSelection() {
    selectedBills = [];
    const items = document.querySelectorAll('.bill-item:checked');
    let total = 0;
    
    items.forEach(item => {
        selectedBills.push({
            id: item.dataset.id,
            name: item.dataset.name,
            vendor: item.dataset.vendor,
            account: item.dataset.account,
            due: item.dataset.due,
            amount: parseInt(item.dataset.amount),
            status: item.dataset.status,
            category: item.dataset.category
        });
        total += parseInt(item.dataset.amount);
    });
    
    document.getElementById('selectedCount').textContent = selectedBills.length + ' tagihan';
    document.getElementById('selectedTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
    
    const btnGenerate = document.getElementById('btnGenerate');
    const btnWa = document.getElementById('btnWa');
    btnGenerate.disabled = selectedBills.length === 0;
    btnWa.disabled = selectedBills.length === 0;
    
    // Update row highlight
    document.querySelectorAll('.select-table tbody tr').forEach(row => {
        const checkbox = row.querySelector('.bill-item');
        if (checkbox && checkbox.checked) {
            row.classList.add('selected');
        } else {
            row.classList.remove('selected');
        }
    });
    
    // Update selectAll checkbox
    const allItems = document.querySelectorAll('.bill-item');
    const checkedItems = document.querySelectorAll('.bill-item:checked');
    document.getElementById('selectAll').checked = allItems.length > 0 && allItems.length === checkedItems.length;
}

function generateReport() {
    if (selectedBills.length === 0) {
        alert('Pilih minimal 1 tagihan!');
        return;
    }
    
    const tbody = document.getElementById('printTableBody');
    let html = '';
    let total = 0;
    
    selectedBills.forEach((bill, idx) => {
        total += bill.amount;
        html += `<tr>
            <td>${idx + 1}</td>
            <td><strong>${bill.name}</strong><br><small style="color: #64748b;">${bill.vendor}</small></td>
            <td>${bill.category}</td>
            <td>${bill.due}</td>
            <td>${bill.account}</td>
            <td class="text-right">Rp ${bill.amount.toLocaleString('id-ID')}</td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
    document.getElementById('printTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
    
    // Show print area
    document.getElementById('printArea').style.display = 'block';
    
    // Scroll to print area
    document.getElementById('printArea').scrollIntoView({ behavior: 'smooth' });
    
    // Trigger print after a short delay
    setTimeout(() => {
        window.print();
    }, 500);
}

function shareWhatsApp() {
    if (selectedBills.length === 0) {
        alert('Pilih minimal 1 tagihan!');
        return;
    }
    
    let total = 0;
    let message = `*PENGAJUAN PENCAIRAN DANA*\n`;
    message += `_Pembayaran Tagihan Bulanan_\n`;
    message += `📅 Periode: <?= date('F Y', strtotime($filterMonth . '-01')) ?>\n\n`;
    message += `*Daftar Tagihan:*\n`;
    message += `━━━━━━━━━━━━━━━\n`;
    
    selectedBills.forEach((bill, idx) => {
        total += bill.amount;
        const statusIcon = bill.status === 'overdue' ? '🔴' : '🟡';
        message += `${idx + 1}. ${bill.name}\n`;
        message += `   ${statusIcon} ${bill.category} · Rp ${bill.amount.toLocaleString('id-ID')}\n`;
        message += `   📆 Jatuh tempo: ${bill.due}\n`;
        if (bill.account && bill.account !== '-') {
            message += `   🏦 No: ${bill.account}\n`;
        }
        message += `\n`;
    });
    
    message += `━━━━━━━━━━━━━━━\n`;
    message += `*TOTAL: Rp ${total.toLocaleString('id-ID')}*\n\n`;
    message += `Mohon persetujuan untuk pencairan dana.\n`;
    message += `\n_Diajukan oleh: <?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff') ?>_`;
    message += `\n_<?= date('d M Y H:i') ?> WIB_`;
    
    const encoded = encodeURIComponent(message);
    window.open(`https://wa.me/?text=${encoded}`, '_blank');
}

// Initialize feather icons
if (typeof feather !== 'undefined') feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
