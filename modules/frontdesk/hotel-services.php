<?php
/**
 * Hotel Services — Motor Rental, Laundry, Jasa, Airport Drop, Harbor Drop
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

$db  = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$businessId  = $_SESSION['business_id'] ?? 1;

// ─── Auto-create table ───────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_service_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    business_id     INT NOT NULL DEFAULT 1,
    order_number    VARCHAR(30)  NOT NULL UNIQUE,
    guest_name      VARCHAR(120) NOT NULL,
    guest_phone     VARCHAR(30)  DEFAULT NULL,
    room_number     VARCHAR(20)  DEFAULT NULL,
    service_type    ENUM('motor_rental','laundry','service','airport_drop','harbor_drop') NOT NULL,
    description     TEXT         DEFAULT NULL,
    quantity        DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
    start_datetime  DATETIME     DEFAULT NULL,
    end_datetime    DATETIME     DEFAULT NULL,
    payment_method  VARCHAR(20)  NOT NULL DEFAULT 'cash',
    payment_status  ENUM('unpaid','paid','partial') NOT NULL DEFAULT 'unpaid',
    paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
    status          ENUM('pending','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    notes           TEXT         DEFAULT NULL,
    created_by      INT          DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_business  (business_id),
    KEY idx_status    (status),
    KEY idx_service   (service_type),
    KEY idx_date      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── Service type labels ──────────────────────────────────────────────────────
$serviceTypes = [
    'motor_rental'  => 'Motor Rental',
    'laundry'       => 'Laundry',
    'service'       => 'Service',
    'airport_drop'  => 'Airport Drop',
    'harbor_drop'   => 'Harbor Drop',
];

$statusColors = [
    'pending'     => '#f59e0b',
    'confirmed'   => '#3b82f6',
    'in_progress' => '#8b5cf6',
    'completed'   => '#10b981',
    'cancelled'   => '#ef4444',
];

$payStatusColors = [
    'unpaid'  => '#ef4444',
    'partial' => '#f59e0b',
    'paid'    => '#10b981',
];

// ─── AJAX actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    ob_start();
    try {
        $action = $_POST['action'];

        if ($action === 'create') {
            $guestName    = trim($_POST['guest_name'] ?? '');
            $guestPhone   = trim($_POST['guest_phone'] ?? '');
            $roomNumber   = trim($_POST['room_number'] ?? '');
            $serviceType  = $_POST['service_type'] ?? '';
            $description  = trim($_POST['description'] ?? '');
            $quantity     = max(1, (float)($_POST['quantity'] ?? 1));
            $unitPrice    = max(0, (float)($_POST['unit_price'] ?? 0));
            $startDt      = $_POST['start_datetime'] ?? null;
            $endDt        = $_POST['end_datetime'] ?? null;
            $payMethod    = $_POST['payment_method'] ?? 'cash';
            $paidAmount   = max(0, (float)($_POST['paid_amount'] ?? 0));
            $notes        = trim($_POST['notes'] ?? '');

            if (!$guestName) throw new Exception('Guest name is required');
            if (!array_key_exists($serviceType, $serviceTypes)) throw new Exception('Invalid service type');

            $totalPrice = round($quantity * $unitPrice, 2);
            $remaining  = $totalPrice - $paidAmount;
            $payStatus  = $paidAmount <= 0 ? 'unpaid' : ($remaining <= 0 ? 'paid' : 'partial');

            // Generate order number
            $prefix = 'SVC-' . date('Ym') . '-';
            $last   = $pdo->query("SELECT order_number FROM hotel_service_orders WHERE order_number LIKE '{$prefix}%' ORDER BY order_number DESC LIMIT 1")->fetchColumn();
            $seq    = $last ? ((int)substr($last, -4) + 1) : 1;
            $orderNo = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO hotel_service_orders
                (business_id, order_number, guest_name, guest_phone, room_number,
                 service_type, description, quantity, unit_price, total_price,
                 start_datetime, end_datetime, payment_method, payment_status, paid_amount,
                 status, notes, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->execute([
                $businessId, $orderNo, $guestName, $guestPhone ?: null, $roomNumber ?: null,
                $serviceType, $description ?: null, $quantity, $unitPrice, $totalPrice,
                ($startDt ?: null), ($endDt ?: null),
                $payMethod, $payStatus, $paidAmount,
                'pending', $notes ?: null, $currentUser['id'] ?? null
            ]);

            ob_clean();
            echo json_encode(['success' => true, 'order_number' => $orderNo, 'id' => $pdo->lastInsertId()]);
            exit;
        }

        if ($action === 'update_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (!$id) throw new Exception('Invalid ID');
            $allowed = ['pending','confirmed','in_progress','completed','cancelled'];
            if (!in_array($status, $allowed)) throw new Exception('Invalid status');
            $pdo->prepare("UPDATE hotel_service_orders SET status=? WHERE id=? AND business_id=?")
                ->execute([$status, $id, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'add_payment') {
            $id     = (int)($_POST['id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $method = $_POST['method'] ?? 'cash';
            if (!$id || $amount <= 0) throw new Exception('Invalid data');

            $row = $pdo->prepare("SELECT total_price, paid_amount FROM hotel_service_orders WHERE id=? AND business_id=?");
            $row->execute([$id, $businessId]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('Order not found');

            $newPaid   = $r['paid_amount'] + $amount;
            $remaining = $r['total_price'] - $newPaid;
            $payStatus = $newPaid <= 0 ? 'unpaid' : ($remaining <= 0 ? 'paid' : 'partial');

            $pdo->prepare("UPDATE hotel_service_orders SET paid_amount=?, payment_status=?, payment_method=? WHERE id=? AND business_id=?")
                ->execute([$newPaid, $payStatus, $method, $id, $businessId]);
            ob_clean();
            echo json_encode(['success' => true, 'payment_status' => $payStatus, 'paid_amount' => $newPaid]);
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Invalid ID');
            $pdo->prepare("DELETE FROM hotel_service_orders WHERE id=? AND business_id=?")
                ->execute([$id, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'get') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM hotel_service_orders WHERE id=? AND business_id=?");
            $stmt->execute([$id, $businessId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            ob_clean();
            echo json_encode(['success' => (bool)$row, 'data' => $row]);
            exit;
        }

        throw new Exception('Unknown action');
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ─── Fetch list ───────────────────────────────────────────────────────────────
$filterType    = $_GET['type']   ?? '';
$filterStatus  = $_GET['status'] ?? '';
$filterDate    = $_GET['date']   ?? '';
$search        = trim($_GET['q'] ?? '');

$where  = ["o.business_id = ?"];
$params = [$businessId];

if ($filterType)   { $where[] = 'o.service_type = ?';  $params[] = $filterType; }
if ($filterStatus) { $where[] = 'o.status = ?';         $params[] = $filterStatus; }
if ($filterDate)   { $where[] = 'DATE(o.created_at) = ?'; $params[] = $filterDate; }
if ($search)       { $where[] = '(o.guest_name LIKE ? OR o.order_number LIKE ? OR o.room_number LIKE ?)';
                     $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$sql    = "SELECT * FROM hotel_service_orders o WHERE " . implode(' AND ', $where) . " ORDER BY o.created_at DESC LIMIT 200";
$stmt   = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Stats ────────────────────────────────────────────────────────────────────
$stats = $pdo->prepare("SELECT
    COUNT(*) as total,
    COALESCE(SUM(total_price),0) as revenue,
    COALESCE(SUM(paid_amount),0) as collected,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END) as unpaid
    FROM hotel_service_orders WHERE business_id=? AND DATE(created_at)=CURDATE()");
$stats->execute([$businessId]);
$today = $stats->fetch(PDO::FETCH_ASSOC);

// In-house guests for autocomplete
try {
    $inHouseGuests = $pdo->query("SELECT g.guest_name, b.room_number FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.status IN ('confirmed','checked_in') ORDER BY g.guest_name LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $inHouseGuests = []; }

include '../../includes/header.php';
?>
<style>
.hs-page { padding: 1.25rem; }
.hs-topbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;
}
.hs-topbar h2 { font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin: 0; }
.hs-stats {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0.75rem; margin-bottom: 1.25rem;
}
.hs-stat {
    background: white; border-radius: 10px; padding: 0.85rem 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07); border-top: 3px solid var(--c);
}
.hs-stat .val { font-size: 1.3rem; font-weight: 800; color: var(--c); }
.hs-stat .lbl { font-size: 0.72rem; color: var(--text-secondary); margin-top:0.15rem; }
.hs-filters {
    background: white; border-radius: 10px; padding: 0.85rem 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07); margin-bottom: 1rem;
    display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center;
}
.hs-filters input, .hs-filters select {
    padding: 0.4rem 0.6rem; border: 1px solid #e2e8f0; border-radius: 6px;
    font-size: 0.8rem; background: white; color: var(--text-primary);
}
.hs-table-wrap {
    background: white; border-radius: 10px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden;
}
.hs-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
.hs-table th {
    background: #f8fafc; padding: 0.65rem 0.85rem; text-align: left;
    font-weight: 600; color: var(--text-secondary); font-size: 0.72rem;
    text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 1px solid #e2e8f0;
}
.hs-table td { padding: 0.65rem 0.85rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.hs-table tr:last-child td { border-bottom: none; }
.hs-table tr:hover { background: #fafbff; }
.hs-badge {
    display: inline-block; padding: 0.2rem 0.55rem; border-radius: 20px;
    font-size: 0.7rem; font-weight: 600; color: white;
}
.hs-svc-badge {
    display: inline-block; padding: 0.2rem 0.55rem; border-radius: 6px;
    font-size: 0.72rem; font-weight: 600; background: #ede9fe; color: #6d28d9;
}
.hs-action-btn {
    padding: 0.25rem 0.55rem; border: none; border-radius: 5px; cursor: pointer;
    font-size: 0.72rem; font-weight: 600; transition: opacity 0.2s;
}
.hs-action-btn:hover { opacity: 0.8; }
/* Modal */
.hs-modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 99999; align-items: center; justify-content: center;
}
.hs-modal-overlay.open { display: flex; }
.hs-modal {
    background: white; border-radius: 14px; padding: 1.5rem;
    width: 96%; max-width: 560px; max-height: 92vh;
    overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.hs-modal h3 { margin: 0 0 1rem; font-size: 1rem; font-weight: 700; }
.hs-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.hs-form-row.full { grid-template-columns: 1fr; }
.hs-field label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.3rem; }
.hs-field input, .hs-field select, .hs-field textarea {
    width: 100%; padding: 0.5rem 0.65rem; border: 1px solid #e2e8f0; border-radius: 7px;
    font-size: 0.85rem; color: var(--text-primary); background: white;
    box-sizing: border-box;
}
.hs-field textarea { resize: vertical; min-height: 60px; }
.hs-field input:focus, .hs-field select:focus, .hs-field textarea:focus {
    outline: none; border-color: var(--primary,#6366f1); box-shadow: 0 0 0 2px rgba(99,102,241,0.15);
}
.hs-total-preview {
    background: linear-gradient(135deg, #f0f4ff, #e8edff);
    border-radius: 8px; padding: 0.75rem 1rem;
    text-align: center; margin: 0.75rem 0;
    font-size: 1.1rem; font-weight: 700; color: #4338ca;
}
.hs-modal-footer { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1rem; }
.btn-hs { padding: 0.5rem 1.25rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; }
.btn-hs-primary { background: var(--primary,#6366f1); color: white; }
.btn-hs-secondary { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
.svc-type-grid {
    display: grid; grid-template-columns: repeat(5,1fr); gap: 0.5rem; margin-bottom: 0.75rem;
}
.svc-type-btn {
    padding: 0.5rem 0.25rem; border: 2px solid #e2e8f0; border-radius: 8px;
    text-align: center; cursor: pointer; font-size: 0.7rem; font-weight: 600;
    color: var(--text-secondary); transition: all 0.15s; background: white;
    line-height: 1.3;
}
.svc-type-btn:hover, .svc-type-btn.active {
    border-color: #6366f1; background: #ede9fe; color: #4c1d95;
}
.svc-type-btn .svc-icon { font-size: 1.4rem; display: block; margin-bottom: 0.2rem; }

/* empty state */
.hs-empty { text-align: center; padding: 3rem 1rem; color: var(--text-secondary); }
.hs-empty .em-icon { font-size: 2.5rem; margin-bottom: 0.5rem; }

@media(max-width:640px) {
    .hs-form-row { grid-template-columns: 1fr; }
    .svc-type-grid { grid-template-columns: repeat(3,1fr); }
    .hs-stats { grid-template-columns: repeat(2,1fr); }
}
</style>

<div class="hs-page">

    <!-- Top bar -->
    <div class="hs-topbar">
        <div>
            <h2>🛎️ Hotel Services</h2>
            <div style="font-size:0.75rem;color:var(--text-secondary)">Motor Rental · Laundry · Service · Airport Drop · Harbor Drop</div>
        </div>
        <button class="btn-hs btn-hs-primary" onclick="openCreateModal()">+ New Service Order</button>
    </div>

    <!-- Stats today -->
    <div class="hs-stats">
        <div class="hs-stat" style="--c:#6366f1">
            <div class="val"><?php echo $today['total']; ?></div>
            <div class="lbl">Orders Today</div>
        </div>
        <div class="hs-stat" style="--c:#10b981">
            <div class="val">Rp <?php echo number_format($today['revenue'], 0, ',', '.'); ?></div>
            <div class="lbl">Revenue Today</div>
        </div>
        <div class="hs-stat" style="--c:#3b82f6">
            <div class="val">Rp <?php echo number_format($today['collected'], 0, ',', '.'); ?></div>
            <div class="lbl">Collected</div>
        </div>
        <div class="hs-stat" style="--c:#ef4444">
            <div class="val"><?php echo $today['unpaid']; ?></div>
            <div class="lbl">Unpaid Today</div>
        </div>
        <div class="hs-stat" style="--c:#8b5cf6">
            <div class="val"><?php echo $today['completed']; ?></div>
            <div class="lbl">Completed Today</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="hs-filters">
        <input type="text" name="q" placeholder="🔍 Search guest / order..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="type">
            <option value="">All Services</option>
            <?php foreach ($serviceTypes as $k => $v): ?>
            <option value="<?php echo $k; ?>" <?php echo $filterType === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Status</option>
            <option value="pending" <?php echo $filterStatus==='pending'?'selected':''; ?>>Pending</option>
            <option value="confirmed" <?php echo $filterStatus==='confirmed'?'selected':''; ?>>Confirmed</option>
            <option value="in_progress" <?php echo $filterStatus==='in_progress'?'selected':''; ?>>In Progress</option>
            <option value="completed" <?php echo $filterStatus==='completed'?'selected':''; ?>>Completed</option>
            <option value="cancelled" <?php echo $filterStatus==='cancelled'?'selected':''; ?>>Cancelled</option>
        </select>
        <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
        <button type="submit" class="btn-hs btn-hs-primary" style="padding:0.4rem 0.9rem;font-size:0.8rem">Filter</button>
        <?php if ($filterType || $filterStatus || $filterDate || $search): ?>
        <a href="hotel-services.php" class="btn-hs btn-hs-secondary" style="padding:0.4rem 0.9rem;font-size:0.8rem;text-decoration:none">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="hs-table-wrap">
        <?php if (empty($orders)): ?>
        <div class="hs-empty">
            <div class="em-icon">🛎️</div>
            <div style="font-weight:600;margin-bottom:0.25rem">No service orders found</div>
            <div style="font-size:0.8rem">Create your first service order above</div>
        </div>
        <?php else: ?>
        <table class="hs-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Service</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Pay Status</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td style="font-weight:600;color:#4338ca"><?php echo htmlspecialchars($o['order_number']); ?></td>
                <td>
                    <div style="font-weight:600"><?php echo htmlspecialchars($o['guest_name']); ?></div>
                    <?php if ($o['guest_phone']): ?>
                    <div style="font-size:0.7rem;color:var(--text-secondary)"><?php echo htmlspecialchars($o['guest_phone']); ?></div>
                    <?php endif; ?>
                </td>
                <td><?php echo $o['room_number'] ? htmlspecialchars($o['room_number']) : '<span style="color:#d1d5db">—</span>'; ?></td>
                <td><span class="hs-svc-badge"><?php echo $serviceTypes[$o['service_type']] ?? $o['service_type']; ?></span></td>
                <td style="font-weight:600">Rp <?php echo number_format($o['total_price'], 0, ',', '.'); ?></td>
                <td style="color:#10b981;font-weight:600">Rp <?php echo number_format($o['paid_amount'], 0, ',', '.'); ?></td>
                <td>
                    <span class="hs-badge" style="background:<?php echo $payStatusColors[$o['payment_status']]; ?>">
                        <?php echo strtoupper($o['payment_status']); ?>
                    </span>
                </td>
                <td>
                    <span class="hs-badge" style="background:<?php echo $statusColors[$o['status']]; ?>">
                        <?php echo ucwords(str_replace('_',' ',$o['status'])); ?>
                    </span>
                </td>
                <td style="font-size:0.72rem;color:var(--text-secondary)"><?php echo date('d M Y', strtotime($o['created_at'])); ?></td>
                <td>
                    <div style="display:flex;gap:0.3rem;flex-wrap:wrap">
                        <a href="hotel-service-invoice.php?id=<?php echo $o['id']; ?>" target="_blank"
                            class="hs-action-btn" style="background:#e0f2fe;color:#0277bd">Invoice</a>
                        <?php if ($o['payment_status'] !== 'paid'): ?>
                        <button class="hs-action-btn" style="background:#dcfce7;color:#15803d"
                            onclick="openPayModal(<?php echo $o['id']; ?>, <?php echo $o['total_price']-$o['paid_amount']; ?>)">Pay</button>
                        <?php endif; ?>
                        <select class="hs-action-btn" style="background:#f3f4f6;color:#374151;padding:0.25rem 0.35rem"
                            onchange="updateStatus(<?php echo $o['id']; ?>, this.value); this.blur()"
                            title="Change status">
                            <?php foreach (['pending','confirmed','in_progress','completed','cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $o['status']===$s?'selected':''; ?>><?php echo ucwords(str_replace('_',' ',$s)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="hs-action-btn" style="background:#fee2e2;color:#b91c1c"
                            onclick="deleteOrder(<?php echo $o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'],ENT_QUOTES); ?>')">✕</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── CREATE MODAL ─────────────────────────────────────────────────────────── -->
<div id="createModal" class="hs-modal-overlay" onclick="if(event.target===this)closeCreateModal()">
  <div class="hs-modal">
    <h3>🛎️ New Service Order</h3>

    <!-- Service type buttons -->
    <div style="margin-bottom:0.5rem">
        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:0.4rem">Service Type *</label>
        <div class="svc-type-grid">
            <button type="button" class="svc-type-btn" data-svc="motor_rental" onclick="selectSvc(this)">
                <span class="svc-icon">🏍️</span>Motor<br>Rental
            </button>
            <button type="button" class="svc-type-btn" data-svc="laundry" onclick="selectSvc(this)">
                <span class="svc-icon">👕</span>Laundry
            </button>
            <button type="button" class="svc-type-btn" data-svc="service" onclick="selectSvc(this)">
                <span class="svc-icon">🔧</span>Service
            </button>
            <button type="button" class="svc-type-btn" data-svc="airport_drop" onclick="selectSvc(this)">
                <span class="svc-icon">✈️</span>Airport<br>Drop
            </button>
            <button type="button" class="svc-type-btn" data-svc="harbor_drop" onclick="selectSvc(this)">
                <span class="svc-icon">⚓</span>Harbor<br>Drop
            </button>
        </div>
        <input type="hidden" id="fSvcType">
    </div>

    <div class="hs-form-row">
        <div class="hs-field">
            <label>Guest Name *</label>
            <input type="text" id="fGuestName" placeholder="Enter guest name" list="guestList" required>
            <datalist id="guestList">
                <?php foreach ($inHouseGuests as $g): ?>
                <option data-room="<?php echo htmlspecialchars($g['room_number']??''); ?>"
                    value="<?php echo htmlspecialchars($g['guest_name']); ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="hs-field">
            <label>Phone</label>
            <input type="text" id="fGuestPhone" placeholder="Optional">
        </div>
    </div>
    <div class="hs-form-row">
        <div class="hs-field">
            <label>Room Number</label>
            <input type="text" id="fRoomNumber" placeholder="e.g. 101">
        </div>
        <div class="hs-field">
            <label>Quantity</label>
            <input type="number" id="fQty" value="1" min="1" step="0.5" oninput="calcTotal()">
        </div>
    </div>
    <div class="hs-form-row">
        <div class="hs-field">
            <label>Unit Price (Rp)</label>
            <input type="number" id="fUnitPrice" value="0" min="0" oninput="calcTotal()">
        </div>
        <div class="hs-field">
            <label>Paid Amount (Rp)</label>
            <input type="number" id="fPaidAmount" value="0" min="0">
        </div>
    </div>
    <div class="hs-form-row">
        <div class="hs-field">
            <label>Start Date &amp; Time</label>
            <input type="datetime-local" id="fStartDt">
        </div>
        <div class="hs-field" id="fEndDtWrap">
            <label>End / Return Date</label>
            <input type="datetime-local" id="fEndDt">
        </div>
    </div>
    <div class="hs-form-row">
        <div class="hs-field">
            <label>Payment Method</label>
            <select id="fPayMethod">
                <option value="cash">Cash</option>
                <option value="transfer">Transfer</option>
                <option value="qris">QRIS</option>
                <option value="card">Card</option>
            </select>
        </div>
        <div class="hs-field full">
            <label>Description</label>
            <input type="text" id="fDescription" placeholder="e.g. Honda Beat • 2 days">
        </div>
    </div>
    <div class="hs-form-row full">
        <div class="hs-field">
            <label>Notes</label>
            <textarea id="fNotes" rows="2" placeholder="Special instructions..."></textarea>
        </div>
    </div>

    <div class="hs-total-preview" id="totalPreview">Total: Rp 0</div>

    <div class="hs-modal-footer">
        <button class="btn-hs btn-hs-secondary" onclick="closeCreateModal()">Cancel</button>
        <button class="btn-hs btn-hs-primary" id="createBtn" onclick="submitCreate()">✅ Create Order</button>
    </div>
  </div>
</div>

<!-- ── PAY MODAL ─────────────────────────────────────────────────────────────── -->
<div id="payModal" class="hs-modal-overlay" onclick="if(event.target===this)closePayModal()">
  <div class="hs-modal" style="max-width:360px">
    <h3>💳 Add Payment</h3>
    <input type="hidden" id="pOrderId">
    <div class="hs-field" style="margin-bottom:0.75rem">
        <label>Remaining Balance</label>
        <div id="pRemaining" style="font-size:1.2rem;font-weight:700;color:#ef4444;padding:0.4rem 0"></div>
    </div>
    <div class="hs-form-row">
        <div class="hs-field">
            <label>Amount (Rp)</label>
            <input type="number" id="pAmount" value="0" min="0">
        </div>
        <div class="hs-field">
            <label>Method</label>
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
        <button class="btn-hs btn-hs-primary" onclick="submitPay()">Save Payment</button>
    </div>
  </div>
</div>

<script>
const BASE_URL = '<?php echo BASE_URL; ?>';

function openCreateModal() {
    document.getElementById('createModal').classList.add('open');
    // Set default start time to now
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('fStartDt').value = now.toISOString().slice(0,16);
}
function closeCreateModal() {
    document.getElementById('createModal').classList.remove('open');
    resetForm();
}

function selectSvc(btn) {
    document.querySelectorAll('.svc-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('fSvcType').value = btn.dataset.svc;
    // Show/hide end date based on service type
    const needEnd = ['motor_rental', 'airport_drop', 'harbor_drop'];
    document.getElementById('fEndDtWrap').style.display = needEnd.includes(btn.dataset.svc) ? '' : 'none';
}

function calcTotal() {
    const qty   = parseFloat(document.getElementById('fQty').value) || 0;
    const price = parseFloat(document.getElementById('fUnitPrice').value) || 0;
    const total = qty * price;
    document.getElementById('totalPreview').textContent = 'Total: Rp ' + total.toLocaleString('id-ID');
    document.getElementById('fPaidAmount').max = total;
}

function resetForm() {
    ['fGuestName','fGuestPhone','fRoomNumber','fDescription','fNotes'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('fQty').value = 1;
    document.getElementById('fUnitPrice').value = 0;
    document.getElementById('fPaidAmount').value = 0;
    document.getElementById('totalPreview').textContent = 'Total: Rp 0';
    document.getElementById('fSvcType').value = '';
    document.querySelectorAll('.svc-type-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('fEndDtWrap').style.display = '';
    document.getElementById('fStartDt').value = '';
    document.getElementById('fEndDt').value = '';
}

function submitCreate() {
    const svcType = document.getElementById('fSvcType').value;
    const guest   = document.getElementById('fGuestName').value.trim();
    if (!svcType) { alert('Please select a service type'); return; }
    if (!guest)   { alert('Guest name is required'); return; }

    const btn = document.getElementById('createBtn');
    btn.disabled = true; btn.textContent = 'Creating...';

    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('service_type',   svcType);
    fd.append('guest_name',     guest);
    fd.append('guest_phone',    document.getElementById('fGuestPhone').value.trim());
    fd.append('room_number',    document.getElementById('fRoomNumber').value.trim());
    fd.append('description',    document.getElementById('fDescription').value.trim());
    fd.append('quantity',       document.getElementById('fQty').value);
    fd.append('unit_price',     document.getElementById('fUnitPrice').value);
    fd.append('paid_amount',    document.getElementById('fPaidAmount').value);
    fd.append('payment_method', document.getElementById('fPayMethod').value);
    fd.append('start_datetime', document.getElementById('fStartDt').value || '');
    fd.append('end_datetime',   document.getElementById('fEndDt').value || '');
    fd.append('notes',          document.getElementById('fNotes').value.trim());

    fetch('hotel-services.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                closeCreateModal();
                location.reload();
            } else {
                alert('Error: ' + (res.message || 'Unknown error'));
                btn.disabled = false; btn.textContent = '✅ Create Order';
            }
        })
        .catch(() => { alert('Network error'); btn.disabled = false; btn.textContent = '✅ Create Order'; });
}

function updateStatus(id, status) {
    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('id', id);
    fd.append('status', status);
    fetch('hotel-services.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(res => { if (!res.success) alert('Failed to update status'); });
}

function deleteOrder(id, code) {
    if (!confirm('Delete order ' + code + '? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fetch('hotel-services.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert('Delete failed'); });
}

function openPayModal(id, remaining) {
    document.getElementById('pOrderId').value = id;
    document.getElementById('pRemaining').textContent = 'Rp ' + Math.round(remaining).toLocaleString('id-ID');
    document.getElementById('pAmount').value = Math.round(remaining);
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() { document.getElementById('payModal').classList.remove('open'); }

function submitPay() {
    const id     = document.getElementById('pOrderId').value;
    const amount = document.getElementById('pAmount').value;
    const method = document.getElementById('pMethod').value;
    if (!amount || parseFloat(amount) <= 0) { alert('Enter valid amount'); return; }
    const fd = new FormData();
    fd.append('action', 'add_payment');
    fd.append('id', id);
    fd.append('amount', amount);
    fd.append('method', method);
    fetch('hotel-services.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(res => {
            if (res.success) { closePayModal(); location.reload(); }
            else alert('Error: ' + (res.message || 'Unknown'));
        });
}

// Autofill room from datalist
document.getElementById('fGuestName').addEventListener('change', function() {
    const opts = document.querySelectorAll('#guestList option');
    for (let o of opts) {
        if (o.value === this.value && o.dataset.room) {
            document.getElementById('fRoomNumber').value = o.dataset.room;
            break;
        }
    }
});

// Click outside close
document.getElementById('fEndDtWrap').style.display = '';
</script>

<?php include '../../includes/footer.php'; ?>
