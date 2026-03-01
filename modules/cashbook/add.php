<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Add New Transaction
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// CQC Detection
$isCQC = (strtolower(ACTIVE_BUSINESS_ID) === 'cqc');

$pageTitle = $isCQC ? '☀️ Input Transaksi Proyek' : 'Tambah Transaksi';
$pageSubtitle = $isCQC ? 'Catat pemasukan & pengeluaran proyek solar panel' : 'Input Transaksi Baru';

// Get divisions and categories
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

// CQC: Load projects and expense categories
$cqcProjects = [];
$cqcCategories = [];
if ($isCQC) {
    try {
        require_once __DIR__ . '/../cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();
        $stmt = $cqcPdo->query("SELECT id, project_name, project_code, client_name, status, budget_idr, spent_idr FROM cqc_projects ORDER BY status != 'installation' ASC, project_name ASC");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $cqcPdo->query("SELECT id, category_name, category_icon FROM cqc_expense_categories WHERE is_active = 1 ORDER BY id");
        $cqcCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('CQC project load error: ' . $e->getMessage());
    }
}

// Get cash accounts from MASTER database (cash_accounts table is in adf_system, not business DB)
$cashAccounts = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get business ID dynamically
    $businessId = getMasterBusinessId();
    
    // Load cash accounts if we have a business ID
    if ($businessId) {
        // Show all account types
        $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? AND is_active = 1 ORDER BY account_type = 'cash' DESC, account_type = 'bank' DESC, account_name");
        $stmt->execute([$businessId]);
        $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching cash accounts: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $cashAccounts = []; // Empty array if query fails
}

