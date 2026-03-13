<?php
/**
 * BILLS / TAGIHAN MODULE
 * Overview of all recurring bills with due date tracking & notifications
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Prevent browser caching to always show fresh data
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// AJAX handler for fetching fresh bill data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_bill') {
    header('Content-Type: application/json');
    $db = Database::getInstance();
    $billId = (int)($_GET['bill_id'] ?? 0);
    if ($billId > 0) {
        $bill = $db->fetchOne(
            "SELECT br.*, bt.bill_name, bt.bill_category, bt.vendor_name, bt.division_id, bt.category_id, bt.payment_method as default_payment
             FROM bill_records br 
             JOIN bill_templates bt ON br.template_id = bt.id 
             WHERE br.id = ?", [$billId]
        );
        if ($bill) {
            echo json_encode(['success' => true, 'bill' => $bill]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Bill not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid bill ID']);
    }
    exit;
}

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();
require_once __DIR__ . '/auto-migrate.php';

$pageTitle = 'Tagihan & Bills';
$pageSubtitle = 'Kelola tagihan rutin & pembayaran';

// Auto-generate bill records for current month
// (creates pending records for active templates that don't have a record yet)
$currentMonth = date('Y-m');
$nextMonth = date('Y-m', strtotime('+1 month'));

try {
    $templates = $db->fetchAll("SELECT * FROM bill_templates WHERE is_active = 1");
    foreach ($templates as $tpl) {
        // Generate for current month
        $existing = $db->fetchOne(
            "SELECT id FROM bill_records WHERE template_id = ? AND bill_period = ?",
            [$tpl['id'], $currentMonth]
        );
        if (!$existing) {
            $dueDate = $currentMonth . '-' . str_pad($tpl['due_day'], 2, '0', STR_PAD_LEFT);
            // Validate date (handle months with fewer days)
            if (!checkdate((int)date('m'), (int)$tpl['due_day'], (int)date('Y'))) {
                $dueDate = date('Y-m-t'); // last day of month
            }
            $db->insert('bill_records', [
                'template_id' => $tpl['id'],
                'bill_period' => $currentMonth,
                'amount' => $tpl['default_amount'],
                'due_date' => $dueDate,
                'status' => 'pending'
            ]);
        }
        
        // Also generate for next month if template is monthly
        if ($tpl['recurrence'] === 'monthly') {
            $existingNext = $db->fetchOne(
                "SELECT id FROM bill_records WHERE template_id = ? AND bill_period = ?",
                [$tpl['id'], $nextMonth]
            );
            if (!$existingNext) {
                $nextDueDate = $nextMonth . '-' . str_pad($tpl['due_day'], 2, '0', STR_PAD_LEFT);
                $nextY = (int)date('Y', strtotime('+1 month'));
                $nextM = (int)date('m', strtotime('+1 month'));
                if (!checkdate($nextM, (int)$tpl['due_day'], $nextY)) {
                    $nextDueDate = date('Y-m-t', strtotime('+1 month'));
                }
                $db->insert('bill_records', [
                    'template_id' => $tpl['id'],
                    'bill_period' => $nextMonth,
                    'amount' => $tpl['default_amount'],
                    'due_date' => $nextDueDate,
                    'status' => 'pending'
                ]);
            }
        }
    }
    
    // Auto-mark overdue bills
    $db->query(
        "UPDATE bill_records SET status = 'overdue' WHERE status = 'pending' AND due_date < CURDATE()"
    );
} catch (Exception $e) {
    // Silent fail on auto-generation
    error_log("Bills auto-generate error: " . $e->getMessage());
}

// Filter params
$filterStatus = getGet('status', 'all');
$filterCategory = getGet('category', 'all');
$filterMonth = getGet('month', $currentMonth);

// Build query
$whereClauses = ["1=1"];
$params = [];

if ($filterMonth !== 'all' && !empty($filterMonth)) {
    $whereClauses[] = "br.bill_period = :month";
    $params['month'] = $filterMonth;
}
if ($filterStatus !== 'all') {
    $whereClauses[] = "br.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterCategory !== 'all') {
    $whereClauses[] = "bt.bill_category = :category";
    $params['category'] = $filterCategory;
}

$whereSQL = implode(' AND ', $whereClauses);

// Fetch bills with template info
$bills = $db->fetchAll(
    "SELECT 
        br.*,
        bt.bill_name,
        bt.bill_category,
        bt.vendor_name,
        bt.account_number,
        bt.is_fixed_amount,
        bt.recurrence,
        bt.reminder_days,
        bt.division_id,
        bt.payment_method as default_payment,
        d.division_name
    FROM bill_records br
    JOIN bill_templates bt ON br.template_id = bt.id
    LEFT JOIN divisions d ON bt.division_id = d.id
    WHERE {$whereSQL}
    ORDER BY br.due_date ASC, bt.bill_name ASC",
    $params
);

// Summary stats
$totalPending = 0;
$totalPaid = 0;
$totalOverdue = 0;
$countPending = 0;
$countPaid = 0;
$countOverdue = 0;
$upcomingDue = [];

$today = new DateTime();
foreach ($bills as $bill) {
    switch ($bill['status']) {
        case 'pending':
            $totalPending += $bill['amount'];
            $countPending++;
            // Check if due soon
            $dueDate = new DateTime($bill['due_date']);
            $diff = $today->diff($dueDate);
            $daysUntil = $diff->invert ? -$diff->days : $diff->days;
            if ($daysUntil >= 0 && $daysUntil <= ($bill['reminder_days'] ?? 3)) {
                $upcomingDue[] = $bill;
            }
            break;
        case 'paid':
            $totalPaid += ($bill['paid_amount'] ?? $bill['amount']);
            $countPaid++;
            break;
        case 'overdue':
            $totalOverdue += $bill['amount'];
            $countOverdue++;
            break;
    }
}

// Get template categories for filter
$categories = [
    'electricity' => ['label' => 'Listrik', 'icon' => '⚡', 'color' => '#f59e0b'],
    'tax' => ['label' => 'Pajak', 'icon' => '🏛️', 'color' => '#8b5cf6'],
    'wifi' => ['label' => 'WiFi/Internet', 'icon' => '📶', 'color' => '#3b82f6'],
    'vehicle' => ['label' => 'Kendaraan', 'icon' => '🏍️', 'color' => '#06b6d4'],
    'po' => ['label' => 'Tagihan PO', 'icon' => '📦', 'color' => '#f97316'],
    'receivable' => ['label' => 'Piutang', 'icon' => '💳', 'color' => '#ec4899'],
    'other' => ['label' => 'Lainnya', 'icon' => '📋', 'color' => '#64748b'],
];

include '../../includes/header.php';
?>

<style>
/* ===== BILLS MODULE STYLES ===== */
.bills-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.bills-stat-card {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-lg);
    padding: 1rem 1.15rem;
    position: relative;
    overflow: hidden;
    transition: var(--transition);
}

