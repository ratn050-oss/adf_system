<?php
/**
 * MULTI-BUSINESS MANAGEMENT SYSTEM
 * Buku Kas Besar - List & Overview
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/print-helper.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();

// ==========================================
// AUTO-SYNC UNSYNCED BOOKING PAYMENTS TO CASHBOOK
// Works on both local AND hosting (with/without synced_to_cashbook column)
// ==========================================
try {
    // Check if this is a hotel business with booking_payments table
    $hasBookingPayments = false;
    try {
        $db->getConnection()->query("SELECT 1 FROM booking_payments LIMIT 1");
        $hasBookingPayments = true;
    } catch (Exception $e) {}

    if ($hasBookingPayments) {
        // Ensure synced_to_cashbook column exists
        $hasSyncCol = false;
        try {
            $syncColChk = $db->getConnection()->query("SHOW COLUMNS FROM booking_payments LIKE 'synced_to_cashbook'");
            $hasSyncCol = $syncColChk && $syncColChk->rowCount() > 0;
        } catch (Exception $e) {}
        if (!$hasSyncCol) {
            try {
                $db->getConnection()->exec("ALTER TABLE booking_payments ADD COLUMN synced_to_cashbook TINYINT(1) NOT NULL DEFAULT 0");
                $db->getConnection()->exec("ALTER TABLE booking_payments ADD COLUMN cashbook_id INT(11) DEFAULT NULL");
                $hasSyncCol = true;
            } catch (Exception $e) {
                error_log("Cashbook page: Cannot add synced_to_cashbook column: " . $e->getMessage());
                $hasSyncCol = false;
            }
        }

        // Check if there are payments to sync
        $needsSync = false;
        if ($hasSyncCol) {
            $unsyncedCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM booking_payments WHERE synced_to_cashbook = 0");
            $needsSync = $unsyncedCount && (int)$unsyncedCount['cnt'] > 0;
        } else {
            // Fallback: always check recent payments
            $recentCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM booking_payments WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)");
            $needsSync = $recentCount && (int)$recentCount['cnt'] > 0;
        }

        if ($needsSync) {
            $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
            $masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $businessId = $_SESSION['business_id'] ?? 1;

            $cbUserId = $currentUser['id'] ?? 1;
            $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$cbUserId]);
            if (!$userExists) {
                $firstUser = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $cbUserId = $firstUser['id'] ?? 1;
            }

            $hasCashAccountId = false;
            try {
                $colChk = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                $hasCashAccountId = $colChk && $colChk->rowCount() > 0;
            } catch (Exception $e) {}

            // Detect payment_method ENUM
            $allowedPaymentMethods = null;
            try {
                $pmColInfo = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
                if ($pmColInfo && strpos($pmColInfo['Type'], 'enum') === 0) {
                    preg_match_all("/'([^']+)'/", $pmColInfo['Type'], $enumMatches);
                    $allowedPaymentMethods = $enumMatches[1] ?? ['cash'];
                }
            } catch (Exception $e) {}

            $division = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%frontdesk%' ORDER BY id ASC LIMIT 1");
            if (!$division) $division = $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
            $divisionId = $division['id'] ?? 1;

            $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id ASC LIMIT 1");
            if (!$category) $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
            $categoryId = $category['id'] ?? 1;

            if ($hasSyncCol) {
                $unsyncedPayments = $db->fetchAll("
                    SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                           b.booking_code, b.booking_source, b.final_price, g.guest_name, r.room_number
                    FROM booking_payments bp
                    JOIN bookings b ON bp.booking_id = b.id
                    LEFT JOIN guests g ON b.guest_id = g.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE bp.synced_to_cashbook = 0 ORDER BY bp.id ASC
                ");
            } else {
                $unsyncedPayments = $db->fetchAll("
                    SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                           b.booking_code, b.booking_source, b.final_price, g.guest_name, r.room_number
                    FROM booking_payments bp
                    JOIN bookings b ON bp.booking_id = b.id
                    LEFT JOIN guests g ON b.guest_id = g.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE bp.payment_date >= DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY bp.id ASC
                ");
            }

            $syncCount = 0;
            foreach ($unsyncedPayments as $payment) {
                try {
                    // Fallback dedup if no sync column
                    if (!$hasSyncCol) {
                        $existingLegacy = $db->fetchOne("SELECT id FROM cash_book WHERE description LIKE ? AND ABS(amount - ?) < 1 AND transaction_type = 'income' LIMIT 1",
                            ['%' . $payment['booking_code'] . '%', $payment['amount']]);
                        if ($existingLegacy) continue;
                    }

                    $netAmount = (float)$payment['amount'];
                    if (in_array(strtolower($payment['payment_method']), ['ota', 'agoda', 'booking'])) {
                        $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ota_fee_other_ota'");
                        $feeStmt->execute();
                        $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
                        if ($feeQuery && (float)($feeQuery['setting_value'] ?? 0) > 0) {
                            $netAmount = $payment['amount'] - ($payment['amount'] * (float)$feeQuery['setting_value'] / 100);
                        }
                    }

                    $accountType = ($payment['payment_method'] === 'cash') ? 'cash' : 'bank';
                    $accountStmt = $masterDb->prepare("SELECT id, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
                    $accountStmt->execute([$businessId, $accountType]);
                    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$account) continue;

                    $guestName = $payment['guest_name'] ?? 'Guest';
                    $roomNum = $payment['room_number'] ?? '';
                    $desc = "Pembayaran Reservasi - {$guestName}";
                    if ($roomNum) $desc .= " (Room {$roomNum})";
                    $desc .= " - {$payment['booking_code']}";
                    $totalPaid = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id = ?", [$payment['booking_id']]);
                    $desc .= ((float)$totalPaid['total'] >= (float)$payment['final_price']) ? ' [LUNAS]' : ' [CICILAN]';

                    $pmMap = ['bank_transfer'=>'transfer','credit_card'=>'debit','credit'=>'debit'];
                    $cbMethod = strtolower($payment['payment_method'] ?? 'cash');
                    $cbMethod = $pmMap[$cbMethod] ?? $cbMethod;
                    if ($allowedPaymentMethods !== null) {
                        if (!in_array($cbMethod, $allowedPaymentMethods)) {
                            $cbMethod = in_array('other', $allowedPaymentMethods) ? 'other' :
                                       (in_array('cash', $allowedPaymentMethods) ? 'cash' : $allowedPaymentMethods[0]);
                        }
                    }

                    if ($hasCashAccountId) {
                        $cashBookInsert = $db->getConnection()->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, cash_account_id, created_by, created_at) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())");
                        $cashBookInsert->execute([$payment['payment_date'], $payment['payment_date'], $divisionId, $categoryId, $desc, $netAmount, $cbMethod, $account['id'], $cbUserId]);
                    } else {
                        $cashBookInsert = $db->getConnection()->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, created_by, created_at) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, NOW())");
                        $cashBookInsert->execute([$payment['payment_date'], $payment['payment_date'], $divisionId, $categoryId, $desc, $netAmount, $cbMethod, $cbUserId]);
                    }

                    $transactionId = $db->getConnection()->lastInsertId();

                    if ($hasSyncCol) {
                        try { $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$transactionId, $payment['payment_id']]); } catch (Exception $e) {}
                    }

                    try {
                        $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                            $account['id'], $transactionId, $payment['payment_date'], $desc, $netAmount, $payment['booking_code'], $cbUserId
                        ]);
                        $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$netAmount, $account['id']]);
                    } catch (Exception $masterErr) {
                        error_log("Cashbook page master sync error: " . $masterErr->getMessage());
                    }
                    $syncCount++;
                } catch (Exception $paymentError) {
                    error_log("Cashbook page sync error payment#{$payment['payment_id']}: " . $paymentError->getMessage());
                    continue;
                }
            }
            if ($syncCount > 0) {
                error_log("Cashbook page auto-sync: {$syncCount} payments synced");
            }
        }
    }
} catch (Exception $syncError) {
    error_log("Cashbook page sync setup error: " . $syncError->getMessage());
}

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// Get company name from settings
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : BUSINESS_NAME;

$pageTitle = BUSINESS_ICON . ' ' . $displayCompanyName . ' - Buku Kas Besar';
$pageSubtitle = 'Pencatatan Transaksi Keuangan';

// Filtering
$filterDate = getGet('date', 'all'); // Changed default from today to 'all'
$filterMonth = getGet('month', date('Y-m')); // Default to current month
$filterType = getGet('type', 'all');
$filterDivision = getGet('division', 'all');
$filterPayment = getGet('payment', 'all');

// Build query with filters
$whereClauses = [];
$params = [];

// If date is specified and not 'all', filter by specific date
if ($filterDate !== 'all' && !empty($filterDate)) {
    $whereClauses[] = "cb.transaction_date = :date";
    $params['date'] = $filterDate;
} 
// Otherwise, filter by month (default to current month)
elseif (!empty($filterMonth) && $filterMonth !== 'all') {
    $whereClauses[] = "DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month";
    $params['month'] = $filterMonth;
}

if ($filterType !== 'all') {
    $whereClauses[] = "cb.transaction_type = :type";
    $params['type'] = $filterType;
}

if ($filterDivision !== 'all') {
    $whereClauses[] = "cb.division_id = :division";
    $params['division'] = $filterDivision;
}

if ($filterPayment !== 'all') {
    $whereClauses[] = "cb.payment_method = :payment";
    $params['payment'] = $filterPayment;
}

$whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get transactions - Use LEFT JOIN to handle missing references
$transactions = $db->fetchAll(
    "SELECT 
        cb.*,
        d.division_name,
        d.division_code,
        c.category_name,
        u.full_name as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN users u ON cb.created_by = u.id
    {$whereSQL}
    ORDER BY cb.transaction_date DESC, cb.transaction_time DESC",
    $params
);

// Get divisions for filter
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

// Calculate totals
$totalIncome = 0;
$totalExpense = 0;
foreach ($transactions as $trans) {
    if ($trans['transaction_type'] === 'income') {
        $totalIncome += $trans['amount'];
    } else {
        $totalExpense += $trans['amount'];
    }
}
$balance = $totalIncome - $totalExpense;

include '../../includes/header.php';
echo getPrintCSS();
?>

<style>
/* ===== COMPACT CASHBOOK TABLE STYLES ===== */
.cb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

