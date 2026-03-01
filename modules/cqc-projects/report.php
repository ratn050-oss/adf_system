<?php
/**
 * CQC Project Completion Report
 * Laporan pemasukan, pengeluaran, dan profit proyek
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once 'db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$justCompleted = isset($_GET['just_completed']) && $_GET['just_completed'] == '1';

if (!$id) {
    header('Location: dashboard.php');
    exit;
}

// Get project details
$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    die("Proyek tidak ditemukan.");
}

// === PENGELUARAN: Get from cqc_project_expenses ===
$stmt = $pdo->prepare("
    SELECT pe.*, ec.category_name, ec.category_icon 
    FROM cqc_project_expenses pe
    LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
    WHERE pe.project_id = ?
    ORDER BY pe.expense_date ASC
");
$stmt->execute([$id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Expense by category
$stmt = $pdo->prepare("
    SELECT ec.category_name, ec.category_icon, 
           SUM(pe.amount) as total, COUNT(*) as count
    FROM cqc_project_expenses pe
    LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
    WHERE pe.project_id = ?
    GROUP BY pe.category_id
    ORDER BY total DESC
");
$stmt->execute([$id]);
$expenseByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalExpense = array_sum(array_column($expenses, 'amount'));

// === PEMASUKAN: Get from cqc_termin_invoices ===
$stmt = $pdo->prepare("
    SELECT * FROM cqc_termin_invoices 
    WHERE project_id = ? 
    ORDER BY termin_number ASC
");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalInvoiced = array_sum(array_column($invoices, 'total_amount'));
$totalPaid = array_sum(array_column($invoices, 'paid_amount'));
$totalUnpaid = $totalInvoiced - $totalPaid;

// Also check cash_book for any direct income linked to project
$db = Database::getInstance();
$projectCode = $project['project_code'];
$cashbookIncome = $db->fetchAll(
    "SELECT * FROM cash_book 
     WHERE transaction_type = 'income' 
     AND description LIKE ? 
     ORDER BY transaction_date ASC",
    ['%[CQC_PROJECT:' . $id . ']%']
);
$totalCashbookIncome = array_sum(array_column($cashbookIncome, 'amount'));

// Cash book expenses linked to this project
$cashbookExpenses = $db->fetchAll(
    "SELECT * FROM cash_book 
     WHERE transaction_type = 'expense' 
     AND description LIKE ? 
     ORDER BY transaction_date ASC",
    ['%[CQC_PROJECT:' . $id . ']%']
);

// === PROFIT CALCULATION ===
$contractValue = !empty($invoices) ? floatval($invoices[0]['contract_value']) : 0;
$totalIncome = $totalPaid; // Actual received money
$profit = $totalIncome - $totalExpense;
$profitMargin = $totalIncome > 0 ? round(($profit / $totalIncome) * 100, 1) : 0;
$budgetEfficiency = floatval($project['budget_idr']) > 0 ? round(($totalExpense / floatval($project['budget_idr'])) * 100, 1) : 0;

// Duration
$startDate = $project['start_date'] ? new DateTime($project['start_date']) : null;
$endDate = $project['actual_completion'] ? new DateTime($project['actual_completion']) : ($project['end_date'] ? new DateTime($project['end_date']) : new DateTime());
$duration = $startDate ? $startDate->diff($endDate)->days : 0;

// Company info
$companyName = 'CQC Enjiniring';
$companyAddress = '';
try {
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM business_settings WHERE business_id = 7");
    foreach ($settings as $s) {
        if ($s['setting_key'] === 'business_name' && $s['setting_value']) $companyName = $s['setting_value'];
        if ($s['setting_key'] === 'address' && $s['setting_value']) $companyAddress = $s['setting_value'];
    }
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<style>
/* CQC Report Theme */
:root { --cqc-gold: #f0b429; --cqc-navy: #0d1f3c; }

.report-container { max-width: 1000px; margin: 0 auto; }

.report-hero {
    background: linear-gradient(135deg, #0d1f3c 0%, #1a3a5c 100%);
    border-radius: 16px; padding: 28px; margin-bottom: 20px; color: #fff; position: relative; overflow: hidden;
}
.report-hero::before {
    content: ''; position: absolute; top: -50%; right: -20%; width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(240,180,41,0.15) 0%, transparent 70%); border-radius: 50%;
}

.report-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
.report-card {
    background: #fff; border-radius: 12px; padding: 18px; border: 1px solid #e2e8f0;
    text-align: center; transition: all 0.2s;
}
.report-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); transform: translateY(-2px); }
.report-card-label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.report-card-value { font-size: 18px; font-weight: 700; }