.bills-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.bills-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

.bills-stat-card.pending::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.bills-stat-card.paid::before { background: linear-gradient(90deg, #10b981, #34d399); }
.bills-stat-card.overdue::before { background: linear-gradient(90deg, #ef4444, #f87171); }

.bills-stat-label {
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    margin-bottom: 0.35rem;
}

.bills-stat-value {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--text-heading);
    line-height: 1.2;
}

.bills-stat-count {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 0.2rem;
}

.bills-stat-card.pending .bills-stat-value { color: #f59e0b; }
.bills-stat-card.paid .bills-stat-value { color: #10b981; }
.bills-stat-card.overdue .bills-stat-value { color: #ef4444; }

/* Notification Banner */
.bills-alert {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.12), rgba(239, 68, 68, 0.08));
    border: 1px solid rgba(245, 158, 11, 0.25);
    border-radius: var(--radius-lg);
    padding: 0.85rem 1rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.bills-alert-icon {
    font-size: 1.25rem;
    line-height: 1;
    flex-shrink: 0;
    margin-top: 0.1rem;
}

.bills-alert-content {
    flex: 1;
    min-width: 0;
}

.bills-alert-title {
    font-size: 0.78rem;
    font-weight: 700;
    color: #f59e0b;
    margin-bottom: 0.25rem;
}

.bills-alert-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.bills-alert-list li {
    font-size: 0.72rem;
    color: var(--text-secondary);
    padding: 0.15rem 0;
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.bills-alert-list li .due-tag {
    font-size: 0.65rem;
    font-weight: 600;
    padding: 0.1rem 0.35rem;
    border-radius: 3px;
    white-space: nowrap;
}

.due-tag.today { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
.due-tag.soon { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }

/* Filter Bar */
.bills-filter {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    align-items: center;
}

.bills-filter select,
.bills-filter input[type="month"] {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 0.75rem;
    padding: 0.45rem 0.65rem;
    border-radius: var(--radius-md);
    outline: none;
    transition: var(--transition);
}

.bills-filter select:focus,
.bills-filter input:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
}

/* Bills Table */
.bills-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.78rem;
}

.bills-table th {
    background: linear-gradient(135deg, var(--bg-secondary), var(--bg-tertiary));
    padding: 0.6rem 0.55rem;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--text-muted);
    border-bottom: 2px solid var(--bg-tertiary);
    white-space: nowrap;
    text-align: left;
}

.bills-table td {
    padding: 0.55rem;
    border-bottom: 1px solid var(--bg-tertiary);
    vertical-align: middle;
}

.bills-table tbody tr {
    transition: var(--transition);
}

.bills-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.04);
}

.bills-table tbody tr.row-overdue {
    background: rgba(239, 68, 68, 0.04);
}

.bills-table tbody tr.row-overdue:hover {
    background: rgba(239, 68, 68, 0.08);
}

/* Bill Name Cell */
.bill-name-cell {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.bill-icon {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}

.bill-info {
    min-width: 0;
}

.bill-info-name {
    font-weight: 600;
    color: var(--text-heading);
    font-size: 0.78rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
}

.bill-info-vendor {
    font-size: 0.65rem;
    color: var(--text-muted);
    margin-top: 0.1rem;
}

/* Category Badge */
.bill-cat {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.2rem 0.45rem;
    border-radius: 4px;
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    white-space: nowrap;
}

/* Status Badge */
.bill-status {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
}

.bill-status.pending {
    background: rgba(245, 158, 11, 0.15);
    color: #d97706;
}

.bill-status.paid {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
}

.bill-status.overdue {
    background: rgba(239, 68, 68, 0.15);
    color: #dc2626;
}

.bill-status.cancelled {
    background: var(--bg-tertiary);
    color: var(--text-muted);
}

/* Due Date */
.bill-due {
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.bill-due.is-overdue {
    color: #ef4444;
    font-weight: 700;
}

.bill-due.is-soon {
    color: #f59e0b;
    font-weight: 600;
}

.bill-due .due-days {
    display: block;
    font-size: 0.6rem;
    color: var(--text-muted);
    font-weight: 400;
}

/* Amount */
.bill-amount {
    font-weight: 600;
    font-size: 0.78rem;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

/* Action Buttons */
.bill-actions {
    display: flex;
    gap: 0.25rem;
    justify-content: center;
}

.bill-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    font-size: 0;
}

.bill-action-btn svg {
    width: 14px;
    height: 14px;
}

.bill-action-btn.pay {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
}
.bill-action-btn.pay:hover {
    background: #10b981;
    color: white;
}

.bill-action-btn.edit {
    background: var(--bg-tertiary);
    color: var(--text-muted);
}
.bill-action-btn.edit:hover {
    background: var(--primary-color);
    color: white;
}

.bill-action-btn.delete {
    background: rgba(239, 68, 68, 0.12);
    color: #ef4444;
}
.bill-action-btn.delete:hover {
    background: #ef4444;
    color: white;
}

/* Pay Bill Modal */
.bill-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.bill-modal-overlay.active {
    display: flex;
}

.bill-modal {
    background: var(--bg-primary);
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-xl);
    width: 100%;
    max-width: 440px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
    box-shadow: var(--shadow-xl);
}

.bill-modal-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-heading);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.bill-modal .form-group {
    margin-bottom: 0.85rem;
}

.bill-modal .form-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.3rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.bill-modal .form-control {
    width: 100%;
    padding: 0.55rem 0.75rem;
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.82rem;
    outline: none;
    transition: var(--transition);
}

.bill-modal .form-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
}

.bill-modal-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1.25rem;
}

