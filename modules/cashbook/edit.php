<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Edit Cash Book Transaction
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// ── Permission: only users with can_edit on cashbook may proceed ─────────────
if (!$auth->canEdit('cashbook')) {
    $_SESSION['error'] = '⛔ Anda tidak memiliki izin untuk mengedit transaksi.';
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$pageTitle = 'Edit Transaksi Kas';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    $_SESSION['error'] = 'ID transaksi tidak valid';
    header('Location: index.php');
    exit;
}

// Get transaction
$transaction = $db->fetchOne(
    "SELECT 
        cb.*,
        d.division_name,
        c.category_name
    FROM cash_book cb
    JOIN divisions d ON cb.division_id = d.id
    JOIN categories c ON cb.category_id = c.id
    WHERE cb.id = :id",
    ['id' => $id]
);

if (!$transaction) {
    $_SESSION['error'] = 'Transaksi tidak ditemukan';
    header('Location: index.php');
    exit;
}

// Check if transaction is editable
if (isset($transaction['is_editable']) && $transaction['is_editable'] == 0) {
    if ($transaction['source_type'] == 'purchase_order') {
        $_SESSION['error'] = '❌ Transaksi ini berasal dari Purchase Order #' . $transaction['source_id'] . '. Silakan edit melalui halaman Purchase Order.';
    } else {
        $_SESSION['error'] = '❌ Transaksi ini tidak dapat diedit. Alasan: Dikunci oleh sistem.';
    }
    header('Location: index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_date = $_POST['transaction_date'] ?? '';
    $transaction_time = $_POST['transaction_time'] ?? '';
    $transaction_type = $_POST['transaction_type'] ?? '';
    $division_id = (int)($_POST['division_id'] ?? 0);
    $category_name_input = trim($_POST['category_name'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = $_POST['description'] ?? '';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $cash_account_id = !empty($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null;
    
    // Validate
    $errors = [];
    if (empty($transaction_date)) $errors[] = 'Tanggal transaksi wajib diisi';
    if (empty($transaction_time)) $errors[] = 'Waktu transaksi wajib diisi';
    if (empty($transaction_type)) $errors[] = 'Tipe transaksi wajib dipilih';
    if ($division_id <= 0) $errors[] = 'Divisi wajib dipilih';
    if (empty($category_name_input)) $errors[] = 'Kategori/Nama wajib diisi';
    if ($amount <= 0) $errors[] = 'Jumlah harus lebih dari 0';
    
    // Find or create category by name
    $category_id = 0;
    if (!empty($category_name_input)) {
        // Check if category exists
        $existingCategory = $db->fetchOne(
            "SELECT id FROM categories WHERE LOWER(category_name) = LOWER(:name) AND category_type = :type",
            ['name' => $category_name_input, 'type' => $transaction_type]
        );
        
        if ($existingCategory) {
            $category_id = $existingCategory['id'];
        } else {
            // Create new category
            $db->insert('categories', [
                'category_name' => $category_name_input,
                'category_type' => $transaction_type,
                'division_id' => $division_id,
                'is_active' => 1
            ]);
            $category_id = $db->getConnection()->lastInsertId();
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Save old data to audit log
            $oldData = json_encode([
                'id' => $transaction['id'],
                'transaction_date' => $transaction['transaction_date'],
                'transaction_time' => $transaction['transaction_time'],
                'transaction_type' => $transaction['transaction_type'],
                'division' => $transaction['division_name'],
                'category' => $transaction['category_name'],
                'amount' => $transaction['amount'],
                'description' => $transaction['description'],
                'payment_method' => $transaction['payment_method']
            ], JSON_UNESCAPED_UNICODE);
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            $db->insert('audit_logs', [
                'table_name' => 'cash_book',
                'record_id' => $id,
                'action' => 'UPDATE',
                'old_data' => $oldData,
                'user_id' => $currentUser['id'],
                'user_name' => $currentUser['full_name'],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent
            ]);
            
            // Update transaction
            $db->update('cash_book', [
                'transaction_date' => $transaction_date,
                'transaction_time' => $transaction_time,
                'transaction_type' => $transaction_type,
                'division_id' => $division_id,
                'category_id' => $category_id,
                'amount' => $amount,
                'description' => $description,
                'payment_method' => $payment_method,
                'cash_account_id' => $cash_account_id
            ], 'id = :id', ['id' => $id]);
            
            // ============================================
            // FIX: Update cash_accounts balance when editing
            // 1. Reverse old transaction from old account
            // 2. Apply new transaction to new account
            // ============================================
            try {
                $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $oldAmount = floatval($transaction['amount']);
                $oldType = $transaction['transaction_type'];
                $oldAccountId = $transaction['cash_account_id'] ?? null;
                $newAmount = $amount;
                $newType = $transaction_type;
                $newAccountId = $cash_account_id;
                
                // Step 1: Reverse old transaction from old account
                if (!empty($oldAccountId)) {
                    if ($oldType === 'income') {
                        // Old income: subtract from old account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                        $stmt->execute([$oldAmount, $oldAccountId]);
                    } else {
                        // Old expense: add back to old account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                        $stmt->execute([$oldAmount, $oldAccountId]);
                    }
                    
                    // Delete old cash_account_transactions record
                    $stmt = $masterDb->prepare("DELETE FROM cash_account_transactions WHERE cash_account_id = ? AND description LIKE ? AND ABS(amount - ?) < 1 ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$oldAccountId, '%' . substr($transaction['description'] ?? '', 0, 50) . '%', $oldAmount]);
                    
                    error_log("EDIT REVERSE: Account #{$oldAccountId}, Type: {$oldType}, Amount: {$oldAmount}");
                }
                
                // Step 2: Apply new transaction to new account
                if (!empty($newAccountId)) {
                    if ($newType === 'income') {
                        // New income: add to new account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                        $stmt->execute([$newAmount, $newAccountId]);
                    } else {
                        // New expense: subtract from new account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                        $stmt->execute([$newAmount, $newAccountId]);
                    }
                    
                    // Insert new cash_account_transactions record
                    $stmt = $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_date, description, amount, transaction_type, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$newAccountId, $transaction_date, $description ?: $category_name_input, $newAmount, $newType, $currentUser['id']]);
                    
                    error_log("EDIT APPLY: Account #{$newAccountId}, Type: {$newType}, Amount: {$newAmount}");
                }
            } catch (Exception $balanceErr) {
                error_log("Edit balance update error: " . $balanceErr->getMessage());
                // Don't fail the edit, just log the error
            }
            
            $db->commit();
            
            $_SESSION['success'] = '✅ Transaksi berhasil diupdate';
            header('Location: index.php');
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = '❌ Gagal update transaksi: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// Get divisions and categories
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");
$categories = $db->fetchAll("SELECT * FROM categories ORDER BY category_name");

// Get cash accounts from MASTER database (cash_accounts table is in adf_system, not business DB)
$cashAccounts = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get business ID dynamically
    $businessId = getMasterBusinessId();
    
    // Load cash accounts if we have a business ID
    if ($businessId) {
        $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? ORDER BY is_default_account DESC, account_name");
        $stmt->execute([$businessId]);
        $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching cash accounts in edit.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $cashAccounts = []; // Empty array if query fails
}

include '../../includes/header.php';
?>

<?php if (isset($_SESSION['error'])): ?>
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i data-feather="x-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1; color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        </div>
    </div>
<?php endif; ?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                ✏️ Edit Transaksi Kas
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Update data transaksi keuangan</p>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
            Kembali
        </a>
    </div>
</div>

<!-- Source Info -->
<?php if ($transaction['source_type'] !== 'manual'): ?>
<div style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-left: 4px solid #3b82f6; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <i data-feather="info" style="width: 20px; height: 20px; color: #1e40af;"></i>
        <div>
            <strong style="color: #1e3a8a;">Source:</strong>
            <span style="color: #1e40af;"><?php echo ucfirst(str_replace('_', ' ', $transaction['source_type'])); ?></span>
            <?php if ($transaction['source_id']): ?>
                <span style="color: #1e40af;"> (#<?php echo $transaction['source_id']; ?>)</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <form method="POST">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem;">
            <!-- Tanggal -->
            <div class="form-group">
                <label class="form-label">Tanggal Transaksi *</label>
                <input type="date" name="transaction_date" class="form-control" value="<?php echo $transaction['transaction_date']; ?>" required>
            </div>

            <!-- Waktu -->
            <div class="form-group">
                <label class="form-label">Waktu Transaksi *</label>
                <input type="time" name="transaction_time" class="form-control" value="<?php echo $transaction['transaction_time']; ?>" required>
            </div>

            <!-- Tipe -->
            <div class="form-group">
                <label class="form-label">Tipe Transaksi *</label>
                <select name="transaction_type" class="form-control" required id="transaction_type">
                    <option value="">Pilih Tipe</option>
                    <option value="income" <?php echo $transaction['transaction_type'] == 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                    <option value="expense" <?php echo $transaction['transaction_type'] == 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                </select>
            </div>

            <!-- Divisi -->
            <div class="form-group">
                <label class="form-label">Divisi *</label>
                <select name="division_id" class="form-control" required>
                    <option value="">Pilih Divisi</option>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div['id']; ?>" <?php echo $transaction['division_id'] == $div['id'] ? 'selected' : ''; ?>>
                            <?php echo $div['division_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Kategori/Nama -->
            <div class="form-group">
                <label class="form-label">Kategori/Nama *</label>
                <input type="text" name="category_name" id="category_name" class="form-control" 
                       value="<?php echo htmlspecialchars($transaction['category_name']); ?>" 
                       placeholder="Ketik kategori atau nama transaksi" 
                       list="category_suggestions" required autocomplete="off">
                <datalist id="category_suggestions">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['category_name']); ?>" data-type="<?php echo $cat['category_type']; ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">💡 Ketik manual atau pilih dari saran yang muncul</small>
            </div>

            <!-- Cash Account -->
            <div class="form-group">
                <label class="form-label">Akun Kas</label>
                <select name="cash_account_id" class="form-control">
                    <option value="">-- Pilih Akun Kas (opsional) --</option>
                    <?php if (empty($cashAccounts)): ?>
                        <option value="" disabled style="color: #dc2626;">⚠️ Tidak ada akun kas tersedia. Hubungi admin!</option>
                    <?php else: ?>
                        <?php foreach ($cashAccounts as $acc): ?>
                            <option value="<?php echo htmlspecialchars($acc['id']); ?>" 
                                    <?php echo (!empty($transaction['cash_account_id']) && $transaction['cash_account_id'] == $acc['id'] ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($cashAccounts)): ?>
                    <small style="color: #dc2626; display: block; margin-top: 0.25rem; padding: 0.5rem; background: #fee2e2; border-radius: 4px; border-left: 3px solid #dc2626;">
                        <strong>⚠️ Error:</strong> Akun kas tidak ditemukan di database master. Pastikan cash_accounts sudah di-setup untuk bisnis ini.
                    </small>
                <?php else: ?>
                    <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">💡 Pilih akun untuk tracking yang lebih detail</small>
                <?php endif; ?>
            </div>

            <!-- Payment Method -->
            <div class="form-group">
                <label class="form-label">Metode Pembayaran *</label>
                <select name="payment_method" class="form-control" required>
                    <option value="cash" <?php echo $transaction['payment_method'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="debit" <?php echo $transaction['payment_method'] == 'debit' ? 'selected' : ''; ?>>Debit</option>
                    <option value="transfer" <?php echo $transaction['payment_method'] == 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                    <option value="qr" <?php echo $transaction['payment_method'] == 'qr' ? 'selected' : ''; ?>>QR Code</option>
                    <option value="edc" <?php echo $transaction['payment_method'] == 'edc' ? 'selected' : ''; ?>>EDC</option>
                    <option value="other" <?php echo $transaction['payment_method'] == 'other' ? 'selected' : ''; ?>>Lainnya</option>
                </select>
            </div>

            <!-- Amount -->
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Jumlah *</label>
                <input type="number" name="amount" class="form-control" step="0.01" min="0" value="<?php echo $transaction['amount']; ?>" required>
            </div>

            <!-- Description -->
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Deskripsi</label>
                <textarea name="description" class="form-control" rows="3"><?php echo $transaction['description']; ?></textarea>
            </div>
        </div>

        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
            <a href="index.php" class="btn btn-secondary">
                <i data-feather="x" style="width: 16px; height: 16px;"></i>
                Batal
            </a>
            <button type="submit" class="btn btn-primary">
                <i data-feather="save" style="width: 16px; height: 16px;"></i>
                Update Transaksi
            </button>
        </div>
    </form>
</div>

<script>
    feather.replace();
    
    // Store all categories for filtering
    const allCategories = [
        <?php foreach ($categories as $cat): ?>
        { name: "<?php echo addslashes($cat['category_name']); ?>", type: "<?php echo $cat['category_type']; ?>" },
        <?php endforeach; ?>
    ];
    
    function updateCategorySuggestions() {
        const type = document.getElementById('transaction_type').value;
        const datalist = document.getElementById('category_suggestions');
        
        // Clear existing options
        datalist.innerHTML = '';
        
        // Add filtered options
        allCategories.forEach(cat => {
            if (type === '' || cat.type === type) {
                const option = document.createElement('option');
                option.value = cat.name;
                datalist.appendChild(option);
            }
        });
    }
    
    // Update suggestions when transaction type changes
    document.getElementById('transaction_type').addEventListener('change', updateCategorySuggestions);
    
    // Initialize on page load
    updateCategorySuggestions();
</script>

<?php include '../../includes/footer.php'; ?>
