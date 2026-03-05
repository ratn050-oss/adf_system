<?php
/**
 * Hotel Services — Multi-item Invoice
 * Motor Rental, Laundry, Service, Airport Drop, Harbor Drop
 * Narayana Hotel Karimunjawa
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db          = Database::getInstance();
$pdo         = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$businessId  = $_SESSION['business_id'] ?? 1;

// ── Auto-create tables ─────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    business_id     INT NOT NULL DEFAULT 1,
    invoice_number  VARCHAR(30) NOT NULL UNIQUE,
    booking_id      INT DEFAULT NULL,
    guest_name      VARCHAR(120) NOT NULL,
    guest_phone     VARCHAR(30)  DEFAULT NULL,
    room_number     VARCHAR(20)  DEFAULT NULL,
    total           DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_status  ENUM('unpaid','paid','partial') NOT NULL DEFAULT 'unpaid',
    payment_method  VARCHAR(20)  NOT NULL DEFAULT 'cash',
    status          ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes           TEXT         DEFAULT NULL,
    tax_rate        DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_by      INT          DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    cashbook_synced  TINYINT(1)   NOT NULL DEFAULT 0,
    KEY idx_biz (business_id),
    KEY idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add cashbook_synced to existing tables that predate this column
try {
    $pdo->query("SELECT cashbook_synced FROM hotel_invoices LIMIT 1");
} catch (\Throwable $e) {
    try { $pdo->exec("ALTER TABLE hotel_invoices ADD COLUMN cashbook_synced TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e2) {}
}
// Add tax columns to existing tables
try { $pdo->query("SELECT tax_rate FROM hotel_invoices LIMIT 1"); } catch (\Throwable $e) {
    try { $pdo->exec("ALTER TABLE hotel_invoices ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0, ADD COLUMN tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0"); } catch (\Throwable $e2) {}
}

// Expand service_type ENUM for existing tables (add narayana_trip, lain_lain)
try { $pdo->query("SELECT 1 FROM hotel_invoice_items WHERE service_type='narayana_trip' LIMIT 0"); } catch (\Throwable $e) {
    try { $pdo->exec("ALTER TABLE hotel_invoice_items MODIFY service_type ENUM('motor_rental','laundry','service','airport_drop','harbor_drop','narayana_trip','lain_lain') NOT NULL"); } catch (\Throwable $e2) {}
}

$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_invoice_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    service_type    ENUM('motor_rental','laundry','service','airport_drop','harbor_drop','narayana_trip','lain_lain') NOT NULL,
    description     VARCHAR(255) DEFAULT NULL,
    quantity        DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
    start_datetime  DATETIME     DEFAULT NULL,
    end_datetime    DATETIME     DEFAULT NULL,
    KEY idx_inv (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Service type config ────────────────────────────────────────────────────────
$serviceTypes = [
    'motor_rental'   => ['label' => 'Motor Rental',   'icon' => '🏍️'],
    'laundry'        => ['label' => 'Laundry',         'icon' => '👕'],
    'service'        => ['label' => 'Service',         'icon' => '🔧'],
    'airport_drop'   => ['label' => 'Airport Drop',    'icon' => '✈️'],
    'harbor_drop'    => ['label' => 'Harbor Drop',     'icon' => '⚓'],
    'narayana_trip'  => ['label' => 'Narayana Trip',   'icon' => '🚤'],
    'lain_lain'      => ['label' => 'Lain-lain',       'icon' => '📦'],
];

$statusColors    = ['pending'=>'#f59e0b','confirmed'=>'#3b82f6','completed'=>'#10b981','cancelled'=>'#ef4444'];
$payStatusColors = ['unpaid'=>'#ef4444','partial'=>'#f59e0b','paid'=>'#10b981'];

// ── Helper: find/create division by service type ──────────────────────────────
function getDivisionForService(PDO $pdo, string $serviceType): int {
    static $cache = [];
    if (isset($cache[$serviceType])) return $cache[$serviceType];
    $nameMap = [
        'motor_rental'  => 'Motor Rental',
        'laundry'       => 'Laundry',
        'service'       => 'General Service',
        'airport_drop'  => 'Airport Drop',
        'harbor_drop'   => 'Harbor Drop',
        'narayana_trip' => 'Narayana Trip',
        'lain_lain'     => 'Lain-lain',
    ];
    $name  = $nameMap[$serviceType] ?? 'Hotel Services';
    $words = explode(' ', strtolower($name));
    $like  = '%' . $words[0] . '%';
    $stmt  = $pdo->prepare("SELECT id FROM divisions WHERE LOWER(division_name) LIKE ? ORDER BY id LIMIT 1");
    $stmt->execute([$like]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $cache[$serviceType] = (int)$row['id']; return $cache[$serviceType]; }
    try {
        $pdo->prepare("INSERT INTO divisions (division_name, is_active, created_at) VALUES (?, 1, NOW())")->execute([$name]);
        $id = (int)$pdo->lastInsertId();
    } catch (\Throwable $e) {
        $any = $pdo->query("SELECT id FROM divisions ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $id = (int)($any['id'] ?? 1);
    }
    $cache[$serviceType] = $id;
    return $id;
}

// ── Helper: sync invoice payment to cashbook (called from process_invoice) ─────
function syncInvoiceToCashbook($db, $businessId, $userId, array $invRow, array $itemGroups, array $serviceTypes): bool {
    try {
        require_once '../../includes/CashbookHelper.php';
        $helper  = new CashbookHelper($db, $businessId, $userId);
        $account = $helper->getCashAccount($invRow['payment_method']);
        if (!$account) return false;

        $cbMethod  = $helper->mapPaymentMethod($invRow['payment_method']);
        $catId     = $helper->getCategoryId();
        $hasCa     = $helper->hasCashAccountIdColumn();
        $bPdo      = $db->getConnection();
        $now       = date('Y-m-d H:i:s');
        $invNo     = $invRow['invoice_number'];
        $guest     = $invRow['guest_name'];
        $paidAmt   = (float)$invRow['paid_amount'];
        $totalAmt  = (float)$invRow['total'];
        $totalInserted = 0;
        $lastTransId   = 0;

        foreach ($itemGroups as $group) {
            $svcType   = $group['service_type'];
            $svcLabel  = $serviceTypes[$svcType]['label'] ?? $svcType;
            $proportion = $totalAmt > 0 ? ($group['type_total'] / $totalAmt) : (1 / count($itemGroups));
            $svcAmount  = $group === end($itemGroups)
                ? round($paidAmt - $totalInserted, 2)   // last item gets remainder to avoid rounding loss
                : round($paidAmt * $proportion, 2);
            if ($svcAmount <= 0) continue;
            $totalInserted += $svcAmount;

            $divId = getDivisionForService($bPdo, $svcType);
            $desc  = "[{$invNo}] {$guest} - {$svcLabel}";

            if ($hasCa) {
                $stmt = $bPdo->prepare("INSERT INTO cash_book
                    (transaction_date, transaction_time, division_id, category_id,
                     description, transaction_type, amount, payment_method,
                     cash_account_id, is_editable, created_by, created_at)
                    VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, 1, ?, NOW())");
                $stmt->execute([$now, $now, $divId, $catId, $desc, $svcAmount, $cbMethod, $account['id'], $userId]);
            } else {
                $stmt = $bPdo->prepare("INSERT INTO cash_book
                    (transaction_date, transaction_time, division_id, category_id,
                     description, transaction_type, amount, payment_method,
                     is_editable, created_by, created_at)
                    VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, 1, ?, NOW())");
                $stmt->execute([$now, $now, $divId, $catId, $desc, $svcAmount, $cbMethod, $userId]);
            }
            $lastTransId = (int)$bPdo->lastInsertId();
        }

        // ── Master DB: one cash_account_transactions entry for total + balance update
        try {
            $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : null);
            if ($masterDbName && $totalInserted > 0) {
                $mPdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname={$masterDbName};charset=" . DB_CHARSET,
                    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $masterDesc = "Hotel Services [{$invNo}] {$guest}";
                $hasTxCol = (bool)$mPdo->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'")->fetch();
                if ($hasTxCol) {
                    $mPdo->prepare("INSERT INTO cash_account_transactions
                        (cash_account_id, transaction_id, transaction_date,
                         description, amount, transaction_type, reference_number, created_by, created_at)
                        VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")
                        ->execute([$account['id'], $lastTransId, $now, $masterDesc, $paidAmt, $invNo, $userId]);
                } else {
                    $mPdo->prepare("INSERT INTO cash_account_transactions
                        (cash_account_id, transaction_date,
                         description, amount, transaction_type, reference_number, created_by, created_at)
                        VALUES (?, DATE(?), ?, ?, 'income', ?, ?, NOW())")
                        ->execute([$account['id'], $now, $masterDesc, $paidAmt, $invNo, $userId]);
                }
                $newBal = $account['current_balance'] + $paidAmt;
                $mPdo->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?")->execute([$newBal, $account['id']]);
            }
        } catch (\Throwable $me) {
            error_log("Hotel svc cashbook master sync: " . $me->getMessage());
        }
        return true;
    } catch (\Throwable $e) {
        error_log("Hotel svc cashbook error: " . $e->getMessage());
        return false;
    }
}

// ── AJAX handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    ob_start();
    try {
        $action = $_POST['action'];

        // ── CREATE ──────────────────────────────────────────────────────────────
        if ($action === 'create') {
            $guestName  = trim($_POST['guest_name'] ?? '');
            $guestPhone = trim($_POST['guest_phone'] ?? '');
            $roomNumber = trim($_POST['room_number'] ?? '');
            $bookingId  = (int)($_POST['booking_id'] ?? 0) ?: null;
            $payMethod  = $_POST['payment_method'] ?? 'cash';
            $paidAmount = max(0, (float)($_POST['paid_amount'] ?? 0));
            $notes      = trim($_POST['notes'] ?? '');
            $taxRate    = max(0, min(100, (float)($_POST['tax_rate'] ?? 0)));

            if (!$guestName) throw new Exception('Guest name is required');

            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items)) throw new Exception('At least one service item is required');

            $subtotal = 0;
            foreach ($items as &$item) {
                $item['qty']        = max(0.5, (float)($item['qty']        ?? 1));
                $item['unit_price'] = max(0,   (float)($item['unit_price'] ?? 0));
                $item['total']      = round($item['qty'] * $item['unit_price'], 2);
                $subtotal += $item['total'];
                if (!isset($serviceTypes[$item['service_type'] ?? ''])) {
                    throw new Exception('Invalid service type');
                }
            }
            unset($item);

            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total     = $subtotal + $taxAmount;  // grand total

            $paidAmount = min($paidAmount, $total);
            $remaining  = $total - $paidAmount;
            $payStatus  = ($paidAmount <= 0) ? 'unpaid' : ($remaining <= 0 ? 'paid' : 'partial');

            // Invoice number
            $prefix = 'HSV-' . date('Ym') . '-';
            $last   = $pdo->query("SELECT invoice_number FROM hotel_invoices WHERE invoice_number LIKE '{$prefix}%' ORDER BY invoice_number DESC LIMIT 1")->fetchColumn();
            $seq    = $last ? ((int)substr($last, -4) + 1) : 1;
            $invNo  = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO hotel_invoices
                (business_id, invoice_number, booking_id, guest_name, guest_phone, room_number,
                 total, paid_amount, payment_status, payment_method, status, notes,
                 tax_rate, tax_amount, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$businessId, $invNo, $bookingId, $guestName, $guestPhone ?: null,
                    $roomNumber ?: null, $total, $paidAmount, $payStatus, $payMethod,
                    'confirmed', $notes ?: null, $taxRate, $taxAmount, $currentUser['id'] ?? null]);
            $invId = (int)$pdo->lastInsertId();

            $iStmt = $pdo->prepare("INSERT INTO hotel_invoice_items
                (invoice_id, service_type, description, quantity, unit_price, total_price, start_datetime, end_datetime)
                VALUES (?,?,?,?,?,?,?,?)");
            $svcLabels = [];
            foreach ($items as $item) {
                $iStmt->execute([
                    $invId, $item['service_type'], $item['description'] ?: null,
                    $item['qty'], $item['unit_price'], $item['total'],
                    $item['start_dt'] ?: null, $item['end_dt'] ?: null,
                ]);
                $svcLabels[] = $serviceTypes[$item['service_type']]['label'];
            }
            $pdo->commit();

            // Cashbook is NOT synced on save — staff must click "Process Invoice" in preview
            ob_clean();
            echo json_encode(['success' => true, 'invoice_number' => $invNo, 'id' => $invId, 'cashbook' => false]);
            exit;
        }

        // ── ADD PAYMENT ─────────────────────────────────────────────────────────
        if ($action === 'add_payment') {
            $id     = (int)($_POST['id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $method = $_POST['method'] ?? 'cash';
            if (!$id || $amount <= 0) throw new Exception('Invalid data');

            $inv = $pdo->prepare("SELECT hi.*, GROUP_CONCAT(hii.service_type SEPARATOR ',') as svc_types
                FROM hotel_invoices hi
                LEFT JOIN hotel_invoice_items hii ON hii.invoice_id = hi.id
                WHERE hi.id=? AND hi.business_id=? GROUP BY hi.id");
            $inv->execute([$id, $businessId]);
            $r = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('Invoice not found');

            $newPaid  = min($r['paid_amount'] + $amount, $r['total']);
            $remain   = $r['total'] - $newPaid;
            $payStatus = ($newPaid <= 0) ? 'unpaid' : ($remain <= 0 ? 'paid' : 'partial');

            $pdo->prepare("UPDATE hotel_invoices SET paid_amount=?, payment_status=?, payment_method=?, updated_at=NOW() WHERE id=? AND business_id=?")
                ->execute([$newPaid, $payStatus, $method, $id, $businessId]);

            // Cashbook NOT synced here — must use "Process Invoice" in preview
            ob_clean();
            echo json_encode(['success' => true, 'payment_status' => $payStatus, 'paid_amount' => $newPaid, 'cashbook' => false]);
            exit;
        }

        // ── PROCESS INVOICE (syncs payment to cashbook per service type) ─────────
        if ($action === 'process_invoice') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Invalid ID');

            $invStmt = $pdo->prepare("SELECT * FROM hotel_invoices WHERE id=? AND business_id=?");
            $invStmt->execute([$id, $businessId]);
            $invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invRow) throw new Exception('Invoice not found');
            if ($invRow['cashbook_synced'] ?? 0) {
                ob_clean();
                echo json_encode(['success' => true, 'already' => true, 'message' => 'Already processed']);
                exit;
            }

            // Group items by service type with proportion totals
            $grpStmt = $pdo->prepare("
                SELECT service_type, SUM(total_price) as type_total
                FROM hotel_invoice_items WHERE invoice_id=?
                GROUP BY service_type ORDER BY service_type");
            $grpStmt->execute([$id]);
            $itemGroups = $grpStmt->fetchAll(PDO::FETCH_ASSOC);

            $cbOk = false;
            if ((float)$invRow['paid_amount'] > 0 && !empty($itemGroups)) {
                $cbOk = syncInvoiceToCashbook($db, $businessId, $currentUser['id'] ?? 1,
                    $invRow, $itemGroups, $serviceTypes);
            }

            // Mark as processed regardless of payment (even unpaid invoices can be "issued")
            $pdo->prepare("UPDATE hotel_invoices SET cashbook_synced=1, updated_at=NOW() WHERE id=?")->execute([$id]);

            ob_clean();
            echo json_encode(['success' => true, 'cashbook' => $cbOk, 'paid_amount' => $invRow['paid_amount']]);
            exit;
        }

        // ── UPDATE STATUS ────────────────────────────────────────────────────────
        if ($action === 'update_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $allowed = ['pending','confirmed','completed','cancelled'];
            if (!$id || !in_array($status, $allowed)) throw new Exception('Invalid');
            $pdo->prepare("UPDATE hotel_invoices SET status=?, updated_at=NOW() WHERE id=? AND business_id=?")
                ->execute([$status, $id, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── DELETE ───────────────────────────────────────────────────────────────
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Invalid ID');
            $pdo->prepare("DELETE FROM hotel_invoice_items WHERE invoice_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM hotel_invoices WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        throw new Exception('Unknown action');
    } catch (Exception $e) {
        ob_clean();
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Fetch list ─────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterDate   = $_GET['date']   ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = ["hi.business_id = ?"];
$params = [$businessId];
if ($filterStatus) { $where[] = 'hi.status = ?';           $params[] = $filterStatus; }
if ($filterDate)   { $where[] = 'DATE(hi.created_at) = ?'; $params[] = $filterDate; }
if ($search) {
    $where[] = '(hi.guest_name LIKE ? OR hi.invoice_number LIKE ? OR hi.room_number LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $pdo->prepare("SELECT hi.*,
    GROUP_CONCAT(DISTINCT hii.service_type ORDER BY hii.id SEPARATOR ',') as service_types,
    COUNT(hii.id) as item_count
    FROM hotel_invoices hi
    LEFT JOIN hotel_invoice_items hii ON hii.invoice_id = hi.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY hi.id ORDER BY hi.created_at DESC LIMIT 200");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats — today totals
$stats = $pdo->prepare("SELECT COUNT(*) as total,
    COALESCE(SUM(total),0) as revenue, COALESCE(SUM(paid_amount),0) as collected,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END) as unpaid
    FROM hotel_invoices WHERE business_id=? AND DATE(created_at)=CURDATE()");
$stats->execute([$businessId]);
$today = $stats->fetch(PDO::FETCH_ASSOC);

// Revenue per service type — this month (paid/partial invoices)
$svcRevStmt = $pdo->prepare("
    SELECT hii.service_type,
           COUNT(DISTINCT hii.invoice_id) AS invoice_count,
           SUM(hii.total_price)           AS total_revenue
    FROM hotel_invoice_items hii
    JOIN hotel_invoices hi ON hii.invoice_id = hi.id
    WHERE hi.business_id = ?
      AND hi.payment_status IN ('paid','partial')
      AND YEAR(hi.created_at)  = YEAR(CURDATE())
      AND MONTH(hi.created_at) = MONTH(CURDATE())
    GROUP BY hii.service_type
    ORDER BY total_revenue DESC
");
$svcRevStmt->execute([$businessId]);
$svcRevStats = $svcRevStmt->fetchAll(PDO::FETCH_ASSOC);

// In-house guests
try {
    $inHouseGuests = $pdo->query("SELECT b.id as booking_id, g.guest_name, r.room_number, g.phone
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC LIMIT 100")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $inHouseGuests = []; }

include '../../includes/header.php';
?>
<style>
.hs-page { padding: 1.25rem; }
.hs-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.75rem; }
.hs-topbar h2 { font-size:1.2rem; font-weight:700; color:var(--text-primary); margin:0; }
.hs-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:0.75rem; margin-bottom:1.25rem; }
.hs-stat { background:white; border-radius:10px; padding:0.85rem 1rem; box-shadow:0 1px 4px rgba(0,0,0,0.07); border-top:3px solid var(--c); }
.hs-stat .val { font-size:1.25rem; font-weight:800; color:var(--c); }
.hs-stat .lbl { font-size:0.72rem; color:var(--text-secondary); margin-top:0.15rem; }
.hs-filters { background:white; border-radius:10px; padding:0.85rem 1rem; box-shadow:0 1px 4px rgba(0,0,0,0.07); margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:0.6rem; align-items:center; }
.hs-filters input, .hs-filters select { padding:0.4rem 0.6rem; border:1px solid #e2e8f0; border-radius:6px; font-size:0.8rem; background:white; color:var(--text-primary); }
.hs-table-wrap { background:white; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.07); overflow:hidden; }
.hs-table { width:100%; border-collapse:collapse; font-size:0.8rem; }
.hs-table th { background:#f8fafc; padding:0.65rem 0.85rem; text-align:left; font-weight:600; color:var(--text-secondary); font-size:0.72rem; text-transform:uppercase; letter-spacing:0.03em; border-bottom:1px solid #e2e8f0; }
.hs-table td { padding:0.65rem 0.85rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.hs-table tr:last-child td { border-bottom:none; }
.hs-table tr:hover td { background:#fafbff; }
.hs-badge { display:inline-block; padding:0.2rem 0.55rem; border-radius:20px; font-size:0.7rem; font-weight:600; color:white; }
.hs-svc-pill { display:inline-block; padding:0.15rem 0.45rem; border-radius:12px; font-size:0.68rem; font-weight:600; background:#ede9fe; color:#5b21b6; margin:0.1rem 0.1rem 0 0; white-space:nowrap; }
.hs-action-btn { padding:0.25rem 0.55rem; border:none; border-radius:5px; cursor:pointer; font-size:0.72rem; font-weight:600; transition:opacity 0.2s; }
.hs-action-btn:hover { opacity:0.8; }
/* Modal */
.hs-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:99999; align-items:center; justify-content:center; padding:1rem; }
.hs-modal-overlay.open { display:flex; }
.hs-modal { background:white; border-radius:14px; padding:1.5rem; width:100%; max-width:660px; max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3); }
.hs-modal h3 { margin:0 0 1rem; font-size:1.05rem; font-weight:700; }
.hs-form-row { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.75rem; }
.hs-form-row.full { grid-template-columns:1fr; }
.hs-field label { display:block; font-size:0.75rem; font-weight:600; color:var(--text-secondary); margin-bottom:0.3rem; }
.hs-field input, .hs-field select, .hs-field textarea { width:100%; padding:0.5rem 0.65rem; border:1px solid #e2e8f0; border-radius:7px; font-size:0.85rem; color:var(--text-primary); background:white; box-sizing:border-box; }
.hs-field textarea { resize:vertical; min-height:55px; }
.hs-field input:focus, .hs-field select:focus, .hs-field textarea:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,0.15); }
/* Guest toggle */
.guest-toggle { display:flex; gap:0.4rem; margin-bottom:0.6rem; }
.guest-toggle button { flex:1; padding:0.4rem 0.6rem; border:2px solid #e2e8f0; border-radius:7px; background:white; font-size:0.78rem; font-weight:600; cursor:pointer; transition:all 0.15s; color:#374151; }
.guest-toggle button.active { border-color:#6366f1; background:#ede9fe; color:#4c1d95; }
/* Items table */
.items-tbl { width:100%; border-collapse:collapse; margin-bottom:0.5rem; font-size:0.8rem; }
.items-tbl th { background:#f8fafc; padding:0.45rem 0.5rem; font-size:0.7rem; font-weight:600; color:var(--text-secondary); text-transform:uppercase; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.items-tbl td { padding:0.35rem 0.3rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.items-tbl td input, .items-tbl td select { padding:0.35rem 0.4rem; border:1px solid #e2e8f0; border-radius:5px; font-size:0.78rem; background:white; box-sizing:border-box; width:100%; }
.items-tbl td input:focus, .items-tbl td select:focus { outline:none; border-color:#6366f1; }
.btn-add-item { background:#f0f4ff; color:#4338ca; border:1px dashed #6366f1; border-radius:7px; padding:0.4rem 0.8rem; font-size:0.78rem; font-weight:600; cursor:pointer; width:100%; margin-bottom:0.75rem; }
.btn-add-item:hover { background:#ede9fe; }
.btn-del-row { background:#fee2e2; color:#b91c1c; border:none; border-radius:4px; padding:0.25rem 0.45rem; cursor:pointer; font-size:0.78rem; font-weight:700; }
.hs-total-preview { background:linear-gradient(135deg,#f0f4ff,#e8edff); border-radius:8px; padding:0.75rem 1rem; text-align:center; margin:0.75rem 0; font-size:1.1rem; font-weight:700; color:#4338ca; }
.hs-modal-footer { display:flex; justify-content:flex-end; gap:0.6rem; margin-top:1rem; }
.btn-hs { padding:0.5rem 1.25rem; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.85rem; }
.btn-hs-primary { background:var(--primary,#6366f1); color:white; }
.btn-hs-secondary { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
.hs-empty { text-align:center; padding:3rem 1rem; color:var(--text-secondary); }
.hs-empty .em-icon { font-size:2.5rem; margin-bottom:0.5rem; }
.sect-label { font-size:0.75rem; font-weight:700; color:var(--text-secondary); margin-bottom:0.4rem; display:block; text-transform:uppercase; letter-spacing:0.04em; }
@media(max-width:580px) {
    .hs-form-row { grid-template-columns:1fr; }
    .hs-stats { grid-template-columns:repeat(2,1fr); }
}
</style>

<div class="hs-page">

    <div class="hs-topbar">
        <div>
            <h2>🛎️ Hotel Services</h2>
            <div style="font-size:0.75rem;color:var(--text-secondary)">Motor Rental · Laundry · Service · Airport Drop · Harbor Drop · Narayana Trip · Lain-lain</div>
        </div>
        <button class="btn-hs btn-hs-primary" onclick="openCreateModal()">+ New Invoice</button>
    </div>

    <!-- Stats -->
    <div class="hs-stats">
        <div class="hs-stat" style="--c:#6366f1"><div class="val"><?php echo $today['total']; ?></div><div class="lbl">Invoices Today</div></div>
        <div class="hs-stat" style="--c:#10b981"><div class="val">Rp <?php echo number_format($today['revenue'],0,',','.'); ?></div><div class="lbl">Revenue Today</div></div>
        <div class="hs-stat" style="--c:#3b82f6"><div class="val">Rp <?php echo number_format($today['collected'],0,',','.'); ?></div><div class="lbl">Collected</div></div>
        <div class="hs-stat" style="--c:#ef4444"><div class="val"><?php echo $today['unpaid']; ?></div><div class="lbl">Unpaid</div></div>
        <div class="hs-stat" style="--c:#8b5cf6"><div class="val"><?php echo $today['completed']; ?></div><div class="lbl">Completed</div></div>
    </div>

    <!-- Revenue per Service Type (this month) -->
    <?php if (!empty($svcRevStats)): ?>
    <div style="background:white;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,0.07);padding:0.85rem 1rem;margin-bottom:1rem;">
        <div style="font-size:0.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.65rem;">
            📊 Revenue per Service — This Month
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
            <?php
            $svcColors = ['motor_rental'=>'#f59e0b','laundry'=>'#3b82f6','service'=>'#10b981','airport_drop'=>'#8b5cf6','harbor_drop'=>'#06b6d4','narayana_trip'=>'#ec4899','lain_lain'=>'#78716c'];
            foreach ($svcRevStats as $sr):
                $svcKey  = $sr['service_type'];
                $svcInfo = $serviceTypes[$svcKey] ?? ['label'=>$svcKey,'icon'=>'🔹'];
                $color   = $svcColors[$svcKey] ?? '#6366f1';
            ?>
            <div style="flex:1;min-width:130px;border-left:3px solid <?php echo $color; ?>;padding:0.5rem 0.75rem;background:#fafbff;border-radius:0 7px 7px 0;">
                <div style="font-size:0.8rem;font-weight:700;color:<?php echo $color; ?>"><?php echo $svcInfo['icon']; ?> <?php echo htmlspecialchars($svcInfo['label']); ?></div>
                <div style="font-size:0.95rem;font-weight:800;color:#1e293b;margin-top:0.15rem">Rp <?php echo number_format($sr['total_revenue'],0,',','.'); ?></div>
                <div style="font-size:0.68rem;color:var(--text-secondary)"><?php echo $sr['invoice_count']; ?> invoice<?php echo $sr['invoice_count']!=1?'s':''; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" class="hs-filters">
        <input type="text" name="q" placeholder="🔍 Guest / Invoice..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="status">
            <option value="">All Status</option>
            <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $filterStatus===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
        <button type="submit" class="btn-hs btn-hs-primary" style="padding:0.4rem 0.9rem;font-size:0.8rem">Filter</button>
        <?php if ($filterStatus||$filterDate||$search): ?>
        <a href="hotel-services.php" class="btn-hs btn-hs-secondary" style="padding:0.4rem 0.9rem;font-size:0.8rem;text-decoration:none">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="hs-table-wrap">
        <?php if (empty($invoices)): ?>
        <div class="hs-empty">
            <div class="em-icon">🛎️</div>
            <div style="font-weight:600;margin-bottom:0.25rem">No service invoices yet</div>
            <div style="font-size:0.8rem">Click "+ New Invoice" to create your first one</div>
        </div>
        <?php else: ?>
        <table class="hs-table">
            <thead>
                <tr>
                    <th>Invoice #</th><th>Guest</th><th>Room</th><th>Services</th>
                    <th>Total</th><th>Paid</th><th>Pay Status</th><th>Status</th><th>Date</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $inv):
                $svcList = array_filter(explode(',', $inv['service_types'] ?? '')); ?>
            <tr>
                <td style="font-weight:700;color:#4338ca;white-space:nowrap"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                <td>
                    <div style="font-weight:600"><?php echo htmlspecialchars($inv['guest_name']); ?></div>
                    <?php if ($inv['guest_phone']): ?><div style="font-size:0.7rem;color:var(--text-secondary)"><?php echo htmlspecialchars($inv['guest_phone']); ?></div><?php endif; ?>
                </td>
                <td><?php echo $inv['room_number'] ? htmlspecialchars($inv['room_number']) : '<span style="color:#d1d5db">—</span>'; ?></td>
                <td>
                    <?php foreach (array_unique($svcList) as $svc): ?>
                    <span class="hs-svc-pill"><?php echo $serviceTypes[$svc]['icon'] ?? ''; ?> <?php echo $serviceTypes[$svc]['label'] ?? $svc; ?></span>
                    <?php endforeach; ?>
                    <?php if ((int)$inv['item_count'] > 1): ?><div style="font-size:0.68rem;color:#6b7280;margin-top:2px"><?php echo $inv['item_count']; ?> items</div><?php endif; ?>
                </td>
                <td style="font-weight:700;white-space:nowrap">Rp <?php echo number_format($inv['total'],0,',','.'); ?></td>
                <td style="color:#10b981;font-weight:600;white-space:nowrap">Rp <?php echo number_format($inv['paid_amount'],0,',','.'); ?></td>
                <td><span class="hs-badge" style="background:<?php echo $payStatusColors[$inv['payment_status']]; ?>"><?php echo strtoupper($inv['payment_status']); ?></span></td>
                <td><span class="hs-badge" style="background:<?php echo $statusColors[$inv['status']]; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                <td style="font-size:0.72rem;color:var(--text-secondary);white-space:nowrap"><?php echo date('d M Y', strtotime($inv['created_at'])); ?></td>
                <td>
                    <div style="display:flex;gap:0.3rem;flex-wrap:wrap;min-width:160px">
                        <a href="hotel-service-invoice.php?id=<?php echo $inv['id']; ?>" target="_blank" class="hs-action-btn" style="background:#e0f2fe;color:#0277bd;text-decoration:none">🖨️ Invoice</a>
                        <?php if ($inv['payment_status'] !== 'paid'): ?>
                        <button class="hs-action-btn" style="background:#dcfce7;color:#15803d"
                            onclick="openPayModal(<?php echo $inv['id']; ?>,<?php echo $inv['total']-$inv['paid_amount']; ?>,'<?php echo htmlspecialchars($inv['invoice_number'],ENT_QUOTES); ?>')">💳 Pay</button>
                        <?php endif; ?>
                        <select class="hs-action-btn" style="background:#f3f4f6;color:#374151"
                            onchange="updateStatus(<?php echo $inv['id']; ?>,this.value);this.blur()">
                            <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $inv['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="hs-action-btn" style="background:#fee2e2;color:#b91c1c"
                            onclick="deleteInvoice(<?php echo $inv['id']; ?>,'<?php echo htmlspecialchars($inv['invoice_number'],ENT_QUOTES); ?>')">✕</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ══ CREATE MODAL ════════════════════════════════════════════════════════════ -->
<div id="createModal" class="hs-modal-overlay" onclick="if(event.target===this)closeCreateModal()">
 <div class="hs-modal">
  <h3>🛎️ New Service Invoice</h3>

  <!-- Guest -->
  <div style="margin-bottom:0.75rem">
    <span class="sect-label">Guest</span>
    <div class="guest-toggle">
      <button type="button" id="btnInhouse" class="active" onclick="setGuestMode('inhouse')">🏨 In-house Guest</button>
      <button type="button" id="btnManual"  onclick="setGuestMode('manual')">✏️ Enter Manually</button>
    </div>
    <div id="inhouseSection">
      <select id="fGuestSelect" onchange="fillFromInhouse()" style="width:100%;padding:0.5rem 0.65rem;border:1px solid #e2e8f0;border-radius:7px;font-size:0.85rem;background:white;box-sizing:border-box">
        <option value="">— Select in-house guest —</option>
        <?php foreach ($inHouseGuests as $g): ?>
        <option value="<?php echo $g['booking_id']; ?>"
          data-name="<?php echo htmlspecialchars($g['guest_name']??''); ?>"
          data-room="<?php echo htmlspecialchars($g['room_number']??''); ?>"
          data-phone="<?php echo htmlspecialchars($g['phone']??''); ?>">
          Room <?php echo htmlspecialchars($g['room_number']??'?'); ?> — <?php echo htmlspecialchars($g['guest_name']??''); ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="manualSection" style="display:none">
      <input type="text" id="fGuestName" placeholder="Enter guest name" style="width:100%;padding:0.5rem 0.65rem;border:1px solid #e2e8f0;border-radius:7px;font-size:0.85rem;box-sizing:border-box">
    </div>
    <input type="hidden" id="fBookingId">
  </div>

  <!-- Phone + Room -->
  <div class="hs-form-row">
    <div class="hs-field"><label>Phone</label><input type="text" id="fPhone" placeholder="Optional"></div>
    <div class="hs-field"><label>Room Number</label><input type="text" id="fRoom" placeholder="e.g. 101"></div>
  </div>

  <!-- Service items -->
  <span class="sect-label">Service Items *</span>
  <div style="overflow-x:auto;margin-bottom:0.4rem">
    <table class="items-tbl">
      <thead>
        <tr>
          <th style="min-width:140px">Service Type</th>
          <th style="min-width:160px">Description</th>
          <th style="width:65px">Qty</th>
          <th style="width:115px">Unit Price</th>
          <th style="width:105px;text-align:right">Subtotal</th>
          <th style="width:34px"></th>
        </tr>
      </thead>
      <tbody id="itemsBody"></tbody>
    </table>
  </div>
  <button type="button" class="btn-add-item" onclick="addItemRow()">+ Add Service Item</button>

  <!-- PPN / Tax -->
  <span class="sect-label">PPN / Pajak</span>
  <div class="hs-form-row" style="margin-bottom:0.5rem">
    <div class="hs-field">
      <label>Tarif PPN</label>
      <select id="fTaxRate" onchange="onTaxRateChange()">
        <option value="0">Tanpa PPN (0%)</option>
        <option value="5">5%</option>
        <option value="10">10%</option>
        <option value="11">11% (Standar)</option>
        <option value="custom">Custom...</option>
      </select>
    </div>
    <div class="hs-field" id="customTaxWrap" style="display:none">
      <label>Persentase Custom (%)</label>
      <input type="number" id="fTaxCustom" value="0" min="0" max="100" step="0.5" placeholder="e.g. 5.5" oninput="refreshTotal()">
    </div>
  </div>

  <!-- Payment -->
  <span class="sect-label">Pembayaran / DP</span>
  <div class="hs-form-row">
    <div class="hs-field">
      <label>Metode Bayar</label>
      <select id="fPayMethod">
        <option value="cash">Cash</option>
        <option value="transfer">Transfer</option>
        <option value="qris">QRIS</option>
        <option value="card">Card</option>
      </select>
    </div>
    <div class="hs-field">
      <label>DP / Down Payment (Rp)</label>
      <input type="number" id="fPaid" value="0" min="0" oninput="enforceMaxPaid()" placeholder="0 = belum bayar">
    </div>
  </div>
  <label style="font-size:0.8rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:0.4rem;margin-bottom:0.75rem">
    <input type="checkbox" id="fFullPay" onchange="toggleFullPay(this.checked)"> Bayar Penuh (Lunas)
  </label>

  <!-- Notes -->
  <div class="hs-field"><label>Notes</label><textarea id="fNotes" rows="2" placeholder="Special instructions..."></textarea></div>

  <div class="hs-total-preview" id="totalPreview" style="text-align:left;line-height:1.7">
    <div style="font-size:0.82rem;color:#6b7280">Subtotal: <span id="tpSubtotal">Rp 0</span></div>
    <div style="font-size:0.82rem;color:#f59e0b" id="tpTaxRow" style="display:none">PPN: <span id="tpTax">Rp 0</span></div>
    <div style="font-size:1.05rem;font-weight:800;color:#4338ca;border-top:1px solid #dde3ff;padding-top:4px;margin-top:2px">Grand Total: <span id="tpGrand">Rp 0</span></div>
    <div style="font-size:0.82rem;color:#10b981" id="tpDpRow" style="display:none">DP Dibayar: <span id="tpDp">Rp 0</span></div>
    <div style="font-size:0.82rem;color:#ef4444" id="tpSisaRow" style="display:none">Sisa: <span id="tpSisa">Rp 0</span></div>
  </div>

  <div class="hs-modal-footer">
    <button class="btn-hs btn-hs-secondary" onclick="closeCreateModal()">Cancel</button>
    <button class="btn-hs btn-hs-primary" id="createBtn" onclick="submitCreate()">✅ Create Invoice</button>
  </div>
 </div>
</div>

<!-- ══ PAY MODAL ══════════════════════════════════════════════════════════════ -->
<div id="payModal" class="hs-modal-overlay" onclick="if(event.target===this)closePayModal()">
 <div class="hs-modal" style="max-width:360px">
  <h3>💳 Add Payment</h3>
  <input type="hidden" id="pInvId">
  <div id="pInvNo" style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:0.5rem"></div>
  <div class="hs-field" style="margin-bottom:0.75rem">
    <label>Remaining Balance</label>
    <div id="pRemaining" style="font-size:1.2rem;font-weight:700;color:#ef4444;padding:0.4rem 0"></div>
  </div>
  <div class="hs-form-row">
    <div class="hs-field"><label>Amount (Rp)</label><input type="number" id="pAmount" value="0" min="0"></div>
    <div class="hs-field"><label>Method</label>
      <select id="pMethod">
        <option value="cash">Cash</option>
        <option value="transfer">Transfer</option>
        <option value="qris">QRIS</option>
        <option value="card">Card</option>
      </select>
    </div>
  </div>
  <div class="hs-modal-footer">
    <button class="btn-hs btn-hs-secondary" onclick="closePayModal()">Cancel</button>
    <button class="btn-hs btn-hs-primary" id="payBtn" onclick="submitPay()">💾 Save &amp; Sync to Cashbook</button>
  </div>
 </div>
</div>

<script>
const SVC_KEYS   = <?php echo json_encode(array_keys($serviceTypes)); ?>;
const SVC_LABELS = <?php echo json_encode(array_values(array_map(fn($v) => $v['icon'].' '.$v['label'], $serviceTypes))); ?>;

// ── Guest mode ────────────────────────────────────────────────────────────────
function setGuestMode(mode) {
    const ih = mode === 'inhouse';
    document.getElementById('inhouseSection').style.display = ih ? '' : 'none';
    document.getElementById('manualSection').style.display  = ih ? 'none' : '';
    document.getElementById('btnInhouse').classList.toggle('active', ih);
    document.getElementById('btnManual').classList.toggle('active', !ih);
    if (!ih) { document.getElementById('fBookingId').value = ''; }
}

function fillFromInhouse() {
    const sel = document.getElementById('fGuestSelect');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('fBookingId').value = opt.value || '';
    document.getElementById('fPhone').value     = opt.dataset.phone || '';
    document.getElementById('fRoom').value      = opt.dataset.room  || '';
}

function getGuestName() {
    const ih = document.getElementById('inhouseSection').style.display !== 'none';
    if (ih) {
        const sel = document.getElementById('fGuestSelect');
        return sel.options[sel.selectedIndex].dataset.name || '';
    }
    return document.getElementById('fGuestName').value.trim();
}

// ── Items ─────────────────────────────────────────────────────────────────────
let rowCnt = 0;

function buildSvcOpts(selected) {
    return SVC_KEYS.map((k, i) =>
        `<option value="${k}" ${k===selected?'selected':''}>${SVC_LABELS[i]}</option>`
    ).join('');
}

function addItemRow(svc, desc, qty, price) {
    rowCnt++;
    const id = 'r' + rowCnt;
    const tr = document.createElement('tr');
    tr.id = id;
    tr.innerHTML =
        `<td><select class="iSvc" onchange="rcalc('${id}')">${buildSvcOpts(svc||'')}</select></td>`+
        `<td><input type="text" class="iDesc" placeholder="e.g. Honda Beat 2 days" value="${desc||''}"></td>`+
        `<td><input type="number" class="iQty" value="${qty||1}" min="0.5" step="0.5" style="width:60px" oninput="rcalc('${id}')"></td>`+
        `<td><input type="number" class="iPrice" value="${price||0}" min="0" style="width:105px" oninput="rcalc('${id}')"></td>`+
        `<td style="font-weight:700;color:#4338ca;text-align:right;white-space:nowrap" class="iTotal">Rp 0</td>`+
        `<td><button type="button" class="btn-del-row" onclick="delRow('${id}')">✕</button></td>`;
    document.getElementById('itemsBody').appendChild(tr);
    rcalc(id);
}

function delRow(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
    refreshTotal();
}

function rcalc(id) {
    const tr = document.getElementById(id);
    if (!tr) return;
    const t = (parseFloat(tr.querySelector('.iQty').value)||0) * (parseFloat(tr.querySelector('.iPrice').value)||0);
    tr.querySelector('.iTotal').textContent = 'Rp ' + Math.round(t).toLocaleString('id-ID');
    refreshTotal();
}

function subtotal() {
    let t = 0;
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        t += (parseFloat(tr.querySelector('.iQty')?.value)||0) * (parseFloat(tr.querySelector('.iPrice')?.value)||0);
    });
    return t;
}

function getTaxRate() {
    const sel = document.getElementById('fTaxRate');
    if (!sel) return 0;
    if (sel.value === 'custom') return parseFloat(document.getElementById('fTaxCustom')?.value) || 0;
    return parseFloat(sel.value) || 0;
}

function grandTotal() {
    const sub = subtotal();
    const rate = getTaxRate();
    return sub + sub * (rate / 100);
}

function onTaxRateChange() {
    const sel = document.getElementById('fTaxRate');
    document.getElementById('customTaxWrap').style.display = sel.value === 'custom' ? '' : 'none';
    refreshTotal();
}

function refreshTotal() {
    const sub  = subtotal();
    const rate = getTaxRate();
    const tax  = sub * (rate / 100);
    const tot  = sub + tax;
    const dp   = parseFloat(document.getElementById('fPaid')?.value) || 0;
    const sisa = Math.max(0, tot - dp);
    const fmt  = v => 'Rp ' + Math.round(v).toLocaleString('id-ID');

    document.getElementById('tpSubtotal').textContent = fmt(sub);
    document.getElementById('tpGrand').textContent    = fmt(tot);

    const taxRow = document.getElementById('tpTaxRow');
    taxRow.style.display = rate > 0 ? '' : 'none';
    document.getElementById('tpTax').textContent = `${fmt(tax)} (${rate}%)`;

    const dpEl = document.getElementById('fPaid');
    const hasDp = dpEl && parseFloat(dpEl.value) > 0;
    document.getElementById('tpDpRow').style.display  = hasDp ? '' : 'none';
    document.getElementById('tpSisaRow').style.display = (hasDp && sisa > 0) ? '' : 'none';
    document.getElementById('tpDp').textContent   = fmt(dp);
    document.getElementById('tpSisa').textContent = fmt(sisa);

    enforceMaxPaid();
    if (document.getElementById('fFullPay').checked)
        document.getElementById('fPaid').value = Math.round(tot);
}

function enforceMaxPaid() {
    const mx = grandTotal(), inp = document.getElementById('fPaid');
    if (parseFloat(inp.value) > mx) inp.value = Math.round(mx);
}

function toggleFullPay(checked) {
    document.getElementById('fPaid').value = checked ? Math.round(grandTotal()) : 0;
    refreshTotal();
}

// ── Open/Close ────────────────────────────────────────────────────────────────
function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    ['fGuestName','fPhone','fRoom','fNotes'].forEach(id => { const e=document.getElementById(id); if(e) e.value=''; });
    document.getElementById('fGuestSelect').value = '';
    document.getElementById('fBookingId').value   = '';
    document.getElementById('fPaid').value        = 0;
    document.getElementById('fFullPay').checked   = false;
    document.getElementById('fTaxRate').value     = '0';
    document.getElementById('customTaxWrap').style.display = 'none';
    if (document.getElementById('fTaxCustom')) document.getElementById('fTaxCustom').value = 0;
    document.getElementById('itemsBody').innerHTML = '';
    rowCnt = 0;
    setGuestMode('inhouse');
    addItemRow();
    refreshTotal();
}
function closeCreateModal() { document.getElementById('createModal').classList.remove('open'); }

// ── Submit create ─────────────────────────────────────────────────────────────
function submitCreate() {
    const guestName = getGuestName();
    if (!guestName) { alert('Please select or enter a guest name'); return; }

    const rows = document.querySelectorAll('#itemsBody tr');
    if (!rows.length) { alert('Add at least one service item'); return; }

    const items = [];
    for (const tr of rows) {
        const svc = tr.querySelector('.iSvc').value;
        if (!svc) { alert('Select service type for all rows'); return; }
        items.push({
            service_type: svc,
            description:  tr.querySelector('.iDesc').value.trim(),
            qty:          parseFloat(tr.querySelector('.iQty').value) || 1,
            unit_price:   parseFloat(tr.querySelector('.iPrice').value) || 0
        });
    }

    const btn = document.getElementById('createBtn');
    btn.disabled = true; btn.textContent = 'Creating...';

    const fd = new FormData();
    fd.append('action',         'create');
    fd.append('guest_name',     guestName);
    fd.append('guest_phone',    document.getElementById('fPhone').value.trim());
    fd.append('room_number',    document.getElementById('fRoom').value.trim());
    fd.append('booking_id',     document.getElementById('fBookingId').value || '');
    fd.append('items',          JSON.stringify(items));
    fd.append('payment_method', document.getElementById('fPayMethod').value);
    fd.append('paid_amount',    document.getElementById('fPaid').value || 0);
    fd.append('tax_rate',       getTaxRate());
    fd.append('notes',          document.getElementById('fNotes').value.trim());

    fetch('hotel-services.php', {method:'POST',body:fd,credentials:'include'})
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                closeCreateModal();
                const cbMsg = res.cashbook ? '\n✅ Tercatat di Buku Kas' : '';
                alert('Invoice ' + res.invoice_number + ' created!' + cbMsg);
                location.reload();
            } else {
                alert('Error: ' + (res.message || 'Unknown'));
                btn.disabled = false; btn.textContent = '✅ Create Invoice';
            }
        })
        .catch(() => { alert('Network error'); btn.disabled = false; btn.textContent = '✅ Create Invoice'; });
}

// ── Status ────────────────────────────────────────────────────────────────────
function updateStatus(id, status) {
    const fd = new FormData();
    fd.append('action','update_status'); fd.append('id',id); fd.append('status',status);
    fetch('hotel-services.php', {method:'POST',body:fd,credentials:'include'})
        .then(r=>r.json()).then(res=>{ if(!res.success) alert('Failed to update status'); });
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteInvoice(id, code) {
    if (!confirm('Delete invoice ' + code + '? Cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action','delete'); fd.append('id',id);
    fetch('hotel-services.php', {method:'POST',body:fd,credentials:'include'})
        .then(r=>r.json()).then(res=>{ if(res.success) location.reload(); else alert('Delete failed'); });
}

// ── Pay modal ─────────────────────────────────────────────────────────────────
function openPayModal(id, remaining, invNo) {
    document.getElementById('pInvId').value = id;
    document.getElementById('pInvNo').textContent = 'Invoice: ' + invNo;
    document.getElementById('pRemaining').textContent = 'Rp ' + Math.round(remaining).toLocaleString('id-ID');
    document.getElementById('pAmount').value = Math.round(remaining);
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() { document.getElementById('payModal').classList.remove('open'); }

function submitPay() {
    const id = document.getElementById('pInvId').value;
    const amount = document.getElementById('pAmount').value;
    if (!amount || parseFloat(amount) <= 0) { alert('Enter valid amount'); return; }
    const btn = document.getElementById('payBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    const fd = new FormData();
    fd.append('action','add_payment'); fd.append('id',id);
    fd.append('amount',amount); fd.append('method',document.getElementById('pMethod').value);
    fetch('hotel-services.php', {method:'POST',body:fd,credentials:'include'})
        .then(r=>r.json())
        .then(res=>{
            if (res.success) {
                closePayModal();
                alert('Payment saved! ' + (res.cashbook ? '✅ Tercatat di Buku Kas' : '⚠️ Gagal sync ke Buku Kas'));
                location.reload();
            } else {
                alert('Error: ' + (res.message||'Unknown'));
                btn.disabled = false; btn.textContent = '💾 Save & Sync to Cashbook';
            }
        });
}
</script>

<?php include '../../includes/footer.php'; ?>