.report-section {
    background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 16px; overflow: hidden;
}
.report-section-header {
    padding: 14px 18px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px;
    background: #f8fafc;
}
.report-section-title { font-size: 14px; font-weight: 700; color: #1e293b; }
.report-section-body { padding: 16px 18px; }

.report-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.report-table th { 
    padding: 10px 12px; text-align: left; background: #f8fafc; border-bottom: 2px solid #e2e8f0;
    font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px;
}
.report-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
.report-table tr:hover { background: #fffbeb; }
.report-table .total-row { background: #f8fafc; font-weight: 700; }
.report-table .total-row td { border-top: 2px solid #e2e8f0; }

.profit-box {
    background: linear-gradient(135deg, #0d1f3c, #1a3a5c); border-radius: 12px; padding: 24px;
    color: #fff; text-align: center; margin-bottom: 20px;
}

.btn-report {
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px;
    border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600;
    transition: all 0.15s; border: none; cursor: pointer;
}
.btn-gold { background: #f0b429; color: #0d1f3c; }
.btn-gold:hover { background: #d4960d; }
.btn-outline { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
.btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }
.btn-green { background: #059669; color: #fff; }
.btn-green:hover { background: #047857; }

<?php if ($justCompleted): ?>
.confetti-banner {
    background: linear-gradient(135deg, #059669, #10b981); border-radius: 12px; padding: 20px;
    text-align: center; margin-bottom: 20px; color: #fff; animation: slideDown 0.5s ease;
}
@keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
<?php endif; ?>

@media (max-width: 768px) {
    .report-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="report-container">

<?php if ($justCompleted): ?>
<div class="confetti-banner">
    <div style="font-size: 36px; margin-bottom: 8px;">🎉</div>
    <div style="font-size: 18px; font-weight: 700;">Proyek Selesai!</div>
    <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;"><?php echo htmlspecialchars($project['project_name']); ?> telah berhasil diselesaikan</div>
</div>
<?php endif; ?>

<!-- Hero Header -->
<div class="report-hero">
    <div style="display: flex; justify-content: space-between; align-items: start; position: relative; z-index: 1;">
        <div>
            <div style="font-size: 11px; color: #f0b429; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 4px;">📊 Laporan Proyek</div>
            <h2 style="font-size: 22px; font-weight: 700; margin: 0 0 6px 0;"><?php echo htmlspecialchars($project['project_name']); ?></h2>
            <div style="font-size: 13px; opacity: 0.8;">
                <?php echo htmlspecialchars($project['project_code']); ?> • <?php echo htmlspecialchars($project['client_name'] ?? '-'); ?>
                <?php if ($project['location']): ?> • <?php echo htmlspecialchars($project['location']); ?><?php endif; ?>
            </div>
            <div style="display: flex; gap: 12px; margin-top: 12px; font-size: 12px;">
                <span style="padding: 4px 10px; background: rgba(255,255,255,0.15); border-radius: 6px;">
                    📅 <?php echo $project['start_date'] ? date('d M Y', strtotime($project['start_date'])) : '-'; ?>
                    → <?php echo $project['actual_completion'] ? date('d M Y', strtotime($project['actual_completion'])) : ($project['end_date'] ? date('d M Y', strtotime($project['end_date'])) : 'Ongoing'); ?>
                </span>
                <span style="padding: 4px 10px; background: rgba(255,255,255,0.15); border-radius: 6px;">
                    ⏱ <?php echo $duration; ?> hari
                </span>
                <?php if (floatval($project['solar_capacity_kwp']) > 0): ?>
                <span style="padding: 4px 10px; background: rgba(240,180,41,0.2); border-radius: 6px; color: #f0b429;">
                    ⚡ <?php echo number_format($project['solar_capacity_kwp'], 1); ?> kWp
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align: right;">
            <span style="padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 700;
                <?php echo $project['status'] === 'completed' ? 'background: #059669; color: #fff;' : 'background: rgba(255,255,255,0.15); color: #fff;'; ?>">
                <?php echo $project['status'] === 'completed' ? '✓ COMPLETED' : strtoupper($project['status']); ?>
            </span>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="report-grid">
    <div class="report-card">
        <div class="report-card-label">Nilai Kontrak</div>
        <div class="report-card-value" style="color: #0d1f3c;">Rp <?php echo number_format($contractValue, 0, ',', '.'); ?></div>
    </div>
    <div class="report-card">
        <div class="report-card-label">Total Pemasukan</div>
        <div class="report-card-value" style="color: #059669;">Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></div>
        <?php if ($totalUnpaid > 0): ?>
        <div style="font-size: 10px; color: #dc2626; margin-top: 4px;">Belum dibayar: Rp <?php echo number_format($totalUnpaid, 0, ',', '.'); ?></div>
        <?php endif; ?>
    </div>
    <div class="report-card">
        <div class="report-card-label">Total Pengeluaran</div>
        <div class="report-card-value" style="color: #dc2626;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></div>
        <div style="font-size: 10px; color: #64748b; margin-top: 4px;">Budget: Rp <?php echo number_format($project['budget_idr'], 0, ',', '.'); ?></div>
    </div>
    <div class="report-card" style="border: 2px solid <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>;">
        <div class="report-card-label">Profit / Loss</div>
        <div class="report-card-value" style="color: <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>;">
            <?php echo $profit >= 0 ? '+' : ''; ?>Rp <?php echo number_format($profit, 0, ',', '.'); ?>
        </div>
        <div style="font-size: 10px; color: <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>; margin-top: 4px;">
            Margin: <?php echo $profitMargin; ?>%
        </div>
    </div>
</div>

<!-- Profit Box -->
<div class="profit-box">
    <div style="display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; gap: 16px; align-items: center;">
        <div>
            <div style="font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px;">Pemasukan</div>
            <div style="font-size: 22px; font-weight: 700; color: #4ade80;">Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></div>
        </div>
        <div style="font-size: 24px; opacity: 0.5;">−</div>
        <div>
            <div style="font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px;">Pengeluaran</div>
            <div style="font-size: 22px; font-weight: 700; color: #f87171;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></div>
        </div>
        <div style="font-size: 24px; opacity: 0.5;">=</div>
        <div>
            <div style="font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px;">
                <?php echo $profit >= 0 ? 'PROFIT' : 'RUGI'; ?>
            </div>
            <div style="font-size: 28px; font-weight: 800; color: <?php echo $profit >= 0 ? '#f0b429' : '#f87171'; ?>;">
                <?php echo $profit >= 0 ? '+' : ''; ?>Rp <?php echo number_format($profit, 0, ',', '.'); ?>
            </div>
        </div>
    </div>
</div>

<!-- PEMASUKAN: Invoice/Termin -->
<div class="report-section">
    <div class="report-section-header">
        <span style="font-size: 18px;">💰</span>
        <div>
            <div class="report-section-title">Pemasukan - Invoice & Pembayaran</div>
            <div style="font-size: 11px; color: #64748b;"><?php echo count($invoices); ?> invoice termin</div>
        </div>
    </div>
    <div class="report-section-body" style="padding: 0;">
        <?php if (!empty($invoices)): ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Invoice</th>
                    <th>Termin</th>
                    <th>Tanggal</th>
                    <th style="text-align: right;">Nilai Invoice</th>
                    <th style="text-align: right;">Dibayar</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $i => $inv): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong></td>
                    <td>Termin <?php echo $inv['termin_number']; ?> (<?php echo $inv['percentage']; ?>%)</td>
                    <td><?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></td>
                    <td style="text-align: right; font-family: monospace;">Rp <?php echo number_format($inv['total_amount'], 0, ',', '.'); ?></td>
                    <td style="text-align: right; font-family: monospace; color: <?php echo $inv['paid_amount'] > 0 ? '#059669' : '#94a3b8'; ?>;">
                        Rp <?php echo number_format($inv['paid_amount'], 0, ',', '.'); ?>
                    </td>
                    <td>
                        <span style="padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
                            <?php 
                            switch($inv['payment_status']) {
                                case 'paid': echo 'background: #dcfce7; color: #166534;'; break;
                                case 'partial': echo 'background: #fef3c7; color: #92400e;'; break;
                                case 'overdue': echo 'background: #fee2e2; color: #991b1b;'; break;
                                default: echo 'background: #f1f5f9; color: #475569;';
                            }
                            ?>">
                            <?php echo ucfirst($inv['payment_status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" style="text-align: right;">TOTAL</td>
                    <td style="text-align: right; font-family: monospace;">Rp <?php echo number_format($totalInvoiced, 0, ',', '.'); ?></td>
                    <td style="text-align: right; font-family: monospace; color: #059669;">Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div style="text-align: center; padding: 30px; color: #94a3b8;">
            <div style="font-size: 24px; margin-bottom: 6px;">📭</div>
            <div style="font-size: 12px;">Belum ada invoice termin</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- PENGELUARAN: By Category Summary -->
<div class="report-section">
    <div class="report-section-header">
        <span style="font-size: 18px;">📊</span>
        <div>
            <div class="report-section-title">Ringkasan Pengeluaran per Kategori</div>
            <div style="font-size: 11px; color: #64748b;">Efisiensi budget: <?php echo $budgetEfficiency; ?>%</div>
        </div>
    </div>
    <div class="report-section-body" style="padding: 0;">
        <?php if (!empty($expenseByCategory)): ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Kategori</th>
                    <th style="text-align: center;">Jumlah Transaksi</th>
                    <th style="text-align: right;">Total</th>
                    <th style="text-align: right;">% dari Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenseByCategory as $cat): 
                    $pct = $totalExpense > 0 ? round(($cat['total'] / $totalExpense) * 100, 1) : 0;
                ?>
                <tr>
                    <td>
                        <span style="margin-right: 6px;"><?php echo $cat['category_icon'] ?? '📦'; ?></span>
                        <?php echo htmlspecialchars($cat['category_name'] ?? 'Lainnya'); ?>
                    </td>
                    <td style="text-align: center;"><?php echo $cat['count']; ?>x</td>
                    <td style="text-align: right; font-family: monospace; font-weight: 600;">Rp <?php echo number_format($cat['total'], 0, ',', '.'); ?></td>
                    <td style="text-align: right;">
                        <div style="display: flex; align-items: center; gap: 6px; justify-content: flex-end;">
                            <div style="width: 60px; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                                <div style="width: <?php echo $pct; ?>%; height: 100%; background: #f0b429; border-radius: 3px;"></div>
                            </div>
                            <?php echo $pct; ?>%
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>TOTAL</td>
                    <td style="text-align: center;"><?php echo count($expenses); ?>x</td>
                    <td style="text-align: right; font-family: monospace;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></td>
                    <td style="text-align: right;">100%</td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div style="text-align: center; padding: 30px; color: #94a3b8;">
            <div style="font-size: 24px; margin-bottom: 6px;">📭</div>
            <div>Belum ada pengeluaran</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- PENGELUARAN: Detail Table -->
<div class="report-section">
    <div class="report-section-header">
        <span style="font-size: 18px;">📋</span>
        <div>
            <div class="report-section-title">Detail Pengeluaran</div>
            <div style="font-size: 11px; color: #64748b;"><?php echo count($expenses); ?> transaksi</div>
        </div>
    </div>
    <div class="report-section-body" style="padding: 0;">
        <?php if (!empty($expenses)): ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Kategori</th>
                    <th>Keterangan</th>
                    <th style="text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $i => $exp): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></td>
                    <td>
                        <span style="margin-right: 4px;"><?php echo $exp['category_icon'] ?? '📦'; ?></span>
                        <?php echo htmlspecialchars($exp['category_name'] ?? 'Lainnya'); ?>
                    </td>
                    <td><?php echo htmlspecialchars($exp['description']); ?></td>
                    <td style="text-align: right; font-family: monospace; color: #dc2626;">Rp <?php echo number_format($exp['amount'], 0, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" style="text-align: right;">TOTAL PENGELUARAN</td>
                    <td style="text-align: right; font-family: monospace; color: #dc2626;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div style="text-align: center; padding: 30px; color: #94a3b8;">
            <div style="font-size: 24px; margin-bottom: 6px;">📭</div>
            <div>Belum ada pengeluaran</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Action Buttons -->
<div style="display: flex; gap: 10px; justify-content: center; margin: 24px 0; flex-wrap: wrap;">
    <a href="dashboard.php" class="btn-report btn-outline">← Kembali</a>
    <a href="detail.php?id=<?php echo $id; ?>" class="btn-report btn-outline">📁 Detail Proyek</a>
    <a href="berita-acara.php?id=<?php echo $id; ?>" class="btn-report btn-gold">📄 Cetak Berita Acara</a>
    <button onclick="window.print();" class="btn-report btn-green">🖨 Print Laporan</button>
</div>

</div>

<style>
@media print {
    .sidebar, .navbar, .btn-report, .confetti-banner { display: none !important; }
    .report-container { max-width: 100%; margin: 0; }
    .report-hero { break-after: avoid; }
    .report-section { break-inside: avoid; }
}
</style>

<?php include '../../includes/footer.php'; ?>
