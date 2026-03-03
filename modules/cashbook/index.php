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
    } catch (\Throwable $e) {}

    if ($hasBookingPayments) {
        // Ensure synced_to_cashbook column exists
        $hasSyncCol = false;
        try {
            $syncColChk = $db->getConnection()->query("SHOW COLUMNS FROM booking_payments LIKE 'synced_to_cashbook'");
            $hasSyncCol = $syncColChk && $syncColChk->rowCount() > 0;
        } catch (\Throwable $e) {}
        if (!$hasSyncCol) {
            try {
                $db->getConnection()->exec("ALTER TABLE booking_payments ADD COLUMN synced_to_cashbook TINYINT(1) NOT NULL DEFAULT 0");
                $db->getConnection()->exec("ALTER TABLE booking_payments ADD COLUMN cashbook_id INT(11) DEFAULT NULL");
                $hasSyncCol = true;
            } catch (\Throwable $e) {
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
            $masterDb = null;
            try {
                $masterDb = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
                    DB_USER, DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            } catch (\Throwable $masterErr) {
                // FALLBACK: Use current DB connection if Master DB fails
                // Critical for Single-DB Hosting environments
                if (defined('DB_NAME')) {
                    try {
                        $masterDb = new PDO(
                            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                            DB_USER, DB_PASS,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                        );
                    } catch (\Throwable $e2) {
                        $masterDb = $db->getConnection();
                    }
                } else {
                    $masterDb = $db->getConnection();
                }
            }
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
            } catch (\Throwable $e) {}

            // Detect payment_method ENUM
            $allowedPaymentMethods = null;
            try {
                $pmColInfo = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
                if ($pmColInfo && strpos($pmColInfo['Type'], 'enum') === 0) {
                    preg_match_all("/'([^']+)'/", $pmColInfo['Type'], $enumMatches);
                    $allowedPaymentMethods = $enumMatches[1] ?? ['cash'];
                }
            } catch (\Throwable $e) {}

            $division = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%front%' OR LOWER(division_name) LIKE '%room%' OR LOWER(division_name) LIKE '%kamar%' ORDER BY id ASC LIMIT 1");
            if (!$division) $division = $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
            $divisionId = $division['id'] ?? 1;

            $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id ASC LIMIT 1");
            if (!$category) $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
            $categoryId = $category['id'] ?? 1;

            // ============================================
            // IMPORTANT: Only sync payments where:
            // - Direct Booking: sync anytime (already paid)
            // - OTA: ONLY sync when booking status = checked_in or checked_out
            // This prevents OTA payments from entering kas before check-in!
            // ============================================
            $otaSources = "'agoda', 'booking', 'booking.com', 'tiket', 'tiket.com', 'traveloka', 'airbnb', 'expedia', 'pegipegi', 'ota'";
            
            if ($hasSyncCol) {
                $unsyncedPayments = $db->fetchAll("
                    SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                           b.booking_code, b.booking_source, b.final_price, b.status as booking_status, g.guest_name, r.room_number
                    FROM booking_payments bp
                    JOIN bookings b ON bp.booking_id = b.id
                    LEFT JOIN guests g ON b.guest_id = g.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE bp.synced_to_cashbook = 0 
                    AND (
                        -- Direct Booking: sync anytime
                        (LOWER(COALESCE(b.booking_source,'')) NOT IN ({$otaSources}) AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%ota%')
                        OR
                        -- OTA: only sync if checked_in or checked_out
                        (b.status IN ('checked_in', 'checked_out'))
                    )
                    ORDER BY bp.id ASC
                ");
            } else {
                $unsyncedPayments = $db->fetchAll("
                    SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                           b.booking_code, b.booking_source, b.final_price, b.status as booking_status, g.guest_name, r.room_number
                    FROM booking_payments bp
                    JOIN bookings b ON bp.booking_id = b.id
                    LEFT JOIN guests g ON b.guest_id = g.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE bp.payment_date >= DATE_SUB(NOW(), INTERVAL 60 DAY) 
                    AND (
                        -- Direct Booking: sync anytime
                        (LOWER(COALESCE(b.booking_source,'')) NOT IN ({$otaSources}) AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%ota%')
                        OR
                        -- OTA: only sync if checked_in or checked_out
                        (b.status IN ('checked_in', 'checked_out'))
                    )
                    ORDER BY bp.id ASC
                ");
            }

            $syncCount = 0;
            foreach ($unsyncedPayments as $payment) {
                try {
                    // ============================================
                    // FIX: Enhanced duplicate prevention
                    // Always check cash_book for existing entry BEFORE insert
                    // ============================================
                    $existingEntry = $db->fetchOne(
                        "SELECT id FROM cash_book WHERE description LIKE ? AND ABS(amount - ?) < 1 AND transaction_type = 'income' AND transaction_date = DATE(?) LIMIT 1",
                        ['%' . $payment['booking_code'] . '%', $payment['amount'], $payment['payment_date']]
                    );
                    if ($existingEntry) {
                        // Mark as synced even if entry already exists (prevent retry loop)
                        if ($hasSyncCol) {
                            try { $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$existingEntry['id'], $payment['payment_id']]); } catch (\Throwable $e) {}
                        }
                        continue;
                    }
                    
                    // Fallback dedup if no sync column (legacy support)
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
                    
                    // FALLBACK: If no specific account type found, get ANY active account
                    if (!$account) {
                        $fallbackStmt = $masterDb->prepare("SELECT id, current_balance FROM cash_accounts WHERE business_id = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
                        $fallbackStmt->execute([$businessId]);
                        $account = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                    }
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
                        try { $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$transactionId, $payment['payment_id']]); } catch (\Throwable $e) {}
                    }

                    try {
                        // SMART FIX: Check if transaction_id column exists
                        $hasTransIdCol = false;
                        try {
                            $chk = $masterDb->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'");
                            $hasTransIdCol = $chk && $chk->rowCount() > 0;
                        } catch (\Throwable $e) {}

                        if ($hasTransIdCol) {
                            $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                                $account['id'], $transactionId, $payment['payment_date'], $desc, $netAmount, $payment['booking_code'], $cbUserId
                            ]);
                        } else {
                            $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                                $account['id'], $payment['payment_date'], $desc, $netAmount, $payment['booking_code'], $cbUserId
                            ]);
                        }
                        
                        $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$netAmount, $account['id']]);
                    } catch (\Throwable $masterErr) {
                        error_log("Cashbook page master sync error: " . $masterErr->getMessage());
                    }
                    $syncCount++;
                } catch (\Throwable $paymentError) {
                    error_log("Cashbook page sync error payment#{$payment['payment_id']}: " . $paymentError->getMessage());
                    continue;
                }
            }
            if ($syncCount > 0) {
                error_log("Cashbook page auto-sync: {$syncCount} payments synced");
            }
        }
    }
} catch (\Throwable $syncError) {
    error_log("Cashbook page sync setup error: " . $syncError->getMessage());
}

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// ============================================
// BUSINESS FEATURE DETECTION (CONFIG-BASED)
// Uses enabled_modules and business_type from config
// NOT hardcoded business ID - allows proper isolation
// ============================================
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isHotel = ($businessConfig['business_type'] ?? '') === 'hotel';