.cb-table th {
    background: linear-gradient(135deg, var(--bg-secondary), var(--bg-tertiary));
    padding: 0.65rem 0.5rem;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--text-muted);
    border-bottom: 2px solid var(--bg-tertiary);
    white-space: nowrap;
}

.cb-table td {
    padding: 0.5rem;
    border-bottom: 1px solid var(--bg-tertiary);
    vertical-align: middle;
}

.cb-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.05);
}

.cb-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
}

.cb-badge.income {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
}

.cb-badge.expense {
    background: rgba(239, 68, 68, 0.15);
    color: #dc2626;
}

.cb-method {
    display: inline-block;
    padding: 0.15rem 0.4rem;
    background: var(--bg-tertiary);
    border-radius: 4px;
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--text-muted);
}

.cb-ref-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: rgba(99, 102, 241, 0.15);
    color: var(--primary-color);
    padding: 0.15rem 0.35rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 600;
    margin-right: 0.35rem;
}

.cb-actions {
    display: flex;
    gap: 0.25rem;
    justify-content: center;
}

.cb-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.cb-action-btn svg {
    width: 14px;
    height: 14px;
}

.cb-action-btn.edit {
    background: var(--bg-tertiary);
    color: var(--text-muted);
}

.cb-action-btn.edit:hover {
    background: var(--primary-color);
    color: white;
}

