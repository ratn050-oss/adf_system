<?php
/**
 * BENS CAFE - Invoice Management
 * Create invoices, mark paid â†’ auto-post to cash_book
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

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// AUTO-CREATE TABLE
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
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
$companyEmail = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_email'")['setting_value'] ?? '';
$companyTagline = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_tagline'")['setting_value'] ?? 'Fresh Coffee & Good Vibes';
$logoUrl = getBusinessLogo();

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

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// AJAX HANDLERS
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
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
                'description' => 'Bayar ' . $inv['invoice_number'] . ' â€” ' . $inv['customer_name'],
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
            echo json_encode(['success' => true, 'message' => 'âœ… Invoice ' . $inv['invoice_number'] . ' berhasil dibayar!']);
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

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// CREATE INVOICE (POST)
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
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
            setFlash('success', "âœ… Invoice $invNumber berhasil dibuat!");
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollback();
            setFlash('error', 'Gagal membuat invoice: ' . $e->getMessage());
        }
    }
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// LOAD DATA
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
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

$pageTitle = 'â˜• Invoice Bens Cafe';
include '../../includes/header.php';

$successMsg = getFlash('success');
$errorMsg = getFlash('error');

// Prepare logo for JS (base64 or URL)
$logoForInvoice = $logoUrl ?: '';
$businessIcon = defined('BUSINESS_ICON') ? BUSINESS_ICON : 'â˜•';
?>

<style>
/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   BENS CAFE INVOICE â€” PREMIUM ELEGANT DESIGN
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
:root {
    --cafe: #92400e; --cafe-light: #fef3c7; --cafe-dark: #78350f; --cafe-bg: #fffbeb;
    --cafe-gold: #d4a574; --cafe-cream: #fdf6ec; --cafe-espresso: #3c1a00;
    --shadow-sm: 0 1px 3px rgba(60,26,0,.06);
    --shadow-md: 0 4px 16px rgba(60,26,0,.08);
    --shadow-lg: 0 12px 40px rgba(60,26,0,.12);
    --shadow-xl: 0 20px 60px rgba(60,26,0,.15);
    --radius: 14px;
}

/* Stats Cards */
.inv-stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 24px; }
.inv-stat { background: #fff; border-radius: var(--radius); padding: 18px 20px; border: 1px solid rgba(146,64,14,.08); position: relative; overflow: hidden; box-shadow: var(--shadow-sm); transition: all .3s; }
.inv-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.inv-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 3px 3px 0 0; }
.inv-stat::after { content: attr(data-icon); position: absolute; right: 16px; top: 14px; font-size: 28px; opacity: .12; }
.inv-stat h4 { font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .8px; margin: 0 0 8px; }
.inv-stat .val { font-size: 24px; font-weight: 800; margin: 0; line-height: 1.2; }
.inv-stat .sub { font-size: 11px; font-weight: 600; color: #9ca3af; margin-top: 2px; }
.inv-stat.s1::before { background: linear-gradient(90deg, var(--cafe), var(--cafe-gold)); } .inv-stat.s1 .val { color: var(--cafe); }
.inv-stat.s2::before { background: linear-gradient(90deg, #ef4444, #f97316); } .inv-stat.s2 .val { color: #dc2626; }
.inv-stat.s3::before { background: linear-gradient(90deg, #10b981, #34d399); } .inv-stat.s3 .val { color: #059669; }
.inv-stat.s4::before { background: linear-gradient(90deg, #3b82f6, #8b5cf6); } .inv-stat.s4 .val { color: #3b82f6; }

/* Toolbar */
.inv-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; gap: 12px; flex-wrap: wrap; }
.inv-filters { display: flex; gap: 4px; background: #f3f4f6; padding: 4px; border-radius: 10px; }
.inv-filters a { padding: 7px 16px; border-radius: 8px; font-size: 11px; font-weight: 700; text-decoration: none; color: #6b7280; transition: all .2s; }
.inv-filters a.active { background: #fff; color: var(--cafe); box-shadow: var(--shadow-sm); }
.inv-filters a:hover:not(.active) { color: var(--cafe-dark); }

/* Table */
.inv-table-wrap { background: #fff; border-radius: var(--radius); border: 1px solid rgba(146,64,14,.06); overflow: hidden; box-shadow: var(--shadow-sm); }
.inv-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.inv-table th { background: linear-gradient(180deg, #fefce8, #fef9c3); padding: 12px 14px; text-align: left; font-size: 10px; font-weight: 800; color: var(--cafe); text-transform: uppercase; letter-spacing: .6px; border-bottom: 2px solid #fcd34d; }
.inv-table td { padding: 12px 14px; border-bottom: 1px solid #f8f4ee; vertical-align: middle; }
.inv-table tr { transition: background .15s; }
.inv-table tr:hover { background: linear-gradient(90deg, #fffbeb, #fff); }
.inv-table tr:last-child td { border-bottom: none; }
.inv-num { font-weight: 800; color: var(--cafe); font-size: 12px; font-family: 'Courier New', monospace; letter-spacing: .3px; }
.badge { padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px; }
.b-unpaid { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #dc2626; border: 1px solid #fca5a5; }
.b-paid { background: linear-gradient(135deg, #f0fdf4, #dcfce7); color: #059669; border: 1px solid #86efac; }
.b-cancelled { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }

/* Buttons */
.btn-cafe { background: linear-gradient(135deg, var(--cafe), var(--cafe-dark)); color: #fff; border: none; padding: 9px 20px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .25s; box-shadow: 0 2px 8px rgba(146,64,14,.2); }
.btn-cafe:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(146,64,14,.3); filter: brightness(1.05); }
.btn-cafe:active { transform: translateY(0); }
.btn-sm { padding: 6px 14px; font-size: 11px; border-radius: 8px; box-shadow: none; }
.btn-pay { background: linear-gradient(135deg, #059669, #047857); box-shadow: 0 2px 8px rgba(5,150,105,.2); }
.btn-pay:hover { box-shadow: 0 6px 20px rgba(5,150,105,.3); }
.btn-view { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 2px 8px rgba(59,130,246,.2); }
.btn-view:hover { box-shadow: 0 6px 20px rgba(59,130,246,.3); }
.btn-del { background: #fff; color: #dc2626; border: 1px solid #fca5a5; box-shadow: none; }
.btn-del:hover { background: #dc2626; color: #fff; box-shadow: 0 4px 12px rgba(220,38,38,.2); }
.btn-ghost { background: #fff; color: #6b7280; border: 1px solid #e5e7eb; box-shadow: none; }
.btn-ghost:hover { background: #f9fafb; color: #374151; }

/* Create Form */
.cf-card { background: #fff; border-radius: var(--radius); padding: 22px 24px; border: 1px solid rgba(146,64,14,.06); margin-bottom: 16px; box-shadow: var(--shadow-sm); }
.cf-title { font-size: 14px; font-weight: 800; color: var(--cafe-dark); margin: 0 0 14px; display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 1px solid #f3f4f6; }
.cf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px; }
.cf-label { font-size: 11px; font-weight: 700; color: #4b5563; margin-bottom: 5px; display: block; letter-spacing: .2px; }
.cf-input { width: 100%; padding: 9px 14px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 12px; background: #fafafa; transition: all .2s; box-sizing: border-box; }
.cf-input:focus { outline: none; border-color: var(--cafe); background: #fff; box-shadow: 0 0 0 3px rgba(146,64,14,.08); }

/* Item rows */
.item-row { display: grid; grid-template-columns: 1fr 70px 120px 100px 36px; gap: 8px; align-items: end; margin-bottom: 8px; padding: 8px 10px; border-radius: 8px; background: #fafafa; transition: background .15s; }
.item-row:hover { background: var(--cafe-cream); }
.item-header { display: grid; grid-template-columns: 1fr 70px 120px 100px 36px; gap: 8px; font-size: 10px; font-weight: 800; color: var(--cafe); text-transform: uppercase; letter-spacing: .5px; padding: 0 10px 8px; border-bottom: 2px solid #fde68a; margin-bottom: 8px; }
.remove-item { width: 32px; height: 32px; border-radius: 8px; border: 1px solid #fca5a5; background: #fff; color: #dc2626; cursor: pointer; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; transition: all .2s; }
.remove-item:hover { background: #dc2626; color: #fff; transform: scale(1.05); }
.totals-box { background: linear-gradient(135deg, var(--cafe-cream), #fffbeb); border: 1.5px solid #fcd34d; border-radius: 12px; padding: 16px 18px; margin-top: 14px; }
.totals-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 12px; color: #4b5563; }
.totals-row.grand { font-size: 18px; font-weight: 800; color: var(--cafe-espresso); border-top: 2px solid var(--cafe); padding-top: 10px; margin-top: 8px; }

/* Modal */
.modal-bg { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(30,10,0,.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
.modal-bg.open { display: flex; }
.modal-box { background: #fff; border-radius: 20px; padding: 28px; max-width: 500px; width: 92%; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-xl); animation: modalIn .3s ease; }
@keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(.97); } to { opacity: 1; transform: none; } }
.modal-title { font-size: 17px; font-weight: 800; color: var(--cafe-dark); margin: 0 0 18px; display: flex; align-items: center; gap: 10px; }

/* Pay method cards */
.pay-methods { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin: 12px 0; }
.pay-card { padding: 14px 8px; border: 2px solid #f3f4f6; border-radius: 12px; text-align: center; cursor: pointer; transition: all .25s; background: #fafafa; }
.pay-card:hover { border-color: var(--cafe-gold); background: var(--cafe-cream); transform: translateY(-1px); }
.pay-card.selected { border-color: var(--cafe); background: var(--cafe-light); box-shadow: 0 0 0 3px rgba(146,64,14,.12); }
.pay-card .pay-icon { font-size: 24px; margin-bottom: 4px; }
.pay-card .pay-label { font-size: 11px; font-weight: 800; color: #374151; }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• INVOICE PREVIEW â€” PREMIUM â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.inv-preview {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: var(--shadow-lg);
    border: 1px solid rgba(146,64,14,.08);
}
.inv-preview-inner { padding: 32px 28px 24px; }

/* Header Band */
.inv-hdr-band {
    background: linear-gradient(135deg, var(--cafe-espresso) 0%, var(--cafe-dark) 50%, var(--cafe) 100%);
    padding: 28px 28px 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.inv-hdr-band::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Ccircle cx='30' cy='30' r='4'/%3E%3C/g%3E%3C/svg%3E");
}
.inv-hdr-content { position: relative; z-index: 1; display: flex; align-items: center; gap: 18px; }
.inv-hdr-logo {
    width: 64px; height: 64px; border-radius: 14px; overflow: hidden;
    background: rgba(255,255,255,.15); backdrop-filter: blur(8px);
    border: 2px solid rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.inv-hdr-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 12px; }
.inv-hdr-logo .fallback-icon { font-size: 32px; filter: drop-shadow(0 2px 4px rgba(0,0,0,.2)); }
.inv-hdr-info { flex: 1; }
.inv-hdr-name { font-size: 20px; font-weight: 800; letter-spacing: .5px; text-shadow: 0 1px 3px rgba(0,0,0,.2); margin: 0; }
.inv-hdr-tagline { font-size: 11px; opacity: .7; margin: 2px 0 0; font-weight: 500; font-style: italic; }
.inv-hdr-contacts { font-size: 10px; opacity: .6; margin-top: 6px; display: flex; flex-wrap: wrap; gap: 8px; }
.inv-hdr-contacts span { display: inline-flex; align-items: center; gap: 3px; }

/* Invoice Title Bar */
.inv-title-bar {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 20px 0 16px; margin: 0 0 16px;
    border-bottom: 2px solid var(--cafe-light);
}
.inv-title-label { font-size: 28px; font-weight: 900; color: var(--cafe); letter-spacing: 2px; line-height: 1; }
.inv-title-number { font-size: 13px; font-weight: 700; color: var(--cafe-dark); font-family: 'Courier New', monospace; margin-top: 4px; }
.inv-title-right { text-align: right; }

/* Meta Grid */
.inv-meta-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; margin-bottom: 20px;
    padding: 14px 16px; background: var(--cafe-cream);
    border-radius: 10px; border: 1px solid #fde68a;
}
.inv-meta-item {}
.inv-meta-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #9ca3af; letter-spacing: .6px; margin-bottom: 2px; }
.inv-meta-val { font-size: 12px; font-weight: 700; color: #1f2937; }

/* Items Table */
.inv-items-tbl { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 18px; }
.inv-items-tbl thead th {
    background: linear-gradient(180deg, var(--cafe-espresso), var(--cafe-dark));
    color: #fff; padding: 10px 12px; text-align: left;
    font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px;
}
.inv-items-tbl thead th:first-child { border-radius: 8px 0 0 0; }
.inv-items-tbl thead th:last-child { border-radius: 0 8px 0 0; text-align: right; }
.inv-items-tbl tbody td { padding: 10px 12px; border-bottom: 1px solid #f3ece4; }
.inv-items-tbl tbody tr:last-child td { border-bottom: none; }
.inv-items-tbl tbody tr:nth-child(even) { background: #fdfaf5; }
.inv-items-tbl td:last-child { text-align: right; font-weight: 700; }
.inv-items-tbl td:nth-child(3) { text-align: center; }
.inv-items-tbl td:nth-child(4) { text-align: right; }

/* Totals */
.inv-totals { margin-left: auto; width: 240px; }
.inv-total-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 12px; color: #6b7280; }
.inv-total-row.grand {
    font-size: 16px; font-weight: 900; color: var(--cafe-espresso);
    border-top: 2px solid var(--cafe); padding: 10px 0 0; margin-top: 6px;
}

/* Paid Stamp */
.inv-stamp {
    text-align: center; margin: 20px 0 8px;
}
.inv-stamp-paid {
    display: inline-flex; align-items: center; gap: 8px;
    background: linear-gradient(135deg, #059669, #047857);
    color: #fff; padding: 8px 24px; border-radius: 24px;
    font-size: 12px; font-weight: 900; letter-spacing: .5px;
    box-shadow: 0 4px 12px rgba(5,150,105,.25);
}
.inv-stamp-unpaid {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    color: #dc2626; padding: 8px 24px; border-radius: 24px;
    font-size: 12px; font-weight: 900; border: 1.5px solid #fca5a5;
}

/* Footer */
.inv-footer-bar {
    background: linear-gradient(135deg, #fef9ee, #fef3c7);
    padding: 16px 28px; text-align: center;
    border-top: 1px solid #fde68a;
}
.inv-footer-bar .thanks { font-size: 13px; font-weight: 700; color: var(--cafe-dark); margin: 0 0 4px; }
.inv-footer-bar .tagline { font-size: 10px; color: #9ca3af; font-style: italic; }
.inv-footer-bar .legal { font-size: 9px; color: #d1d5db; margin-top: 6px; }

/* Print */
@media print { body * { visibility: hidden; } #printArea, #printArea * { visibility: visible; } #printArea { position: absolute; left: 0; top: 0; width: 100%; } }
@media (max-width: 768px) {
    .inv-stats { grid-template-columns: repeat(2,1fr); }
    .item-row, .item-header { grid-template-columns: 1fr 60px 100px 36px; }
    .item-row .line-total-col, .item-header .line-total-col { display: none; }
    .inv-meta-grid { grid-template-columns: 1fr; }
    .inv-totals { width: 100%; }
}
</style>

<?php if ($successMsg): ?>
<div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #86efac;border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:12px;color:#065f46;font-weight:700;display:flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(5,150,105,.08);">âœ… <?php echo $successMsg; ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div style="background:linear-gradient(135deg,#fef2f2,#fee2e2);border:1px solid #fca5a5;border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:12px;color:#991b1b;font-weight:700;display:flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(220,38,38,.08);">âš ï¸ <?php echo $errorMsg; ?></div>
<?php endif; ?>

<!-- â•â•â• VIEW: INVOICE LIST â•â•â• -->
<div id="viewList">

<div class="inv-stats">
    <div class="inv-stat s1" data-icon="ðŸ“„"><h4>Total Invoice</h4><p class="val"><?php echo (int)$stats['total']; ?></p></div>
    <div class="inv-stat s2" data-icon="â³"><h4>Belum Bayar</h4><p class="val"><?php echo (int)$stats['unpaid_count']; ?></p><div class="sub"><?php echo formatCurrency($stats['unpaid_amount'] ?? 0); ?></div></div>
    <div class="inv-stat s3" data-icon="ðŸ’°"><h4>Lunas Hari Ini</h4><p class="val"><?php echo formatCurrency($stats['today_paid'] ?? 0); ?></p></div>
    <div class="inv-stat s4" data-icon="âœ…"><h4>Total Lunas</h4><p class="val"><?php echo (int)$stats['paid_count']; ?></p></div>
</div>

<div class="inv-toolbar">
    <div class="inv-filters">
        <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">ðŸ“‹ Semua</a>
        <a href="?filter=unpaid" class="<?php echo $filter === 'unpaid' ? 'active' : ''; ?>">â³ Belum Bayar</a>
        <a href="?filter=paid" class="<?php echo $filter === 'paid' ? 'active' : ''; ?>">âœ… Lunas</a>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <form method="GET" style="display:flex;gap:6px;">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari invoice..." class="cf-input" style="width:180px;padding:7px 13px;font-size:12px;">
            <button type="submit" class="btn-cafe btn-sm">ðŸ”</button>
        </form>
        <button onclick="showCreate()" class="btn-cafe">âž• Buat Invoice</button>
    </div>
</div>

<div class="inv-table-wrap">
<?php if (empty($invoices)): ?>
    <div style="text-align:center;padding:50px 20px;">
        <div style="font-size:48px;margin-bottom:12px;opacity:.3;">â˜•</div>
        <div style="font-size:14px;font-weight:700;color:#9ca3af;">Belum ada invoice</div>
        <div style="font-size:12px;color:#d1d5db;margin-top:4px;">Klik <b>Buat Invoice</b> untuk mulai</div>
    </div>
<?php else: ?>
    <table class="inv-table">
        <thead><tr><th>No. Invoice</th><th>Pelanggan</th><th>Total</th><th>Metode</th><th>Status</th><th>Tanggal</th><th style="text-align:center;">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr>
            <td><span class="inv-num"><?php echo htmlspecialchars($inv['invoice_number']); ?></span></td>
            <td>
                <div style="font-weight:700;font-size:12px;color:#1f2937;"><?php echo htmlspecialchars($inv['customer_name']); ?></div>
                <?php if ($inv['customer_phone']): ?><div style="font-size:10px;color:#9ca3af;margin-top:1px;">ðŸ“± <?php echo htmlspecialchars($inv['customer_phone']); ?></div><?php endif; ?>
            </td>
            <td style="font-weight:800;color:var(--cafe-dark);"><?php echo formatCurrency($inv['total_amount']); ?></td>
            <td>
                <?php if ($inv['payment_method']): ?>
                    <span style="font-size:11px;font-weight:700;color:var(--cafe);background:var(--cafe-light);padding:3px 8px;border-radius:6px;"><?php echo ucfirst($inv['payment_method']); ?></span>
                <?php else: ?>
                    <span style="color:#d1d5db;">â€”</span>
                <?php endif; ?>
            </td>
            <td><span class="badge b-<?php echo $inv['status']; ?>"><?php echo $inv['status'] === 'paid' ? 'âœ… Lunas' : ($inv['status'] === 'unpaid' ? 'â³ Belum' : 'âŒ Batal'); ?></span></td>
            <td style="font-size:11px;color:#6b7280;">
                <?php echo date('d/m/Y H:i', strtotime($inv['created_at'])); ?>
                <?php if ($inv['paid_at']): ?><br><span style="color:#059669;font-size:10px;font-weight:600;">ðŸ’° <?php echo date('d/m H:i', strtotime($inv['paid_at'])); ?></span><?php endif; ?>
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                    <button onclick="viewInvoice(<?php echo $inv['id']; ?>)" class="btn-cafe btn-sm btn-view" title="Lihat">ðŸ‘ï¸</button>
                    <?php if ($inv['status'] === 'unpaid'): ?>
                    <button onclick="openPayModal(<?php echo $inv['id']; ?>, '<?php echo htmlspecialchars($inv['invoice_number']); ?>', <?php echo $inv['total_amount']; ?>)" class="btn-cafe btn-sm btn-pay" title="Bayar">ðŸ’° Pay</button>
                    <button onclick="deleteInvoice(<?php echo $inv['id']; ?>)" class="btn-cafe btn-sm btn-del" title="Hapus">ðŸ—‘ï¸</button>
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

<!-- â•â•â• VIEW: CREATE INVOICE â•â•â• -->
<div id="viewCreate" style="display:none;">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
        <button onclick="hideCreate()" class="btn-cafe btn-ghost btn-sm">â† Kembali</button>
        <div>
            <h2 style="font-size:17px;font-weight:800;color:var(--cafe-dark);margin:0;">â˜• Buat Invoice Baru</h2>
            <p style="font-size:11px;color:#9ca3af;margin:2px 0 0;">Isi detail pesanan pelanggan</p>
        </div>
    </div>
    <form method="POST" id="createForm">
        <input type="hidden" name="action" value="create_invoice">
        <div class="cf-card">
            <div class="cf-title">ðŸ‘¤ Info Pelanggan</div>
            <div class="cf-row">
                <div><label class="cf-label">Nama Pelanggan</label><input type="text" name="customer_name" class="cf-input" placeholder="Walk-in" value="Walk-in"></div>
                <div><label class="cf-label">No. HP</label><input type="text" name="customer_phone" class="cf-input" placeholder="081xxx"></div>
            </div>
            <div><label class="cf-label">Catatan untuk Pelanggan</label><textarea name="customer_note" class="cf-input" rows="2" placeholder="Opsional" style="resize:vertical;"></textarea></div>
        </div>

        <div class="cf-card">
            <div class="cf-title">ðŸ“‹ Item Pesanan</div>
            <div class="item-header">
                <div>Nama Item</div><div>Qty</div><div>Harga</div><div class="line-total-col">Subtotal</div><div></div>
            </div>
            <div id="itemsContainer">
                <div class="item-row" data-index="0">
                    <div><input type="text" name="item_name[]" class="cf-input" placeholder="Nama menu/item" required style="font-weight:600;"></div>
                    <div><input type="number" name="item_qty[]" class="cf-input qty-input" value="1" min="1" onchange="calcTotals()" onkeyup="calcTotals()"></div>
                    <div><input type="text" name="item_price[]" class="cf-input price-input" placeholder="0" required onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()"></div>
                    <div class="line-total-col" style="font-size:12px;font-weight:800;color:var(--cafe);padding-top:8px;text-align:right;" data-linetotal>Rp 0</div>
                    <div><button type="button" class="remove-item" onclick="removeItem(this)" title="Hapus">Ã—</button></div>
                </div>
            </div>
            <button type="button" onclick="addItem()" style="margin-top:10px;background:var(--cafe-cream);border:2px dashed var(--cafe-gold);color:var(--cafe);padding:10px 16px;border-radius:10px;font-size:11px;font-weight:800;cursor:pointer;width:100%;transition:all .2s;" onmouseover="this.style.background='var(--cafe-light)'" onmouseout="this.style.background='var(--cafe-cream)'">âž• Tambah Item</button>

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
            <textarea name="notes" class="cf-input" rows="2" placeholder="Catatan internal (opsional)" style="resize:vertical;"></textarea>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="hideCreate()" class="btn-cafe btn-ghost" style="padding:11px 24px;">Batal</button>
            <button type="submit" class="btn-cafe" style="padding:11px 28px;font-size:13px;">ðŸ’¾ Simpan Invoice</button>
        </div>
    </form>
</div>

<!-- â•â•â• MODAL: PAY INVOICE â•â•â• -->
<div class="modal-bg" id="payModal">
    <div class="modal-box">
        <div class="modal-title">ðŸ’° Bayar Invoice</div>
        <div style="background:linear-gradient(135deg,var(--cafe-cream),#fffbeb);border-radius:12px;padding:16px;margin-bottom:16px;border:1px solid #fde68a;">
            <div style="font-size:10px;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Invoice</div>
            <div style="font-size:16px;font-weight:800;color:var(--cafe-dark);margin-top:2px;" id="payInvNum"></div>
            <div style="font-size:24px;font-weight:900;color:var(--cafe);margin-top:6px;" id="payInvAmount"></div>
        </div>

        <label class="cf-label">Metode Pembayaran *</label>
        <div class="pay-methods" id="payMethods">
            <div class="pay-card" data-method="cash" onclick="selectPayMethod('cash')">
                <div class="pay-icon">ðŸ’µ</div><div class="pay-label">Cash</div>
            </div>
            <div class="pay-card" data-method="transfer" onclick="selectPayMethod('transfer')">
                <div class="pay-icon">ðŸ¦</div><div class="pay-label">Transfer</div>
            </div>
            <div class="pay-card" data-method="qr" onclick="selectPayMethod('qr')">
                <div class="pay-icon">ðŸ“±</div><div class="pay-label">QR Code</div>
            </div>
            <div class="pay-card" data-method="debit" onclick="selectPayMethod('debit')">
                <div class="pay-icon">ðŸ’³</div><div class="pay-label">Debit</div>
            </div>
            <div class="pay-card" data-method="edc" onclick="selectPayMethod('edc')">
                <div class="pay-icon">ðŸ–¥ï¸</div><div class="pay-label">EDC</div>
            </div>
            <div class="pay-card" data-method="other" onclick="selectPayMethod('other')">
                <div class="pay-icon">ðŸ“¦</div><div class="pay-label">Lainnya</div>
            </div>
        </div>

        <label class="cf-label" style="margin-top:14px;">Uang Masuk ke Rekening *</label>
        <select class="cf-input" id="payAccount" style="margin-bottom:16px;">
            <option value="">â€” Pilih Rekening â€”</option>
            <?php foreach ($cashAccounts as $acc): ?>
            <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo ucfirst($acc['account_type']); ?>) â€” <?php echo formatCurrency($acc['current_balance']); ?></option>
            <?php endforeach; ?>
        </select>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button onclick="closePayModal()" class="btn-cafe btn-ghost">Batal</button>
            <button onclick="submitPay()" class="btn-cafe btn-pay" style="padding:11px 28px;" id="payBtn">ðŸ’° Bayar Sekarang</button>
        </div>
    </div>
</div>

<!-- â•â•â• MODAL: VIEW INVOICE (PREMIUM) â•â•â• -->
<div class="modal-bg" id="viewModal">
    <div class="modal-box" style="max-width:560px;padding:0;overflow:hidden;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 24px;background:#fafafa;border-bottom:1px solid #f3f4f6;">
            <div style="font-size:14px;font-weight:800;color:var(--cafe-dark);display:flex;align-items:center;gap:8px;">ðŸ‘ï¸ Detail Invoice</div>
            <div style="display:flex;gap:6px;">
                <button onclick="printInvoice()" class="btn-cafe btn-sm btn-ghost">ðŸ–¨ï¸ Print</button>
                <button onclick="document.getElementById('viewModal').classList.remove('open')" class="btn-cafe btn-sm btn-ghost">âœ•</button>
            </div>
        </div>
        <div style="padding:0;max-height:75vh;overflow-y:auto;" id="printArea">
            <div class="inv-preview" id="invPreviewContent">
                <!-- Filled by JS -->
            </div>
        </div>
    </div>
</div>

<script>
const LOGO_URL = <?php echo json_encode($logoForInvoice); ?>;
const BIZ_ICON = <?php echo json_encode($businessIcon); ?>;
const COMPANY_NAME = <?php echo json_encode($companyName); ?>;
const COMPANY_ADDRESS = <?php echo json_encode($companyAddress); ?>;
const COMPANY_PHONE = <?php echo json_encode($companyPhone); ?>;
const COMPANY_EMAIL = <?php echo json_encode($companyEmail); ?>;
const COMPANY_TAGLINE = <?php echo json_encode($companyTagline); ?>;

// â”€â”€â”€ CREATE FORM â”€â”€â”€
function showCreate() { document.getElementById('viewList').style.display = 'none'; document.getElementById('viewCreate').style.display = 'block'; }
function hideCreate() { document.getElementById('viewList').style.display = 'block'; document.getElementById('viewCreate').style.display = 'none'; }

let itemIndex = 1;
function addItem() {
    const c = document.getElementById('itemsContainer');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.dataset.index = itemIndex;
    row.innerHTML = `
        <div><input type="text" name="item_name[]" class="cf-input" placeholder="Nama menu/item" required style="font-weight:600;"></div>
        <div><input type="number" name="item_qty[]" class="cf-input qty-input" value="1" min="1" onchange="calcTotals()" onkeyup="calcTotals()"></div>
        <div><input type="text" name="item_price[]" class="cf-input price-input" placeholder="0" required onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()"></div>
        <div class="line-total-col" style="font-size:12px;font-weight:800;color:var(--cafe);padding-top:8px;text-align:right;" data-linetotal>Rp 0</div>
        <div><button type="button" class="remove-item" onclick="removeItem(this)" title="Hapus">Ã—</button></div>
    `;
    c.appendChild(row);
    itemIndex++;
    row.querySelector('input').focus();
}

function removeItem(btn) {
    if (document.querySelectorAll('#itemsContainer .item-row').length <= 1) return;
    btn.closest('.item-row').remove();
    calcTotals();
}

function parseRp(val) { return parseFloat(String(val).replace(/[^0-9]/g, '')) || 0; }
function formatPrice(el) { let v = el.value.replace(/[^0-9]/g, ''); if (v) el.value = parseInt(v).toLocaleString('id-ID'); }
function fmtRp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

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

// â”€â”€â”€ PAY MODAL â”€â”€â”€
let payInvId = 0, payMethod = '';
function openPayModal(id, num, amount) {
    payInvId = id; payMethod = '';
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
    btn.disabled = true; btn.textContent = 'â³ Memproses...';
    const fd = new FormData();
    fd.append('invoice_id', payInvId);
    fd.append('payment_method', payMethod);
    fd.append('cash_account_id', accId);
    fetch('?ajax=pay', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { closePayModal(); location.reload(); }
            else { alert(data.message || 'Gagal'); btn.disabled = false; btn.textContent = 'ðŸ’° Bayar Sekarang'; }
        }).catch(() => { alert('Network error'); btn.disabled = false; btn.textContent = 'ðŸ’° Bayar Sekarang'; });
}

// â”€â”€â”€ VIEW INVOICE (PREMIUM RENDER) â”€â”€â”€
function buildLogoHtml() {
    if (LOGO_URL) {
        return `<img src="${escHtml(LOGO_URL)}" alt="${escHtml(COMPANY_NAME)}" style="width:100%;height:100%;object-fit:cover;border-radius:12px;">`;
    }
    return `<span class="fallback-icon">${BIZ_ICON}</span>`;
}

function viewInvoice(id) {
    fetch('?ajax=get&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert('Invoice not found'); return; }
            const inv = data.invoice, items = data.items;
            let itemsHtml = '';
            items.forEach((it, i) => {
                itemsHtml += `<tr>
                    <td style="color:#9ca3af;font-weight:600;">${String(i+1).padStart(2,'0')}</td>
                    <td style="font-weight:700;color:#1f2937;">${escHtml(it.item_name)}</td>
                    <td>${it.qty}x</td>
                    <td>${fmtRp(it.unit_price)}</td>
                    <td>${fmtRp(it.subtotal)}</td>
                </tr>`;
            });

            const stampHtml = inv.status === 'paid'
                ? `<div class="inv-stamp"><span class="inv-stamp-paid">âœ… LUNAS â€” ${(inv.payment_method||'').toUpperCase()} â€” ${inv.paid_at ? inv.paid_at.substring(0,16) : ''}</span></div>`
                : `<div class="inv-stamp"><span class="inv-stamp-unpaid">â³ BELUM BAYAR</span></div>`;

            document.getElementById('invPreviewContent').innerHTML = `
                <!-- Header Band -->
                <div class="inv-hdr-band">
                    <div class="inv-hdr-content">
                        <div class="inv-hdr-logo">${buildLogoHtml()}</div>
                        <div class="inv-hdr-info">
                            <div class="inv-hdr-name">${escHtml(COMPANY_NAME)}</div>
                            <div class="inv-hdr-tagline">${escHtml(COMPANY_TAGLINE)}</div>
                            <div class="inv-hdr-contacts">
                                ${COMPANY_ADDRESS ? `<span>ðŸ“ ${escHtml(COMPANY_ADDRESS)}</span>` : ''}
                            </div>
                            <div class="inv-hdr-contacts">
                                ${COMPANY_PHONE ? `<span>ðŸ“± ${escHtml(COMPANY_PHONE)}</span>` : ''}
                                ${COMPANY_EMAIL ? `<span>âœ‰ï¸ ${escHtml(COMPANY_EMAIL)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="inv-preview-inner">
                    <!-- Invoice Title -->
                    <div class="inv-title-bar">
                        <div>
                            <div class="inv-title-label">INVOICE</div>
                            <div class="inv-title-number">${escHtml(inv.invoice_number)}</div>
                        </div>
                        <div class="inv-title-right">
                            <div style="font-size:10px;color:#9ca3af;font-weight:700;text-transform:uppercase;">Tanggal</div>
                            <div style="font-size:13px;font-weight:800;color:#1f2937;">${inv.created_at ? inv.created_at.substring(0,10) : ''}</div>
                        </div>
                    </div>

                    <!-- Customer Info -->
                    <div class="inv-meta-grid">
                        <div class="inv-meta-item">
                            <div class="inv-meta-label">Pelanggan</div>
                            <div class="inv-meta-val">${escHtml(inv.customer_name)}</div>
                        </div>
                        ${inv.customer_phone ? `<div class="inv-meta-item"><div class="inv-meta-label">Telepon</div><div class="inv-meta-val">${escHtml(inv.customer_phone)}</div></div>` : ''}
                        ${inv.customer_note ? `<div class="inv-meta-item" style="grid-column:1/-1;"><div class="inv-meta-label">Catatan</div><div class="inv-meta-val">${escHtml(inv.customer_note)}</div></div>` : ''}
                    </div>

                    <!-- Items -->
                    <table class="inv-items-tbl">
                        <thead><tr><th style="width:36px;">#</th><th>Item</th><th style="text-align:center;width:50px;">Qty</th><th style="text-align:right;width:100px;">Harga</th><th style="text-align:right;width:100px;">Subtotal</th></tr></thead>
                        <tbody>${itemsHtml}</tbody>
                    </table>

                    <!-- Totals -->
                    <div class="inv-totals">
                        <div class="inv-total-row"><span>Subtotal</span><span style="font-weight:700;">${fmtRp(inv.subtotal)}</span></div>
                        ${parseFloat(inv.discount_amount) > 0 ? `<div class="inv-total-row"><span>Diskon</span><span style="color:#dc2626;font-weight:700;">- ${fmtRp(inv.discount_amount)}</span></div>` : ''}
                        ${parseFloat(inv.tax_amount) > 0 ? `<div class="inv-total-row"><span>Pajak</span><span style="font-weight:700;">${fmtRp(inv.tax_amount)}</span></div>` : ''}
                        <div class="inv-total-row grand"><span>TOTAL</span><span>${fmtRp(inv.total_amount)}</span></div>
                    </div>

                    ${stampHtml}
                </div>

                <!-- Footer -->
                <div class="inv-footer-bar">
                    <div class="thanks">Terima Kasih atas Kunjungan Anda! â˜•</div>
                    <div class="tagline">${escHtml(COMPANY_NAME)} â€” ${escHtml(COMPANY_TAGLINE)}</div>
                    <div class="legal">Dokumen ini sah dan diproses secara elektronik</div>
                </div>
            `;
            document.getElementById('viewModal').classList.add('open');
        });
}

function printInvoice() {
    const content = document.getElementById('printArea').innerHTML;
    const w = window.open('', '_blank', 'width=500,height=700');
    w.document.write(`<!DOCTYPE html><html><head><title>Invoice - ${escHtml(COMPANY_NAME)}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', -apple-system, sans-serif; color: #1f2937; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .inv-preview { max-width: 480px; margin: 0 auto; box-shadow: none; border: none; }
        .inv-preview-inner { padding: 24px 20px 16px; }
        .inv-hdr-band { background: linear-gradient(135deg, #3c1a00, #78350f, #92400e) !important; padding: 22px 20px; color: #fff; }
        .inv-hdr-content { display: flex; align-items: center; gap: 14px; }
        .inv-hdr-logo { width: 52px; height: 52px; border-radius: 12px; overflow: hidden; background: rgba(255,255,255,.15); border: 2px solid rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .inv-hdr-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
        .inv-hdr-logo .fallback-icon { font-size: 26px; }
        .inv-hdr-info { flex: 1; }
        .inv-hdr-name { font-size: 17px; font-weight: 800; }
        .inv-hdr-tagline { font-size: 10px; opacity: .7; font-style: italic; }
        .inv-hdr-contacts { font-size: 9px; opacity: .6; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 6px; }
        .inv-title-bar { display: flex; justify-content: space-between; padding: 16px 0 12px; margin-bottom: 12px; border-bottom: 2px solid #fef3c7; }
        .inv-title-label { font-size: 24px; font-weight: 900; color: #92400e; letter-spacing: 2px; }
        .inv-title-number { font-size: 12px; font-weight: 700; color: #78350f; font-family: 'Courier New', monospace; margin-top: 3px; }
        .inv-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; padding: 12px 14px; background: #fdf6ec; border-radius: 8px; border: 1px solid #fde68a; }
        .inv-meta-label { font-size: 8px; font-weight: 700; text-transform: uppercase; color: #9ca3af; letter-spacing: .6px; }
        .inv-meta-val { font-size: 11px; font-weight: 700; color: #1f2937; }
        .inv-items-tbl { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 14px; }
        .inv-items-tbl thead th { background: linear-gradient(180deg, #3c1a00, #78350f) !important; color: #fff !important; padding: 8px 10px; text-align: left; font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; }
        .inv-items-tbl thead th:first-child { border-radius: 6px 0 0 0; }
        .inv-items-tbl thead th:last-child { border-radius: 0 6px 0 0; text-align: right; }
        .inv-items-tbl tbody td { padding: 8px 10px; border-bottom: 1px solid #f3ece4; }
        .inv-items-tbl tbody tr:nth-child(even) { background: #fdfaf5; }
        .inv-items-tbl td:last-child { text-align: right; font-weight: 700; }
        .inv-items-tbl td:nth-child(3) { text-align: center; }
        .inv-items-tbl td:nth-child(4) { text-align: right; }
        .inv-totals { margin-left: auto; width: 200px; }
        .inv-total-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 11px; color: #6b7280; }
        .inv-total-row.grand { font-size: 14px; font-weight: 900; color: #3c1a00; border-top: 2px solid #92400e; padding-top: 8px; margin-top: 4px; }
        .inv-stamp { text-align: center; margin: 16px 0 6px; }
        .inv-stamp-paid { display: inline-block; background: #059669 !important; color: #fff; padding: 6px 20px; border-radius: 20px; font-size: 11px; font-weight: 800; }
        .inv-stamp-unpaid { display: inline-block; background: #fef2f2; color: #dc2626; padding: 6px 20px; border-radius: 20px; font-size: 11px; font-weight: 800; border: 1px solid #fca5a5; }
        .inv-footer-bar { background: #fef9ee !important; padding: 14px 20px; text-align: center; border-top: 1px solid #fde68a; }
        .inv-footer-bar .thanks { font-size: 12px; font-weight: 700; color: #78350f; }
        .inv-footer-bar .tagline { font-size: 9px; color: #9ca3af; font-style: italic; }
        .inv-footer-bar .legal { font-size: 8px; color: #d1d5db; margin-top: 4px; }
    </style></head><body onload="window.print();window.close()">${content}</body></html>`);
    w.document.close();
}

function escHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function deleteInvoice(id) {
    if (!confirm('Hapus invoice ini?')) return;
    const fd = new FormData(); fd.append('invoice_id', id);
    fetch('?ajax=delete', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message); });
}

document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
</script>

<?php include '../../includes/footer.php'; ?>