// Legacy compatibility - use feature flags for conditional logic
$isCQC = $hasProjectModule; // Only true if business has cqc-projects module enabled

// Project module: Load project names for mapping (only if module enabled)
$cqcProjectMap = [];
if ($hasProjectModule) {
    try {
        require_once __DIR__ . '/../cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();
        $stmt = $cqcPdo->query("SELECT id, project_name, project_code, client_name, status, budget_idr, spent_idr FROM cqc_projects ORDER BY project_name");
        $cqcAllProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cqcAllProjects as $p) {
            $cqcProjectMap[$p['id']] = $p;
        }
        
        // Also load expense-to-project mapping
        $cqcExpenseProjectMap = [];
        $stmt2 = $cqcPdo->query("SELECT e.description, e.amount, e.expense_date, e.project_id, e.category_id, c.category_name, c.category_icon FROM cqc_project_expenses e LEFT JOIN cqc_expense_categories c ON e.category_id = c.id");
        $cqcExpenseRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cqcExpenseRows as $exp) {
            $key = $exp['description'] . '|' . number_format($exp['amount'], 2, '.', '') . '|' . $exp['expense_date'];
            $cqcExpenseProjectMap[$key] = $exp;
        }
    } catch (Exception $e) {
        error_log('CQC project map error: ' . $e->getMessage());
    }
}

// Get company name from settings
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : BUSINESS_NAME;

$pageTitle = ($isCQC ? '☀️ CQC' : BUSINESS_ICON . ' ' . $displayCompanyName) . ' - Buku Kas Besar';
$pageSubtitle = $isCQC ? 'Pencatatan Keuangan Proyek Solar Panel' : 'Pencatatan Transaksi Keuangan';

// Filtering - sanitize inputs (handle empty strings from form submission)
$filterDate = trim(getGet('date', ''));
$filterMonth = trim(getGet('month', ''));
$filterType = trim(getGet('type', 'all'));
$filterDivision = trim(getGet('division', 'all'));
$filterPayment = trim(getGet('payment', 'all'));
$filterUser = trim(getGet('user', 'all'));

// SMART CONFLICT RESOLUTION: If both date and month are provided,
// and date falls within the selected month, prioritize MONTH filter
// (user likely just forgot to clear the date field)
if (!empty($filterDate) && !empty($filterMonth)) {
    if (substr($filterDate, 0, 7) === $filterMonth) {
        // Date is within the selected month - use month filter
        $filterDate = '';
    }
    // If date is from a different month, use date (user explicitly picked it)
}

// Default month to current if no filters provided at all
// For CQC, default to no month filter to show all transactions
if (empty($filterDate) && empty($filterMonth) && !isset($_GET['date']) && !isset($_GET['type'])) {
    if (!$isCQC) {
        $filterMonth = date('Y-m');
    }
    // CQC: no default filter, show all transactions
}

// Validate month format (YYYY-MM) - fix for browsers that don't support type="month"
if (!empty($filterMonth) && !preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = date('Y-m'); // fallback to current month
}

// Build query with filters
$whereClauses = [];
$params = [];

// If date is specified, filter by specific date
if (!empty($filterDate)) {
    $whereClauses[] = "cb.transaction_date = :date";
    $params['date'] = $filterDate;
} 
// Otherwise, filter by month
elseif (!empty($filterMonth)) {
    $whereClauses[] = "DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month";
    $params['month'] = $filterMonth;
}

if (!empty($filterType) && $filterType !== 'all') {
    $whereClauses[] = "cb.transaction_type = :type";
    $params['type'] = $filterType;
}

if (!empty($filterDivision) && $filterDivision !== 'all') {
    $whereClauses[] = "cb.division_id = :division";
    $params['division'] = $filterDivision;
}

if (!empty($filterPayment) && $filterPayment !== 'all') {
    $whereClauses[] = "cb.payment_method = :payment";
    $params['payment'] = $filterPayment;
}

if (!empty($filterUser) && $filterUser !== 'all') {
    $whereClauses[] = "cb.created_by = :user_id";
    $params['user_id'] = $filterUser;
}

$whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get transactions - Use LEFT JOIN to handle missing references
// Check if transaction_time column exists (might differ on hosting)
$hasTransactionTime = true;
try {
    $db->getConnection()->query("SELECT transaction_time FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasTransactionTime = false;
}
$orderBy = $hasTransactionTime ? 'cb.transaction_date DESC, cb.transaction_time DESC' : 'cb.transaction_date DESC, cb.id DESC';

// Use cross-database join to get user names from master database
$masterDbName = DB_NAME;
$transactions = $db->fetchAll(
    "SELECT 
        cb.*,
        COALESCE(d.division_name, 'Unknown') as division_name,
        COALESCE(d.division_code, '-') as division_code,
        COALESCE(c.category_name, 'Unknown') as category_name,
        COALESCE(u.full_name, 'System') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN {$masterDbName}.users u ON cb.created_by = u.id
    {$whereSQL}
    ORDER BY {$orderBy}",
    $params
);

// Debug: if query returns empty but shouldn't, log it
if (empty($transactions) && empty($whereClauses)) {
    error_log('CASHBOOK DEBUG: No transactions found even without filter. DB=' . Database::getCurrentDatabase() . ' whereSQL=' . $whereSQL);
}

// Get divisions for filter
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

// Get users for filter (from master database)
$usersForFilter = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $usersForFilter = $masterDb->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: empty array
    $usersForFilter = [];
}

