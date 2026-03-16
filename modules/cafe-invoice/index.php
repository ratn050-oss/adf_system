<?php
/**
 * BENS CAFE - Invoice Management
 * Create invoices, mark paid → auto-post to cash_book
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();

// Auto-fix ENUM on cash_book
try { $pdo->exec("ALTER TABLE `cash_book` MODIFY COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`"); } catch (Exception $e) {}

// ══════════════════════════════════════════════
// AUTO-CREATE TABLE
// ══════════════════════════════════════════════
$pdo->exec("CREATE TABLE IF NOT EXISTS `cafe_invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(30) NOT NULL,
    `customer_name` VARCHAR(100) NOT NULL DEFAULT 'Walk-in',
    `customer_phone` VARCHAR(30) DEFAULT NULL,
    `customer_note` TEXT DEFAULT NULL,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status` ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `cash_account_id` INT DEFAULT NULL,
    `cash_book_id` INT DEFAULT NULL,
    `paid_at` DATETIME DEFAULT NULL,
    `paid_by` INT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_inv_num` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `cafe_invoice_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT NOT NULL,
    `item_name` VARCHAR(150) NOT NULL,
    `qty` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
    INDEX `idx_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load company info
$companyName = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_name'")['setting_value'] ?? 'Bens Cafe';
$companyAddress = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_address'")['setting_value'] ?? '';
$companyPhone = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_phone'")['setting_value'] ?? '';

// Load cash accounts from master DB
$masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$businessId = getMasterBusinessId();
$cashAccounts = $masterDb->prepare("SELECT id, account_name, account_type, current_balance FROM cash_accounts WHERE business_id = ? AND is_active = 1 ORDER BY account_type, account_name");
$cashAccounts->execute([$businessId]);
$cashAccounts = $cashAccounts->fetchAll(PDO::FETCH_ASSOC);

// Load divisions
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");
$defaultDivision = $divisions[0]['id'] ?? 1;

// ══════════════════════════════════════════════
// AJAX HANDLERS
// ══════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // --- Get invoice detail ---
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $inv = $db->fetchOne("SELECT * FROM cafe_invoices WHERE id = ?", [(int)$_GET['id']]);
        if (!$inv) { echo json_encode(['success' => false]); exit; }
        $items = $db->fetchAll("SELECT * FROM cafe_invoice_items WHERE invoice_id = ?", [(int)$_GET['id']]);
        echo json_encode(['success' => true, 'invoice' => $inv, 'items' => $items]);
        exit;
    }

    // --- Pay invoice ---
    if ($_GET['ajax'] === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $cashAccountId = !empty($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null;

        $inv = $db->fetchOne("SELECT * FROM cafe_invoices WHERE id = ? AND status = 'unpaid'", [$invId]);
        if (!$inv) {
            echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan atau sudah dibayar']);
            exit;
        }

        try {
            $db->beginTransaction();

            // Find or create "Invoice" category
            $cat = $db->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) = 'invoice cafe' AND category_type = 'income'");
            if (!$cat) {
                $db->insert('categories', ['category_name' => 'Invoice Cafe', 'category_type' => 'income', 'division_id' => $defaultDivision, 'is_active' => 1]);
                $catId = $pdo->lastInsertId();
            } else {
                $catId = $cat['id'];
            }

            // Insert to cash_book
            $cbId = $db->insert('cash_book', [
                'transaction_date' => date('Y-m-d'),
                'transaction_time' => date('H:i:s'),
                'division_id' => $defaultDivision,
                'category_id' => $catId,
                'transaction_type' => 'income',
                'amount' => $inv['total_amount'],
                'description' => 'Bayar ' . $inv['invoice_number'] . ' — ' . $inv['customer_name'],
                'payment_method' => $paymentMethod,
                'cash_account_id' => $cashAccountId,
                'created_by' => $_SESSION['user_id'],
                'source_type' => 'invoice_payment',
                'is_editable' => 1
            ]);

            // Update invoice
            $db->update('cafe_invoices', [
                'status' => 'paid',
                'payment_method' => $paymentMethod,
                'cash_account_id' => $cashAccountId,
                'cash_book_id' => $cbId,
                'paid_at' => date('Y-m-d H:i:s'),
                'paid_by' => $_SESSION['user_id']
            ], 'id = :id', ['id' => $invId]);

            // Update cash account balance in master DB
            if ($cashAccountId) {
                try {
                    $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$inv['total_amount'], $cashAccountId]);
                    $masterDb->prepare("INSERT INTO cash_account_transactions (account_id, business_id, transaction_type, amount, description, reference_type, reference_id, created_at) VALUES (?, ?, 'credit', ?, ?, 'cafe_invoice', ?, NOW())")
                        ->execute([$cashAccountId, $businessId, $inv['total_amount'], 'Bayar ' . $inv['invoice_number'], $invId]);
                } catch (Exception $e) { error_log("Cash account update: " . $e->getMessage()); }
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => '✅ Invoice ' . $inv['invoice_number'] . ' berhasil dibayar!']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // --- Delete invoice ---
    if ($_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $inv = $db->fetchOne("SELECT * FROM cafe_invoices WHERE id = ? AND status = 'unpaid'", [$invId]);
        if (!$inv) { echo json_encode(['success' => false, 'message' => 'Hanya invoice unpaid yang bisa dihapus']); exit; }
        $db->delete('cafe_invoice_items', 'invoice_id = :id', ['id' => $invId]);
        $db->delete('cafe_invoices', 'id = :id', ['id' => $invId]);
        echo json_encode(['success' => true, 'message' => 'Invoice dihapus']);
        exit;
    }

    exit;
}

// ══════════════════════════════════════════════
// CREATE INVOICE (POST)
// ══════════════════════════════════════════════
if (isPost() && getPost('action') === 'create_invoice') {
    $customerName = sanitize(getPost('customer_name')) ?: 'Walk-in';
    $customerPhone = sanitize(getPost('customer_phone'));
    $customerNote = sanitize(getPost('customer_note'));
    $discount = floatval(str_replace(['.', ','], ['', '.'], getPost('discount_amount', '0')));
    $taxPercent = floatval(getPost('tax_percent', '0'));
    $notes = sanitize(getPost('notes'));

    $itemNames = getPost('item_name', []);
    $itemQtys = getPost('item_qty', []);
    $itemPrices = getPost('item_price', []);

    if (empty($itemNames) || empty($itemNames[0])) {
        setFlash('error', 'Minimal 1 item harus diisi');
    } else {
        try {
            $db->beginTransaction();

            // Generate invoice number: INV-CAFE-YYYYMMDD-XXX
            $today = date('Ymd');
            $last = $db->fetchOne("SELECT invoice_number FROM cafe_invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1", ["INV-CF-$today-%"]);
            $seq = 1;
            if ($last) {
                $parts = explode('-', $last['invoice_number']);
                $seq = (int)end($parts) + 1;
            }
            $invNumber = "INV-CF-$today-" . str_pad($seq, 3, '0', STR_PAD_LEFT);

            $subtotal = 0;
            $validItems = [];
            for ($i = 0; $i < count($itemNames); $i++) {
                $name = trim($itemNames[$i] ?? '');
                $qty = max(1, (int)($itemQtys[$i] ?? 1));
                $price = floatval(str_replace(['.', ','], ['', '.'], $itemPrices[$i] ?? '0'));
                if (empty($name) || $price <= 0) continue;
                $lineTotal = $qty * $price;
                $subtotal += $lineTotal;
                $validItems[] = ['name' => $name, 'qty' => $qty, 'price' => $price, 'subtotal' => $lineTotal];
            }

            if (empty($validItems)) {
                throw new Exception('Tidak ada item valid');
            }

            $taxAmount = round($subtotal * $taxPercent / 100);
            $totalAmount = $subtotal - $discount + $taxAmount;

            $invId = $db->insert('cafe_invoices', [
                'invoice_number' => $invNumber,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_note' => $customerNote,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'notes' => $notes,
                'created_by' => $_SESSION['user_id']
            ]);

            foreach ($validItems as $item) {
                $db->insert('cafe_invoice_items', [
                    'invoice_id' => $invId,
                    'item_name' => $item['name'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['price'],
                    'subtotal' => $item['subtotal']
                ]);
            }

            $db->commit();
            setFlash('success', "✅ Invoice $invNumber berhasil dibuat!");
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollback();
            setFlash('error', 'Gagal membuat invoice: ' . $e->getMessage());
        }
    }
}

// ══════════════════════════════════════════════
// LOAD DATA
// ══════════════════════════════════════════════
$filter = sanitize(getGet('filter', 'all'));
$search = sanitize(getGet('q', ''));
$where = "1=1";
$params = [];
if ($filter === 'unpaid') $where .= " AND ci.status = 'unpaid'";
elseif ($filter === 'paid') $where .= " AND ci.status = 'paid'";
if ($search) { $where .= " AND (ci.invoice_number LIKE ? OR ci.customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$invoices = $db->fetchAll("SELECT ci.*, u.full_name as creator_name FROM cafe_invoices ci LEFT JOIN " . DB_NAME . ".users u ON u.id = ci.created_by WHERE $where ORDER BY ci.id DESC LIMIT 100", $params);

$stats = $db->fetchOne("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='unpaid' THEN 1 ELSE 0 END) as unpaid_count,
    SUM(CASE WHEN status='unpaid' THEN total_amount ELSE 0 END) as unpaid_amount,
    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN status='paid' AND DATE(paid_at) = CURDATE() THEN total_amount ELSE 0 END) as today_paid
FROM cafe_invoices");

$pageTitle = '☕ Invoice Bens Cafe';
include '../../includes/header.php';

$successMsg = getFlash('success');
$errorMsg = getFlash('error');
?>

<style>
:root { --cafe: #92400e; --cafe-light: #fef3c7; --cafe-dark: #78350f; --cafe-bg: #fffbeb; }
.inv-stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; }
.inv-stat { background: #fff; border-radius: 12px; padding: 16px; border: 1px solid #f3f4f6; position: relative; overflow: hidden; }
.inv-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.inv-stat h4 { font-size: 10px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin: 0 0 6px; }
.inv-stat .val { font-size: 22px; font-weight: 800; margin: 0; }
.inv-stat.s1::before { background: var(--cafe); } .inv-stat.s1 .val { color: var(--cafe); }
.inv-stat.s2::before { background: #ef4444; } .inv-stat.s2 .val { color: #ef4444; }
.inv-stat.s3::before { background: #10b981; } .inv-stat.s3 .val { color: #10b981; }
.inv-stat.s4::before { background: #3b82f6; } .inv-stat.s4 .val { color: #3b82f6; }

.inv-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 10px; flex-wrap: wrap; }
.inv-filters { display: flex; gap: 6px; }
.inv-filters a { padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 600; text-decoration: none; color: #6b7280; background: #f3f4f6; border: 1px solid transparent; transition: all .2s; }
.inv-filters a.active, .inv-filters a:hover { background: var(--cafe-light); color: var(--cafe); border-color: var(--cafe); }

.inv-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.inv-table th { background: #f9fafb; padding: 10px 12px; text-align: left; font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; border-bottom: 2px solid #e5e7eb; }
.inv-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.inv-table tr:hover { background: #fefce8; }
.inv-num { font-weight: 700; color: var(--cafe); font-size: 12px; }
.badge { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; display: inline-block; }
.b-unpaid { background: #fef2f2; color: #dc2626; } .b-paid { background: #f0fdf4; color: #059669; } .b-cancelled { background: #f3f4f6; color: #6b7280; }

.btn-cafe { background: var(--cafe); color: #fff; border: none; padding: 8px 18px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; }
.btn-cafe:hover { background: var(--cafe-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(146,64,14,.25); }
.btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 6px; }
.btn-pay { background: #059669; color: #fff; } .btn-pay:hover { background: #047857; }
.btn-view { background: #3b82f6; color: #fff; } .btn-view:hover { background: #2563eb; }
.btn-del { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; } .btn-del:hover { background: #dc2626; color: #fff; }
.btn-ghost { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; } .btn-ghost:hover { background: #e5e7eb; }

/* Create form */
.cf-card { background: #fff; border-radius: 14px; padding: 20px; border: 1px solid #f3f4f6; margin-bottom: 16px; }
.cf-title { font-size: 15px; font-weight: 800; color: var(--cafe-dark); margin: 0 0 12px; display: flex; align-items: center; gap: 8px; }
.cf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px; }
.cf-label { font-size: 11px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block; }
.cf-input { width: 100%; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 12px; background: #fff; transition: border-color .2s; box-sizing: border-box; }
.cf-input:focus { outline: none; border-color: var(--cafe); box-shadow: 0 0 0 3px rgba(146,64,14,.1); }

/* Item rows */
.item-row { display: grid; grid-template-columns: 1fr 70px 120px 100px 36px; gap: 8px; align-items: end; margin-bottom: 8px; }
.item-row .cf-label { font-size: 10px; }
.item-header { font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 8px; }
.item-header > div { padding: 0 4px; }
.remove-item { width: 32px; height: 32px; border-radius: 6px; border: 1px solid #fca5a5; background: #fef2f2; color: #dc2626; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; }
.remove-item:hover { background: #dc2626; color: #fff; }
.totals-box { background: var(--cafe-bg); border: 1px solid #fcd34d; border-radius: 10px; padding: 14px; margin-top: 12px; }
.totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 12px; color: #374151; }
.totals-row.grand { font-size: 16px; font-weight: 800; color: var(--cafe-dark); border-top: 2px solid var(--cafe); padding-top: 8px; margin-top: 6px; }

/* Modal */
.modal-bg { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.5); align-items: center; justify-content: center; }
.modal-bg.open { display: flex; }
.modal-box { background: #fff; border-radius: 16px; padding: 24px; max-width: 480px; width: 92%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 60px rgba(0,0,0,.2); }
.modal-title { font-size: 16px; font-weight: 800; color: var(--cafe-dark); margin: 0 0 16px; display: flex; align-items: center; gap: 8px; }

/* Pay method cards */
.pay-methods { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin: 12px 0; }
.pay-card { padding: 12px; border: 2px solid #e5e7eb; border-radius: 10px; text-align: center; cursor: pointer; transition: all .2s; }
.pay-card:hover { border-color: var(--cafe); background: var(--cafe-bg); }
.pay-card.selected { border-color: var(--cafe); background: var(--cafe-light); box-shadow: 0 0 0 3px rgba(146,64,14,.15); }
.pay-card .pay-icon { font-size: 20px; margin-bottom: 4px; }
.pay-card .pay-label { font-size: 11px; font-weight: 700; color: #374151; }

/* Invoice preview */
.inv-preview { border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; background: #fff; }
.inv-header { text-align: center; border-bottom: 2px solid var(--cafe); padding-bottom: 16px; margin-bottom: 16px; }
.inv-logo { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-bottom: 8px; background: var(--cafe-light); display: inline-flex; align-items: center; justify-content: center; font-size: 28px; }
.inv-biz-name { font-size: 18px; font-weight: 800; color: var(--cafe-dark); margin: 4px 0 2px; }
.inv-biz-detail { font-size: 10px; color: #6b7280; }
.inv-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; font-size: 11px; }
.inv-meta-label { color: #6b7280; font-weight: 600; }
.inv-meta-val { color: #111827; font-weight: 700; }
.inv-items-tbl { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 16px; }
.inv-items-tbl th { background: var(--cafe-bg); padding: 8px; text-align: left; font-weight: 700; color: var(--cafe-dark); border-bottom: 1px solid #fcd34d; }
.inv-items-tbl td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; }
.inv-items-tbl td:last-child, .inv-items-tbl th:last-child { text-align: right; }
.inv-footer { text-align: center; font-size: 10px; color: #9ca3af; margin-top: 16px; padding-top: 12px; border-top: 1px dashed #d1d5db; }
.inv-paid-stamp { background: #059669; color: #fff; padding: 6px 20px; border-radius: 20px; font-size: 12px; font-weight: 800; display: inline-block; margin-top: 8px; }

/* Print */
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { position: absolute; left: 0; top: 0; width: 100%; }
}
@media (max-width: 768px) {
    .inv-stats { grid-template-columns: repeat(2,1fr); }
    .item-row { grid-template-columns: 1fr 60px 100px 36px; }
    .item-row .line-total-col { display: none; }
}
</style>

<?php if ($successMsg): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#065f46;font-weight:600;"><?php echo $successMsg; ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#991b1b;font-weight:600;"><?php echo $errorMsg; ?></div>
<?php endif; ?>

<!-- ═══ VIEW: INVOICE LIST ═══ -->
<div id="viewList">

<div class="inv-stats">
    <div class="inv-stat s1"><h4>Total Invoice</h4><p class="val"><?php echo (int)$stats['total']; ?></p></div>
    <div class="inv-stat s2"><h4>Belum Bayar</h4><p class="val"><?php echo (int)$stats['unpaid_count']; ?><span style="font-size:11px;font-weight:600;color:#6b7280;"> (<?php echo formatCurrency($stats['unpaid_amount'] ?? 0); ?>)</span></p></div>
    <div class="inv-stat s3"><h4>Lunas Hari Ini</h4><p class="val"><?php echo formatCurrency($stats['today_paid'] ?? 0); ?></p></div>
    <div class="inv-stat s4"><h4>Total Lunas</h4><p class="val"><?php echo (int)$stats['paid_count']; ?></p></div>
</div>

<div class="inv-toolbar">
    <div class="inv-filters">
        <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">Semua</a>
        <a href="?filter=unpaid" class="<?php echo $filter === 'unpaid' ? 'active' : ''; ?>">⏳ Belum Bayar</a>
        <a href="?filter=paid" class="<?php echo $filter === 'paid' ? 'active' : ''; ?>">✅ Lunas</a>
    </div>
    <div style="display:flex;gap:8px;">
        <form method="GET" style="display:flex;gap:6px;">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari invoice..." class="cf-input" style="width:180px;padding:6px 12px;">
            <button type="submit" class="btn-cafe btn-sm">🔍</button>
        </form>
        <button onclick="showCreate()" class="btn-cafe">➕ Buat Invoice</button>
    </div>
</div>

<div style="background:#fff;border-radius:14px;border:1px solid #f3f4f6;overflow:hidden;">
<?php if (empty($invoices)): ?>
    <div style="text-align:center;padding:40px;color:#6b7280;font-size:13px;">☕ Belum ada invoice. Klik <b>Buat Invoice</b> untuk mulai.</div>
<?php else: ?>
    <table class="inv-table">
        <thead><tr><th>No. Invoice</th><th>Pelanggan</th><th>Total</th><th>Metode</th><th>Status</th><th>Tanggal</th><th style="text-align:center;">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr>
            <td><span class="inv-num"><?php echo htmlspecialchars($inv['invoice_number']); ?></span></td>
            <td>
                <div style="font-weight:600;font-size:12px;"><?php echo htmlspecialchars($inv['customer_name']); ?></div>
                <?php if ($inv['customer_phone']): ?><div style="font-size:10px;color:#6b7280;">📱 <?php echo htmlspecialchars($inv['customer_phone']); ?></div><?php endif; ?>
            </td>
            <td style="font-weight:700;"><?php echo formatCurrency($inv['total_amount']); ?></td>
            <td>
                <?php if ($inv['payment_method']): ?>
                    <span style="font-size:11px;font-weight:600;color:var(--cafe);"><?php echo ucfirst($inv['payment_method']); ?></span>
                <?php else: ?>
                    <span style="color:#9ca3af;">—</span>
                <?php endif; ?>
            </td>
            <td><span class="badge b-<?php echo $inv['status']; ?>"><?php echo $inv['status'] === 'paid' ? '✅ Lunas' : ($inv['status'] === 'unpaid' ? '⏳ Belum' : '❌ Batal'); ?></span></td>
            <td style="font-size:11px;color:#6b7280;">
                <?php echo date('d/m/Y H:i', strtotime($inv['created_at'])); ?>
                <?php if ($inv['paid_at']): ?><br><span style="color:#059669;font-size:10px;">💰 <?php echo date('d/m H:i', strtotime($inv['paid_at'])); ?></span><?php endif; ?>
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                    <button onclick="viewInvoice(<?php echo $inv['id']; ?>)" class="btn-cafe btn-sm btn-view" title="Lihat">👁️</button>
                    <?php if ($inv['status'] === 'unpaid'): ?>
                    <button onclick="openPayModal(<?php echo $inv['id']; ?>, '<?php echo htmlspecialchars($inv['invoice_number']); ?>', <?php echo $inv['total_amount']; ?>)" class="btn-cafe btn-sm btn-pay" title="Bayar">💰 Pay</button>
                    <button onclick="deleteInvoice(<?php echo $inv['id']; ?>)" class="btn-cafe btn-sm btn-del" title="Hapus">🗑️</button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
</div>

<!-- ═══ VIEW: CREATE INVOICE ═══ -->
<div id="viewCreate" style="display:none;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <button onclick="hideCreate()" class="btn-cafe btn-ghost btn-sm">← Kembali</button>
        <h2 style="font-size:16px;font-weight:800;color:var(--cafe-dark);margin:0;">☕ Buat Invoice Baru</h2>
    </div>
    <form method="POST" id="createForm">
        <input type="hidden" name="action" value="create_invoice">
        <div class="cf-card">
            <div class="cf-title">👤 Info Pelanggan</div>
            <div class="cf-row">
                <div><label class="cf-label">Nama Pelanggan</label><input type="text" name="customer_name" class="cf-input" placeholder="Walk-in" value="Walk-in"></div>
                <div><label class="cf-label">No. HP</label><input type="text" name="customer_phone" class="cf-input" placeholder="081xxx"></div>
            </div>
            <div><label class="cf-label">Catatan</label><textarea name="customer_note" class="cf-input" rows="2" placeholder="Catatan untuk pelanggan (opsional)"></textarea></div>
        </div>

        <div class="cf-card">
            <div class="cf-title">📋 Item Pesanan</div>
            <div class="item-header item-row" style="margin-bottom:4px;">
                <div>Nama Item</div><div>Qty</div><div>Harga</div><div class="line-total-col">Subtotal</div><div></div>
            </div>
            <div id="itemsContainer">
                <div class="item-row" data-index="0">
                    <div><input type="text" name="item_name[]" class="cf-input" placeholder="Nama menu/item" required></div>
                    <div><input type="number" name="item_qty[]" class="cf-input qty-input" value="1" min="1" onchange="calcTotals()" onkeyup="calcTotals()"></div>
                    <div><input type="text" name="item_price[]" class="cf-input price-input" placeholder="0" required onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()"></div>
                    <div class="line-total-col" style="font-size:12px;font-weight:700;color:var(--cafe);padding-top:8px;text-align:right;" data-linetotal>Rp 0</div>
                    <div><button type="button" class="remove-item" onclick="removeItem(this)" title="Hapus">×</button></div>
                </div>
            </div>
            <button type="button" onclick="addItem()" style="margin-top:8px;background:none;border:1px dashed var(--cafe);color:var(--cafe);padding:8px 16px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;width:100%;">➕ Tambah Item</button>

            <div class="totals-box">
                <div class="cf-row" style="margin-bottom:8px;">
                    <div><label class="cf-label">Diskon (Rp)</label><input type="text" name="discount_amount" class="cf-input" value="0" onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()" id="discountInput"></div>
                    <div><label class="cf-label">Pajak (%)</label><input type="number" name="tax_percent" class="cf-input" value="0" min="0" max="100" step="0.5" onchange="calcTotals()" onkeyup="calcTotals()" id="taxInput"></div>
                </div>
                <div class="totals-row"><span>Subtotal</span><span id="dispSubtotal">Rp 0</span></div>
                <div class="totals-row"><span>Diskon</span><span id="dispDiscount" style="color:#dc2626;">- Rp 0</span></div>
                <div class="totals-row"><span>Pajak</span><span id="dispTax">Rp 0</span></div>
                <div class="totals-row grand"><span>TOTAL</span><span id="dispTotal">Rp 0</span></div>
            </div>
        </div>

        <div class="cf-card">
            <label class="cf-label">Catatan Internal</label>
            <textarea name="notes" class="cf-input" rows="2" placeholder="Catatan internal (opsional)"></textarea>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="hideCreate()" class="btn-cafe btn-ghost">Batal</button>
            <button type="submit" class="btn-cafe" style="padding:10px 24px;font-size:13px;">💾 Simpan Invoice</button>
        </div>
    </form>
</div>

<!-- ═══ MODAL: PAY INVOICE ═══ -->
<div class="modal-bg" id="payModal">
    <div class="modal-box">
        <div class="modal-title">💰 Bayar Invoice</div>
        <div style="background:var(--cafe-bg);border-radius:10px;padding:12px;margin-bottom:14px;">
            <div style="font-size:11px;color:#6b7280;">Invoice</div>
            <div style="font-size:15px;font-weight:800;color:var(--cafe-dark);" id="payInvNum"></div>
            <div style="font-size:20px;font-weight:800;color:var(--cafe);margin-top:4px;" id="payInvAmount"></div>
        </div>

        <label class="cf-label">Metode Pembayaran *</label>
        <div class="pay-methods" id="payMethods">
            <div class="pay-card" data-method="cash" onclick="selectPayMethod('cash')">
                <div class="pay-icon">💵</div><div class="pay-label">Cash</div>
            </div>
            <div class="pay-card" data-method="transfer" onclick="selectPayMethod('transfer')">
                <div class="pay-icon">🏦</div><div class="pay-label">Transfer</div>
            </div>
            <div class="pay-card" data-method="qr" onclick="selectPayMethod('qr')">
                <div class="pay-icon">📱</div><div class="pay-label">QR Code</div>
            </div>
            <div class="pay-card" data-method="debit" onclick="selectPayMethod('debit')">
                <div class="pay-icon">💳</div><div class="pay-label">Debit</div>
            </div>
            <div class="pay-card" data-method="edc" onclick="selectPayMethod('edc')">
                <div class="pay-icon">🖥️</div><div class="pay-label">EDC</div>
            </div>
            <div class="pay-card" data-method="other" onclick="selectPayMethod('other')">
                <div class="pay-icon">📦</div><div class="pay-label">Lainnya</div>
            </div>
        </div>

        <label class="cf-label" style="margin-top:12px;">Uang Masuk ke Rekening *</label>
        <select class="cf-input" id="payAccount" style="margin-bottom:14px;">
            <option value="">— Pilih Rekening —</option>
            <?php foreach ($cashAccounts as $acc): ?>
            <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo ucfirst($acc['account_type']); ?>) — <?php echo formatCurrency($acc['current_balance']); ?></option>
            <?php endforeach; ?>
        </select>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button onclick="closePayModal()" class="btn-cafe btn-ghost">Batal</button>
            <button onclick="submitPay()" class="btn-cafe btn-pay" style="padding:10px 24px;" id="payBtn">💰 Bayar Sekarang</button>
        </div>
    </div>
</div>

<!-- ═══ MODAL: VIEW INVOICE ═══ -->
<div class="modal-bg" id="viewModal">
    <div class="modal-box" style="max-width:520px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div class="modal-title" style="margin:0;">👁️ Detail Invoice</div>
            <div style="display:flex;gap:6px;">
                <button onclick="printInvoice()" class="btn-cafe btn-sm btn-ghost">🖨️ Print</button>
                <button onclick="document.getElementById('viewModal').classList.remove('open')" class="btn-cafe btn-sm btn-ghost">✕</button>
            </div>
        </div>
        <div id="printArea">
        <div class="inv-preview" id="invPreviewContent">
            <!-- Filled by JS -->
        </div>
        </div>
    </div>
</div>

<script>
// ─── CREATE FORM ───
function showCreate() { document.getElementById('viewList').style.display = 'none'; document.getElementById('viewCreate').style.display = 'block'; }
function hideCreate() { document.getElementById('viewList').style.display = 'block'; document.getElementById('viewCreate').style.display = 'none'; }

let itemIndex = 1;
function addItem() {
    const c = document.getElementById('itemsContainer');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.dataset.index = itemIndex;
    row.innerHTML = `
        <div><input type="text" name="item_name[]" class="cf-input" placeholder="Nama menu/item" required></div>
        <div><input type="number" name="item_qty[]" class="cf-input qty-input" value="1" min="1" onchange="calcTotals()" onkeyup="calcTotals()"></div>
        <div><input type="text" name="item_price[]" class="cf-input price-input" placeholder="0" required onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()"></div>
        <div class="line-total-col" style="font-size:12px;font-weight:700;color:var(--cafe);padding-top:8px;text-align:right;" data-linetotal>Rp 0</div>
        <div><button type="button" class="remove-item" onclick="removeItem(this)" title="Hapus">×</button></div>
    `;
    c.appendChild(row);
    itemIndex++;
    row.querySelector('input').focus();
}

function removeItem(btn) {
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    if (rows.length <= 1) return;
    btn.closest('.item-row').remove();
    calcTotals();
}

function parseRp(val) {
    return parseFloat(String(val).replace(/[^0-9]/g, '')) || 0;
}

function formatPrice(el) {
    let v = el.value.replace(/[^0-9]/g, '');
    if (v) el.value = parseInt(v).toLocaleString('id-ID');
}

function fmtRp(n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

function calcTotals() {
    let subtotal = 0;
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        const qty = parseInt(row.querySelector('.qty-input')?.value) || 1;
        const price = parseRp(row.querySelector('.price-input')?.value);
        const line = qty * price;
        subtotal += line;
        const lt = row.querySelector('[data-linetotal]');
        if (lt) lt.textContent = fmtRp(line);
    });
    const discount = parseRp(document.getElementById('discountInput').value);
    const taxPct = parseFloat(document.getElementById('taxInput').value) || 0;
    const tax = Math.round(subtotal * taxPct / 100);
    const total = subtotal - discount + tax;

    document.getElementById('dispSubtotal').textContent = fmtRp(subtotal);
    document.getElementById('dispDiscount').textContent = '- ' + fmtRp(discount);
    document.getElementById('dispTax').textContent = fmtRp(tax);
    document.getElementById('dispTotal').textContent = fmtRp(total);
}

// ─── PAY MODAL ───
let payInvId = 0, payMethod = '';
function openPayModal(id, num, amount) {
    payInvId = id;
    payMethod = '';
    document.getElementById('payInvNum').textContent = num;
    document.getElementById('payInvAmount').textContent = fmtRp(amount);
    document.querySelectorAll('.pay-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('payAccount').value = '';
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() { document.getElementById('payModal').classList.remove('open'); }

function selectPayMethod(method) {
    payMethod = method;
    document.querySelectorAll('.pay-card').forEach(c => c.classList.remove('selected'));
    document.querySelector(`.pay-card[data-method="${method}"]`).classList.add('selected');
}

function submitPay() {
    if (!payMethod) { alert('Pilih metode pembayaran!'); return; }
    const accId = document.getElementById('payAccount').value;
    if (!accId) { alert('Pilih rekening tujuan!'); return; }
    const btn = document.getElementById('payBtn');
    btn.disabled = true; btn.textContent = '⏳ Memproses...';

    const fd = new FormData();
    fd.append('invoice_id', payInvId);
    fd.append('payment_method', payMethod);
    fd.append('cash_account_id', accId);

    fetch('?ajax=pay', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closePayModal();
                location.reload();
            } else {
                alert(data.message || 'Gagal membayar');
                btn.disabled = false; btn.textContent = '💰 Bayar Sekarang';
            }
        })
        .catch(err => { alert('Network error'); btn.disabled = false; btn.textContent = '💰 Bayar Sekarang'; });
}

// ─── VIEW INVOICE ───
function viewInvoice(id) {
    fetch('?ajax=get&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert('Invoice not found'); return; }
            const inv = data.invoice, items = data.items;
            let itemsHtml = '';
            items.forEach((it, i) => {
                itemsHtml += `<tr><td>${i+1}</td><td>${escHtml(it.item_name)}</td><td style="text-align:center;">${it.qty}</td><td style="text-align:right;">${fmtRp(it.unit_price)}</td><td style="text-align:right;">${fmtRp(it.subtotal)}</td></tr>`;
            });
            const paidInfo = inv.status === 'paid' 
                ? `<div style="text-align:center;margin-top:12px;"><span class="inv-paid-stamp">✅ LUNAS — ${inv.payment_method ? inv.payment_method.toUpperCase() : ''} — ${inv.paid_at ? inv.paid_at.substring(0,16) : ''}</span></div>` 
                : `<div style="text-align:center;margin-top:12px;"><span class="badge b-unpaid" style="font-size:12px;padding:6px 20px;">⏳ BELUM BAYAR</span></div>`;

            document.getElementById('invPreviewContent').innerHTML = `
                <div class="inv-header">
                    <div class="inv-logo">☕</div>
                    <div class="inv-biz-name"><?php echo htmlspecialchars($companyName); ?></div>
                    <div class="inv-biz-detail"><?php echo htmlspecialchars($companyAddress); ?></div>
                    <div class="inv-biz-detail">📱 <?php echo htmlspecialchars($companyPhone); ?></div>
                </div>
                <div style="text-align:center;margin-bottom:14px;">
                    <div style="font-size:14px;font-weight:800;color:var(--cafe-dark);">INVOICE</div>
                    <div style="font-size:13px;font-weight:700;color:var(--cafe);">${escHtml(inv.invoice_number)}</div>
                </div>
                <div class="inv-meta">
                    <div><span class="inv-meta-label">Pelanggan:</span><br><span class="inv-meta-val">${escHtml(inv.customer_name)}</span></div>
                    <div style="text-align:right;"><span class="inv-meta-label">Tanggal:</span><br><span class="inv-meta-val">${inv.created_at ? inv.created_at.substring(0,10) : ''}</span></div>
                    ${inv.customer_phone ? `<div><span class="inv-meta-label">No. HP:</span><br><span class="inv-meta-val">${escHtml(inv.customer_phone)}</span></div>` : ''}
                    ${inv.customer_note ? `<div><span class="inv-meta-label">Catatan:</span><br><span class="inv-meta-val">${escHtml(inv.customer_note)}</span></div>` : ''}
                </div>
                <table class="inv-items-tbl">
                    <thead><tr><th>#</th><th>Item</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Harga</th><th style="text-align:right;">Subtotal</th></tr></thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
                <div style="border-top:2px solid var(--cafe);padding-top:10px;">
                    <div class="totals-row"><span>Subtotal</span><span>${fmtRp(inv.subtotal)}</span></div>
                    ${parseFloat(inv.discount_amount) > 0 ? `<div class="totals-row"><span>Diskon</span><span style="color:#dc2626;">- ${fmtRp(inv.discount_amount)}</span></div>` : ''}
                    ${parseFloat(inv.tax_amount) > 0 ? `<div class="totals-row"><span>Pajak</span><span>${fmtRp(inv.tax_amount)}</span></div>` : ''}
                    <div class="totals-row grand"><span>TOTAL</span><span>${fmtRp(inv.total_amount)}</span></div>
                </div>
                ${paidInfo}
                <div class="inv-footer">Terima kasih atas kunjungan Anda! ☕<br><?php echo htmlspecialchars($companyName); ?></div>
            `;
            document.getElementById('viewModal').classList.add('open');
        });
}

function printInvoice() {
    const content = document.getElementById('printArea').innerHTML;
    const w = window.open('', '_blank', 'width=400,height=600');
    w.document.write(`<!DOCTYPE html><html><head><title>Invoice</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 20px; font-size: 12px; color: #111; }
        .inv-preview { max-width: 380px; margin: 0 auto; }
        .inv-header { text-align: center; border-bottom: 2px solid #92400e; padding-bottom: 12px; margin-bottom: 12px; }
        .inv-logo { font-size: 28px; }
        .inv-biz-name { font-size: 18px; font-weight: 800; color: #78350f; }
        .inv-biz-detail { font-size: 10px; color: #6b7280; }
        .inv-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 12px; font-size: 11px; }
        .inv-meta-label { color: #6b7280; font-weight: 600; }
        .inv-meta-val { color: #111; font-weight: 700; }
        .inv-items-tbl { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 12px; }
        .inv-items-tbl th { background: #fffbeb; padding: 6px; text-align: left; font-weight: 700; color: #78350f; border-bottom: 1px solid #fcd34d; }
        .inv-items-tbl td { padding: 5px 6px; border-bottom: 1px solid #f3f4f6; }
        .totals-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 12px; }
        .totals-row.grand { font-size: 15px; font-weight: 800; border-top: 2px solid #92400e; padding-top: 6px; margin-top: 4px; }
        .inv-paid-stamp { background: #059669; color: #fff; padding: 4px 16px; border-radius: 16px; font-size: 11px; font-weight: 800; }
        .badge { padding: 3px 10px; border-radius: 16px; font-size: 10px; font-weight: 700; }
        .b-unpaid { background: #fef2f2; color: #dc2626; }
        .inv-footer { text-align: center; font-size: 10px; color: #9ca3af; margin-top: 14px; padding-top: 10px; border-top: 1px dashed #d1d5db; }
    </style></head><body onload="window.print();window.close()">${content}</body></html>`);
    w.document.close();
}

function escHtml(s) {
    const div = document.createElement('div');
    div.textContent = s || '';
    return div.innerHTML;
}

function deleteInvoice(id) {
    if (!confirm('Hapus invoice ini?')) return;
    const fd = new FormData();
    fd.append('invoice_id', id);
    fetch('?ajax=delete', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message); });
}

// Close modals on background click
document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
</script>

<?php include '../../includes/footer.php'; ?>