.cb-action-btn.delete {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.cb-action-btn.delete:hover {
    background: #ef4444;
    color: white;
}

.cb-action-btn.locked {
    background: var(--bg-tertiary);
    color: var(--text-muted);
    opacity: 0.5;
    cursor: not-allowed;
}

/* ===== ELEGANT PRINT STYLES ===== */
@media print {
    * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        color-adjust: exact;
    }
    
    body {
        background: white;
        margin: 0;
        padding: 0;
    }
    
    /* Hide non-print elements */
    .sidebar, .page-header, button, .btn, .table-actions, .table-header > div:last-child, 
    form, a[href*="add"], a[href*="logs"], [onclick*="print"] {
        display: none !important;
    }
    
    /* Main content */
    .main-content, .content-wrapper, .page-content {
        width: 100%;
        padding: 0;
        margin: 0;
        background: white;
    }
    
    /* Print header */
    .print-header {
        display: table;
        width: 100%;
        margin-bottom: 2rem;
        border-bottom: 3px solid #1e293b;
        padding-bottom: 1.5rem;
    }
    
    .print-header-left {
        display: table-cell;
        width: 15%;
        vertical-align: middle;
        text-align: center;
    }
    
    .print-header-center {
        display: table-cell;
        width: 70%;
        vertical-align: middle;
        text-align: center;
        padding: 0 2rem;
    }
    
    .print-header-right {
        display: table-cell;
        width: 15%;
        vertical-align: middle;
        text-align: center;
    }
    
    .print-logo {
        width: 80px;
        height: 80px;
        object-fit: contain;
        margin: 0 auto;
    }
    
    .print-company-name {
        font-size: 1.5rem;
        font-weight: 800;
        color: #0f172a;
        margin: 0.5rem 0 0.25rem 0;
        letter-spacing: -0.5px;
    }
    
    .print-company-type {
        font-size: 0.95rem;
        color: #64748b;
        margin: 0;
        font-weight: 500;
    }
    
    .print-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0f172a;
        margin: 1.5rem 0 0.5rem 0;
        text-align: center;
        text-decoration: underline;
        text-decoration-color: #6366f1;
        text-underline-offset: 0.5rem;
    }
    
    .print-period {
        font-size: 0.9rem;
        color: #475569;
        text-align: center;
        margin-bottom: 1.5rem;
    }
    
    /* Summary cards for print */
    .print-summary {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
        page-break-inside: avoid;
    }
    
    .print-summary-card {
        padding: 1rem;
        border: 2px solid #cbd5e1;
        border-radius: 0.5rem;
        background: #f8fafc;
        text-align: center;
    }
    
    .print-summary-label {
        font-size: 0.85rem;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .print-summary-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0f172a;
    }
    
    .print-summary-value.income {
        color: #059669;
    }
    
    .print-summary-value.expense {
        color: #dc2626;
    }
    
    .print-summary-value.balance {
        color: #1e40af;
    }
    
    /* Table styling */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
        page-break-inside: avoid;
    }
    
    thead {
        background: #1e293b;
        color: white;
    }
    
    th {
        padding: 0.75rem;
        text-align: left;
        font-weight: 700;
        font-size: 0.85rem;
        border: 1px solid #0f172a;
        letter-spacing: 0.5px;
    }
    
    th:last-child {
        text-align: right;
    }
    
    td {
        padding: 0.65rem 0.75rem;
        border: 1px solid #cbd5e1;
        font-size: 0.85rem;
    }
    
    tbody tr:nth-child(odd) {
        background: #f8fafc;
    }
    
    tbody tr:nth-child(even) {
        background: white;
    }
    
    .badge {
        display: inline-block;
        padding: 0.3rem 0.6rem;
        border-radius: 0.3rem;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .badge.income {
        background: #d1fae5;
        color: #065f46;
    }
    
    .badge.expense {
        background: #fee2e2;
        color: #991b1b;
    }
    
    td[style*="text-align: right"] {
        text-align: right !important;
        font-weight: 600;
    }
    
    .date-cell::before {
        content: attr(data-date);
    }
    
    /* Print footer */
    .print-footer {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 2px solid #cbd5e1;
        display: flex;
        justify-content: space-around;
        text-align: center;
        page-break-inside: avoid;
    }
    
    .print-footer-item {
        flex: 1;
    }
    
    .print-footer-label {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 2rem;
    }
    
    .print-footer-line {
        border-top: 1px solid #0f172a;
        height: 1px;
        margin-bottom: 0.5rem;
    }
    
    .print-footer-text {
        font-size: 0.85rem;
        color: #0f172a;
        font-weight: 600;
    }
    
    .print-notes {
        margin-top: 2rem;
        padding: 1rem;
        background: #f1f5f9;
        border-left: 4px solid #6366f1;
        font-size: 0.85rem;
        color: #334155;
    }
    
    /* Page break */
    @page {
        margin: 0.5in;
        size: A4;
    }
}
</style>