.bill-modal-actions .btn {
    flex: 1;
    padding: 0.55rem;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}

.bill-modal-actions .btn-cancel {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
}

.bill-modal-actions .btn-pay {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.bill-modal-actions .btn-pay:hover {
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35);
}

/* Header Actions */
.bills-header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.bills-header-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 0.85rem;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: var(--transition);
}

.bills-header-actions .btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
}

.bills-header-actions .btn-primary:hover {
    box-shadow: var(--shadow-glow);
}

.bills-header-actions .btn svg { width: 15px; height: 15px; }

/* Empty State */
.bills-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--text-muted);
}

.bills-empty-icon {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}

.bills-empty-text {
    font-size: 0.85rem;
    margin-bottom: 0.35rem;
}

.bills-empty-sub {
    font-size: 0.72rem;
}

/* Responsive */
@media (max-width: 768px) {
    .bills-stats { grid-template-columns: 1fr; gap: 0.5rem; }
    .bills-table { font-size: 0.72rem; }
    .bill-info-name { max-width: 120px; }
    .bills-filter { flex-direction: column; }
}
</style>

<!-- Page Header -->
<div class="card" style="margin-bottom: 1.25rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--text-heading); margin: 0;">
                <i data-feather="file-text" style="width: 20px; height: 20px; display: inline; vertical-align: -3px;"></i>
                Tagihan & Pembayaran
            </h2>
            <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0.2rem 0 0;">Kelola tagihan rutin bulanan - listrik, pajak, wifi, kendaraan, PO & lainnya</p>
        </div>
        <div class="bills-header-actions">
            <a href="<?= BASE_URL ?>/modules/bills/templates.php" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary);">
                <i data-feather="settings"></i> Template
            </a>
            <a href="<?= BASE_URL ?>/modules/bills/create.php" class="btn btn-primary">
                <i data-feather="plus"></i> Tambah Tagihan
            </a>
        </div>
    </div>
