<?php
/**
 * EDIT BOOKING PAGE
 * Full edit form matching reservation form
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$bookingId = $_GET['id'] ?? null;

if (!$bookingId) {
    header('Location: reservasi.php');
    exit;
}

// Get booking details with guest info
$stmt = $pdo->prepare("
    SELECT b.*, g.guest_name, g.phone as guest_phone, g.email as guest_email, 
           COALESCE(g.id_card_number,'') as guest_id_number,
           r.room_number, rt.type_name, rt.base_price
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    JOIN rooms r ON b.room_id = r.id
    JOIN room_types rt ON r.room_type_id = rt.id
    WHERE b.id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header('Location: reservasi.php');
    exit;
}

// Get all rooms
$rooms = $db->fetchAll("
    SELECT r.id, r.room_number, rt.type_name, rt.base_price
    FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id
    WHERE r.status != 'maintenance'
    ORDER BY r.room_number
");

// Get booking sources from DB
$bookingSources = $db->fetchAll("SELECT source_key, source_name, source_type, fee_percent, icon FROM booking_sources WHERE is_active = 1 ORDER BY sort_order ASC");
if (!$bookingSources) {
    $bookingSources = [
        ['source_key'=>'walk_in','source_name'=>'Walk-in','source_type'=>'direct','fee_percent'=>0,'icon'=>'🚶'],
        ['source_key'=>'phone','source_name'=>'Phone','source_type'=>'direct','fee_percent'=>0,'icon'=>'📞'],
        ['source_key'=>'online','source_name'=>'Online','source_type'=>'direct','fee_percent'=>0,'icon'=>'🌐'],
        ['source_key'=>'agoda','source_name'=>'Agoda','source_type'=>'ota','fee_percent'=>15,'icon'=>'🅰️'],
        ['source_key'=>'booking','source_name'=>'Booking.com','source_type'=>'ota','fee_percent'=>12,'icon'=>'🅱️'],
        ['source_key'=>'tiket','source_name'=>'Tiket.com','source_type'=>'ota','fee_percent'=>10,'icon'=>'🎫'],
        ['source_key'=>'traveloka','source_name'=>'Traveloka','source_type'=>'ota','fee_percent'=>15,'icon'=>'✈️'],
        ['source_key'=>'airbnb','source_name'=>'Airbnb','source_type'=>'ota','fee_percent'=>3,'icon'=>'🏡'],
        ['source_key'=>'ota','source_name'=>'OTA Lainnya','source_type'=>'ota','fee_percent'=>10,'icon'=>'🌐'],
    ];
}

// Build OTA fees map for JS
$otaFees = [];
foreach ($bookingSources as $src) {
    $otaFees[$src['source_key']] = (float)$src['fee_percent'];
}

// Get total paid
$paidRow = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id = ?", [$bookingId]);
$totalPaid = (float)($paidRow['total'] ?? 0);

$pageTitle = 'Edit Booking';
include '../../includes/header.php';
?>

<style>
.edit-page { max-width: 680px; margin: 0 auto; padding: 1.5rem 1rem; }
.edit-card { background: var(--bg-primary, #fff); border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); overflow: hidden; }
.edit-card-header { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 1rem 1.5rem; }
.edit-card-header h2 { margin: 0; font-size: 1.2rem; }
.edit-card-header .booking-code { opacity: 0.85; font-size: 0.85rem; margin-top: 0.25rem; font-family: 'Courier New', monospace; }
.edit-card-body { padding: 1.5rem; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
.form-group { margin-bottom: 1rem; }
.form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; color: var(--text-secondary, #475569); }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 6px; font-size: 0.9rem; background: var(--bg-secondary, #f8fafc);
    color: var(--text-primary, #1e293b); box-sizing: border-box; transition: border-color 0.2s;
}
.form-group input:focus, .form-group select:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
.form-group textarea { min-height: 60px; resize: vertical; }

.price-box { background: rgba(99,102,241,0.04); border: 1px solid rgba(99,102,241,0.15); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
.price-line { display: flex; justify-content: space-between; align-items: center; padding: 0.35rem 0; font-size: 0.9rem; }
.price-line-total { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-top: 2px solid rgba(99,102,241,0.2); margin-top: 0.5rem; font-weight: 700; font-size: 1.05rem; }
.ota-fee-row { background: #fef3c7; padding: 0.5rem 0.75rem; border-radius: 6px; margin: 0.5rem 0; }

.status-badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; color: white; }
.status-pending { background: #f59e0b; }
.status-confirmed { background: #6366f1; }
.status-checked_in { background: #10b981; }

.discount-row { display: flex; align-items: center; gap: 0.5rem; }
.disc-type-btn { padding: 4px 10px; font-size: 0.75rem; border: 1px solid #6366f1; cursor: pointer; transition: all 0.2s; }
.disc-type-btn.active { background: #6366f1; color: white; }
.disc-type-btn:not(.active) { background: white; color: #6366f1; }

.btn-row { display: flex; gap: 1rem; margin-top: 1.5rem; }
.btn-save { flex: 1; padding: 0.75rem; background: #10b981; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; }
.btn-save:hover { background: #059669; transform: translateY(-1px); }
.btn-cancel { flex: 1; padding: 0.75rem; background: var(--bg-secondary, #f3f4f6); color: var(--text-secondary, #6b7280); border: 1px solid var(--border-color, #e5e7eb); border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; text-decoration: none; text-align: center; }
.btn-cancel:hover { background: #e5e7eb; }

.alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
.alert-success { background: rgba(16,185,129,0.1); color: #059669; border: 1px solid #d1fae5; }
.alert-error { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid #fee2e2; }

.paid-info { background: rgba(16,185,129,0.06); border: 1px solid rgba(16,185,129,0.2); border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.85rem; color: #059669; margin-bottom: 1rem; }
</style>

<div class="edit-page">
    <div class="edit-card">
        <div class="edit-card-header">
            <h2>✏️ Edit Reservasi</h2>
            <div class="booking-code"><?php echo htmlspecialchars($booking['booking_code']); ?> · 
                <span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo strtoupper(str_replace('_',' ',$booking['status'])); ?></span>
            </div>
        </div>
        
        <div class="edit-card-body">
            <div id="alertBox"></div>
            
            <?php if ($totalPaid > 0): ?>
            <div class="paid-info">
                💰 Sudah Dibayar: <strong>Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></strong>
                <?php if ($totalPaid >= $booking['final_price']): ?>
                    <span style="margin-left:0.5rem;background:#10b981;color:white;padding:2px 8px;border-radius:4px;font-size:0.7rem;">LUNAS</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <form id="editForm" onsubmit="return saveEdit(event)">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                
                <!-- GUEST INFO -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Tamu *</label>
                        <input type="text" name="guest_name" id="guestName" value="<?php echo htmlspecialchars($booking['guest_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Telepon</label>
                        <input type="text" name="guest_phone" id="guestPhone" value="<?php echo htmlspecialchars($booking['guest_phone'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="guest_email" value="<?php echo htmlspecialchars($booking['guest_email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>No. KTP/Paspor</label>
                        <input type="text" name="guest_id_number" value="<?php echo htmlspecialchars($booking['guest_id_number'] ?? ''); ?>">
                    </div>
                </div>
                
                <!-- DATES -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Check-in *</label>
                        <input type="date" name="check_in_date" id="checkIn" value="<?php echo $booking['check_in_date']; ?>" required onchange="recalculate()">
                    </div>
                    <div class="form-group">
                        <label>Check-out *</label>
                        <input type="date" name="check_out_date" id="checkOut" value="<?php echo $booking['check_out_date']; ?>" required onchange="recalculate()">
                    </div>
                </div>
                
                <!-- ROOM -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Kamar</label>
                        <select name="room_id" id="roomSelect" onchange="recalculate()">
                            <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo $room['id']; ?>" 
                                    data-price="<?php echo $room['base_price']; ?>"
                                    <?php echo $room['id'] == $booking['room_id'] ? 'selected' : ''; ?>>
                                Room <?php echo $room['room_number']; ?> - <?php echo $room['type_name']; ?> (Rp <?php echo number_format($room['base_price'],0,',','.'); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jumlah Tamu</label>
                        <input type="number" name="num_guests" min="1" max="10" value="<?php echo $booking['adults'] ?? 1; ?>">
                    </div>
                </div>
                
                <!-- SOURCE & PRICE -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Booking Source</label>
                        <select name="booking_source" id="bookingSource" onchange="recalculate()">
                            <?php 
                            $directSources = array_filter($bookingSources, fn($s) => $s['source_type'] === 'direct');
                            $otaSrc = array_filter($bookingSources, fn($s) => $s['source_type'] !== 'direct');
                            ?>
                            <optgroup label="Direct">
                                <?php foreach ($directSources as $src): ?>
                                <option value="<?php echo $src['source_key']; ?>" 
                                        <?php echo $src['source_key'] === $booking['booking_source'] ? 'selected' : ''; ?>>
                                    <?php echo $src['icon'] . ' ' . $src['source_name']; ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="OTA">
                                <?php foreach ($otaSrc as $src): ?>
                                <option value="<?php echo $src['source_key']; ?>"
                                        <?php echo $src['source_key'] === $booking['booking_source'] ? 'selected' : ''; ?>>
                                    <?php echo $src['icon'] . ' ' . $src['source_name'] . ' (fee ' . $src['fee_percent'] . '%)'; ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Harga/Malam (Rp)</label>
                        <input type="number" name="room_price" id="roomPrice" value="<?php echo $booking['room_price']; ?>" step="1000" onchange="recalculate()">
                    </div>
                </div>
                
                <!-- DISCOUNT -->
                <div class="form-group">
                    <label>Diskon</label>
                    <div class="discount-row">
                        <button type="button" class="disc-type-btn active" data-type="rp" onclick="setDiscType('rp')" style="border-radius:4px 0 0 4px;">Rp</button>
                        <button type="button" class="disc-type-btn" data-type="percent" onclick="setDiscType('percent')" style="border-radius:0 4px 4px 0;">%</button>
                        <input type="number" name="discount_value" id="discountValue" value="<?php echo $booking['discount']; ?>" min="0" style="flex:1;" onchange="recalculate()">
                        <input type="hidden" name="discount_type" id="discountType" value="rp">
                    </div>
                </div>
                
                <!-- SPECIAL REQUEST -->
                <div class="form-group">
                    <label>Catatan / Permintaan Khusus</label>
                    <textarea name="special_requests"><?php echo htmlspecialchars($booking['special_request'] ?? ''); ?></textarea>
                </div>
                
                <!-- PRICE SUMMARY -->
                <div class="price-box">
                    <div class="price-line">
                        <span>Malam:</span>
                        <strong id="dispNights"><?php echo $booking['total_nights']; ?></strong>
                    </div>
                    <div class="price-line">
                        <span>Subtotal:</span>
                        <strong id="dispSubtotal">Rp <?php echo number_format($booking['total_price'],0,',','.'); ?></strong>
                    </div>
                    <div class="price-line" id="discountRow" style="<?php echo $booking['discount'] > 0 ? '' : 'display:none'; ?>">
                        <span>Diskon:</span>
                        <strong id="dispDiscount" style="color:#ef4444;">- Rp <?php echo number_format($booking['discount'],0,',','.'); ?></strong>
                    </div>
                    <div class="price-line ota-fee-row" id="otaFeeRow" style="display:none;">
                        <span style="color:#92400e;">Fee OTA (<span id="dispFeePercent">0</span>%):</span>
                        <strong id="dispFeeAmount" style="color:#dc2626;">- Rp 0</strong>
                    </div>
                    <div class="price-line-total">
                        <span>TOTAL (Net):</span>
                        <strong id="dispTotal" style="color:#10b981;">Rp <?php echo number_format($booking['final_price'],0,',','.'); ?></strong>
                    </div>
                </div>
                
                <div class="btn-row">
                    <button type="submit" class="btn-save" id="btnSave">💾 Simpan Perubahan</button>
                    <a href="reservasi.php" class="btn-cancel">❌ Batal</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const OTA_FEES = <?php echo json_encode($otaFees); ?>;
let discountType = 'rp';

function setDiscType(type) {
    discountType = type;
    document.getElementById('discountType').value = type;
    document.querySelectorAll('.disc-type-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.type === type);
    });
    recalculate();
}

function recalculate() {
    const ci = new Date(document.getElementById('checkIn').value);
    const co = new Date(document.getElementById('checkOut').value);
    if (!ci || !co || co <= ci) return;
    
    const nights = Math.ceil((co - ci) / 86400000);
    const price = parseFloat(document.getElementById('roomPrice').value) || 0;
    const subtotal = nights * price;
    
    // Discount
    const discVal = parseFloat(document.getElementById('discountValue').value) || 0;
    let discountAmount = 0;
    if (discountType === 'percent') {
        discountAmount = Math.round(subtotal * discVal / 100);
    } else {
        discountAmount = discVal;
    }
    
    const afterDiscount = subtotal - discountAmount;
    
    // OTA Fee
    const source = document.getElementById('bookingSource').value;
    const feePercent = OTA_FEES[source] || 0;
    let feeAmount = 0;
    if (feePercent > 0) {
        feeAmount = Math.round(afterDiscount * feePercent / 100);
    }
    
    const total = afterDiscount - feeAmount;
    
    // Update display
    document.getElementById('dispNights').textContent = nights;
    document.getElementById('dispSubtotal').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
    
    if (discountAmount > 0) {
        document.getElementById('discountRow').style.display = 'flex';
        document.getElementById('dispDiscount').textContent = '- Rp ' + discountAmount.toLocaleString('id-ID');
    } else {
        document.getElementById('discountRow').style.display = 'none';
    }
    
    if (feePercent > 0) {
        document.getElementById('otaFeeRow').style.display = 'flex';
        document.getElementById('dispFeePercent').textContent = feePercent;
        document.getElementById('dispFeeAmount').textContent = '- Rp ' + feeAmount.toLocaleString('id-ID');
    } else {
        document.getElementById('otaFeeRow').style.display = 'none';
    }
    
    document.getElementById('dispTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
}

function saveEdit(event) {
    event.preventDefault();
    
    const btn = document.getElementById('btnSave');
    btn.innerHTML = '⏳ Menyimpan...';
    btn.disabled = true;
    
    const form = document.getElementById('editForm');
    const formData = new FormData(form);
    
    fetch('<?php echo BASE_URL; ?>/api/update-reservation.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        const alertBox = document.getElementById('alertBox');
        if (data.success) {
            alertBox.innerHTML = '<div class="alert alert-success">✅ ' + data.message + '</div>';
            setTimeout(() => { window.location.href = 'reservasi.php'; }, 1500);
        } else {
            alertBox.innerHTML = '<div class="alert alert-error">❌ ' + data.message + '</div>';
            btn.innerHTML = '💾 Simpan Perubahan';
            btn.disabled = false;
        }
    })
    .catch(err => {
        document.getElementById('alertBox').innerHTML = '<div class="alert alert-error">❌ Error: ' + err.message + '</div>';
        btn.innerHTML = '💾 Simpan Perubahan';
        btn.disabled = false;
    });
    
    return false;
}

// Initial calculation including OTA
recalculate();
</script>

<?php include '../../includes/footer.php'; ?>