<!-- Print Version (Hidden in Screen) -->
<div style="display: none;" id="printSection" class="print-content">
    <?php 
    $periodText = !empty($filterMonth) ? date('F Y', strtotime($filterMonth . '-01')) : (($filterDate !== 'all') ? formatDate($filterDate) : 'Semua Periode');
    echo printHeader($db, $displayCompanyName, BUSINESS_ICON, BUSINESS_TYPE, '📊 DAFTAR TRANSAKSI', 'Periode: ' . $periodText);
    ?>
    
    <!-- Transactions Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 10%;">Tanggal</th>
                <th style="width: 6%;">Waktu</th>
                <th style="width: 12%;">Divisi</th>
                <th style="width: 12%;">Kategori</th>
                <th style="width: 6%;">Tipe</th>
                <th style="width: 14%; text-align: right;">Jumlah</th>
                <th style="width: 40%;">Deskripsi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $trans): ?>
                <tr>
                    <td><?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?></td>
                    <td><?php echo date('H:i', strtotime($trans['transaction_time'])); ?></td>
                    <td><strong><?php echo $trans['division_name']; ?></strong><br><span style="color: #94a3b8; font-size: 0.75rem;"><?php echo $trans['division_code']; ?></span></td>
                    <td><?php echo $trans['category_name']; ?></td>
                    <td><span class="badge <?php echo $trans['transaction_type']; ?>"><?php echo $trans['transaction_type'] === 'income' ? 'Masuk' : 'Keluar'; ?></span></td>
                    <td style="text-align: right; color: <?php echo $trans['transaction_type'] === 'income' ? '#059669' : '#dc2626'; ?>;">
                        <?php echo formatCurrency($trans['amount']); ?>
                    </td>
                    <td><?php echo $trans['description'] ?: '-'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Screen Display Section -->