</div>

<?php if (!empty($upcomingDue) || $countOverdue > 0): ?>
<!-- Due Soon / Overdue Alert -->
<div class="bills-alert">
    <div class="bills-alert-icon">🔔</div>
    <div class="bills-alert-content">
        <div class="bills-alert-title">
            <?php if ($countOverdue > 0): ?>
                <?= $countOverdue ?> tagihan lewat jatuh tempo!
            <?php else: ?>
                Tagihan akan jatuh tempo dalam beberapa hari
            <?php endif; ?>
        </div>
        <ul class="bills-alert-list">
            <?php 
            // Show overdue first, then upcoming
            $alertBills = array_merge(
                array_filter($bills, fn($b) => $b['status'] === 'overdue'),
                $upcomingDue
            );
            foreach (array_slice($alertBills, 0, 5) as $ab): 
                $dueD = new DateTime($ab['due_date']);
                $diffD = $today->diff($dueD);
                $daysLeft = $diffD->invert ? -$diffD->days : $diffD->days;
            ?>
            <li>
                <span><?= htmlspecialchars($ab['bill_name']) ?> — <?= formatCurrency($ab['amount']) ?></span>
                <?php if ($daysLeft < 0): ?>
                    <span class="due-tag today">Lewat <?= abs($daysLeft) ?> hari</span>
                <?php elseif ($daysLeft === 0): ?>
                    <span class="due-tag today">Hari ini!</span>
                <?php else: ?>
                    <span class="due-tag soon"><?= $daysLeft ?> hari lagi</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="bills-stats">
    <div class="bills-stat-card pending">
        <div class="bills-stat-label">Perkiraan Tagihan</div>
        <div class="bills-stat-value"><?= formatCurrency($totalPending) ?></div>
        <div class="bills-stat-count"><?= $countPending ?> tagihan</div>
    </div>
    <div class="bills-stat-card overdue">
        <div class="bills-stat-label">Jatuh Tempo</div>
        <div class="bills-stat-value"><?= formatCurrency($totalOverdue) ?></div>
        <div class="bills-stat-count"><?= $countOverdue ?> tagihan</div>
    </div>
    <div class="bills-stat-card paid">
        <div class="bills-stat-label">Sudah Dibayar</div>
        <div class="bills-stat-value"><?= formatCurrency($totalPaid) ?></div>
        <div class="bills-stat-count"><?= $countPaid ?> tagihan</div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 1.25rem;">
    <form method="GET" class="bills-filter">
        <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>" style="min-width: 140px;">
        <select name="status">
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>Jatuh Tempo</option>
            <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Sudah Bayar</option>
            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
        <select name="category">
            <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>Semua Kategori</option>
            <?php foreach ($categories as $catKey => $catInfo): ?>
            <option value="<?= $catKey ?>" <?= $filterCategory === $catKey ? 'selected' : '' ?>><?= $catInfo['icon'] ?> <?= $catInfo['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn" style="background: var(--primary-color); color: white; border: none; padding: 0.45rem 0.85rem; border-radius: var(--radius-md); font-size: 0.75rem; font-weight: 600; cursor: pointer;">
            <i data-feather="filter" style="width: 13px; height: 13px; display: inline; vertical-align: -2px;"></i> Filter
        </button>
    </form>
</div>

<!-- Bills Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($bills)): ?>
    <div class="bills-empty">
        <div class="bills-empty-icon">📋</div>
        <div class="bills-empty-text">Belum ada tagihan untuk periode ini</div>
        <div class="bills-empty-sub">Buat template tagihan terlebih dahulu untuk generate otomatis</div>
    </div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="bills-table">
            <thead>
                <tr>
                    <th>Tagihan</th>
                    <th>Kategori</th>
                    <th>Jatuh Tempo</th>
                    <th style="text-align: right;">Nominal</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bills as $bill): 
                    $catInfo = $categories[$bill['bill_category']] ?? $categories['other'];
                    $dueDate = new DateTime($bill['due_date']);
                    $diff = $today->diff($dueDate);
                    $daysUntil = $diff->invert ? -$diff->days : $diff->days;
                    $dueClass = '';
                    $dueText = '';
                    if ($bill['status'] !== 'paid' && $bill['status'] !== 'cancelled') {
                        if ($daysUntil < 0) {
                            $dueClass = 'is-overdue';
                            $dueText = 'Lewat ' . abs($daysUntil) . ' hari';
                        } elseif ($daysUntil === 0) {
                            $dueClass = 'is-overdue';
                            $dueText = 'Hari ini!';
                        } elseif ($daysUntil <= ($bill['reminder_days'] ?? 3)) {
                            $dueClass = 'is-soon';
                            $dueText = $daysUntil . ' hari lagi';
                        }
                    }
                    $rowClass = $bill['status'] === 'overdue' ? 'row-overdue' : '';
                ?>
                <tr class="<?= $rowClass ?>">
                    <td>
                        <div class="bill-name-cell">
                            <div class="bill-icon" style="background: <?= $catInfo['color'] ?>20; color: <?= $catInfo['color'] ?>;">
                                <?= $catInfo['icon'] ?>
                            </div>
                            <div class="bill-info">
                                <div class="bill-info-name"><?= htmlspecialchars($bill['bill_name']) ?></div>
                                <div class="bill-info-vendor"><?= htmlspecialchars($bill['vendor_name'] ?? '—') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="bill-cat" style="background: <?= $catInfo['color'] ?>18; color: <?= $catInfo['color'] ?>;">
                            <?= $catInfo['icon'] ?> <?= $catInfo['label'] ?>
                        </span>
                    </td>
                    <td>
                        <div class="bill-due <?= $dueClass ?>">
                            <?= date('d M Y', strtotime($bill['due_date'])) ?>
                            <?php if ($dueText): ?>
                            <span class="due-days"><?= $dueText ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span class="bill-amount"><?= formatCurrency($bill['amount']) ?></span>
                    </td>
                    <td style="text-align: center;">
                        <span class="bill-status <?= $bill['status'] ?>">
                            <?php
                            switch ($bill['status']) {
                                case 'pending': echo 'Pending'; break;
                                case 'paid': echo 'Lunas'; break;
                                case 'overdue': echo 'Lewat'; break;
                                case 'cancelled': echo 'Batal'; break;
                            }
                            ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div class="bill-actions">
                            <?php if ($bill['status'] !== 'paid' && $bill['status'] !== 'cancelled'): ?>
                            <button class="bill-action-btn pay" title="Bayar" onclick="openPayModal(<?= $bill['id'] ?>)">
                                <i data-feather="check-circle"></i>
                            </button>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/modules/bills/edit-record.php?id=<?= $bill['id'] ?>" class="bill-action-btn edit" title="Edit">
                                <i data-feather="edit-2"></i>
                            </a>
                            <?php if ($bill['status'] !== 'paid'): ?>
                            <a href="<?= BASE_URL ?>/modules/bills/edit-record.php?id=<?= $bill['id'] ?>&delete=1" 
                               class="bill-action-btn delete" title="Hapus"
                               onclick="return confirm('Hapus tagihan ini?');">
                                <i data-feather="trash-2"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Pay Bill Modal -->