// Handle form submission
if (isPost()) {
    $transactionDate = sanitize(getPost('transaction_date'));
    $transactionTime = sanitize(getPost('transaction_time'));
    $divisionId = sanitize(getPost('division_id'));
    $categoryName = sanitize(getPost('category_name'));
    $transactionType = sanitize(getPost('transaction_type'));
    $amount = str_replace(['.', ','], '', getPost('amount')); // Remove formatting
    $description = sanitize(getPost('description'));
    $paymentMethod = sanitize(getPost('payment_method'));
    $cashAccountId = sanitize(getPost('cash_account_id')) ?: null; // Optional: can be null for now
    
    // Validation
    if (empty($transactionDate) || empty($divisionId) || empty($categoryName) || empty($amount)) {
        setFlash('error', 'Mohon lengkapi semua field yang wajib diisi!');
    } else {
        try {
            // Check if category exists, create if not
            $existingCategory = $db->fetchOne(
                "SELECT id FROM categories WHERE LOWER(category_name) = LOWER(:name) AND category_type = :type",
                ['name' => $categoryName, 'type' => $transactionType]
            );
            
            if ($existingCategory) {
                $categoryId = $existingCategory['id'];
            } else {
                // Create new category
                $db->insert('categories', [
                    'category_name' => $categoryName,
                    'category_type' => $transactionType,
                    'division_id' => $divisionId,
                    'is_active' => 1
                ]);
                
                // Get the newly inserted ID
                $categoryId = $db->getConnection()->lastInsertId();
            }
            
            // CQC: Embed project reference in description for list page lookup
            if ($isCQC && !empty(getPost('cqc_project_id'))) {
                $cqcProjVal = getPost('cqc_project_id');
                if ($cqcProjVal === 'operational') {
                    // Operational Office - non-project expense
                    $description = '[OPERATIONAL_OFFICE] ' . $description;
                } else {
                    $cqcProjId = intval($cqcProjVal);
                    $description = '[CQC_PROJECT:' . $cqcProjId . '] ' . $description;
                }
            }

            $data = [
                'transaction_date' => $transactionDate,
                'transaction_time' => $transactionTime ?: date('H:i:s'),
                'division_id' => $divisionId,
                'category_id' => $categoryId,
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'description' => $description,
                'payment_method' => $paymentMethod,
                'cash_account_id' => $cashAccountId,
                'created_by' => $_SESSION['user_id'],
                'source_type' => 'manual',
                'is_editable' => 1
            ];
            
            // ============================================
            // SMART LOGIC - ALWAYS Use Petty Cash First for Expense
            // ============================================
            $autoSwitched = false;
            if ($transactionType === 'expense') {
                try {
                    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Get business ID
                    $businessId = getMasterBusinessId();
                    
                    // ALWAYS get Petty Cash account first
                    $stmt = $masterDb->prepare("SELECT id, account_name, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' ORDER BY id LIMIT 1");
                    $stmt->execute([$businessId]);
                    $pettyCashAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($pettyCashAccount) {
                        error_log("SMART LOGIC - Petty Cash: {$pettyCashAccount['account_name']}, Balance: {$pettyCashAccount['current_balance']}, Expense Amount: {$amount}");
                        
                        // Check if Petty Cash is enough
                        if ($pettyCashAccount['current_balance'] >= $amount) {
                            // Petty Cash cukup, pakai Petty Cash
                            $cashAccountId = $pettyCashAccount['id'];
                            $data['cash_account_id'] = $cashAccountId;
                            error_log("SMART LOGIC - Using Petty Cash (sufficient balance)");
                        } else {
                            // Petty Cash tidak cukup, switch ke Modal Owner
                            error_log("SMART LOGIC TRIGGERED - Petty Cash insufficient, switching to Modal Owner");
                            
                            // Find Modal Owner account
                            $stmt = $masterDb->prepare("SELECT id, account_name, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital' ORDER BY id LIMIT 1");
                            $stmt->execute([$businessId]);
                            $modalOwnerAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($modalOwnerAccount) {
                                error_log("MODAL OWNER FOUND - ID: {$modalOwnerAccount['id']}, Balance: {$modalOwnerAccount['current_balance']}");
                                
                                // Auto-switch to Modal Owner (allow going negative if needed)
                                $cashAccountId = $modalOwnerAccount['id'];
                                $data['cash_account_id'] = $cashAccountId;
                                $autoSwitched = true;
                                
                                // Add notification to description
                                $originalDesc = $description ?: '';
                                $autoNote = '[AUTO: Petty Cash habis (Saldo: ' . number_format($pettyCashAccount['current_balance']) . '), potong dari Modal Owner]';
                                $data['description'] = trim($originalDesc . ' ' . $autoNote);
                                $description = $data['description'];
                                
                                error_log("AUTO-SWITCHED to Modal Owner ID: {$cashAccountId}");
                            } else {
                                error_log("MODAL OWNER NOT FOUND - Using original account selection");
                            }
                        }
                    } else {
                        error_log("PETTY CASH NOT FOUND - Using original account selection");
                    }
                } catch (Exception $e) {
                    error_log("Smart logic error: " . $e->getMessage());
                    // Continue with original account if error
                }
            }
            // ============================================
            
            // Start transaction for atomic operation
            $db->beginTransaction();
            
            try {
                // Insert to cash_book (business database)
                if ($db->insert('cash_book', $data)) {
                    $transactionId = $db->getConnection()->lastInsertId();
                    
                    // If user selected a cash account, also save to cash_account_transactions (master DB)
                    if (!empty($cashAccountId)) {
                        error_log("PROCESSING cash account transaction - Account ID: {$cashAccountId}, Type: {$transactionType}, Amount: {$amount}");
                        
                        try {
                            $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                            $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            
                            // Get account type to determine transaction type
                            $stmt = $masterDb->prepare("SELECT account_type FROM cash_accounts WHERE id = ?");
                            $stmt->execute([$cashAccountId]);
                            $account = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Determine transaction type for cash_account_transactions
                            $accountTransactionType = $transactionType; // Default: 'income' or 'expense'
                            
                            // If this is owner_capital account and income = capital injection
                            if ($account && $account['account_type'] === 'owner_capital' && $transactionType === 'income') {
                                $accountTransactionType = 'capital_injection';
                            }
                            
                            // Insert to cash_account_transactions
                            $stmt = $masterDb->prepare("
                                INSERT INTO cash_account_transactions 
                                (cash_account_id, transaction_date, description, amount, transaction_type, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            
                            $stmt->execute([
                                $cashAccountId,
                                $transactionDate,
                                $data['description'] ?: $categoryName, // Use updated description from $data
                                $amount,
                                $accountTransactionType,
                                $_SESSION['user_id']
                            ]);
                            
                            error_log("SAVED to cash_account_transactions - Account ID: {$cashAccountId}, Type: {$accountTransactionType}, Amount: {$amount}");
                            
                            // Update current_balance in cash_accounts
                            if ($transactionType === 'income') {
                                // Income: add to balance
                                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                                $stmt->execute([$amount, $cashAccountId]);
                                error_log("BALANCE UPDATED - Account ID: {$cashAccountId} - INCOME +{$amount}");
                            } else {
                                // Expense: subtract from balance (smart logic already handled account switch)
                                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                                $stmt->execute([$amount, $cashAccountId]);
                                error_log("BALANCE UPDATED - Account ID: {$cashAccountId} - EXPENSE -{$amount}");
                            }
                            
                        } catch (Exception $e) {
                            error_log("ERROR saving to cash_account_transactions: " . $e->getMessage());
                            error_log("ERROR Stack Trace: " . $e->getTraceAsString());
                            // Rethrow to fail the transaction if balance update fails
                            throw new Exception("Gagal update balance akun: " . $e->getMessage());
                        }
                    } else {
                        error_log("WARNING: cash_account_id is empty, skipping cash account transaction");
                    }
                    
                    // CQC: Also save to cqc_project_expenses if project selected (expense only)
                    // Skip if "operational" - that's for office expenses, not project expenses
                    $cqcProjectVal = getPost('cqc_project_id');
                    if ($isCQC && !empty($cqcProjectVal) && $cqcProjectVal !== 'operational') {
                        try {
                            require_once __DIR__ . '/../cqc-projects/db-helper.php';
                            $cqcPdo = getCQCDatabaseConnection();
                            $cqcProjectId = intval($cqcProjectVal);
                            $cqcCategoryId = !empty(getPost('cqc_category_id')) ? intval(getPost('cqc_category_id')) : null;
                            
                            if ($transactionType === 'expense') {
                                // Add source marker to notes to track it came from cashbook
                                $syncNotes = '[SYNCED_FROM_CASHBOOK:' . $transactionId . '] ' . ($description ?: $categoryName);
                                $stmtExp = $cqcPdo->prepare("INSERT INTO cqc_project_expenses (project_id, category_id, description, amount, expense_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmtExp->execute([$cqcProjectId, $cqcCategoryId, $categoryName, $amount, $transactionDate, $syncNotes, $_SESSION['user_id']]);
                                error_log("CQC SYNC: Cashbook -> Project expense. Project ID: {$cqcProjectId}, Amount: {$amount}");
                                
                                // Update spent_idr on the project
                                $stmtUpd = $cqcPdo->prepare("UPDATE cqc_projects SET spent_idr = (SELECT COALESCE(SUM(amount),0) FROM cqc_project_expenses WHERE project_id = ?) WHERE id = ?");
                                $stmtUpd->execute([$cqcProjectId, $cqcProjectId]);
                            }
                        } catch (Exception $e) {
                            error_log('CQC expense save error: ' . $e->getMessage());
                        }
                    }
                    
                    $db->commit();
                    
                    // Success message with auto-switch notification
                    if ($autoSwitched) {
                        setFlash('success', 'Transaksi berhasil! ⚡ Petty Cash tidak cukup, otomatis dipotong dari Modal Owner.');
                    } else {
                        setFlash('success', 'Transaksi berhasil ditambahkan!');
                    }
                    
                    redirect(BASE_URL . '/modules/cashbook/index.php');
                } else {
                    $db->rollBack();
                    setFlash('error', 'Gagal menambahkan transaksi!');
                }
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('error', 'Error: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            setFlash('error', 'Error: ' . $e->getMessage());
        }
    }
}

include '../../includes/header.php';
?>

<?php if ($isCQC): ?>
<style>
:root, body, body[data-theme="light"], body[data-theme="dark"] {
    --primary-color: #f0b429 !important;
    --primary-dark: #d4960d !important;
    --secondary-color: #0d1f3c !important;
}
.transaction-type-card:has(input[value="income"]:checked),
.transaction-type-card:has(input[value="expense"]:checked),
.payment-method-card:has(input[type="radio"]:checked) {
    border-color: #0d1f3c !important;
    box-shadow: 0 0 0 4px rgba(13,31,60,0.15), 0 8px 20px rgba(13,31,60,0.1) !important;
}
.transaction-type-card:has(input[value="income"]:checked) { border-color: #10b981 !important; box-shadow: 0 0 0 4px rgba(16,185,129,0.15) !important; }
.transaction-type-card:has(input[value="expense"]:checked) { border-color: #ef4444 !important; box-shadow: 0 0 0 4px rgba(239,68,68,0.15) !important; }
.btn-primary { background: linear-gradient(135deg, #0d1f3c, #1a3a5c) !important; color: #f0b429 !important; border: none !important; }
.btn-primary:hover { background: linear-gradient(135deg, #122a4e, #1f4570) !important; }
.cqc-project-option { display: flex; justify-content: space-between; }
.cqc-form-header { background: #ffffff !important; border-bottom: none !important; border-left: 4px solid #f0b429 !important; }
.cqc-form-header h3 { color: #0d1f3c !important; }
.cqc-form-header i { color: #f0b429 !important; }
.cqc-select-project { border: 2px solid rgba(13,31,60,0.15) !important; font-weight: 600 !important; }
.cqc-select-project:focus { border-color: #f0b429 !important; box-shadow: 0 0 0 3px rgba(240,180,41,0.2) !important; }
</style>
<?php endif; ?>

<style>
.payment-method-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0.75rem;
    background: var(--bg-tertiary);
    border: 2px solid transparent;
    border-radius: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.payment-method-card:hover {
    background: var(--bg-secondary);
    transform: translateY(-2px);
}

.payment-method-card input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.payment-method-card input[type="radio"]:checked + .payment-content {
    border-color: var(--primary-color);
}

.payment-method-card input[type="radio"]:checked ~ * {
    color: var(--primary-color);
}

/* Enhanced checked state for payment method */
.payment-method-card input[type="radio"]:checked ~ .payment-content {
    position: relative;
}

.payment-method-card input[type="radio"]:checked ~ .payment-content::after {
    content: '✓';
    position: absolute;
    top: -8px;
    right: -8px;
    width: 20px;
    height: 20px;
    background: var(--success);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.payment-method-card input[type="radio"]:checked {
    & + .payment-content {
        background: rgba(99, 102, 241, 0.1);
        border-radius: 0.5rem;
        padding: 0.25rem;
    }
}

.payment-method-card:has(input[type="radio"]:checked) {
    background: rgba(99, 102, 241, 0.05);
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.compact-form-group {
    margin-bottom: 0.5rem;
}

.compact-form-group:last-child {
    margin-bottom: 0;
}

.transaction-type-card {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-tertiary);
    border: 2px solid transparent;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.3s ease;
    min-height: 85px;
}

.transaction-type-card:hover {
    background: var(--bg-secondary);
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}

.transaction-type-card input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.transaction-type-card input[type="radio"]:checked ~ div i {
    transform: scale(1.1);
}

.transaction-type-card:has(input[type="radio"]:checked) {
    background: rgba(99, 102, 241, 0.08);
    border-color: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15), 0 8px 20px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

/* Green highlight for income */
.transaction-type-card:has(input[value="income"]:checked) {
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15), 0 8px 20px rgba(16, 185, 129, 0.2);
}

/* Red highlight for expense */
.transaction-type-card:has(input[value="expense"]:checked) {
    border-color: #ef4444;
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.15), 0 8px 20px rgba(239, 68, 68, 0.2);
}
</style>

<form method="POST" id="transactionForm" onsubmit="return validateForm('transactionForm')">
    <!-- Main Form Container -->
    <div class="card" style="max-width: 920px; margin: 0 auto 0.75rem;">
        <div class="<?php echo $isCQC ? 'cqc-form-header' : ''; ?>" style="padding: 0.875rem 1rem; border-bottom: 1px solid var(--bg-tertiary); <?php echo !$isCQC ? 'background: linear-gradient(135deg, var(--primary-color)15, var(--bg-secondary));' : 'border-radius: 12px 12px 0 0;'; ?>">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                <?php if ($isCQC): ?>
                    ☀️ Input Transaksi Proyek CQC
                <?php else: ?>
                    <i data-feather="plus-circle" style="width: 16px; height: 16px;"></i> Tambah Transaksi Baru
                <?php endif; ?>
            </h3>
        </div>
        
        <div style="padding: 0.875rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.625rem 0.875rem;">
            <!-- Transaction Type - FIRST (Full Width) -->
            <div style="grid-column: span 2; margin-bottom: 0.5rem;">
                <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.5rem; display: block;">Tipe Transaksi <span style="color: var(--danger);">*</span></label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; max-width: 400px;">
                    <label class="transaction-type-card" style="padding: 0.75rem;">
                        <input type="radio" name="transaction_type" value="income" required>
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.3rem; text-align: center;">
                            <i data-feather="trending-up" style="width: 22px; height: 22px; color: var(--success); stroke-width: 2.5;"></i>
                            <div>
                                <div style="font-weight: 700; font-size: 0.875rem; color: var(--text-primary);">UANG MASUK</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Pemasukan</div>
                            </div>
                        </div>
                    </label>
                    
                    <label class="transaction-type-card" style="padding: 0.75rem;">
                        <input type="radio" name="transaction_type" value="expense" required checked>
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.3rem; text-align: center;">
                            <i data-feather="trending-down" style="width: 22px; height: 22px; color: var(--danger); stroke-width: 2.5;"></i>
                            <div>
                                <div style="font-weight: 700; font-size: 0.875rem; color: var(--text-primary);">UANG KELUAR</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Pengeluaran</div>
                            </div>
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Column 1 -->
            <div>
                <!-- Date & Time -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Tanggal <span style="color: var(--danger);">*</span></label>
                    <input type="date" name="transaction_date" class="form-control" style="height: 34px; font-size: 0.813rem;" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Waktu</label>
                    <input type="time" name="transaction_time" class="form-control" style="height: 34px; font-size: 0.813rem;" value="<?php echo date('H:i'); ?>">
                </div>
                
                <?php if ($isCQC): ?>
                <!-- CQC: Project Selection -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; color: #0d1f3c;">☀️ Proyek <span style="color: var(--danger);">*</span></label>
                    <select name="cqc_project_id" id="cqc_project_id" class="form-control cqc-select-project" style="height: 38px; font-size: 0.813rem;" required onchange="updateCQCProjectInfo(this)">
                        <option value="">-- Pilih Proyek --</option>
                        <option value="operational" data-budget="0" data-spent="0" data-remaining="0" data-client="Office" style="background: #f0f9ff; font-weight: 600;">🏢 Operasional Office (Non-Proyek)</option>
                        <?php foreach ($cqcProjects as $proj): 
                            $statusLabels = ['planning'=>'📋 Planning','procurement'=>'🛒 Procurement','installation'=>'⚡ Instalasi','testing'=>'🔧 Testing','completed'=>'✅ Selesai','on_hold'=>'⏸️ Ditunda'];
                            $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
                            $remaining = floatval($proj['budget_idr']) - floatval($proj['spent_idr'] ?? 0);
                        ?>
                            <option value="<?php echo $proj['id']; ?>" 
                                    data-budget="<?php echo $proj['budget_idr']; ?>" 
                                    data-spent="<?php echo $proj['spent_idr'] ?? 0; ?>"
                                    data-remaining="<?php echo $remaining; ?>"
                                    data-client="<?php echo htmlspecialchars($proj['client_name'] ?? ''); ?>">
                                <?php echo htmlspecialchars($proj['project_code'] . ' - ' . $proj['project_name']); ?> [<?php echo $statusLabel; ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="cqcProjectInfo" style="display: none; margin-top: 0.4rem; padding: 0.5rem 0.75rem; background: linear-gradient(135deg, rgba(13,31,60,0.05), rgba(240,180,41,0.08)); border-radius: 8px; border-left: 3px solid #f0b429;">
                        <div style="display: flex; gap: 1rem; font-size: 0.7rem;">
                            <span>💰 Budget: <strong id="cqcBudgetDisplay">-</strong></span>
                            <span>📤 Terpakai: <strong id="cqcSpentDisplay" style="color: #ef4444;">-</strong></span>
                            <span>💵 Sisa: <strong id="cqcRemainingDisplay" style="color: #10b981;">-</strong></span>
                        </div>
                    </div>
                    <!-- Hidden division_id for cashbook compatibility -->
                    <input type="hidden" name="division_id" value="<?php echo !empty($divisions) ? $divisions[0]['id'] : 1; ?>">
                </div>
                
                <!-- CQC: Expense Category -->
                <div class="compact-form-group" id="cqcExpenseSection">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; color: #0d1f3c;">📦 Kategori Biaya <span style="color: var(--danger);">*</span></label>
                    <select name="cqc_category_id" id="cqc_category_id" class="form-control" style="height: 34px; font-size: 0.813rem;" onchange="document.querySelector('[name=category_name]').value = this.options[this.selectedIndex].text">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($cqcCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo $cat['category_icon'] . ' ' . htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom">✏️ Tulis Manual</option>
                    </select>
                    <input type="text" name="category_name" class="form-control" style="height: 34px; font-size: 0.813rem; margin-top: 0.3rem;" placeholder="Nama item / deskripsi biaya" required>
                </div>
                <!-- CQC: Income Type -->
                <div class="compact-form-group" id="cqcIncomeSection" style="display: none;">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; color: #0d1f3c;">💰 Jenis Pemasukan <span style="color: var(--danger);">*</span></label>
                    <select name="cqc_income_type" id="cqc_income_type" class="form-control" style="height: 34px; font-size: 0.813rem;" onchange="updateCQCIncomeCategory(this)">
                        <option value="">-- Pilih Jenis --</option>
                        <option value="dp">💵 DP Masuk</option>
                        <option value="termin">📄 Pembayaran Termin</option>
                        <option value="pelunasan">✅ Pelunasan</option>
                        <option value="retensi">🔒 Retensi / Garansi</option>
                        <option value="manual">✏️ Tulis Manual</option>
                    </select>
                    <input type="text" name="cqc_income_desc" id="cqc_income_desc" class="form-control" style="height: 34px; font-size: 0.813rem; margin-top: 0.3rem;" placeholder="Keterangan pemasukan" readonly>
                </div>
                <?php else: ?>
                <!-- Division -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Divisi <span style="color: var(--danger);">*</span></label>
                    <select name="division_id" id="division_id" class="form-control" style="height: 34px; font-size: 0.813rem;" required>
                        <option value="">-- Pilih Divisi --</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div['id']; ?>"><?php echo $div['division_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Category -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Kategori/Nama <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="category_name" class="form-control" style="height: 34px; font-size: 0.813rem;" placeholder="Nama kategori atau nama item" required>
                </div>
                <?php endif; ?>
                
                <!-- Amount -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Jumlah <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="amount" class="form-control amount-input" style="height: 36px; font-size: 0.938rem; font-weight: 600;" placeholder="0" required>
                </div>
            </div>
            
            <!-- Column 2 -->
            <div>
                <!-- Cash Account Selection -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; display: flex; align-items: center; justify-content: space-between;">
                        <span>Pilih Akun <span style="color: var(--danger);">*</span></span>
                        <a href="accounts.php" style="font-size: 0.7rem; font-weight: 600; color: <?php echo $isCQC ? '#0d1f3c' : 'var(--primary-color)'; ?>; text-decoration: none; display: flex; align-items: center; gap: 0.2rem;">
                            <i data-feather="settings" style="width: 12px; height: 12px;"></i> Setup Rekening
                        </a>
                    </label>
                    <select name="cash_account_id" class="form-control" style="height: 34px; font-size: 0.813rem; font-weight: 600;" required>
                        <option value="">-- Pilih Akun --</option>
                        <?php if (empty($cashAccounts)): ?>
                            <option value="" disabled style="color: #dc2626;">⚠️ Tidak ada akun kas tersedia. Hubungi admin!</option>
                        <?php else: ?>
                            <?php 
                            $accTypeIcons = ['cash'=>'💵','bank'=>'🏦','e-wallet'=>'📱','owner_capital'=>'👤','credit_card'=>'💳'];
                            foreach ($cashAccounts as $acc): 
                                $icon = $accTypeIcons[$acc['account_type']] ?? '💰';
                            ?>
                                <option value="<?php echo htmlspecialchars($acc['id']); ?>">
                                    <?php echo $icon . ' ' . htmlspecialchars($acc['account_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($cashAccounts)): ?>
                        <div style="font-size: 0.75rem; color: #dc2626; margin-top: 0.3rem; padding: 0.5rem; background: #fee2e2; border-radius: 4px; border-left: 3px solid #dc2626;">
                            <strong>⚠️ Error:</strong> Akun kas tidak ditemukan di database master. Pastikan cash_accounts sudah di-setup untuk bisnis ini.
                        </div>
                    <?php else: ?>
                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.3rem; line-height: 1.4;">
                            💡 Pilih rekening tujuan. <a href="accounts.php" style="color: <?php echo $isCQC ? '#0d1f3c' : 'var(--primary-color)'; ?>; font-weight: 600; text-decoration: none;">Tambah/edit rekening →</a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Payment Method -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Metode Pembayaran <span style="color: var(--danger);">*</span></label>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.4rem;">
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="cash" required checked>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="dollar-sign" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #10b981;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Cash</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="debit" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="credit-card" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #3b82f6;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Debit</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="transfer" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="send" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #8b5cf6;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Transfer</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="qr" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="smartphone" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #f59e0b;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">QR Code</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="edc" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="cpu" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #ec4899;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">EDC</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="other" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="more-horizontal" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #6b7280;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Lainnya</div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- Description -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Keterangan</label>
                    <textarea name="description" class="form-control" rows="2" style="font-size: 0.813rem; resize: none; line-height: 1.5;" placeholder="Keterangan tambahan (opsional)"></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Form Actions Container -->
    <div class="card" style="max-width: 920px; margin: 0 auto; padding: 0.875rem 1.125rem; background: var(--bg-secondary); display: flex; justify-content: flex-end; gap: 0.75rem;">
        <a href="index.php" class="btn btn-secondary" style="padding: 0.625rem 1.125rem; font-size: 0.875rem;">
            <i data-feather="x" style="width: 15px; height: 15px;"></i> Batal
        </a>
        <button type="submit" class="btn btn-primary" style="padding: 0.625rem 1.25rem; font-size: 0.875rem;">
            <i data-feather="save" style="width: 15px; height: 15px;"></i> Simpan Transaksi
        </button>
    </div>
</form>

<script>
feather.replace();

<?php if ($isCQC): ?>
// CQC Project Info Display
function updateCQCProjectInfo(select) {
    const opt = select.options[select.selectedIndex];
    const info = document.getElementById('cqcProjectInfo');
    if (!opt.value) { info.style.display = 'none'; return; }
    
    // Handle Operational Office selection
    if (opt.value === 'operational') {
        info.innerHTML = '<div style="display: flex; gap: 1rem; font-size: 0.75rem; color: #0d1f3c;"><span>🏢 <strong>Biaya Operasional Kantor</strong> - Pengeluaran ini tidak terkait dengan proyek tertentu</span></div>';
        info.style.display = 'block';
        info.style.background = 'linear-gradient(135deg, rgba(59,130,246,0.08), rgba(59,130,246,0.03))';
        info.style.borderLeftColor = '#3b82f6';
        return;
    }
    
    // Reset styling for regular projects
    info.style.background = 'linear-gradient(135deg, rgba(13,31,60,0.05), rgba(240,180,41,0.08))';
    info.style.borderLeftColor = '#f0b429';
    info.innerHTML = '<div style="display: flex; gap: 1rem; font-size: 0.7rem;"><span>💰 Budget: <strong id="cqcBudgetDisplay">-</strong></span><span>📤 Terpakai: <strong id="cqcSpentDisplay" style="color: #ef4444;">-</strong></span><span>💵 Sisa: <strong id="cqcRemainingDisplay" style="color: #10b981;">-</strong></span></div>';
    
    const budget = parseFloat(opt.dataset.budget || 0);
    const spent = parseFloat(opt.dataset.spent || 0);
    const remaining = parseFloat(opt.dataset.remaining || 0);
    
    document.getElementById('cqcBudgetDisplay').textContent = 'Rp ' + budget.toLocaleString('id-ID');
    document.getElementById('cqcSpentDisplay').textContent = 'Rp ' + spent.toLocaleString('id-ID');
    document.getElementById('cqcRemainingDisplay').textContent = 'Rp ' + remaining.toLocaleString('id-ID');
    document.getElementById('cqcRemainingDisplay').style.color = remaining >= 0 ? '#10b981' : '#ef4444';
    info.style.display = 'block';
    
    // Update income description if income is selected
    updateCQCIncomeCategory(document.getElementById('cqc_income_type'));
}

// Toggle expense/income sections based on transaction type
function toggleCQCSections() {
    const type = document.querySelector('input[name="transaction_type"]:checked')?.value;
    const expSection = document.getElementById('cqcExpenseSection');
    const incSection = document.getElementById('cqcIncomeSection');
    const catInput = document.querySelector('[name=category_name]');
    
    if (type === 'expense') {
        expSection.style.display = 'block';
        incSection.style.display = 'none';
        catInput.setAttribute('required', 'required');
        catInput.value = '';
    } else {
        expSection.style.display = 'none';
        incSection.style.display = 'block';
        catInput.removeAttribute('required');
        // Reset income type
        document.getElementById('cqc_income_type').value = '';
        document.getElementById('cqc_income_desc').value = '';
    }
}

// Update category_name based on income type + selected project
function updateCQCIncomeCategory(select) {
    if (!select) return;
    const type = select.value;
    const projSelect = document.getElementById('cqc_project_id');
    const projOpt = projSelect.options[projSelect.selectedIndex];
    const projName = projOpt && projOpt.value ? projOpt.textContent.trim().split(' [')[0] : '';
    const descInput = document.getElementById('cqc_income_desc');
    const catInput = document.querySelector('[name=category_name]');
    
    const labels = {
        'dp': 'DP Masuk',
        'termin': 'Pembayaran Termin',
        'pelunasan': 'Pelunasan',
        'retensi': 'Retensi / Garansi'
    };
    
    if (type === 'manual') {
        descInput.removeAttribute('readonly');
        descInput.placeholder = 'Tulis keterangan pemasukan...';
        descInput.value = '';
        descInput.focus();
        // category_name will be set on form submit
    } else if (type && labels[type]) {
        descInput.setAttribute('readonly', 'readonly');
        const desc = projName ? labels[type] + ' - ' + projName : labels[type];
        descInput.value = desc;
        catInput.value = desc;
    } else {
        descInput.setAttribute('readonly', 'readonly');
        descInput.value = '';
        catInput.value = '';
    }
}

// Listen for transaction type changes
document.querySelectorAll('input[name="transaction_type"]').forEach(radio => {
    radio.addEventListener('change', toggleCQCSections);
});

// Handle form submit - sync income desc to category_name
document.getElementById('transactionForm').addEventListener('submit', function() {
    const type = document.querySelector('input[name="transaction_type"]:checked')?.value;
    if (type === 'income') {
        const desc = document.getElementById('cqc_income_desc').value;
        if (desc) {
            document.querySelector('[name=category_name]').value = desc;
        }
    }
});

// Initialize on load
toggleCQCSections();
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