<div id="screenSection">

<?php if (isset($_SESSION['success'])): ?>
    <div style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(16,185,129,0.15); animation: slideInDown 0.5s ease-out;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-feather="check-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #065f46; font-size: 1.125rem; margin-bottom: 0.25rem;">✅ Berhasil!</div>
                <div style="color: #047857; font-size: 0.95rem;"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            </div>
            <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #059669; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15); animation: slideInDown 0.5s ease-out;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-feather="x-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #991b1b; font-size: 1.125rem; margin-bottom: 0.25rem;">❌ Error!</div>
                <div style="color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            </div>
            <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #dc2626; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
        </div>
    </div>
<?php endif; ?>

<style>
@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<!-- Summary Cards -->
<div class="dashboard-grid" style="margin-bottom: 2rem;">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Total Pemasukan</div>
                <div class="card-value text-success"><?php echo formatCurrency($totalIncome); ?></div>
            </div>
            <div class="card-icon income">
                <i data-feather="arrow-down-circle"></i>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Total Pengeluaran</div>
                <div class="card-value text-danger"><?php echo formatCurrency($totalExpense); ?></div>
            </div>
            <div class="card-icon expense">
                <i data-feather="arrow-up-circle"></i>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Saldo</div>
                <div class="card-value <?php echo $balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo formatCurrency($balance); ?>
                </div>
            </div>
            <div class="card-icon balance">
                <i data-feather="dollar-sign"></i>
            </div>
        </div>
    </div>
</div>

