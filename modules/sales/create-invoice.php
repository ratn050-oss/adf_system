<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Buat Invoice Baru';

// Get divisions
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

// Fetch customers from Database module
$customers = [];
try {
    $customers = $db->fetchAll("SELECT id, customer_code, customer_name, company_name, phone, email, address, city FROM customers WHERE is_active = 1 ORDER BY customer_name");
} catch (Exception $e) {
    // Customers table may not exist yet
}

// Handle form submission
if (isPost()) {
    try {
        // Debug: Log form data
        error_log("Form submitted with POST data: " . print_r($_POST, true));
        
        $db->getConnection()->beginTransaction();
        
        // Get form data
        $invoice_date = sanitize(getPost('invoice_date'));
        $customer_name = sanitize(getPost('customer_name'));
        $customer_phone = sanitize(getPost('customer_phone'));
        $customer_email = sanitize(getPost('customer_email'));
        $customer_address = sanitize(getPost('customer_address'));
        $division_id = (int)getPost('division_id');
        $payment_method = sanitize(getPost('payment_method'));
        $payment_status = sanitize(getPost('payment_status'));
        $notes = sanitize(getPost('notes'));
        
        // Validate payment method for sales_invoices_header
        $valid_payment_methods = ['cash', 'debit', 'transfer', 'qr', 'other'];
        if (!in_array($payment_method, $valid_payment_methods)) {
            $payment_method = 'other';
        }
        
        // Get items
        $item_names = getPost('item_name') ?? [];
        $item_descriptions = getPost('item_description') ?? [];
        $categories = getPost('category') ?? [];
        $quantities = getPost('quantity') ?? [];
        $unit_prices = getPost('unit_price') ?? [];
        
        if (empty($item_names) || count($item_names) === 0) {
            throw new Exception('Minimal harus ada 1 item');
        }
        
        // Calculate totals
        $subtotal = 0;
        $items = [];
        foreach ($item_names as $index => $item_name) {
            if (empty($item_name)) continue;
            
            $qty = (float)str_replace(['.', ','], ['', '.'], $quantities[$index] ?? 1);
            $price = (float)str_replace(['.', ','], ['', '.'], $unit_prices[$index] ?? 0);
            $total = $qty * $price;
            
            $items[] = [
                'item_name' => trim($item_name),
                'item_description' => trim($item_descriptions[$index] ?? ''),
                'category' => trim($categories[$index] ?? ''),
                'quantity' => $qty,
                'unit_price' => $price,
                'total_price' => $total
            ];
            
            $subtotal += $total;
        }
        
        $discount_amount = (float)str_replace(['.', ','], ['', '.'], getPost('discount_amount') ?? 0);
        $tax_amount = (float)str_replace(['.', ','], ['', '.'], getPost('tax_amount') ?? 0);
        $total_amount = $subtotal - $discount_amount + $tax_amount;
        
        // Generate invoice number: INV-YYYYMM-XXXX
        $prefix = 'INV-' . date('Ym') . '-';
        $lastInvoice = $db->fetchOne("
            SELECT invoice_number 
            FROM sales_invoices_header 
            WHERE invoice_number LIKE ? 
            ORDER BY invoice_number DESC 
            LIMIT 1
        ", [$prefix . '%']);
        
        if ($lastInvoice) {
            $lastNumber = (int)substr($lastInvoice['invoice_number'], -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        $invoice_number = $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        
        // Validate required data
        if (empty($customer_name)) {
            throw new Exception('Nama customer wajib diisi');
        }
        if (empty($division_id) || $division_id <= 0) {
            throw new Exception('Divisi wajib dipilih');
        }
        if (empty($currentUser['id'])) {
            throw new Exception('User ID tidak valid');
        }
        
        // Insert invoice header
        error_log("Attempting to insert invoice header");
        error_log("Customer: $customer_name, Division ID: $division_id, Total: $total_amount");
        
        $invoice_id = $db->insert('sales_invoices_header', [
            'invoice_number' => $invoice_number,
            'invoice_date' => $invoice_date,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_email' => $customer_email,
            'customer_address' => $customer_address,
            'division_id' => $division_id,
            'payment_method' => $payment_method,
            'payment_status' => $payment_status,
            'subtotal' => $subtotal,
            'discount_amount' => $discount_amount,
            'tax_amount' => $tax_amount,
            'total_amount' => $total_amount,
            'paid_amount' => $payment_status === 'paid' ? $total_amount : 0,
            'notes' => $notes,
            'created_by' => $currentUser['id']
        ]);
        
        error_log("Invoice header inserted with ID: $invoice_id");
        
        // Insert items
        error_log("Inserting " . count($items) . " items");
        foreach ($items as $item) {
            $item['invoice_header_id'] = $invoice_id;
            $result = $db->insert('sales_invoices_detail', $item);
            if (!$result) {
                throw new Exception('Gagal insert invoice detail');
            }
        }
        error_log("All items inserted successfully");
        
        // CASHBOOK: Only create entry when Pay action is clicked (pay-invoice.php)
        // Draft invoices do NOT go to cashbook until paid
        
        $db->getConnection()->commit();
        
        error_log("Transaction committed successfully: $invoice_number (ID: $invoice_id)");
        
        $_SESSION['success'] = "Invoice $invoice_number berhasil dibuat!";
        
        $redirect_url = BASE_URL . '/modules/sales/index.php';
        error_log("Redirecting to: $redirect_url");
        
        redirect($redirect_url);
        exit;
        
    } catch (Exception $e) {
        $db->getConnection()->rollBack();
        error_log("Error creating invoice: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<?php if (isset($_SESSION['error'])): ?>
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15);">
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
.invoice-container {
    max-width: 960px;
    margin: 0 auto;
}

.invoice-header {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(139, 92, 246, 0.05));
    padding: 1.25rem 1.5rem;
    border-radius: 0.875rem;
    border: 1px solid rgba(99, 102, 241, 0.1);
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.invoice-header h1 {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.section {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: 0.75rem;
    padding: 1.25rem;
    margin-bottom: 1rem;
}

.section-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.875rem;
    padding-bottom: 0.6rem;
    border-bottom: 2px solid var(--primary-color);
}

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.875rem; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.875rem; }

.form-group { margin-bottom: 0; }
.form-label { 
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 0.35rem;
    display: block;
}

.form-control {
    background: var(--bg-primary);
    border: 1.5px solid var(--bg-tertiary);
    border-radius: 0.5rem;
    padding: 0.6rem 0.75rem;
    font-size: 0.875rem;
    transition: all 0.2s ease;
}

/* Input Group for Percentages */
.input-group {
    display: flex;
    align-items: stretch;
}
.input-group .form-control {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
    flex: 1;
}
.input-group-text {
    background: var(--bg-tertiary);
    border: 1.5px solid var(--bg-tertiary);
    border-left: none;
    padding: 0 0.875rem;
    display: flex;
    align-items: center;
    border-top-right-radius: 0.5rem;
    border-bottom-right-radius: 0.5rem;
    color: var(--text-muted);
    font-weight: 700;
    font-size: 0.875rem;
}
}

.form-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    background: var(--bg-primary);
}

.item-row {
    background: var(--bg-primary);
    border: 1.5px solid var(--bg-tertiary);
    border-radius: 0.75rem;
    padding: 1rem;
    margin-bottom: 0.75rem;
    transition: all 0.2s ease;
}

.item-row:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.item-number {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.btn-remove {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 0.4rem 0.8rem;
    border-radius: 0.5rem;
    cursor: pointer;
    font-size: 0.75rem;
    font-weight: 600;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.btn-remove:hover {
    background: #ef4444;
    color: white;
    border-color: #ef4444;
}

.item-grid {
    display: grid;
    grid-template-columns: 2fr 1.2fr 0.8fr 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.input-small { font-size: 0.85rem; }

#totalSummary {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.05));
    border: 2px solid var(--primary-color);
    border-radius: 1rem;
    padding: 1.75rem;
    margin-bottom: 1.25rem;
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.12);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
    font-size: 0.95rem;
}

.summary-label { color: var(--text-muted); font-weight: 600; }
.summary-value { color: var(--text-primary); font-weight: 700; }

.summary-divider {
    border-top: 2px solid var(--primary-color);
    margin: 1rem 0;
}

.total-amount {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 0.5rem;
}

.total-label {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.total-value {
    font-size: 2.2rem;
    font-weight: 900;
    background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.button-group {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 1.5rem;
}

.btn-action {
    padding: 0.85rem 1.5rem;
    border-radius: 0.7rem;
    border: none;
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-save {
    background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
    color: white;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
}

.btn-cancel {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

.btn-cancel:hover {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    opacity: 0.8;
}

.btn-add {
    background: rgba(99, 102, 241, 0.1);
    color: var(--primary-color);
    border: 1.5px dashed var(--primary-color);
    padding: 0.6rem 1.2rem;
    border-radius: 0.6rem;
    cursor: pointer;
    font-weight: 700;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.btn-add:hover {
    background: var(--primary-color);
    color: white;
}
</style>

<div class="invoice-container">
    <!-- Header -->
    <div class="invoice-header">
        <div>
            <h1>
                <i data-feather="file-text" style="width: 28px; height: 28px;"></i> 
                Create Invoice
            </h1>
            <p style="margin: 0.3rem 0 0 2.8rem; color: var(--text-muted); font-size: 0.9rem;">Buat invoice baru dengan mudah</p>
        </div>
    </div>

    <form method="POST" id="invoiceForm">
        <!-- Customer Section -->
        <div class="section">
            <div class="section-title">👤 Customer Information</div>
            <div class="grid-2">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Pilih dari Database</label>
                    <select id="customer_select" class="form-control" onchange="fillCustomerData()">
                        <option value="">-- Pilih Customer atau input manual --</option>
                        <?php foreach ($customers as $cust): ?>
                        <option value="<?php echo $cust['id']; ?>"
                            data-name="<?php echo htmlspecialchars($cust['customer_name'] . ($cust['company_name'] ? ' (' . $cust['company_name'] . ')' : '')); ?>"
                            data-phone="<?php echo htmlspecialchars($cust['phone'] ?? ''); ?>"
                            data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>"
                            data-address="<?php echo htmlspecialchars(($cust['address'] ?? '') . ($cust['city'] ? ', ' . $cust['city'] : '')); ?>">
                            <?php echo htmlspecialchars($cust['customer_code'] . ' - ' . $cust['customer_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nama Customer <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="customer_name" id="customer_name" class="form-control" placeholder="PT. Maju Jaya Indonesia" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">No. Telepon</label>
                    <input type="text" name="customer_phone" id="customer_phone" class="form-control" placeholder="081234567890">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="customer_email" id="customer_email" class="form-control" placeholder="customer@example.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <input type="text" name="customer_address" id="customer_address" class="form-control" placeholder="Jl. Merdeka No. 123...">
                </div>
            </div>
        </div>

        <!-- Invoice Details -->
        <div class="section">
            <div class="section-title">📋 Invoice Details</div>
            <div class="grid-3">
                <div class="form-group">
                    <label class="form-label">Tanggal <span style="color: #ef4444;">*</span></label>
                    <input type="date" name="invoice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Divisi <span style="color: #ef4444;">*</span></label>
                    <select name="division_id" class="form-control" required>
                        <option value="">Pilih Divisi...</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div['id']; ?>"><?php echo $div['division_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Metode Pembayaran <span style="color: #ef4444;">*</span></label>
                    <select name="payment_method" class="form-control" required>
                        <option value="cash">💵 Cash</option>
                        <option value="debit">💳 Debit Card</option>
                        <option value="transfer">🔄 Transfer</option>
                        <option value="qr">📱 QR Code</option>
                        <option value="other">➕ Lainnya</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Items Section -->
        <div class="section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <div class="section-title" style="margin: 0; border: none;">📦 Produk / Layanan</div>
                <button type="button" class="btn-add" onclick="addItem()">
                    <i data-feather="plus" style="width: 16px; height: 16px;"></i> Tambah Item
                </button>
            </div>
            
            <div id="itemsContainer">
                <!-- Items akan ditambahkan di sini -->
            </div>
        </div>

        <!-- Summary & Notes -->
        <div id="totalSummary">
            <div class="grid-3" style="margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">💰 Diskon (%)</label>
                    <div class="input-group">
                        <input type="number" name="discount_percent" id="discount_percent" class="form-control input-small" value="0" min="0" max="100" step="0.01" onkeyup="calculateTotal()" onchange="calculateTotal()" placeholder="0">
                        <span class="input-group-text">%</span>
                    </div>
                    <input type="hidden" name="discount_amount" id="discount_amount" value="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">🏦 PPN (%)</label>
                    <div class="input-group">
                        <input type="number" name="tax_percent" id="tax_percent" class="form-control input-small" value="0" min="0" max="100" step="0.01" onkeyup="calculateTotal()" onchange="calculateTotal()" placeholder="0">
                        <span class="input-group-text">%</span>
                    </div>
                    <input type="hidden" name="tax_amount" id="tax_amount" value="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">📊 Status Pembayaran</label>
                    <select name="payment_status" class="form-control input-small" required>
                        <option value="draft" selected>📝 Draft</option>
                        <option value="unpaid">⏳ Belum Bayar</option>
                    </select>
                    <small style="color: var(--text-muted); font-size: 0.75rem;">💡 Invoice masuk ke Buku Kas saat dibayar (klik tombol bayar)</small>
                </div>
            </div>

            <div style="background: var(--bg-primary); border-radius: 0.75rem; padding: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label class="form-label">📝 Catatan Tambahan</label>
                    <textarea name="notes" class="form-control input-small" rows="2" style="resize: none;" placeholder="Catatan atau keterangan khusus (opsional)"></textarea>
                </div>
            </div>

            <div class="summary-divider"></div>

            <div class="summary-row">
                <span class="summary-label">Subtotal:</span>
                <span class="summary-value" id="subtotal_display">Rp 0</span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">Diskon:</span>
                <span class="summary-value" id="discount_display">- Rp 0</span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">Pajak:</span>
                <span class="summary-value" id="tax_display">+ Rp 0</span>
            </div>

            <div class="total-amount">
                <span class="total-label">TOTAL</span>
                <span class="total-value" id="total_display">Rp 0</span>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="button-group">
            <a href="index.php" class="btn-action btn-cancel">
                <i data-feather="x" style="width: 18px; height: 18px;"></i> Batal
            </a>
            <button type="submit" class="btn-action btn-save">
                <i data-feather="check-circle" style="width: 18px; height: 18px;"></i> Buat Invoice
            </button>
        </div>
    </form>
</div>

<script>
let itemCount = 0;

function addItem() {
    itemCount++;
    const container = document.getElementById('itemsContainer');
    const itemRow = document.createElement('div');
    itemRow.className = 'item-row';
    itemRow.id = `item-${itemCount}`;
    
    itemRow.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <h5 style="font-size: 0.875rem; font-weight: 600; margin: 0; color: var(--text-primary);">Item #${itemCount}</h5>
            <button type="button" class="remove-item-btn" onclick="removeItem(${itemCount})">
                <i data-feather="x" style="width: 14px; height: 14px;"></i> Hapus
            </button>
        </div>
        
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 0.75rem;">
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; display: block;">Nama Item *</label>
                <input type="text" name="item_name[]" class="form-control" required>
            </div>
            
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; display: block;">Kategori</label>
                <select name="category[]" class="form-control">
                    <option value="">-- Pilih --</option>
                    <option value="rental_motor">Rental Motor</option>
                    <option value="rental_mobil">Rental Mobil</option>
                    <option value="laundry">Laundry</option>
                    <option value="tour">Tour/Trip</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; display: block;">Qty *</label>
                <input type="number" name="quantity[]" class="form-control" value="1" min="0.01" step="0.01" required onkeyup="calculateTotal()">
            </div>
            
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; display: block;">Harga Satuan *</label>
                <input type="text" name="unit_price[]" class="form-control" value="0" required onkeyup="calculateTotal()">
            </div>
            
            <div>
                <label style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; display: block;">Total</label>
                <input type="text" class="form-control item-total" readonly style="background: var(--bg-tertiary); font-weight: 700;">
            </div>
        </div>
        
        <div style="margin-top: 0.75rem;">
            <label style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; display: block;">Deskripsi</label>
            <textarea name="item_description[]" class="form-control" rows="1" style="resize: none; font-size: 0.813rem;"></textarea>
        </div>
    `;
    
    container.appendChild(itemRow);
    feather.replace();
    calculateTotal();
}

function removeItem(id) {
    const item = document.getElementById(`item-${id}`);
    if (item) {
        item.remove();
        calculateTotal();
    }
}

// Auto-fill customer data from dropdown
function fillCustomerData() {
    const select = document.getElementById('customer_select');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        document.getElementById('customer_name').value = option.dataset.name || '';
        document.getElementById('customer_phone').value = option.dataset.phone || '';
        document.getElementById('customer_email').value = option.dataset.email || '';
        document.getElementById('customer_address').value = option.dataset.address || '';
    }
}

function calculateTotal() {
    const quantities = document.getElementsByName('quantity[]');
    const unitPrices = document.getElementsByName('unit_price[]');
    const itemTotals = document.querySelectorAll('.item-total');
    
    let subtotal = 0;
    
    for (let i = 0; i < quantities.length; i++) {
        const qty = parseFloat(quantities[i].value) || 0;
        const price = parseFloat(unitPrices[i].value.replace(/[^0-9.-]+/g, '')) || 0;
        const total = qty * price;
        
        itemTotals[i].value = 'Rp ' + total.toLocaleString('id-ID');
        subtotal += total;
    }
    
    // Calculate based on Percentages
    const discountPercent = parseFloat(document.getElementById('discount_percent').value) || 0;
    const taxPercent = parseFloat(document.getElementById('tax_percent').value) || 0;
    
    // Discount Amount = Subtotal * (Discount% / 100)
    const discountAmount = Math.round(subtotal * (discountPercent / 100));
    
    // Tax Base = Subtotal - Discount
    const taxBase = subtotal - discountAmount;
    // Tax Amount = TaxBase * (Tax% / 100)
    const taxAmount = Math.round(taxBase * (taxPercent / 100));
    
    // Update Hidden Inputs for Server
    document.getElementById('discount_amount').value = discountAmount;
    document.getElementById('tax_amount').value = taxAmount;

    const grandTotal = subtotal - discountAmount + taxAmount;
    
    document.getElementById('subtotal_display').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
    // Display shows amount and percentage for clarity if needed, or just amount as per UI elements
    if(document.getElementById('discount_display')) {
         document.getElementById('discount_display').textContent = '- Rp ' + discountAmount.toLocaleString('id-ID');
    }
    if(document.getElementById('tax_display')) {
         document.getElementById('tax_display').textContent = '+ Rp ' + taxAmount.toLocaleString('id-ID');
    }
    document.getElementById('total_display').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID');
}

// Add first item on load
document.addEventListener('DOMContentLoaded', function() {
    addItem();
    
    // Form validation
    const form = document.getElementById('invoiceForm');
    form.addEventListener('submit', function(e) {
        console.log('Form is being submitted');
        
        // Check if at least one item exists
        const itemsContainer = document.getElementById('itemsContainer');
        if (itemsContainer.children.length === 0) {
            e.preventDefault();
            alert('Harap tambahkan minimal 1 item!');
            return false;
        }
        
        // Validate all items have name
        const itemNames = document.getElementsByName('item_name[]');
        for (let i = 0; i < itemNames.length; i++) {
            if (!itemNames[i].value.trim()) {
                e.preventDefault();
                alert('Semua item harus memiliki nama!');
                return false;
            }
        }
        
        console.log('Form validation passed, submitting...');
        return true;
    });
});

feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
