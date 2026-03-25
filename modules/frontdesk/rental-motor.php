<?php
/**
 * Rental Motor Monitoring — Hotel Services Sub-Module
 * Track motorcycle rentals: units, start/end dates, invoicing
 * Integrated with Hotel Service invoicing system
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
$pdo->exec("CREATE TABLE IF NOT EXISTS rental_motors (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    business_id   INT NOT NULL DEFAULT 1,
    plate_number  VARCHAR(20) NOT NULL,
    motor_name    VARCHAR(100) NOT NULL,
    color         VARCHAR(30) DEFAULT NULL,
    year          SMALLINT DEFAULT NULL,
    daily_rate    DECIMAL(15,2) NOT NULL DEFAULT 0,
    status        ENUM('available','rented','maintenance') NOT NULL DEFAULT 'available',
    notes         TEXT DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_biz (business_id),
    KEY idx_status (business_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS rental_motor_bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    business_id     INT NOT NULL DEFAULT 1,
    motor_id        INT NOT NULL,
    invoice_id      INT DEFAULT NULL,
    guest_name      VARCHAR(120) NOT NULL,
    guest_phone     VARCHAR(30) DEFAULT NULL,
    room_number     VARCHAR(20) DEFAULT NULL,
    booking_id      INT DEFAULT NULL,
    start_datetime  DATETIME NOT NULL,
    end_datetime    DATETIME NOT NULL,
    actual_return   DATETIME DEFAULT NULL,
    daily_rate      DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
    deposit         DECIMAL(15,2) NOT NULL DEFAULT 0,
    status          ENUM('active','returned','overdue','cancelled') NOT NULL DEFAULT 'active',
    notes           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_biz (business_id),
    KEY idx_motor (motor_id),
    KEY idx_invoice (invoice_id),
    KEY idx_status (business_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Auto-update overdue rentals ────────────────────────────────────────────────
$pdo->exec("UPDATE rental_motor_bookings SET status='overdue'
    WHERE status='active' AND end_datetime < NOW() AND business_id={$businessId}");

// ── AJAX handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    ob_start();
    try {
        $action = $_POST['action'];

        // ── SAVE MOTOR (add / edit) ─────────────────────────────────────────
        if ($action === 'save_motor') {
            $mid         = (int)($_POST['motor_id'] ?? 0);
            $plateNumber = strtoupper(trim($_POST['plate_number'] ?? ''));
            $motorName   = trim($_POST['motor_name'] ?? '');
            $color       = trim($_POST['color'] ?? '');
            $year        = (int)($_POST['year'] ?? 0) ?: null;
            $dailyRate   = max(0, (float)($_POST['daily_rate'] ?? 0));
            $motorStatus = $_POST['motor_status'] ?? 'available';
            $notes       = trim($_POST['notes'] ?? '');

            if (!$plateNumber || !$motorName) throw new Exception('Plat nomor dan nama motor wajib diisi');
            if (!in_array($motorStatus, ['available','rented','maintenance'])) $motorStatus = 'available';

            if ($mid) {
                $pdo->prepare("UPDATE rental_motors SET plate_number=?,motor_name=?,color=?,year=?,daily_rate=?,status=?,notes=?,updated_at=NOW()
                    WHERE id=? AND business_id=?")
                    ->execute([$plateNumber, $motorName, $color ?: null, $year, $dailyRate, $motorStatus, $notes ?: null, $mid, $businessId]);
            } else {
                $pdo->prepare("INSERT INTO rental_motors (business_id,plate_number,motor_name,color,year,daily_rate,status,notes)
                    VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$businessId, $plateNumber, $motorName, $color ?: null, $year, $dailyRate, $motorStatus, $notes ?: null]);
                $mid = (int)$pdo->lastInsertId();
            }
            ob_clean();
            echo json_encode(['success' => true, 'id' => $mid]);
            exit;
        }

        // ── DELETE MOTOR ────────────────────────────────────────────────────
        if ($action === 'delete_motor') {
            $mid = (int)($_POST['motor_id'] ?? 0);
            if (!$mid) throw new Exception('Invalid ID');
            // Check if motor has active rentals
            $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings WHERE motor_id=? AND status IN ('active','overdue') AND business_id=?");
            $activeCheck->execute([$mid, $businessId]);
            if ((int)$activeCheck->fetchColumn() > 0) throw new Exception('Tidak bisa hapus: motor sedang disewa');
            $pdo->prepare("DELETE FROM rental_motors WHERE id=? AND business_id=?")->execute([$mid, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── CREATE RENTAL ───────────────────────────────────────────────────
        if ($action === 'create_rental') {
            $motorId    = (int)($_POST['motor_id'] ?? 0);
            $guestName  = trim($_POST['guest_name'] ?? '');
            $guestPhone = trim($_POST['guest_phone'] ?? '');
            $roomNumber = trim($_POST['room_number'] ?? '');
            $bookingId  = (int)($_POST['booking_id'] ?? 0) ?: null;
            $startDt    = trim($_POST['start_datetime'] ?? '');
            $endDt      = trim($_POST['end_datetime'] ?? '');
            $dailyRate  = max(0, (float)($_POST['daily_rate'] ?? 0));
            $deposit    = max(0, (float)($_POST['deposit'] ?? 0));
            $notes      = trim($_POST['notes'] ?? '');
            $createInvoice = !empty($_POST['create_invoice']);

            if (!$motorId || !$guestName || !$startDt || !$endDt) throw new Exception('Data tidak lengkap');

            // Validate motor exists & is available
            $motor = $pdo->prepare("SELECT * FROM rental_motors WHERE id=? AND business_id=?");
            $motor->execute([$motorId, $businessId]);
            $motorRow = $motor->fetch(PDO::FETCH_ASSOC);
            if (!$motorRow) throw new Exception('Motor tidak ditemukan');
            if ($motorRow['status'] === 'rented') throw new Exception('Motor sedang disewa');
            if ($motorRow['status'] === 'maintenance') throw new Exception('Motor sedang maintenance');

            // Calculate total
            $start = new DateTime($startDt);
            $end   = new DateTime($endDt);
            if ($end <= $start) throw new Exception('Tanggal selesai harus setelah tanggal mulai');
            $days  = max(1, (int)ceil($start->diff($end)->days));
            $totalPrice = round($days * $dailyRate, 2);

            $pdo->beginTransaction();

            $invoiceId = null;
            if ($createInvoice) {
                // Create hotel service invoice
                $prefix = 'HSV-' . date('Ym') . '-';
                $last   = $pdo->query("SELECT invoice_number FROM hotel_invoices WHERE invoice_number LIKE '{$prefix}%' ORDER BY invoice_number DESC LIMIT 1")->fetchColumn();
                $seq    = $last ? ((int)substr($last, -4) + 1) : 1;
                $invNo  = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $paidAmt   = min($deposit, $totalPrice);
                $payStatus = ($paidAmt <= 0) ? 'unpaid' : ($paidAmt >= $totalPrice ? 'paid' : 'partial');

                $pdo->prepare("INSERT INTO hotel_invoices
                    (business_id, invoice_number, booking_id, guest_name, guest_phone, room_number,
                     total, paid_amount, payment_status, payment_method, status, notes, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([$businessId, $invNo, $bookingId, $guestName, $guestPhone ?: null,
                        $roomNumber ?: null, $totalPrice, $paidAmt, $payStatus, 'cash',
                        'confirmed', "Rental Motor: {$motorRow['motor_name']} ({$motorRow['plate_number']})" . ($notes ? " - {$notes}" : ''),
                        $currentUser['id'] ?? null]);
                $invoiceId = (int)$pdo->lastInsertId();

                // Add invoice item
                $pdo->prepare("INSERT INTO hotel_invoice_items
                    (invoice_id, service_type, description, quantity, unit_price, total_price, start_datetime, end_datetime)
                    VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$invoiceId, 'motor_rental',
                        "{$motorRow['motor_name']} ({$motorRow['plate_number']})" . ($notes ? " - {$notes}" : ''),
                        $days, $dailyRate, $totalPrice, $startDt, $endDt]);
            }

            // Create rental booking record
            $pdo->prepare("INSERT INTO rental_motor_bookings
                (business_id, motor_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                 start_datetime, end_datetime, daily_rate, total_price, deposit, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$businessId, $motorId, $invoiceId, $guestName, $guestPhone ?: null,
                    $roomNumber ?: null, $bookingId, $startDt, $endDt, $dailyRate, $totalPrice,
                    $deposit, 'active', $notes ?: null, $currentUser['id'] ?? null]);
            $rentalId = (int)$pdo->lastInsertId();

            // Update motor status to rented
            $pdo->prepare("UPDATE rental_motors SET status='rented', updated_at=NOW() WHERE id=?")->execute([$motorId]);

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'rental_id' => $rentalId, 'invoice_id' => $invoiceId]);
            exit;
        }

        // ── RETURN MOTOR ────────────────────────────────────────────────────
        if ($action === 'return_motor') {
            $rentalId = (int)($_POST['rental_id'] ?? 0);
            if (!$rentalId) throw new Exception('Invalid rental ID');

            $rental = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name
                FROM rental_motor_bookings rb
                JOIN rental_motors rm ON rb.motor_id = rm.id
                WHERE rb.id=? AND rb.business_id=?");
            $rental->execute([$rentalId, $businessId]);
            $rentalRow = $rental->fetch(PDO::FETCH_ASSOC);
            if (!$rentalRow) throw new Exception('Rental tidak ditemukan');
            if ($rentalRow['status'] === 'returned') throw new Exception('Motor sudah dikembalikan');

            $returnTime = date('Y-m-d H:i:s');

            // Calculate actual days and adjust total if overdue additional days
            $start      = new DateTime($rentalRow['start_datetime']);
            $actualEnd  = new DateTime($returnTime);
            $actualDays = max(1, (int)ceil($start->diff($actualEnd)->days));
            $origDays   = max(1, (int)ceil($start->diff(new DateTime($rentalRow['end_datetime']))->days));

            $newTotal = $rentalRow['total_price'];
            if ($actualDays > $origDays) {
                $extraDays = $actualDays - $origDays;
                $extraCharge = round($extraDays * (float)$rentalRow['daily_rate'], 2);
                $newTotal = round((float)$rentalRow['total_price'] + $extraCharge, 2);
            }

            $pdo->beginTransaction();

            $pdo->prepare("UPDATE rental_motor_bookings SET status='returned', actual_return=?, total_price=?, updated_at=NOW() WHERE id=?")
                ->execute([$returnTime, $newTotal, $rentalId]);

            // Update motor status back to available
            $pdo->prepare("UPDATE rental_motors SET status='available', updated_at=NOW() WHERE id=?")->execute([$rentalRow['motor_id']]);

            // Update invoice total if exists and total changed
            if ($rentalRow['invoice_id'] && $newTotal != (float)$rentalRow['total_price']) {
                $pdo->prepare("UPDATE hotel_invoices SET total=?, updated_at=NOW() WHERE id=? AND cashbook_synced=0")
                    ->execute([$newTotal, $rentalRow['invoice_id']]);
                $pdo->prepare("UPDATE hotel_invoice_items SET quantity=?, total_price=? WHERE invoice_id=? AND service_type='motor_rental'")
                    ->execute([$actualDays, $newTotal, $rentalRow['invoice_id']]);
            }

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'actual_days' => $actualDays, 'new_total' => $newTotal]);
            exit;
        }

        // ── CANCEL RENTAL ───────────────────────────────────────────────────
        if ($action === 'cancel_rental') {
            $rentalId = (int)($_POST['rental_id'] ?? 0);
            if (!$rentalId) throw new Exception('Invalid ID');

            $rental = $pdo->prepare("SELECT * FROM rental_motor_bookings WHERE id=? AND business_id=?");
            $rental->execute([$rentalId, $businessId]);
            $rentalRow = $rental->fetch(PDO::FETCH_ASSOC);
            if (!$rentalRow) throw new Exception('Rental tidak ditemukan');

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE rental_motor_bookings SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([$rentalId]);

            // Free up motor if it was rented for this booking
            $otherActive = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings WHERE motor_id=? AND status IN ('active','overdue') AND id!=?");
            $otherActive->execute([$rentalRow['motor_id'], $rentalId]);
            if ((int)$otherActive->fetchColumn() === 0) {
                $pdo->prepare("UPDATE rental_motors SET status='available', updated_at=NOW() WHERE id=?")->execute([$rentalRow['motor_id']]);
            }

            // Cancel invoice if exists
            if ($rentalRow['invoice_id']) {
                $pdo->prepare("UPDATE hotel_invoices SET status='cancelled', updated_at=NOW() WHERE id=? AND cashbook_synced=0")
                    ->execute([$rentalRow['invoice_id']]);
            }

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── ADD TO EXISTING INVOICE ─────────────────────────────────────────
        if ($action === 'add_to_invoice') {
            $rentalId  = (int)($_POST['rental_id'] ?? 0);
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            if (!$rentalId || !$invoiceId) throw new Exception('Data tidak lengkap');

            $rental = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name
                FROM rental_motor_bookings rb
                JOIN rental_motors rm ON rb.motor_id = rm.id
                WHERE rb.id=? AND rb.business_id=?");
            $rental->execute([$rentalId, $businessId]);
            $rentalRow = $rental->fetch(PDO::FETCH_ASSOC);
            if (!$rentalRow) throw new Exception('Rental tidak ditemukan');

            // Verify invoice exists
            $inv = $pdo->prepare("SELECT * FROM hotel_invoices WHERE id=? AND business_id=? AND cashbook_synced=0");
            $inv->execute([$invoiceId, $businessId]);
            if (!$inv->fetch()) throw new Exception('Invoice tidak ditemukan atau sudah diproses');

            $start = new DateTime($rentalRow['start_datetime']);
            $end   = new DateTime($rentalRow['end_datetime']);
            $days  = max(1, (int)ceil($start->diff($end)->days));

            $pdo->beginTransaction();

            // Add item to invoice
            $pdo->prepare("INSERT INTO hotel_invoice_items
                (invoice_id, service_type, description, quantity, unit_price, total_price, start_datetime, end_datetime)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$invoiceId, 'motor_rental',
                    "{$rentalRow['motor_name']} ({$rentalRow['plate_number']})",
                    $days, $rentalRow['daily_rate'], $rentalRow['total_price'],
                    $rentalRow['start_datetime'], $rentalRow['end_datetime']]);

            // Update invoice total
            $pdo->prepare("UPDATE hotel_invoices SET total = total + ?, updated_at=NOW() WHERE id=?")
                ->execute([$rentalRow['total_price'], $invoiceId]);

            // Link rental to invoice
            $pdo->prepare("UPDATE rental_motor_bookings SET invoice_id=?, updated_at=NOW() WHERE id=?")
                ->execute([$invoiceId, $rentalId]);

            $pdo->commit();
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

// ── Fetch Data ─────────────────────────────────────────────────────────────────
// Motors fleet
$motors = $pdo->prepare("SELECT * FROM rental_motors WHERE business_id=? ORDER BY status ASC, motor_name ASC");
$motors->execute([$businessId]);
$motorList = $motors->fetchAll(PDO::FETCH_ASSOC);

// Active & all rentals
$filterRentalStatus = $_GET['rs'] ?? '';
$filterSearch       = trim($_GET['q'] ?? '');

$rwhere  = ["rb.business_id = ?"];
$rparams = [$businessId];
if ($filterRentalStatus) { $rwhere[] = "rb.status = ?"; $rparams[] = $filterRentalStatus; }
if ($filterSearch) {
    $rwhere[] = "(rb.guest_name LIKE ? OR rm.plate_number LIKE ? OR rm.motor_name LIKE ?)";
    $rparams[] = "%{$filterSearch}%";
    $rparams[] = "%{$filterSearch}%";
    $rparams[] = "%{$filterSearch}%";
}

$rentalStmt = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name, rm.color as motor_color,
    hi.invoice_number, hi.payment_status as inv_pay_status
    FROM rental_motor_bookings rb
    JOIN rental_motors rm ON rb.motor_id = rm.id
    LEFT JOIN hotel_invoices hi ON rb.invoice_id = hi.id
    WHERE " . implode(' AND ', $rwhere) . "
    ORDER BY FIELD(rb.status,'active','overdue','returned','cancelled'), rb.start_datetime DESC
    LIMIT 200");
$rentalStmt->execute($rparams);
$rentals = $rentalStmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$totalMotors     = count($motorList);
$availableMotors = count(array_filter($motorList, fn($m) => $m['status'] === 'available'));
$rentedMotors    = count(array_filter($motorList, fn($m) => $m['status'] === 'rented'));
$maintenanceMotors = count(array_filter($motorList, fn($m) => $m['status'] === 'maintenance'));

$revenueStmt = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) as revenue, COUNT(*) as total_rentals
    FROM rental_motor_bookings WHERE business_id=? AND status IN ('active','returned','overdue')
    AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())");
$revenueStmt->execute([$businessId]);
$revStats = $revenueStmt->fetch(PDO::FETCH_ASSOC);

$activeRentals = count(array_filter($rentals, fn($r) => in_array($r['status'], ['active','overdue'])));

// In-house guests for guest picker
try {
    $inHouseGuests = $pdo->query("SELECT b.id as booking_id, g.guest_name, r.room_number, g.phone
        FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in' ORDER BY r.room_number ASC LIMIT 100")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $inHouseGuests = []; }

// Existing open invoices for "add to invoice" feature
try {
    $openInvoices = $pdo->prepare("SELECT id, invoice_number, guest_name, room_number, total
        FROM hotel_invoices WHERE business_id=? AND cashbook_synced=0 AND status NOT IN ('cancelled')
        ORDER BY created_at DESC LIMIT 50");
    $openInvoices->execute([$businessId]);
    $openInvoiceList = $openInvoices->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $openInvoiceList = []; }

include '../../includes/header.php';
?>
<style>
.rm-page { padding: 1.25rem; }
.rm-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.75rem; }
.rm-topbar h2 { font-size:1.2rem; font-weight:700; color:var(--text-primary); margin:0; }
.rm-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:0.75rem; margin-bottom:1.25rem; }
.rm-stat { background:white; border-radius:10px; padding:0.85rem 1rem; box-shadow:0 1px 4px rgba(0,0,0,0.07); border-top:3px solid var(--c); }
.rm-stat .val { font-size:1.25rem; font-weight:800; color:var(--c); }
.rm-stat .lbl { font-size:0.72rem; color:var(--text-secondary); margin-top:0.15rem; }
/* Fleet grid */
.rm-fleet { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:0.75rem; margin-bottom:1.25rem; }
.rm-motor-card { background:white; border-radius:10px; padding:0.9rem; box-shadow:0 1px 4px rgba(0,0,0,0.07); border-left:4px solid var(--mc); position:relative; transition:transform 0.15s; }
.rm-motor-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.1); }
.rm-motor-card .mc-plate { font-size:0.9rem; font-weight:800; color:#1e293b; }
.rm-motor-card .mc-name { font-size:0.78rem; color:var(--text-secondary); margin-top:0.15rem; }
.rm-motor-card .mc-rate { font-size:0.75rem; color:#6366f1; font-weight:600; margin-top:0.3rem; }
.rm-motor-card .mc-status { position:absolute; top:0.65rem; right:0.75rem; display:inline-block; padding:0.15rem 0.5rem; border-radius:20px; font-size:0.68rem; font-weight:600; color:white; }
.rm-motor-card .mc-actions { display:flex; gap:0.35rem; margin-top:0.6rem; }
.mc-btn { padding:0.2rem 0.5rem; border:none; border-radius:5px; font-size:0.7rem; font-weight:600; cursor:pointer; transition:opacity 0.2s; }
.mc-btn:hover { opacity:0.8; }
/* Filters */
.rm-filters { background:white; border-radius:10px; padding:0.85rem 1rem; box-shadow:0 1px 4px rgba(0,0,0,0.07); margin-bottom:1rem; display:flex; flex-wrap:wrap; gap:0.6rem; align-items:center; }
.rm-filters input, .rm-filters select { padding:0.4rem 0.6rem; border:1px solid #e2e8f0; border-radius:6px; font-size:0.8rem; background:white; color:var(--text-primary); }
/* Table */
.rm-table-wrap { background:white; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.07); overflow:hidden; margin-bottom:1.25rem; }
.rm-table { width:100%; border-collapse:collapse; font-size:0.8rem; }
.rm-table th { background:#f8fafc; padding:0.65rem 0.85rem; text-align:left; font-weight:600; color:var(--text-secondary); font-size:0.72rem; text-transform:uppercase; letter-spacing:0.03em; border-bottom:1px solid #e2e8f0; }
.rm-table td { padding:0.65rem 0.85rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.rm-table tr:last-child td { border-bottom:none; }
.rm-table tr:hover td { background:#fafbff; }
.rm-badge { display:inline-block; padding:0.2rem 0.55rem; border-radius:20px; font-size:0.7rem; font-weight:600; color:white; }
.rm-action-btn { padding:0.25rem 0.55rem; border:none; border-radius:5px; cursor:pointer; font-size:0.72rem; font-weight:600; transition:opacity 0.2s; }
.rm-action-btn:hover { opacity:0.8; }
/* Modal */
.rm-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:99999; align-items:center; justify-content:center; padding:1rem; }
.rm-modal-overlay.open { display:flex; }
.rm-modal { background:white; border-radius:14px; padding:1.5rem; width:100%; max-width:560px; max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3); }
.rm-modal h3 { margin:0 0 1rem; font-size:1.05rem; font-weight:700; }
.rm-form-row { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.75rem; }
.rm-form-row.full { grid-template-columns:1fr; }
.rm-field label { display:block; font-size:0.75rem; font-weight:600; color:var(--text-secondary); margin-bottom:0.3rem; }
.rm-field input, .rm-field select, .rm-field textarea { width:100%; padding:0.5rem 0.65rem; border:1px solid #e2e8f0; border-radius:7px; font-size:0.85rem; color:var(--text-primary); background:white; box-sizing:border-box; }
.rm-field textarea { resize:vertical; min-height:55px; }
.rm-field input:focus, .rm-field select:focus, .rm-field textarea:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,0.15); }
.rm-modal-footer { display:flex; justify-content:flex-end; gap:0.6rem; margin-top:1rem; }
.btn-rm { padding:0.5rem 1.25rem; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.85rem; }
.btn-rm-primary { background:var(--primary,#6366f1); color:white; }
.btn-rm-secondary { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
.btn-rm-success { background:#10b981; color:white; }
.btn-rm-danger { background:#ef4444; color:white; }
.rm-empty { text-align:center; padding:3rem 1rem; color:var(--text-secondary); }
.rm-empty .em-icon { font-size:2.5rem; margin-bottom:0.5rem; }
/* Guest toggle */
.guest-toggle { display:flex; gap:0.4rem; margin-bottom:0.6rem; }
.guest-toggle button { flex:1; padding:0.4rem 0.6rem; border:2px solid #e2e8f0; border-radius:7px; background:white; font-size:0.78rem; font-weight:600; cursor:pointer; transition:all 0.15s; color:#374151; }
.guest-toggle button.active { border-color:#6366f1; background:#ede9fe; color:#4c1d95; }
/* Section labels */
.rm-section { font-size:0.78rem; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.04em; margin:1.25rem 0 0.6rem; display:flex; align-items:center; gap:0.4rem; }
/* Overdue pulse */
.rm-overdue-pulse { animation: overduePulse 2s ease-in-out infinite; }
@keyframes overduePulse { 0%,100% { opacity:1; } 50% { opacity:0.6; } }
/* Tabs */
.rm-tabs { display:flex; border-bottom:2px solid #e2e8f0; margin-bottom:1rem; }
.rm-tab { padding:0.5rem 1rem; font-size:0.82rem; font-weight:600; cursor:pointer; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-2px; background:none; border-top:none; border-left:none; border-right:none; }
.rm-tab.active { color:#4338ca; border-bottom-color:#6366f1; }
.rm-tab-pane { display:none; }
.rm-tab-pane.active { display:block; }
.rm-total-preview { background:linear-gradient(135deg,#f0f4ff,#e8edff); border-radius:8px; padding:0.75rem 1rem; text-align:center; margin:0.75rem 0; font-size:1.1rem; font-weight:700; color:#4338ca; }
@media(max-width:580px) {
    .rm-form-row { grid-template-columns:1fr; }
    .rm-stats { grid-template-columns:repeat(2,1fr); }
    .rm-fleet { grid-template-columns:repeat(2,1fr); }
    .rm-table { font-size:0.72rem; }
    .rm-table th, .rm-table td { padding:0.5rem 0.5rem; }
}
</style>

<div class="rm-page">

    <!-- Top Bar -->
    <div class="rm-topbar">
        <div>
            <h2>🏍️ Monitoring Rental Motor</h2>
            <div style="font-size:0.75rem;color:var(--text-secondary)">
                Kelola armada motor & pantau penyewaan aktif
            </div>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <a href="hotel-services.php" class="btn-rm btn-rm-secondary" style="text-decoration:none;font-size:0.8rem;padding:0.4rem 0.8rem">
                ← Hotel Services
            </a>
            <button class="btn-rm btn-rm-secondary" onclick="openMotorModal()" style="font-size:0.8rem;padding:0.4rem 0.8rem">+ Tambah Motor</button>
            <button class="btn-rm btn-rm-primary" onclick="openRentalModal()" style="font-size:0.8rem;padding:0.4rem 0.8rem">+ Sewa Baru</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="rm-stats">
        <div class="rm-stat" style="--c:#6366f1"><div class="val"><?php echo $totalMotors; ?></div><div class="lbl">Total Motor</div></div>
        <div class="rm-stat" style="--c:#10b981"><div class="val"><?php echo $availableMotors; ?></div><div class="lbl">Tersedia</div></div>
        <div class="rm-stat" style="--c:#f59e0b"><div class="val"><?php echo $rentedMotors; ?></div><div class="lbl">Disewa</div></div>
        <div class="rm-stat" style="--c:#ef4444"><div class="val"><?php echo $activeRentals; ?></div><div class="lbl">Rental Aktif</div></div>
        <div class="rm-stat" style="--c:#8b5cf6"><div class="val">Rp <?php echo number_format($revStats['revenue'],0,',','.'); ?></div><div class="lbl">Revenue Bulan Ini</div></div>
        <div class="rm-stat" style="--c:#06b6d4"><div class="val"><?php echo $revStats['total_rentals']; ?></div><div class="lbl">Transaksi Bulan Ini</div></div>
    </div>

    <!-- Tabs -->
    <div class="rm-tabs">
        <button class="rm-tab active" id="tab-monitoring" onclick="switchTab('monitoring')">📊 Monitoring</button>
        <button class="rm-tab" id="tab-fleet" onclick="switchTab('fleet')">🏍️ Armada Motor</button>
        <button class="rm-tab" id="tab-history" onclick="switchTab('history')">📋 Riwayat</button>
    </div>

    <!-- TAB: Monitoring (Active Rentals) -->
    <div class="rm-tab-pane active" id="pane-monitoring">
        <?php
        $activeRentalsList = array_filter($rentals, fn($r) => in_array($r['status'], ['active','overdue']));
        if (empty($activeRentalsList)):
        ?>
        <div class="rm-empty">
            <div class="em-icon">🏍️</div>
            <p>Tidak ada rental aktif saat ini</p>
        </div>
        <?php else: ?>
        <div class="rm-table-wrap">
            <table class="rm-table">
                <thead>
                    <tr>
                        <th>Motor</th>
                        <th>Tamu</th>
                        <th>Kamar</th>
                        <th>Mulai</th>
                        <th>Kembali</th>
                        <th>Sisa Waktu</th>
                        <th>Harga</th>
                        <th>Invoice</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeRentalsList as $r):
                        $now     = new DateTime();
                        $endDt   = new DateTime($r['end_datetime']);
                        $isOverdue = $r['status'] === 'overdue';
                        $diff    = $now->diff($endDt);
                        if ($isOverdue) {
                            $remaining = "Terlambat " . $diff->days . "h " . $diff->h . "j";
                        } else {
                            $remaining = $diff->days . "h " . $diff->h . "j " . $diff->i . "m";
                        }
                    ?>
                    <tr class="<?php echo $isOverdue ? 'rm-overdue-pulse' : ''; ?>">
                        <td>
                            <div style="font-weight:700;font-size:0.82rem"><?php echo htmlspecialchars($r['plate_number']); ?></div>
                            <div style="font-size:0.72rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['motor_name']); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:600"><?php echo htmlspecialchars($r['guest_name']); ?></div>
                            <?php if ($r['guest_phone']): ?>
                            <div style="font-size:0.7rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['guest_phone']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($r['room_number'] ?? '-'); ?></td>
                        <td style="font-size:0.75rem"><?php echo date('d M H:i', strtotime($r['start_datetime'])); ?></td>
                        <td style="font-size:0.75rem"><?php echo date('d M H:i', strtotime($r['end_datetime'])); ?></td>
                        <td>
                            <span style="font-weight:700;color:<?php echo $isOverdue ? '#ef4444' : '#10b981'; ?>;font-size:0.78rem">
                                <?php echo $remaining; ?>
                            </span>
                        </td>
                        <td style="font-weight:600">Rp <?php echo number_format($r['total_price'],0,',','.'); ?></td>
                        <td>
                            <?php if ($r['invoice_number']): ?>
                            <a href="hotel-service-invoice.php?id=<?php echo $r['invoice_id']; ?>" target="_blank"
                               style="color:#6366f1;font-weight:600;font-size:0.75rem;text-decoration:none">
                                <?php echo htmlspecialchars($r['invoice_number']); ?>
                            </a>
                            <?php if ($r['inv_pay_status']): ?>
                            <span class="rm-badge" style="background:<?php echo ['unpaid'=>'#ef4444','partial'=>'#f59e0b','paid'=>'#10b981'][$r['inv_pay_status']] ?? '#6b7280'; ?>;font-size:0.62rem">
                                <?php echo $r['inv_pay_status']; ?>
                            </span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="color:var(--text-secondary);font-size:0.72rem">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="rm-badge <?php echo $isOverdue ? 'rm-overdue-pulse' : ''; ?>"
                                  style="background:<?php echo $isOverdue ? '#ef4444' : '#10b981'; ?>">
                                <?php echo $isOverdue ? '⚠ Overdue' : '✓ Aktif'; ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap">
                            <button class="rm-action-btn" style="background:#dcfce7;color:#15803d" onclick="returnMotor(<?php echo $r['id']; ?>,'<?php echo htmlspecialchars(addslashes($r['motor_name'])); ?>')">
                                ↩ Kembali
                            </button>
                            <?php if (!$r['invoice_id']): ?>
                            <button class="rm-action-btn" style="background:#e0e7ff;color:#4338ca" onclick="openAddToInvoice(<?php echo $r['id']; ?>)">
                                📄 Invoice
                            </button>
                            <?php endif; ?>
                            <button class="rm-action-btn" style="background:#fee2e2;color:#b91c1c" onclick="cancelRental(<?php echo $r['id']; ?>)">✕</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAB: Fleet -->
    <div class="rm-tab-pane" id="pane-fleet">
        <?php if (empty($motorList)): ?>
        <div class="rm-empty">
            <div class="em-icon">🏍️</div>
            <p>Belum ada motor terdaftar</p>
            <button class="btn-rm btn-rm-primary" onclick="openMotorModal()" style="margin-top:0.5rem">+ Tambah Motor</button>
        </div>
        <?php else: ?>
        <div class="rm-fleet">
            <?php
            $statusColors = ['available'=>'#10b981','rented'=>'#f59e0b','maintenance'=>'#6b7280'];
            $statusLabels = ['available'=>'Tersedia','rented'=>'Disewa','maintenance'=>'Maint.'];
            foreach ($motorList as $m):
                $mc = $statusColors[$m['status']] ?? '#6b7280';
            ?>
            <div class="rm-motor-card" style="--mc:<?php echo $mc; ?>">
                <span class="mc-status" style="background:<?php echo $mc; ?>"><?php echo $statusLabels[$m['status']] ?? $m['status']; ?></span>
                <div class="mc-plate"><?php echo htmlspecialchars($m['plate_number']); ?></div>
                <div class="mc-name">
                    <?php echo htmlspecialchars($m['motor_name']); ?>
                    <?php if ($m['color']): ?><span style="color:var(--text-secondary)"> · <?php echo htmlspecialchars($m['color']); ?></span><?php endif; ?>
                    <?php if ($m['year']): ?><span style="color:var(--text-secondary)"> · <?php echo $m['year']; ?></span><?php endif; ?>
                </div>
                <div class="mc-rate">Rp <?php echo number_format($m['daily_rate'],0,',','.'); ?> / hari</div>
                <?php if ($m['notes']): ?>
                <div style="font-size:0.7rem;color:var(--text-secondary);margin-top:0.2rem"><?php echo htmlspecialchars(substr($m['notes'],0,60)); ?></div>
                <?php endif; ?>
                <div class="mc-actions">
                    <button class="mc-btn" style="background:#e0e7ff;color:#4338ca" onclick="editMotor(<?php echo htmlspecialchars(json_encode($m)); ?>)">✏️ Edit</button>
                    <?php if ($m['status'] === 'available'): ?>
                    <button class="mc-btn" style="background:#dcfce7;color:#15803d" onclick="openRentalModal(<?php echo $m['id']; ?>)">🔑 Sewakan</button>
                    <?php endif; ?>
                    <?php if ($m['status'] !== 'rented'): ?>
                    <button class="mc-btn" style="background:#fee2e2;color:#b91c1c" onclick="deleteMotor(<?php echo $m['id']; ?>,'<?php echo htmlspecialchars(addslashes($m['plate_number'])); ?>')">🗑️</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAB: History -->
    <div class="rm-tab-pane" id="pane-history">
        <form method="GET" class="rm-filters">
            <input type="text" name="q" placeholder="🔍 Cari tamu / plat..." value="<?php echo htmlspecialchars($filterSearch); ?>">
            <select name="rs">
                <option value="">Semua Status</option>
                <?php foreach (['active'=>'Aktif','overdue'=>'Overdue','returned'=>'Dikembalikan','cancelled'=>'Dibatalkan'] as $sk=>$sl): ?>
                <option value="<?php echo $sk; ?>" <?php echo $filterRentalStatus===$sk?'selected':''; ?>><?php echo $sl; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-rm btn-rm-primary" style="padding:0.4rem 0.9rem;font-size:0.8rem">Filter</button>
            <?php if ($filterRentalStatus || $filterSearch): ?>
            <a href="rental-motor.php" class="btn-rm btn-rm-secondary" style="padding:0.4rem 0.9rem;font-size:0.8rem;text-decoration:none">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($rentals)): ?>
        <div class="rm-empty">
            <div class="em-icon">📋</div>
            <p>Belum ada data rental</p>
        </div>
        <?php else: ?>
        <div class="rm-table-wrap">
            <table class="rm-table">
                <thead>
                    <tr>
                        <th>Motor</th>
                        <th>Tamu</th>
                        <th>Kamar</th>
                        <th>Mulai</th>
                        <th>Selesai</th>
                        <th>Hari</th>
                        <th>Total</th>
                        <th>Deposit</th>
                        <th>Invoice</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rStatusColors = ['active'=>'#10b981','overdue'=>'#ef4444','returned'=>'#6b7280','cancelled'=>'#94a3b8'];
                    $rStatusLabels = ['active'=>'Aktif','overdue'=>'Overdue','returned'=>'Kembali','cancelled'=>'Batal'];
                    foreach ($rentals as $r):
                        $start = new DateTime($r['start_datetime']);
                        $end   = new DateTime($r['end_datetime']);
                        $days  = max(1, (int)ceil($start->diff($end)->days));
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;font-size:0.82rem"><?php echo htmlspecialchars($r['plate_number']); ?></div>
                            <div style="font-size:0.72rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['motor_name']); ?></div>
                        </td>
                        <td style="font-weight:600"><?php echo htmlspecialchars($r['guest_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['room_number'] ?? '-'); ?></td>
                        <td style="font-size:0.75rem"><?php echo date('d M Y H:i', strtotime($r['start_datetime'])); ?></td>
                        <td style="font-size:0.75rem">
                            <?php echo date('d M Y H:i', strtotime($r['end_datetime'])); ?>
                            <?php if ($r['actual_return']): ?>
                            <div style="font-size:0.68rem;color:#10b981">↩ <?php echo date('d M H:i', strtotime($r['actual_return'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-weight:600"><?php echo $days; ?></td>
                        <td style="font-weight:600">Rp <?php echo number_format($r['total_price'],0,',','.'); ?></td>
                        <td>Rp <?php echo number_format($r['deposit'],0,',','.'); ?></td>
                        <td>
                            <?php if ($r['invoice_number']): ?>
                            <a href="hotel-service-invoice.php?id=<?php echo $r['invoice_id']; ?>" target="_blank"
                               style="color:#6366f1;font-weight:600;font-size:0.75rem;text-decoration:none">
                                <?php echo htmlspecialchars($r['invoice_number']); ?>
                            </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <span class="rm-badge" style="background:<?php echo $rStatusColors[$r['status']] ?? '#6b7280'; ?>">
                                <?php echo $rStatusLabels[$r['status']] ?? $r['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Add/Edit Motor -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="rm-modal-overlay" id="motorModal" onclick="if(event.target===this)closeMotorModal()">
    <div class="rm-modal">
        <h3 id="motorModalTitle">Tambah Motor</h3>
        <input type="hidden" id="fm_id" value="0">
        <div class="rm-form-row">
            <div class="rm-field">
                <label>Plat Nomor *</label>
                <input type="text" id="fm_plate" placeholder="AB 1234 CD" style="text-transform:uppercase">
            </div>
            <div class="rm-field">
                <label>Nama Motor *</label>
                <input type="text" id="fm_name" placeholder="Honda Vario 125">
            </div>
        </div>
        <div class="rm-form-row">
            <div class="rm-field">
                <label>Warna</label>
                <input type="text" id="fm_color" placeholder="Hitam">
            </div>
            <div class="rm-field">
                <label>Tahun</label>
                <input type="number" id="fm_year" placeholder="2024" min="2000" max="2030">
            </div>
        </div>
        <div class="rm-form-row">
            <div class="rm-field">
                <label>Tarif per Hari (Rp) *</label>
                <input type="number" id="fm_rate" placeholder="100000" min="0">
            </div>
            <div class="rm-field">
                <label>Status</label>
                <select id="fm_status">
                    <option value="available">Tersedia</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
        </div>
        <div class="rm-form-row full">
            <div class="rm-field">
                <label>Catatan</label>
                <textarea id="fm_notes" placeholder="Catatan tambahan..."></textarea>
            </div>
        </div>
        <div class="rm-modal-footer">
            <button class="btn-rm btn-rm-secondary" onclick="closeMotorModal()">Batal</button>
            <button class="btn-rm btn-rm-primary" onclick="saveMotor()">💾 Simpan</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: New Rental -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="rm-modal-overlay" id="rentalModal" onclick="if(event.target===this)closeRentalModal()">
    <div class="rm-modal" style="max-width:620px">
        <h3>🔑 Sewa Motor Baru</h3>

        <!-- Guest Toggle -->
        <div class="guest-toggle">
            <button class="active" onclick="toggleGuestMode('inhouse',this)">🏨 Tamu In-House</button>
            <button onclick="toggleGuestMode('manual',this)">✏️ Input Manual</button>
        </div>

        <!-- In-house guest picker -->
        <div id="guestInhouse" style="margin-bottom:0.75rem">
            <div class="rm-field">
                <label>Pilih Tamu In-House</label>
                <select id="fr_guest_select" onchange="onGuestSelect()">
                    <option value="">-- Pilih Tamu --</option>
                    <?php foreach ($inHouseGuests as $ig): ?>
                    <option value="<?php echo $ig['booking_id']; ?>"
                            data-name="<?php echo htmlspecialchars($ig['guest_name']); ?>"
                            data-phone="<?php echo htmlspecialchars($ig['phone'] ?? ''); ?>"
                            data-room="<?php echo htmlspecialchars($ig['room_number'] ?? ''); ?>">
                        <?php echo htmlspecialchars(($ig['room_number'] ? "#{$ig['room_number']} - " : '') . $ig['guest_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Manual guest input -->
        <div id="guestManual" style="display:none">
            <div class="rm-form-row">
                <div class="rm-field">
                    <label>Nama Tamu *</label>
                    <input type="text" id="fr_guest_name" placeholder="Nama lengkap">
                </div>
                <div class="rm-field">
                    <label>No. HP</label>
                    <input type="text" id="fr_guest_phone" placeholder="08xxxxxxxxxx">
                </div>
            </div>
            <div class="rm-form-row">
                <div class="rm-field">
                    <label>No. Kamar</label>
                    <input type="text" id="fr_room" placeholder="101">
                </div>
                <div class="rm-field"></div>
            </div>
        </div>

        <input type="hidden" id="fr_booking_id" value="">

        <div class="rm-form-row">
            <div class="rm-field">
                <label>Pilih Motor *</label>
                <select id="fr_motor" onchange="onMotorSelect()">
                    <option value="">-- Pilih Motor --</option>
                    <?php foreach ($motorList as $m): if ($m['status'] !== 'available') continue; ?>
                    <option value="<?php echo $m['id']; ?>" data-rate="<?php echo $m['daily_rate']; ?>">
                        <?php echo htmlspecialchars("{$m['plate_number']} - {$m['motor_name']}"); ?>
                        (Rp <?php echo number_format($m['daily_rate'],0,',','.'); ?>/hari)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rm-field">
                <label>Tarif / Hari (Rp)</label>
                <input type="number" id="fr_rate" placeholder="0" min="0" onchange="calcRentalTotal()">
            </div>
        </div>

        <div class="rm-form-row">
            <div class="rm-field">
                <label>Tanggal Mulai *</label>
                <input type="datetime-local" id="fr_start" onchange="calcRentalTotal()">
            </div>
            <div class="rm-field">
                <label>Tanggal Kembali *</label>
                <input type="datetime-local" id="fr_end" onchange="calcRentalTotal()">
            </div>
        </div>

        <div class="rm-form-row">
            <div class="rm-field">
                <label>Deposit (Rp)</label>
                <input type="number" id="fr_deposit" placeholder="0" min="0" value="0">
            </div>
            <div class="rm-field" style="display:flex;align-items:flex-end;padding-bottom:0.15rem">
                <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
                    <input type="checkbox" id="fr_create_invoice" checked>
                    <span style="font-size:0.82rem;font-weight:600">Buat Invoice Otomatis</span>
                </label>
            </div>
        </div>

        <div class="rm-total-preview" id="rentalTotalPreview">
            Total: Rp 0 (0 hari)
        </div>

        <div class="rm-form-row full">
            <div class="rm-field">
                <label>Catatan</label>
                <textarea id="fr_notes" placeholder="Catatan tambahan..."></textarea>
            </div>
        </div>

        <div class="rm-modal-footer">
            <button class="btn-rm btn-rm-secondary" onclick="closeRentalModal()">Batal</button>
            <button class="btn-rm btn-rm-success" onclick="createRental()">🔑 Proses Sewa</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Add to Existing Invoice -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="rm-modal-overlay" id="addToInvModal" onclick="if(event.target===this)closeAddToInvModal()">
    <div class="rm-modal" style="max-width:480px">
        <h3>📄 Tambahkan ke Invoice</h3>
        <p style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:1rem">
            Gabungkan rental ini dengan invoice Hotel Service yang sudah ada
        </p>
        <input type="hidden" id="ati_rental_id" value="0">
        <div class="rm-field" style="margin-bottom:1rem">
            <label>Pilih Invoice</label>
            <select id="ati_invoice_id">
                <option value="">-- Pilih Invoice --</option>
                <?php foreach ($openInvoiceList as $oi): ?>
                <option value="<?php echo $oi['id']; ?>">
                    <?php echo htmlspecialchars("{$oi['invoice_number']} - {$oi['guest_name']}"); ?>
                    <?php if ($oi['room_number']): ?>(#<?php echo htmlspecialchars($oi['room_number']); ?>)<?php endif; ?>
                    — Rp <?php echo number_format($oi['total'],0,',','.'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rm-modal-footer">
            <button class="btn-rm btn-rm-secondary" onclick="closeAddToInvModal()">Batal</button>
            <button class="btn-rm btn-rm-primary" onclick="addToInvoice()">📄 Gabungkan</button>
        </div>
    </div>
</div>

<script>
// ── Tab switching ───────────────────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.rm-tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.rm-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('pane-' + name).classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
}

// ── Motor Modal ─────────────────────────────────────────────────────────────
function openMotorModal() {
    document.getElementById('fm_id').value = 0;
    document.getElementById('fm_plate').value = '';
    document.getElementById('fm_name').value = '';
    document.getElementById('fm_color').value = '';
    document.getElementById('fm_year').value = '';
    document.getElementById('fm_rate').value = '';
    document.getElementById('fm_status').value = 'available';
    document.getElementById('fm_notes').value = '';
    document.getElementById('motorModalTitle').textContent = 'Tambah Motor';
    document.getElementById('motorModal').classList.add('open');
}

function editMotor(m) {
    document.getElementById('fm_id').value = m.id;
    document.getElementById('fm_plate').value = m.plate_number;
    document.getElementById('fm_name').value = m.motor_name;
    document.getElementById('fm_color').value = m.color || '';
    document.getElementById('fm_year').value = m.year || '';
    document.getElementById('fm_rate').value = m.daily_rate;
    document.getElementById('fm_status').value = m.status;
    document.getElementById('fm_notes').value = m.notes || '';
    document.getElementById('motorModalTitle').textContent = 'Edit Motor';
    document.getElementById('motorModal').classList.add('open');
}

function closeMotorModal() {
    document.getElementById('motorModal').classList.remove('open');
}

function saveMotor() {
    const fd = new FormData();
    fd.append('action', 'save_motor');
    fd.append('motor_id', document.getElementById('fm_id').value);
    fd.append('plate_number', document.getElementById('fm_plate').value);
    fd.append('motor_name', document.getElementById('fm_name').value);
    fd.append('color', document.getElementById('fm_color').value);
    fd.append('year', document.getElementById('fm_year').value);
    fd.append('daily_rate', document.getElementById('fm_rate').value);
    fd.append('motor_status', document.getElementById('fm_status').value);
    fd.append('notes', document.getElementById('fm_notes').value);

    fetch('rental-motor.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                closeMotorModal();
                location.reload();
            } else {
                alert(d.message || 'Gagal menyimpan');
            }
        })
        .catch(() => alert('Network error'));
}

function deleteMotor(id, plate) {
    if (!confirm('Hapus motor ' + plate + '?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_motor');
    fd.append('motor_id', id);
    fetch('rental-motor.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) location.reload();
            else alert(d.message || 'Gagal menghapus');
        })
        .catch(() => alert('Network error'));
}

// ── Rental Modal ────────────────────────────────────────────────────────────
function openRentalModal(preselectedMotorId) {
    // Reset form
    document.getElementById('fr_guest_select').value = '';
    document.getElementById('fr_guest_name').value = '';
    document.getElementById('fr_guest_phone').value = '';
    document.getElementById('fr_room').value = '';
    document.getElementById('fr_booking_id').value = '';
    document.getElementById('fr_motor').value = preselectedMotorId || '';
    document.getElementById('fr_rate').value = '';
    document.getElementById('fr_deposit').value = '0';
    document.getElementById('fr_notes').value = '';
    document.getElementById('fr_create_invoice').checked = true;

    // Set default dates
    const now = new Date();
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('fr_start').value = formatDateTimeLocal(now);
    document.getElementById('fr_end').value = formatDateTimeLocal(tomorrow);

    if (preselectedMotorId) {
        onMotorSelect();
    }
    calcRentalTotal();
    document.getElementById('rentalModal').classList.add('open');
}

function closeRentalModal() {
    document.getElementById('rentalModal').classList.remove('open');
}

function formatDateTimeLocal(d) {
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' +
        String(d.getDate()).padStart(2,'0') + 'T' + String(d.getHours()).padStart(2,'0') + ':' +
        String(d.getMinutes()).padStart(2,'0');
}

function toggleGuestMode(mode, btn) {
    document.querySelectorAll('.guest-toggle button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('guestInhouse').style.display = mode === 'inhouse' ? 'block' : 'none';
    document.getElementById('guestManual').style.display = mode === 'manual' ? 'block' : 'none';
}

function onGuestSelect() {
    const sel = document.getElementById('fr_guest_select');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('fr_guest_name').value = opt.dataset.name || '';
        document.getElementById('fr_guest_phone').value = opt.dataset.phone || '';
        document.getElementById('fr_room').value = opt.dataset.room || '';
        document.getElementById('fr_booking_id').value = opt.value;
    }
}

function onMotorSelect() {
    const sel = document.getElementById('fr_motor');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('fr_rate').value = opt.dataset.rate || 0;
    }
    calcRentalTotal();
}

function calcRentalTotal() {
    const rate  = parseFloat(document.getElementById('fr_rate').value) || 0;
    const start = new Date(document.getElementById('fr_start').value);
    const end   = new Date(document.getElementById('fr_end').value);
    let days = 0, total = 0;
    if (start && end && end > start) {
        days = Math.max(1, Math.ceil((end - start) / (1000 * 60 * 60 * 24)));
        total = days * rate;
    }
    document.getElementById('rentalTotalPreview').textContent =
        'Total: Rp ' + total.toLocaleString('id-ID') + ' (' + days + ' hari)';
}

function createRental() {
    // Determine guest name from in-house or manual
    const inhouseMode = document.getElementById('guestInhouse').style.display !== 'none';
    let guestName = document.getElementById('fr_guest_name').value;
    if (inhouseMode) {
        const sel = document.getElementById('fr_guest_select');
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) guestName = opt.dataset.name;
    }

    const fd = new FormData();
    fd.append('action', 'create_rental');
    fd.append('motor_id', document.getElementById('fr_motor').value);
    fd.append('guest_name', guestName);
    fd.append('guest_phone', document.getElementById('fr_guest_phone').value);
    fd.append('room_number', document.getElementById('fr_room').value);
    fd.append('booking_id', document.getElementById('fr_booking_id').value);
    fd.append('start_datetime', document.getElementById('fr_start').value);
    fd.append('end_datetime', document.getElementById('fr_end').value);
    fd.append('daily_rate', document.getElementById('fr_rate').value);
    fd.append('deposit', document.getElementById('fr_deposit').value);
    fd.append('notes', document.getElementById('fr_notes').value);
    if (document.getElementById('fr_create_invoice').checked) {
        fd.append('create_invoice', '1');
    }

    fetch('rental-motor.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                closeRentalModal();
                if (d.invoice_id) {
                    if (confirm('Rental berhasil dibuat! Buka invoice?')) {
                        window.open('hotel-service-invoice.php?id=' + d.invoice_id, '_blank');
                    }
                } else {
                    alert('Rental berhasil dibuat!');
                }
                location.reload();
            } else {
                alert(d.message || 'Gagal membuat rental');
            }
        })
        .catch(() => alert('Network error'));
}

// ── Return Motor ────────────────────────────────────────────────────────────
function returnMotor(rentalId, motorName) {
    if (!confirm('Konfirmasi pengembalian motor ' + motorName + '?')) return;
    const fd = new FormData();
    fd.append('action', 'return_motor');
    fd.append('rental_id', rentalId);
    fetch('rental-motor.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                let msg = 'Motor berhasil dikembalikan!';
                if (d.actual_days && d.new_total) {
                    msg += '\nTotal: Rp ' + parseFloat(d.new_total).toLocaleString('id-ID') + ' (' + d.actual_days + ' hari)';
                }
                alert(msg);
                location.reload();
            } else {
                alert(d.message || 'Gagal');
            }
        })
        .catch(() => alert('Network error'));
}

// ── Cancel Rental ───────────────────────────────────────────────────────────
function cancelRental(rentalId) {
    if (!confirm('Yakin batalkan rental ini?')) return;
    const fd = new FormData();
    fd.append('action', 'cancel_rental');
    fd.append('rental_id', rentalId);
    fetch('rental-motor.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { alert('Rental dibatalkan'); location.reload(); }
            else alert(d.message || 'Gagal');
        })
        .catch(() => alert('Network error'));
}

// ── Add to Invoice ──────────────────────────────────────────────────────────
function openAddToInvoice(rentalId) {
    document.getElementById('ati_rental_id').value = rentalId;
    document.getElementById('ati_invoice_id').value = '';
    document.getElementById('addToInvModal').classList.add('open');
}

function closeAddToInvModal() {
    document.getElementById('addToInvModal').classList.remove('open');
}

function addToInvoice() {
    const rentalId  = document.getElementById('ati_rental_id').value;
    const invoiceId = document.getElementById('ati_invoice_id').value;
    if (!invoiceId) { alert('Pilih invoice terlebih dahulu'); return; }

    const fd = new FormData();
    fd.append('action', 'add_to_invoice');
    fd.append('rental_id', rentalId);
    fd.append('invoice_id', invoiceId);
    fetch('rental-motor.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert('Berhasil ditambahkan ke invoice!');
                closeAddToInvModal();
                location.reload();
            } else {
                alert(d.message || 'Gagal');
            }
        })
        .catch(() => alert('Network error'));
}

// ── Init: Feather Icons ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (typeof feather !== 'undefined') feather.replace();
});
</script>

<?php include '../../includes/footer.php'; ?>