<!-- Transactions Table -->
<div class="table-container">
    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center;">
                <i data-feather="book" style="width: 20px; height: 20px; color: white;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Daftar Transaksi
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    <?php echo count($transactions); ?> transaksi ditemukan
                </p>
            </div>
        </div>
        <div class="table-actions" style="display: flex; gap: 0.5rem;">
            <a href="logs.php" class="btn btn-secondary" style="display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="activity" style="width: 16px; height: 16px;"></i>
                <span>Audit Log</span>
            </a>
            <a href="add.php" class="btn btn-primary">
                <i data-feather="plus" style="width: 16px; height: 16px;"></i> Tambah Transaksi
            </a>
            <a href="index.php?<?php echo http_build_query(array_merge($_GET, ['print' => '1'])); ?>" target="_blank" class="btn btn-secondary">
                <i data-feather="printer" style="width: 16px; height: 16px;"></i> Cetak PDF
            </a>
        </div>
    </div>
    
    <!-- Filters -->
    <form method="GET" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.75rem; margin-bottom: 1.5rem; padding: 1.25rem; background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--bg-tertiary);">
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Tanggal</label>
            <input type="date" name="date" value="<?php echo $filterDate !== 'all' ? $filterDate : ''; ?>" class="form-control" style="height: 38px; font-size: 0.875rem;">
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Bulan</label>
            <input type="month" name="month" value="<?php echo $filterMonth; ?>" class="form-control" style="height: 38px; font-size: 0.875rem;">
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Tipe</label>
            <select name="type" class="form-control" style="height: 38px; font-size: 0.875rem;">
                <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>Semua</option>
                <option value="income" <?php echo $filterType === 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                <option value="expense" <?php echo $filterType === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Divisi</label>
            <select name="division" class="form-control" style="height: 38px; font-size: 0.875rem;">
                <option value="all">Semua Divisi</option>
                <?php foreach ($divisions as $div): ?>
                    <option value="<?php echo $div['id']; ?>" <?php echo $filterDivision == $div['id'] ? 'selected' : ''; ?>>
                        <?php echo $div['division_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Jenis Pembayaran</label>
            <select name="payment" class="form-control" style="height: 38px; font-size: 0.875rem;">
                <option value="all" <?php echo $filterPayment === 'all' ? 'selected' : ''; ?>>Semua</option>
                <option value="cash" <?php echo $filterPayment === 'cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="debit" <?php echo $filterPayment === 'debit' ? 'selected' : ''; ?>>Debit</option>
                <option value="transfer" <?php echo $filterPayment === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                <option value="qr" <?php echo $filterPayment === 'qr' ? 'selected' : ''; ?>>QR Code</option>
                <option value="edc" <?php echo $filterPayment === 'edc' ? 'selected' : ''; ?>>EDC</option>
                <option value="other" <?php echo $filterPayment === 'other' ? 'selected' : ''; ?>>Lainnya</option>
            </select>
        </div>
        
        <div style="display: flex; align-items: flex-end; gap: 0.625rem; grid-column: span 5;">
            <button type="submit" class="btn btn-primary" style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 40px;">
                <i data-feather="filter" style="width: 16px; height: 16px;"></i> 
                <span>Filter</span>
            </button>
            <a href="index.php" class="btn btn-secondary" style="flex: 0 0 auto; display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 40px; padding: 0 1.25rem;">
                <i data-feather="x" style="width: 16px; height: 16px;"></i> 
                <span>Reset</span>
            </a>
        </div>
    </form>
    
    <!-- Table -->
    <div style="overflow-x: auto;">
        <table class="cb-table">
            <thead>
                <tr>
                    <th style="width: 85px;">Tanggal</th>
                    <th style="width: 50px;">Waktu</th>
                    <th style="width: 100px;">Divisi</th>
                    <th style="width: 110px;">Kategori</th>
                    <th style="width: 60px;">Tipe</th>
                    <th style="width: 70px;">Metode</th>
                    <th style="width: 100px; text-align: right;">Jumlah</th>
                    <th style="text-align: center; padding-left: 1.5rem;">Deskripsi</th>
                    <th style="width: 70px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
                            <div>Belum ada transaksi</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    // Pre-calculate users per date (Shift detection)
                    $usersByDate = [];
                    foreach ($transactions as $t) {
                        $d = date('Y-m-d', strtotime($t['transaction_date']));
                        $userName = $t['created_by_name'] ? $t['created_by_name'] : 'System';
                        
                        if (!isset($usersByDate[$d])) { 
                            $usersByDate[$d] = []; 
                        }
                        
                        // Avoid duplicates
                        if (!in_array($userName, $usersByDate[$d])) { 
                            $usersByDate[$d][] = $userName; 
                        }
                    }

                    $previousDate = null;
                    foreach ($transactions as $trans): 
                        // Date Separator Logic
                        $currentDate = date('Y-m-d', strtotime($trans['transaction_date']));
                        // Show separator for first item OR when date changes
                        if ($previousDate === null || $currentDate !== $previousDate):
                            // Get users for this specific date
                            $shiftUsers = implode(', ', $usersByDate[$currentDate] ?? []);
                    ?>
                        <tr style="background: linear-gradient(135deg, #f1f5f9, #e2e8f0);">
                            <td colspan="9" style="text-align: center; font-weight: 700; color: #475569; padding: 0.5rem; font-size: 0.8rem;">
                                Transaksi tanggal: <?php echo formatDate($trans['transaction_date']); ?>
                                <span style="margin-left: 15px; font-weight: 500; color: #64748b; font-size: 0.85em;">
                                    <i data-feather="users" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i>
                                    Shift: <?php echo $shiftUsers; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endif; 
                        $previousDate = $currentDate;
                    ?>
                        <tr>
                            <td style="font-size: 0.8rem; white-space: nowrap;">
                                <?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?>
                            </td>
                            <td style="font-size: 0.8rem;"><?php echo date('H:i', strtotime($trans['transaction_time'])); ?></td>
                            <td style="font-size: 0.8rem;">
                                <strong><?php echo $trans['division_name']; ?></strong>
                                <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo $trans['division_code']; ?></div>
                            </td>
                            <td style="font-size: 0.8rem;">
                                <?php 
                                    if ($trans['source_type'] === 'purchase_order' && strpos($trans['category_name'], 'Supplies') !== false) {
                                        if (preg_match('/Pembayaran PO .* - (.*)/', $trans['description'], $matches)) {
                                            echo 'Payment ' . htmlspecialchars($matches[1]);
                                        } else {
                                            echo 'Payment Supplier';
                                        }
                                    } else {
                                        echo $trans['category_name']; 
                                    }
                                ?>
                            </td>
                            <td>
                                <span class="cb-badge <?php echo $trans['transaction_type']; ?>">
                                    <?php echo $trans['transaction_type'] === 'income' ? 'MASUK' : 'KELUAR'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="cb-method">
                                    <?php echo htmlspecialchars(isset($trans['payment_method']) ? strtoupper($trans['payment_method']) : '-'); ?>
                                </span>
                            </td>
                            <td style="text-align: right; font-weight: 700; font-size: 0.85rem; color: <?php echo $trans['transaction_type'] === 'income' ? '#059669' : '#dc2626'; ?>;">
                                <?php echo formatCurrency($trans['amount']); ?>
                            </td>
                            <td style="font-size: 0.8rem;">
                                <?php if (isset($trans['source_type']) && $trans['source_type'] != 'manual'): ?>
                                    <span class="cb-ref-tag">
                                        <i data-feather="shopping-cart" style="width: 10px; height: 10px;"></i>
                                        <?php echo isset($trans['reference_no']) ? $trans['reference_no'] : 'REF'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php echo $trans['description'] ?: '-'; ?>
                            </td>
                            <td>
                                <div class="cb-actions">
                                    <?php if (isset($trans['is_editable']) && $trans['is_editable'] == 1): ?>
                                        <a href="edit.php?id=<?php echo $trans['id']; ?>" class="cb-action-btn edit" title="Edit">
                                            <i data-feather="edit-2"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="cb-action-btn locked" title="Dari PO">
                                            <i data-feather="lock"></i>
                                        </span>
                                    <?php endif; ?>
                                    <a href="delete.php?id=<?php echo $trans['id']; ?>" 
                                       onclick="return confirm('Yakin ingin menghapus transaksi ini?')" 
                                       class="cb-action-btn delete" 
                                       title="Hapus">
                                        <i data-feather="trash-2"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- JavaScript for Print Handling -->
<script>
    // Check if print parameter is in URL
    const urlParams = new URLSearchParams(window.location.search);
    const isPrint = urlParams.has('print') || window.location.search.includes('print=1');
    
    if (isPrint) {
        // Remove screen content and show print content
        document.getElementById('screenSection').innerHTML = document.getElementById('printSection').innerHTML;
        document.getElementById('printSection').remove();
        
        // Auto-trigger print dialog
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    } else {
        // Hide print section for screen
        document.getElementById('printSection').style.display = 'none';
    }
    
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