<div class="bill-modal-overlay" id="payModal">
    <div class="bill-modal">
        <div class="bill-modal-title">
            <i data-feather="check-circle" style="width: 20px; height: 20px; color: #10b981;"></i>
            Bayar Tagihan
        </div>
        <form method="POST" action="<?= BASE_URL ?>/modules/bills/pay.php">
            <input type="hidden" name="record_id" id="pay_record_id">
            <input type="hidden" name="template_id" id="pay_template_id">
            
            <div style="background: var(--bg-secondary); border-radius: var(--radius-md); padding: 0.75rem; margin-bottom: 1rem;">
                <div style="font-size: 0.72rem; color: var(--text-muted);">Tagihan</div>
                <div style="font-size: 0.88rem; font-weight: 600; color: var(--text-heading);" id="pay_bill_name"></div>
                <div style="font-size: 0.68rem; color: var(--text-muted); margin-top: 0.15rem;" id="pay_bill_vendor"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Tanggal Bayar</label>
                <input type="date" name="paid_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Jumlah Bayar (Rp)</label>
                <input type="text" name="paid_amount" id="pay_amount" class="form-control" required 
                       oninput="this.value = this.value.replace(/[^0-9]/g,''); formatPayAmount(this);">
            </div>

            <div class="form-group">
                <label class="form-label">Metode Pembayaran</label>
                <select name="payment_method" id="pay_method" class="form-control">
                    <option value="transfer">Transfer Bank</option>
                    <option value="cash">Tunai / Cash</option>
                    <option value="qr">QR Payment</option>
                    <option value="debit">Debit</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Catatan (opsional)</label>
                <input type="text" name="notes" class="form-control" placeholder="No. ref, keterangan, dll">
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" name="auto_cashbook" id="auto_cashbook" value="1" checked style="width: 16px; height: 16px; accent-color: var(--primary-color);">
                <label for="auto_cashbook" style="font-size: 0.75rem; color: var(--text-secondary); cursor: pointer;">
                    Otomatis catat di Buku Kas
                </label>
            </div>

            <div class="bill-modal-actions">
                <button type="button" class="btn btn-cancel" onclick="closePayModal()">Batal</button>
                <button type="submit" class="btn btn-pay">💰 Bayar & Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Pay Modal - Fetch fresh data via AJAX
