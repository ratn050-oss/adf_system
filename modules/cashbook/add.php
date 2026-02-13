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

$pageTitle = 'Tambah Transaksi';
$pageSubtitle = 'Input Transaksi Baru';

// Get divisions and categories
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

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
            
            $data = [
                'transaction_date' => $transactionDate,
                'transaction_time' => $transactionTime ?: date('H:i:s'),
                'division_id' => $divisionId,
                'category_id' => $categoryId,
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'description' => $description,
                'payment_method' => $paymentMethod,
                'created_by' => $_SESSION['user_id'],
                'source_type' => 'manual',
                'is_editable' => 1
            ];
            
            if ($db->insert('cash_book', $data)) {
                setFlash('success', 'Transaksi berhasil ditambahkan!');
                redirect(BASE_URL . '/modules/cashbook/index.php');
            } else {
                setFlash('error', 'Gagal menambahkan transaksi!');
            }
        } catch (Exception $e) {
            setFlash('error', 'Error: ' . $e->getMessage());
        }
    }
}

include '../../includes/header.php';
?>

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
    margin-bottom: 0.875rem;
}

.compact-form-group:last-child {
    margin-bottom: 0;
}

.transaction-type-card {
    position: relative;
    display: flex;
    align-items: center;
    padding: 0.875rem;
    background: var(--bg-tertiary);
    border: 2px solid transparent;
    border-radius: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.transaction-type-card:hover {
    background: var(--bg-secondary);
}

.transaction-type-card input[type="radio"] {
    margin-right: 0.75rem;
}

.transaction-type-card input[type="radio"]:checked {
    accent-color: var(--primary-color);
}
</style>

<form method="POST" id="transactionForm" onsubmit="return validateForm('transactionForm')">
    <!-- Main Form Container -->
    <div class="card" style="max-width: 620px; margin: 0 auto 0.875rem;">
        <div style="padding: 0.875rem 1rem; border-bottom: 1px solid var(--bg-tertiary); background: linear-gradient(135deg, var(--primary-color)15, var(--bg-secondary));">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                <i data-feather="plus-circle" style="width: 18px; height: 18px;"></i> Tambah Transaksi Baru
            </h3>
        </div>
        
        <div style="padding: 1rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1rem;">
            <!-- Column 1 -->
            <div>
                <!-- Date & Time -->
                <div class="compact-form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Tanggal <span style="color: var(--danger);">*</span></label>
                    <input type="date" name="transaction_date" class="form-control" style="height: 34px; font-size: 0.813rem;" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="compact-form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Waktu</label>
                    <input type="time" name="transaction_time" class="form-control" style="height: 34px; font-size: 0.813rem;" value="<?php echo date('H:i'); ?>">
                </div>
                
                <!-- Division -->
                <div class="compact-form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Divisi <span style="color: var(--danger);">*</span></label>
                    <select name="division_id" id="division_id" class="form-control" style="height: 34px; font-size: 0.813rem;" required>
                        <option value="">-- Pilih Divisi --</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div['id']; ?>"><?php echo $div['division_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Category -->
                <div class="compact-form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Kategori <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="category_name" class="form-control" style="height: 34px; font-size: 0.813rem;" placeholder="Nama kategori" required>
                </div>
                
                <!-- Amount -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Jumlah <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="amount" class="form-control amount-input" style="height: 38px; font-size: 0.938rem; font-weight: 600;" placeholder="0" required>
                </div>
            </div>
            
            <!-- Column 2 -->
            <div>
                <!-- Transaction Type -->
                <div class="compact-form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.375rem;">Tipe Transaksi <span style="color: var(--danger);">*</span></label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <label class="transaction-type-card" style="padding: 0.5rem;">
                            <input type="radio" name="transaction_type" value="income" required checked>
                            <div style="display: flex; align-items: center; gap: 0.375rem;">
                                <i data-feather="trending-up" style="width: 14px; height: 14px; color: var(--success);"></i>
                                <div>
                                    <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Pemasukan</div>
                                </div>
                            </div>
                        </label>
                        
                        <label class="transaction-type-card" style="padding: 0.5rem;">
                            <input type="radio" name="transaction_type" value="expense" required>
                            <div style="display: flex; align-items: center; gap: 0.375rem;">
                                <i data-feather="trending-down" style="width: 14px; height: 14px; color: var(--danger);"></i>
                                <div>
                                    <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Pengeluaran</div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- Payment Method -->
                <div class="compact-form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.375rem;">Metode Pembayaran <span style="color: var(--danger);">*</span></label>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.375rem;">
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="cash" required checked>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="dollar-sign" style="width: 16px; height: 16px; margin-bottom: 0.125rem; color: #10b981;"></i>
                                <div style="font-weight: 600; font-size: 0.688rem; color: var(--text-primary);">Cash</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="debit" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="credit-card" style="width: 16px; height: 16px; margin-bottom: 0.125rem; color: #3b82f6;"></i>
                                <div style="font-weight: 600; font-size: 0.688rem; color: var(--text-primary);">Debit</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="transfer" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="send" style="width: 16px; height: 16px; margin-bottom: 0.125rem; color: #8b5cf6;"></i>
                                <div style="font-weight: 600; font-size: 0.688rem; color: var(--text-primary);">Transfer</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="qr" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="smartphone" style="width: 16px; height: 16px; margin-bottom: 0.125rem; color: #f59e0b;"></i>
                                <div style="font-weight: 600; font-size: 0.688rem; color: var(--text-primary);">QR Code</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="edc" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="cpu" style="width: 16px; height: 16px; margin-bottom: 0.125rem; color: #ec4899;"></i>
                                <div style="font-weight: 600; font-size: 0.688rem; color: var(--text-primary);">EDC</div>
                            </div>
                        </label>
                        
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="other" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="more-horizontal" style="width: 16px; height: 16px; margin-bottom: 0.125rem; color: #6b7280;"></i>
                                <div style="font-weight: 600; font-size: 0.688rem; color: var(--text-primary);">Lainnya</div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- Description -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Keterangan</label>
                    <textarea name="description" class="form-control" rows="3" style="font-size: 0.813rem; resize: none;" placeholder="Keterangan tambahan (opsional)"></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Form Actions Container -->
    <div class="card" style="max-width: 620px; margin: 0 auto; padding: 0.75rem 1rem; background: var(--bg-secondary); display: flex; justify-content: flex-end; gap: 0.625rem;">
        <a href="index.php" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.813rem;">
            <i data-feather="x" style="width: 14px; height: 14px;"></i> Batal
        </a>
        <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.813rem;">
            <i data-feather="save" style="width: 14px; height: 14px;"></i> Simpan Transaksi
        </button>
    </div>
</form>

<script>
feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