// Calculate totals
$totalIncome = 0;
$totalExpense = 0;
$totalOwnerFund = 0; // ALL BUSINESSES: Owner top-up to Kas Operasional (NOT income)
$totalRealIncome = 0; // Real income from customers/invoices
$totalOfficeExpense = 0; // Office/operational expenses only (no project)
$totalProjectExpense = 0; // Project-linked expenses (for contractor businesses)
foreach ($transactions as $trans) {
    if ($trans['transaction_type'] === 'income') {
        $totalIncome += $trans['amount'];
        // ALL BUSINESSES: Separate owner fund from real income
        if (isset($trans['source_type']) && $trans['source_type'] === 'owner_fund') {
            $totalOwnerFund += $trans['amount'];
        } else {
            $totalRealIncome += $trans['amount'];
        }
    } else {
        $totalExpense += $trans['amount'];
        // Contractor businesses: Separate office vs project expenses
        if ($isContractor) {
            $desc = $trans['description'] ?? '';
            if (preg_match('/\[CQC_PROJECT:\d+\]/', $desc)) {
                $totalProjectExpense += $trans['amount'];
            } else {
                $totalOfficeExpense += $trans['amount'];
            }
        }
    }
}
$balance = $totalIncome - $totalExpense;

include '../../includes/header.php';
echo getPrintCSS();
?>

<?php if ($isCQC): ?>
<style>
/* ===== CQC BUKU KAS - CLEAN ELEGANT DESIGN ===== */
:root, body, body[data-theme="light"], body[data-theme="dark"] {
    --primary-color: #f0b429 !important;
    --primary-dark: #d4960d !important;
    --secondary-color: #0d1f3c !important;
}

/* Filter Card */
.cqc-filter-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e5e7eb;
    border-left: 4px solid #f0b429;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.cqc-filter-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 1rem;
}

.cqc-filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.cqc-filter-label {
    font-size: 0.7rem;
    font-weight: 700;
    color: #0d1f3c;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.cqc-filter-input {
    height: 40px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 0 0.75rem;
    font-size: 0.813rem;
    background: #f9fafb;
    color: #0d1f3c;
    transition: all 0.2s;
}

.cqc-filter-input:focus {
    outline: none;
    border-color: #f0b429;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(240, 180, 41, 0.1);
}

.cqc-filter-actions {
    grid-column: span 6;
    display: flex;
    gap: 0.75rem;
    margin-top: 0.5rem;
}

.cqc-btn-filter {
    flex: 1;
    height: 42px;
    background: #f0b429;
    color: #0d1f3c;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.875rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.cqc-btn-filter:hover {
    background: #d4960d;
    transform: translateY(-1px);
}