async function openPayModal(billId) {
    try {
        const response = await fetch('<?= BASE_URL ?>/modules/bills/index.php?ajax=get_bill&bill_id=' + billId);
        const data = await response.json();
        
        if (!data.success) {
            alert('Gagal mengambil data tagihan: ' + (data.message || 'Unknown error'));
            return;
        }
        
        const bill = data.bill;
        document.getElementById('pay_record_id').value = bill.id;
        document.getElementById('pay_template_id').value = bill.template_id;
        document.getElementById('pay_bill_name').textContent = bill.bill_name;
        document.getElementById('pay_bill_vendor').textContent = bill.vendor_name || '—';
        
        const amountField = document.getElementById('pay_amount');
        amountField.value = parseInt(bill.amount).toLocaleString('id-ID');
        amountField.dataset.raw = parseInt(bill.amount);
        
        if (bill.default_payment) {
            document.getElementById('pay_method').value = bill.default_payment;
        }
        
        document.getElementById('payModal').classList.add('active');
    } catch (error) {
        console.error('Error fetching bill:', error);
        alert('Gagal mengambil data tagihan. Silakan refresh halaman.');
    }
}

function closePayModal() {
    document.getElementById('payModal').classList.remove('active');
}

function formatPayAmount(el) {
    let raw = el.value.replace(/\D/g, '');
    el.dataset.raw = raw;
    if (raw) {
        el.value = parseInt(raw).toLocaleString('id-ID');
    }
}

// Close modal on overlay click
document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});

// Submit form - replace formatted amount with raw number
document.querySelector('#payModal form').addEventListener('submit', function(e) {
    const amountField = document.getElementById('pay_amount');
    amountField.value = amountField.dataset.raw || amountField.value.replace(/\D/g, '');
});

// Initialize feather icons for dynamic content
if (typeof feather !== 'undefined') feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