.cqc-btn-reset {
    padding: 0 1.5rem;
    height: 42px;
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.cqc-btn-reset:hover {
    background: #e5e7eb;
}

/* Table Header */
.table-header-cqc {
    background: #fff !important;
    border-radius: 12px !important;
    padding: 1rem 1.25rem !important;
    margin-bottom: 1rem !important;
    border: 1px solid #e5e7eb !important;
    border-left: 4px solid #f0b429 !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04) !important;
}

.table-header-cqc h3 { color: #0d1f3c !important; }
.table-header-cqc p { color: #6b7280 !important; }

/* Buttons */
.btn-primary { background: #f0b429 !important; color: #0d1f3c !important; border: none !important; font-weight: 700 !important; }
.btn-primary:hover { background: #d4960d !important; }
.btn-secondary { background: #f3f4f6 !important; color: #374151 !important; border: 1px solid #e5e7eb !important; }
.btn-secondary:hover { background: #e5e7eb !important; }

/* Table Styling */
.cb-table { border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; }
.cb-table th { 
    background: #f9fafb !important; 
    color: #0d1f3c !important; 
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    border-bottom: 2px solid #f0b429 !important;
    padding: 0.75rem 0.5rem !important;
}
.cb-table td { border-bottom: 1px solid #f3f4f6 !important; }
.cb-table tbody tr:hover { background: rgba(240, 180, 41, 0.04) !important; }

/* Date Header Row */
.cb-table .date-header-row td {
    background: linear-gradient(90deg, rgba(240, 180, 41, 0.1), transparent) !important;
    font-weight: 700 !important;
    color: #0d1f3c !important;
    border-bottom: 1px solid rgba(240, 180, 41, 0.3) !important;
}

/* Tags */
.cb-badge.income { background: rgba(16, 185, 129, 0.12); color: #059669; font-weight: 700; }
.cb-badge.expense { background: rgba(239, 68, 68, 0.12); color: #dc2626; font-weight: 700; }
.cb-ref-tag { background: rgba(240, 180, 41, 0.15) !important; color: #92400e !important; }
.cqc-project-tag { display: inline-flex; align-items: center; gap: 0.25rem; background: linear-gradient(135deg, rgba(240,180,41,0.15), rgba(240,180,41,0.08)); color: #0d1f3c; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.72rem; font-weight: 700; border-left: 3px solid #f0b429; }
.cqc-office-tag { display: inline-flex; align-items: center; gap: 0.25rem; background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(59,130,246,0.06)); color: #1d4ed8; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.72rem; font-weight: 700; border-left: 3px solid #3b82f6; }

/* Info Chips */
.cqc-payment-info { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.4rem; }
.cqc-info-chip { display: inline-flex; align-items: center; gap: 0.2rem; padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.68rem; font-weight: 600; }
.cqc-info-chip.method { background: rgba(59,130,246,0.1); color: #2563eb; }
.cqc-info-chip.account { background: rgba(139,92,246,0.1); color: #7c3aed; }
.cqc-info-chip.category { background: rgba(240,180,41,0.12); color: #92400e; }
.cqc-info-chip.user { background: rgba(107,114,128,0.1); color: #4b5563; }

/* Action Buttons */
.cb-action-btn.edit { background: rgba(240, 180, 41, 0.15); color: #92400e; }
.cb-action-btn.edit:hover { background: #f0b429; color: #0d1f3c; }
.cb-action-btn.delete { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
.cb-action-btn.delete:hover { background: #ef4444; color: #fff; }

@media (max-width: 1024px) {
    .cqc-filter-grid { grid-template-columns: repeat(2, 1fr); }
    .cqc-filter-actions { grid-column: span 2; }
}

@media (max-width: 640px) {
    .cqc-filter-grid { grid-template-columns: 1fr; }
    .cqc-filter-actions { grid-column: span 1; flex-direction: column; }
}

/* ===== CQC DAILY EXPENSES CONTAINER ===== */
.cqc-daily-expenses {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e5e7eb;
    border-left: 4px solid #f0b429;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.cqc-daily-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.cqc-daily-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: linear-gradient(135deg, #f0b429, #d4960d);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.cqc-daily-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0d1f3c;
}

.cqc-daily-subtitle {
    font-size: 0.7rem;
    color: #6b7280;
}

.cqc-daily-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.cqc-daily-card {
    padding: 1rem;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    transition: all 0.2s;
}

.cqc-daily-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
}

.cqc-daily-card.owner {
    background: linear-gradient(135deg, rgba(240,180,41,0.08), rgba(240,180,41,0.03));
    border-left: 4px solid #f0b429;
}

.cqc-daily-card.expense {
    background: linear-gradient(135deg, rgba(239,68,68,0.06), rgba(239,68,68,0.02));
    border-left: 4px solid #ef4444;
}

.cqc-daily-card.balance {
    background: linear-gradient(135deg, rgba(59,130,246,0.08), rgba(59,130,246,0.03));
    border-left: 4px solid #3b82f6;
}

.cqc-daily-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.35rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.cqc-daily-card.owner .cqc-daily-label { color: #92400e; }
.cqc-daily-card.expense .cqc-daily-label { color: #dc2626; }
.cqc-daily-card.balance .cqc-daily-label { color: #2563eb; }

.cqc-daily-value {
    font-size: 1.4rem;
    font-weight: 800;
    margin-bottom: 0.25rem;
}

.cqc-daily-card.owner .cqc-daily-value { color: #b45309; }
.cqc-daily-card.expense .cqc-daily-value { color: #dc2626; }
.cqc-daily-card.balance .cqc-daily-value { color: #2563eb; }

.cqc-daily-desc {
    font-size: 0.65rem;
    color: #6b7280;
}

@media (max-width: 768px) {
    .cqc-daily-grid { grid-template-columns: 1fr; }
    .cqc-daily-value { font-size: 1.2rem; }
}
</style>
<?php endif; ?>

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

/* ===== PAYMENT INFO CHIPS (Global) ===== */
.cqc-payment-info {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.4rem;
}

.cqc-info-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    padding: 0.2rem 0.5rem;
    border-radius: 5px;
    font-size: 0.68rem;
    font-weight: 600;
}

.cqc-info-chip.method {
    background: rgba(59, 130, 246, 0.1);
    color: #2563eb;
}

.cqc-info-chip.account {
    background: rgba(139, 92, 246, 0.1);
    color: #7c3aed;
}

.cqc-info-chip.category {
    background: rgba(240, 180, 41, 0.12);
    color: #92400e;
}

.cqc-info-chip.user {
    background: rgba(107, 114, 128, 0.1);
    color: #4b5563;
}

/* ===== INPUT BY USER BADGE ===== */
.cb-user-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.5rem;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(99, 102, 241, 0.05));
    color: #4f46e5;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
    white-space: nowrap;
    border: 1px solid rgba(99, 102, 241, 0.15);
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
        font-family: 'Segoe UI', Arial, sans-serif;
    }
    
    /* Hide non-print elements */
    .sidebar, .page-header, button, .btn, .table-actions, .table-header > div:last-child, 
    form, a[href*="add"], a[href*="logs"], [onclick*="print"], .dashboard-grid, .table-container {
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
        margin-bottom: 1rem;
        border-bottom: 2px solid #111827;
        padding-bottom: 1rem;
    }
    
    .print-header-left {
        display: table-cell;
        width: 12%;
        vertical-align: middle;
        text-align: center;
    }
    
    .print-header-center {
        display: table-cell;
        width: 76%;
        vertical-align: middle;
        text-align: center;
        padding: 0 1rem;
    }
    
    .print-header-right {
        display: table-cell;
        width: 12%;
        vertical-align: middle;
        text-align: right;
    }
    
    .print-logo {
        width: 70px;
        height: 70px;
        object-fit: contain;
        margin: 0 auto;
    }
    
    .print-company-name {
        font-size: 1.4rem;
        font-weight: 800;
        color: #111827;
        margin: 0 0 0.15rem 0;
        letter-spacing: -0.3px;
    }
    
    .print-company-type {
        font-size: 0.8rem;
        color: #6b7280;
        margin: 0;
        font-weight: 400;
    }
    
    .print-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #111827;
        margin: 0.75rem 0 0.3rem 0;
        text-align: center;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .print-period {
        font-size: 0.85rem;
        color: #6b7280;
        text-align: center;
        margin-bottom: 0;
    }
    
    /* Summary cards for print */
    .print-summary {
        display: flex;
        gap: 0;
        margin-bottom: 1rem;
        page-break-inside: avoid;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        overflow: hidden;
    }
    
    .print-summary-card {
        flex: 1;
        padding: 0.6rem 0.75rem;
        text-align: center;
        border-right: 1px solid #d1d5db;
    }
    
    .print-summary-card:last-child {
        border-right: none;
    }
    
    .print-summary-label {
        font-size: 0.7rem;
        color: #6b7280;
        font-weight: 600;
        margin-bottom: 0.2rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .print-summary-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: #111827;
    }
    
    .print-summary-value.income { color: #059669; }
    .print-summary-value.expense { color: #dc2626; }
    .print-summary-value.balance { color: #111827; }
    
    /* Table styling */
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    
    thead {
        background: #111827;
        color: white;
    }
    
    th {
        padding: 0.5rem 0.5rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.7rem;
        border: 1px solid #111827;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }
    
    td {
        padding: 0.35rem 0.5rem;
        border: 1px solid #e5e7eb;
        font-size: 0.78rem;
        line-height: 1.3;
    }
    
    tbody tr:nth-child(even) {
        background: #f9fafb;
    }
    
    tfoot td {
        border-color: #d1d5db;
    }
    
    .badge {
        display: inline-block;
        padding: 0.15rem 0.4rem;
        border-radius: 3px;
        font-size: 0.65rem;
        font-weight: 700;
    }
    
    .badge.income {
        background: #d1fae5;
        color: #065f46;
    }
    
    .badge.expense {
        background: #fee2e2;
        color: #991b1b;
    }
    
    /* Print footer */
    .print-footer {
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid #d1d5db;
        display: flex;
        justify-content: space-around;
        text-align: center;
        page-break-inside: avoid;
    }
    
    .print-footer-item { flex: 1; }
    
    .print-footer-label {
        font-size: 0.75rem;
        color: #6b7280;
        margin-bottom: 2.5rem;
    }
    
    .print-footer-line {
        border-top: 1px solid #111827;
        height: 1px;
        margin-bottom: 0.3rem;
        width: 70%;
        margin-left: auto;
        margin-right: auto;
    }
    
    .print-footer-text {
        font-size: 0.8rem;
        color: #111827;
        font-weight: 600;
    }
    
    /* Page break */
    @page {
        margin: 0.4in 0.5in;
        size: A4;
    }
}
</style>

<!-- Print Version (Hidden in Screen) -->
<div style="display: none;" id="printSection" class="print-content">
    <?php 
    // Build dynamic period text
    $periodText = !empty($filterMonth) ? date('F Y', strtotime($filterMonth . '-01')) : (!empty($filterDate) ? formatDate($filterDate) : 'Semua Periode');
    
    // Build dynamic title based on active filters
    $printTitle = 'LAPORAN BUKU KAS BESAR';
    $filterTags = [];
    
    // Payment method filter label
    $paymentLabels = [
        'cash' => 'Transaksi Cash',
        'transfer' => 'Transaksi Transfer Bank',
        'debit' => 'Transaksi Debit/Kartu',
        'qr' => 'Transaksi QR Code',
        'edc' => 'Transaksi EDC',
        'other' => 'Transaksi Lainnya'
    ];
    if (!empty($filterPayment) && $filterPayment !== 'all') {
        $printTitle = strtoupper($paymentLabels[$filterPayment] ?? 'Transaksi ' . ucfirst($filterPayment));
        $filterTags[] = 'Pembayaran: ' . ucfirst($filterPayment);
    }
    
    // Division filter label
    if (!empty($filterDivision) && $filterDivision !== 'all') {
        $divName = '';
        foreach ($divisions as $d) {
            if ($d['id'] == $filterDivision) { $divName = $d['division_name']; break; }
        }
        if ($divName) {
            $printTitle = 'LAPORAN KAS DIVISI ' . strtoupper($divName);
            $filterTags[] = 'Divisi: ' . $divName;
        }
    }
    
    // Type filter label
    if (!empty($filterType) && $filterType !== 'all') {
        $filterTags[] = 'Tipe: ' . ($filterType === 'income' ? 'Pemasukan' : 'Pengeluaran');
    }
    
    // If both payment + division, combine
    if (!empty($filterPayment) && $filterPayment !== 'all' && !empty($filterDivision) && $filterDivision !== 'all' && $divName) {
        $printTitle = strtoupper(($paymentLabels[$filterPayment] ?? 'Transaksi ' . ucfirst($filterPayment)) . ' - Divisi ' . $divName);
    }
    
    echo printHeader($db, $displayCompanyName, BUSINESS_ICON, BUSINESS_TYPE, $printTitle, 'Periode: ' . $periodText);
    ?>
    
    <?php if (!empty($filterTags)): ?>
    <div style="text-align: center; margin-bottom: 0.75rem;">
        <?php foreach ($filterTags as $tag): ?>
        <span style="display: inline-block; padding: 0.2rem 0.6rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.75rem; color: #475569; margin: 0 0.15rem;"><?php echo $tag; ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Summary Totals -->
    <div class="print-summary">
        <div class="print-summary-card">
            <div class="print-summary-label">Total Pemasukan</div>
            <div class="print-summary-value income"><?php echo formatCurrency($totalIncome); ?></div>
            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.2rem;"><?php $incomeCount = 0; foreach($transactions as $t) if($t['transaction_type']==='income') $incomeCount++; echo $incomeCount; ?> transaksi</div>
        </div>
        <div class="print-summary-card">
            <div class="print-summary-label">Total Pengeluaran</div>
            <div class="print-summary-value expense"><?php echo formatCurrency($totalExpense); ?></div>
            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.2rem;"><?php echo count($transactions) - $incomeCount; ?> transaksi</div>
        </div>
        <div class="print-summary-card">
            <div class="print-summary-label">Saldo / Selisih</div>
            <div class="print-summary-value balance"><?php echo formatCurrency($balance); ?></div>
            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.2rem;"><?php echo count($transactions); ?> total transaksi</div>
        </div>
    </div>
    
    <!-- Transactions Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 9%;">Tanggal</th>
                <th style="width: 5%;">Waktu</th>
                <th style="width: 11%;">Divisi</th>
                <th style="width: 11%;">Kategori</th>
                <th style="width: 5%;">Tipe</th>
                <th style="width: 6%;">Metode</th>
                <th style="width: 13%; text-align: right;">Jumlah</th>
                <th>Deskripsi</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($transactions as $trans): ?>
                <tr>
                    <td style="text-align: center; color: #94a3b8; font-size: 0.75rem;"><?php echo $no++; ?></td>
                    <td><?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?></td>
                    <td><?php echo isset($trans['transaction_time']) ? date('H:i', strtotime($trans['transaction_time'])) : '-'; ?></td>
                    <td><strong><?php echo $trans['division_name']; ?></strong></td>
                    <td><?php echo $trans['category_name']; ?></td>
                    <td><span class="badge <?php echo $trans['transaction_type']; ?>"><?php echo $trans['transaction_type'] === 'income' ? 'Masuk' : 'Keluar'; ?></span></td>
                    <td style="text-align: center; font-size: 0.75rem; text-transform: uppercase;"><?php echo htmlspecialchars($trans['payment_method'] ?? '-'); ?></td>
                    <td style="text-align: right; font-weight: 700; color: <?php echo $trans['transaction_type'] === 'income' ? '#059669' : '#dc2626'; ?>;">
                        <?php echo formatCurrency($trans['amount']); ?>
                    </td>
                    <td style="font-size: 0.8rem;"><?php echo $trans['description'] ?: '-'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight: 600; font-size: 0.78rem;">
                <td colspan="7" style="text-align: right; padding: 0.4rem 0.5rem; border-top: 1.5px solid #d1d5db;">Total Pemasukan:</td>
                <td style="text-align: right; color: #059669; padding: 0.4rem 0.5rem; border-top: 1.5px solid #d1d5db;"><?php echo formatCurrency($totalIncome); ?></td>
                <td style="border-top: 1.5px solid #d1d5db;"></td>
            </tr>
            <tr style="font-weight: 600; font-size: 0.78rem;">
                <td colspan="7" style="text-align: right; padding: 0.4rem 0.5rem;">Total Pengeluaran:</td>
                <td style="text-align: right; color: #dc2626; padding: 0.4rem 0.5rem;"><?php echo formatCurrency($totalExpense); ?></td>
                <td></td>
            </tr>
            <tr style="font-weight: 700; font-size: 0.85rem; background: #f3f4f6;">
                <td colspan="7" style="text-align: right; padding: 0.5rem; border-top: 1.5px solid #9ca3af;">Saldo:</td>
                <td style="text-align: right; padding: 0.5rem; border-top: 1.5px solid #9ca3af; color: #111827;"><?php echo formatCurrency($balance); ?></td>
                <td style="border-top: 1.5px solid #9ca3af;"></td>
            </tr>
        </tfoot>
    </table>
    
    <?php echo printFooter($currentUser['full_name'] ?? 'Admin'); ?>
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
    <?php if ($isCQC): 
        $saldoKasOperasional = $totalOwnerFund - $totalOfficeExpense;
    ?>
    <!-- CQC: Kas Operasional = Dana Owner - Pengeluaran (Invoice TIDAK masuk sini) -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Dana dari Owner</div>
                <div class="card-value" style="color: #d97706;"><?php echo formatCurrency($totalOwnerFund); ?></div>
            </div>
            <div class="card-icon" style="background: linear-gradient(135deg, #fbbf24, #f59e0b);">
                <i data-feather="download"></i>
            </div>
        </div>
    </div>
    <?php else: ?>
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
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title"><?php echo $isCQC ? 'Pengeluaran Office' : 'Total Pengeluaran'; ?></div>
                <div class="card-value text-danger"><?php echo formatCurrency($isCQC ? $totalOfficeExpense : $totalExpense); ?></div>
            </div>
            <div class="card-icon expense">
                <i data-feather="arrow-up-circle"></i>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title"><?php echo $isCQC ? 'Saldo Kas Operasional' : 'Saldo'; ?></div>
                <div class="card-value <?php echo ($isCQC ? $saldoKasOperasional : $balance) >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo formatCurrency($isCQC ? $saldoKasOperasional : $balance); ?>
                </div>
            </div>
            <div class="card-icon balance">
                <i data-feather="dollar-sign"></i>
            </div>
        </div>
    </div>
</div>

<?php if ($isCQC): ?>
<!-- CQC Kas Operasional -->
<div class="cqc-daily-expenses">
    <div class="cqc-daily-header">
        <div class="cqc-daily-icon">💰</div>
        <div>
            <div class="cqc-daily-title">Kas Operasional CQC</div>
            <div class="cqc-daily-subtitle">Dana owner untuk operasional harian • Pengeluaran langsung memotong kas besar</div>
        </div>
    </div>
    <div class="cqc-daily-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="cqc-daily-card owner">
            <div class="cqc-daily-label">
                <i data-feather="download" style="width: 14px; height: 14px;"></i>
                Dana dari Owner
            </div>
            <div class="cqc-daily-value">Rp <?php echo number_format($totalOwnerFund, 0, ',', '.'); ?></div>
            <div class="cqc-daily-desc">Top up kas operasional harian</div>
        </div>
        <div class="cqc-daily-card expense">
            <div class="cqc-daily-label">
                <i data-feather="upload" style="width: 14px; height: 14px;"></i>
                Pengeluaran Office
            </div>
            <div class="cqc-daily-value">Rp <?php echo number_format($totalOfficeExpense, 0, ',', '.'); ?></div>
            <div class="cqc-daily-desc">Biaya operasional harian</div>
        </div>
        <div class="cqc-daily-card balance">
            <div class="cqc-daily-label">
                <i data-feather="credit-card" style="width: 14px; height: 14px;"></i>
                Saldo Kas Operasional
            </div>
            <div class="cqc-daily-value" style="color: <?php echo $saldoKasOperasional >= 0 ? '#2563eb' : '#dc2626'; ?>;">
                Rp <?php echo number_format($saldoKasOperasional, 0, ',', '.'); ?>
            </div>
            <div class="cqc-daily-desc">Dana owner − pengeluaran office</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Transactions Table -->
<div class="table-container">
    <div class="table-header <?php echo $isCQC ? 'table-header-cqc' : ''; ?>" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <?php if ($isCQC): ?>
            <div style="width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #f0b429, #d4960d); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                ☀️
            </div>
            <?php else: ?>
            <div style="width: 40px; height: 40px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center;">
                <i data-feather="book" style="width: 20px; height: 20px; color: white;"></i>
            </div>
            <?php endif; ?>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; margin: 0;">
                    <?php echo $isCQC ? 'Buku Kas Proyek CQC' : 'Daftar Transaksi'; ?>
                </h3>
                <p style="font-size: 0.813rem; margin: 0;">
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
    <?php if ($isCQC): ?>
    <form method="GET" action="" autocomplete="off" class="cqc-filter-card">
        <div class="cqc-filter-grid">
            <div class="cqc-filter-group">
                <label class="cqc-filter-label">📅 Tanggal</label>
                <input type="date" id="filterDate" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" class="cqc-filter-input" autocomplete="off" onchange="if(this.value) document.getElementById('filterMonth').value=''"<?php echo empty($filterDate) ? ' placeholder="Pilih tanggal"' : ''; ?>>
            </div>
            
            <div class="cqc-filter-group">
                <label class="cqc-filter-label">📆 Bulan</label>
                <input type="month" id="filterMonth" name="month" value="<?php echo htmlspecialchars($filterMonth); ?>" class="cqc-filter-input" autocomplete="off" placeholder="YYYY-MM" pattern="\d{4}-\d{2}" onchange="if(this.value) document.getElementById('filterDate').value=''">
            </div>
            
            <div class="cqc-filter-group">
                <label class="cqc-filter-label">📊 Tipe</label>
                <select name="type" class="cqc-filter-input">
                    <option value="all" <?php echo ($filterType === 'all' || empty($filterType)) ? 'selected' : ''; ?>>Semua</option>
                    <option value="income" <?php echo $filterType === 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                    <option value="expense" <?php echo $filterType === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                </select>
            </div>
            
            <div class="cqc-filter-group">
                <label class="cqc-filter-label">☀️ Proyek</label>
                <select name="division" class="cqc-filter-input">
                    <option value="all">Semua Proyek</option>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div['id']; ?>" <?php echo $filterDivision == $div['id'] ? 'selected' : ''; ?>>
                            <?php echo $div['division_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="cqc-filter-group">
                <label class="cqc-filter-label">💳 Pembayaran</label>
                <select name="payment" class="cqc-filter-input">
                    <option value="all" <?php echo ($filterPayment === 'all' || empty($filterPayment)) ? 'selected' : ''; ?>>Semua</option>
                    <option value="cash" <?php echo $filterPayment === 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="transfer" <?php echo $filterPayment === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                    <option value="debit" <?php echo $filterPayment === 'debit' ? 'selected' : ''; ?>>Debit</option>
                    <option value="qr" <?php echo $filterPayment === 'qr' ? 'selected' : ''; ?>>QR Code</option>
                </select>
            </div>
            
            <div class="cqc-filter-group">
                <label class="cqc-filter-label">👤 Input By</label>
                <select name="user" class="cqc-filter-input">
                    <option value="all" <?php echo ($filterUser === 'all' || empty($filterUser)) ? 'selected' : ''; ?>>Semua User</option>
                    <?php foreach ($usersForFilter as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="cqc-filter-actions">
                <button type="submit" class="cqc-btn-filter">
                    <i data-feather="filter" style="width: 16px; height: 16px;"></i> 
                    <span>Filter Data</span>
                </button>
                <a href="index.php" class="cqc-btn-reset">
                    <i data-feather="x" style="width: 16px; height: 16px;"></i> 
                    <span>Reset</span>
                </a>
            </div>
        </div>
    </form>
    <?php else: ?>
    <form method="GET" action="" autocomplete="off" style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 0.75rem; margin-bottom: 1.5rem; padding: 1.25rem; background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--bg-tertiary);">
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Tanggal</label>
            <input type="date" id="filterDate" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" class="form-control" autocomplete="off" style="height: 38px; font-size: 0.875rem;" onchange="if(this.value) document.getElementById('filterMonth').value=''"<?php echo empty($filterDate) ? ' placeholder="Pilih tanggal"' : ''; ?>>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Bulan</label>
            <input type="month" id="filterMonth" name="month" value="<?php echo htmlspecialchars($filterMonth); ?>" class="form-control" autocomplete="off" style="height: 38px; font-size: 0.875rem;" placeholder="YYYY-MM" pattern="\d{4}-\d{2}" onchange="if(this.value) document.getElementById('filterDate').value=''">
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Tipe</label>
            <select name="type" class="form-control" style="height: 38px; font-size: 0.875rem;">
                <option value="all" <?php echo ($filterType === 'all' || empty($filterType)) ? 'selected' : ''; ?>>Semua</option>
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
                <option value="all" <?php echo ($filterPayment === 'all' || empty($filterPayment)) ? 'selected' : ''; ?>>Semua</option>
                <option value="cash" <?php echo $filterPayment === 'cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="debit" <?php echo $filterPayment === 'debit' ? 'selected' : ''; ?>>Debit</option>
                <option value="transfer" <?php echo $filterPayment === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                <option value="qr" <?php echo $filterPayment === 'qr' ? 'selected' : ''; ?>>QR Code</option>
                <option value="edc" <?php echo $filterPayment === 'edc' ? 'selected' : ''; ?>>EDC</option>
                <option value="other" <?php echo $filterPayment === 'other' ? 'selected' : ''; ?>>Lainnya</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Input By</label>
            <select name="user" class="form-control" style="height: 38px; font-size: 0.875rem;">
                <option value="all" <?php echo ($filterUser === 'all' || empty($filterUser)) ? 'selected' : ''; ?>>Semua User</option>
                <?php foreach ($usersForFilter as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['full_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="display: flex; align-items: flex-end; gap: 0.625rem; grid-column: span 6;">
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
    <?php endif; ?>
    
    <!-- Table -->
    <div style="overflow-x: auto;">
        <table class="cb-table">
            <thead>
                <tr>
                    <th style="width: 85px;">Tanggal</th>
                    <th style="width: 50px;">Waktu</th>
                    <th style="width: 100px;"><?php echo $isCQC ? 'Proyek' : 'Divisi'; ?></th>
                    <th style="width: 110px;">Kategori/Nama</th>
                    <th style="width: 60px;">Tipe</th>
                    <th style="width: 70px;">Metode</th>
                    <th style="width: 100px; text-align: right;">Jumlah</th>
                    <th style="text-align: left;">Keterangan</th>
                    <th style="width: 80px;">Input By</th>
                    <th style="width: 70px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
                            <div>Belum ada transaksi</div>
                            <?php if (!empty($filterDate) || !empty($filterMonth)): ?>
                            <div style="margin-top: 0.5rem; font-size: 0.8rem;">
                                Filter aktif: <?php echo !empty($filterDate) ? "Tanggal: {$filterDate}" : "Bulan: {$filterMonth}"; ?>
                                <br><a href="index.php" style="color: var(--primary-color);">Klik untuk reset filter</a>
                            </div>
                            <?php endif; ?>
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
                            <td colspan="10" style="text-align: center; font-weight: 700; color: #475569; padding: 0.5rem; font-size: 0.8rem;">
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
                                <?php if ($isCQC): ?>
                                    <?php 
                                    // Check for Operational Office first
                                    $descForParse = $trans['description'] ?? '';
                                    $isOperational = strpos($descForParse, '[OPERATIONAL_OFFICE]') !== false;
                                    
                                    // Parse [CQC_PROJECT:id] from description
                                    $cqcProjMatch = null;
                                    if (!$isOperational && preg_match('/\[CQC_PROJECT:(\d+)\]/', $descForParse, $pidMatch)) {
                                        $cqcProjMatch = $cqcProjectMap[intval($pidMatch[1])] ?? null;
                                    }
                                    // Fallback: try expense mapping
                                    if (!$isOperational && !$cqcProjMatch) {
                                        $lookupKey = ($trans['category_name'] ?? '') . '|' . number_format($trans['amount'], 2, '.', '') . '|' . $trans['transaction_date'];
                                        if (isset($cqcExpenseProjectMap[$lookupKey])) {
                                            $expMatch = $cqcExpenseProjectMap[$lookupKey];
                                            $cqcProjMatch = $cqcProjectMap[$expMatch['project_id']] ?? null;
                                        }
                                    }
                                    ?>
                                    <?php if ($isOperational): ?>
                                    <span class="cqc-office-tag">🏢 Office</span>
                                    <div style="font-size: 0.7rem; color: #475569; margin-top: 0.15rem;">Operasional Kantor</div>
                                    <?php elseif ($cqcProjMatch): ?>
                                    <span class="cqc-project-tag">☀️ <?php echo htmlspecialchars($cqcProjMatch['project_code']); ?></span>
                                    <div style="font-size: 0.7rem; color: #475569; margin-top: 0.15rem;"><?php echo htmlspecialchars($cqcProjMatch['project_name']); ?></div>
                                    <?php else: ?>
                                    <span style="font-size: 0.75rem; color: #9ca3af;">—</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong><?php echo $trans['division_name']; ?></strong>
                                    <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo $trans['division_code']; ?></div>
                                <?php endif; ?>
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
                                <?php 
                                    $descDisplay = $trans['description'] ?: '-';
                                    // Strip CQC project tag and operational tag from display
                                    if ($isCQC) {
                                        $descDisplay = trim(preg_replace('/\[CQC_PROJECT:\d+\]\s*/', '', $descDisplay));
                                        $descDisplay = trim(preg_replace('/\[OPERATIONAL_OFFICE\]\s*/', '', $descDisplay));
                                        if (empty($descDisplay)) $descDisplay = '-';
                                    }
                                    echo $descDisplay;
                                ?>
                                
                            </td>
                            <td style="text-align: center;">
                                <span class="cb-user-badge">
                                    👤 <?php echo htmlspecialchars($trans['created_by_name'] ?: 'System'); ?>
                                </span>
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
        // Hide sidebar and page header
        document.querySelectorAll('.sidebar, .page-header').forEach(el => el.style.display = 'none');
        
        // Full width main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.marginLeft = '0';
            mainContent.style.padding = '0';
            mainContent.style.maxWidth = '100%';
        }
        
        // Replace screen content with print content
        const printHTML = document.getElementById('printSection').innerHTML;
        document.getElementById('screenSection').innerHTML = '';
        document.getElementById('screenSection').style.cssText = 'max-width: 900px; margin: 0 auto; padding: 2rem 1.5rem; background: white; font-family: Segoe UI, Arial, sans-serif;';
        document.getElementById('screenSection').innerHTML = printHTML;
        document.getElementById('printSection').remove();
        
        // Inject print-preview styles
        const printStyle = document.createElement('style');
        printStyle.textContent = `
            body { background: #f3f4f6 !important; }
            .print-header {
                display: table; width: 100%;
                margin-bottom: 1rem; border-bottom: 2px solid #111827; padding-bottom: 1rem;
            }
            .print-header-left { display: table-cell; width: 12%; vertical-align: middle; text-align: center; }
            .print-header-center { display: table-cell; width: 76%; vertical-align: middle; text-align: center; padding: 0 1rem; }
            .print-header-right { display: table-cell; width: 12%; vertical-align: middle; text-align: right; }
            .print-logo { width: 65px; height: 65px; object-fit: contain; }
            .print-company-name { font-size: 1.4rem; font-weight: 800; color: #111827; margin: 0 0 0.1rem 0; }
            .print-company-type { display: none; }
            .print-title { font-size: 1rem; font-weight: 700; color: #111827; margin: 0.5rem 0 0.2rem 0; text-transform: uppercase; letter-spacing: 1px; }
            .print-period { font-size: 0.85rem; color: #6b7280; margin: 0; }
            .print-summary {
                display: flex; gap: 0; margin-bottom: 1rem;
                border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden;
            }
            .print-summary-card { flex: 1; padding: 0.6rem 0.75rem; text-align: center; border-right: 1px solid #d1d5db; }
            .print-summary-card:last-child { border-right: none; }
            .print-summary-label { font-size: 0.7rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.15rem; }
            .print-summary-value { font-size: 1.05rem; font-weight: 800; color: #111827; }
            .print-summary-value.income { color: #059669; }
            .print-summary-value.expense { color: #dc2626; }
            .print-summary-value.balance { color: #111827; }
            #screenSection table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
            #screenSection thead { background: #111827; color: white; }
            #screenSection th { padding: 0.45rem 0.5rem; font-weight: 600; font-size: 0.7rem; border: 1px solid #111827; text-transform: uppercase; letter-spacing: 0.3px; text-align: left; }
            #screenSection td { padding: 0.35rem 0.5rem; border: 1px solid #e5e7eb; font-size: 0.78rem; line-height: 1.3; }
            #screenSection tbody tr:nth-child(even) { background: #f9fafb; }
            #screenSection tfoot td { border-color: #d1d5db; }
            #screenSection .badge { display: inline-block; padding: 0.15rem 0.4rem; border-radius: 3px; font-size: 0.65rem; font-weight: 700; }
            #screenSection .badge.income { background: #d1fae5; color: #065f46; }
            #screenSection .badge.expense { background: #fee2e2; color: #991b1b; }
            .print-footer { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #d1d5db; display: flex; justify-content: space-around; text-align: center; }
            .print-footer-item { flex: 1; }
            .print-footer-label { font-size: 0.75rem; color: #6b7280; margin-bottom: 2.5rem; }
            .print-footer-line { border-top: 1px solid #111827; width: 70%; margin: 0 auto 0.3rem auto; }
            .print-footer-text { font-size: 0.8rem; color: #111827; font-weight: 600; }
        `;
        document.head.appendChild(printStyle);
        
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
