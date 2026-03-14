<?php
/**
 * BREAKFAST ORDER - Rewritten clean version
 * Flow: Pick guest (not yet ordered today) → Pick menu → Submit → Pick next guest
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) { header('Location: ' . BASE_URL . '/403.php'); exit; }

$db = Database::getInstance();
$pdo = $db->getConnection();
$today = date('Y-m-d');

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_menus (
        id INT PRIMARY KEY AUTO_INCREMENT, menu_name VARCHAR(100) NOT NULL,
        description TEXT, category ENUM('western','indonesian','asian','drinks','beverages','extras') DEFAULT 'western',
        price DECIMAL(10,2) DEFAULT 0.00, is_free BOOLEAN DEFAULT TRUE, is_available BOOLEAN DEFAULT TRUE,
        image_url VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
        id INT PRIMARY KEY AUTO_INCREMENT, booking_id INT NULL, guest_name VARCHAR(100) NOT NULL,
        room_number TEXT, total_pax INT DEFAULT 1, breakfast_time TIME, breakfast_date DATE,
        location VARCHAR(20) DEFAULT 'restaurant', menu_items TEXT, special_requests TEXT,
        total_price DECIMAL(10,2) DEFAULT 0.00, order_status VARCHAR(20) DEFAULT 'pending',
        created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_guest_date (guest_name, breakfast_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Auto-cleanup existing duplicates (keep newest ID per guest+date), then add UNIQUE index
try {
    $pdo->exec("DELETE bo1 FROM breakfast_orders bo1
        INNER JOIN breakfast_orders bo2
        ON bo1.guest_name = bo2.guest_name
        AND bo1.breakfast_date = bo2.breakfast_date
        AND bo1.id < bo2.id");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE breakfast_orders ADD UNIQUE INDEX uk_guest_date (guest_name, breakfast_date)");
} catch (Exception $e) { /* already exists */ }

// Get menus
$freeMenus = $paidMenus = [];
try {
    $freeMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=1 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
    $paidMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=0 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get in-house guests WHO HAVE NOT ORDERED TODAY
// Group by guest_id: one guest may have multiple bookings/rooms
$inHouseGuests = [];
try {
    $stmt = $pdo->prepare("
        SELECT g.id as guest_id, g.guest_name, 
               GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ',') as rooms,
               GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') as booking_ids
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        AND g.guest_name NOT IN (
            SELECT bo.guest_name FROM breakfast_orders bo 
            WHERE bo.breakfast_date = ? AND bo.guest_name IS NOT NULL AND bo.guest_name != ''
        )
        GROUP BY g.id, g.guest_name
        ORDER BY MIN(r.room_number) ASC
    ");
    $stmt->execute([$today]);
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Today's orders for sidebar
$todayOrders = [];
try {
    $stmt = $pdo->prepare("SELECT bo.* FROM breakfast_orders bo
        WHERE bo.breakfast_date = ?
        AND bo.id = (SELECT MAX(bo2.id) FROM breakfast_orders bo2 WHERE bo2.guest_name = bo.guest_name AND bo2.breakfast_date = bo.breakfast_date)
        ORDER BY bo.breakfast_time ASC, bo.id ASC");
    $stmt->execute([$today]);
    $todayOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todayOrders as &$o) { $o['menu_items'] = json_decode($o['menu_items'], true) ?: []; }
} catch (Exception $e) {}

// Edit mode
$editOrder = null; $editMenuIds = []; $editMenuQty = []; $editMenuNotes = [];
if (!empty($_GET['edit'])) {
    $editOrder = $db->fetchOne("SELECT * FROM breakfast_orders WHERE id = ?", [(int)$_GET['edit']]);
    if ($editOrder) {
        foreach (json_decode($editOrder['menu_items'], true) ?: [] as $item) {
            $editMenuIds[] = $item['menu_id'];
            $editMenuQty[$item['menu_id']] = $item['quantity'];
            if (!empty($item['note'])) $editMenuNotes[$item['menu_id']] = $item['note'];
        }
    }
}

$pageTitle = 'Breakfast Order';
include '../../includes/header.php';
?>

<style>
.bf-wrap{max-width:1300px;margin:0 auto}
.bf-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem}
.bf-head h1{font-size:1.5rem;font-weight:800;background:linear-gradient(135deg,#f59e0b,#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin:0;display:flex;align-items:center;gap:.5rem}
.bf-head-actions{display:flex;gap:.5rem}
.bf-head-btn{padding:.5rem .875rem;background:var(--bg-secondary);border:1px solid var(--bg-tertiary);color:var(--text-primary);border-radius:8px;font-size:.75rem;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:.35rem;transition:all .2s}
.bf-head-btn:hover{border-color:var(--primary-color);background:rgba(99,102,241,.1)}
.bf-grid{display:grid;grid-template-columns:1fr 350px;gap:1.25rem}
.bf-card{background:var(--bg-secondary);border:1px solid var(--bg-tertiary);border-radius:12px;padding:1rem}
.bf-section{margin-bottom:1rem}
.bf-title{font-size:.85rem;font-weight:700;color:var(--text-primary);margin-bottom:.65rem;padding-bottom:.4rem;border-bottom:2px solid var(--bg-tertiary);display:flex;align-items:center;gap:.4rem}
.bf-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:.75rem}
.bf-group{display:flex;flex-direction:column}
.bf-label{font-size:.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;margin-bottom:.3rem}
.bf-input,.bf-select{padding:.55rem .65rem;border-radius:6px;background:var(--bg-primary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.85rem;width:100%}
.bf-input:focus,.bf-select:focus{outline:none;border-color:var(--primary-color)}
.bf-radio-group{display:flex;gap:.5rem}
.bf-radio-label{flex:1;display:flex;align-items:center;justify-content:center;gap:.35rem;padding:.5rem;background:var(--bg-primary);border:2px solid var(--bg-tertiary);border-radius:8px;cursor:pointer;font-size:.78rem;font-weight:600;transition:all .2s}
.bf-radio-label:hover{border-color:var(--primary-color)}
.bf-radio-label:has(input:checked){border-color:var(--primary-color);background:rgba(99,102,241,.15)}
.bf-radio-label input{display:none}
.bf-menu-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem}
.bf-menu-item{background:var(--bg-primary);border:1px solid var(--bg-tertiary);border-radius:8px;padding:.65rem;transition:all .2s;cursor:pointer}
.bf-menu-item:hover{border-color:var(--primary-color)}
.bf-menu-item:has(input:checked){border-color:#10b981;background:rgba(16,185,129,.1)}
.bf-menu-cb{display:flex;align-items:flex-start;gap:.5rem}
.bf-menu-cb input[type="checkbox"]{margin-top:.15rem;width:16px;height:16px;cursor:pointer}
.bf-menu-name{font-size:.8rem;font-weight:700;color:var(--text-primary);margin-bottom:.2rem}
.bf-menu-price{font-size:.72rem;font-weight:700;color:#10b981}
.bf-menu-cat{display:inline-block;padding:.15rem .4rem;background:rgba(99,102,241,.15);border-radius:4px;font-size:.6rem;font-weight:600;text-transform:uppercase;color:var(--primary-color)}
.bf-menu-qty{display:none;align-items:center;gap:.35rem;margin-top:.5rem;padding-top:.5rem;border-top:1px dashed var(--bg-tertiary)}
.bf-menu-item:has(input:checked) .bf-menu-qty{display:flex}
.bf-qty-input{width:50px;padding:.3rem;border-radius:4px;background:var(--bg-secondary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.8rem;text-align:center}
.bf-menu-note{display:none;margin-top:.35rem}
.bf-menu-item:has(input:checked) .bf-menu-note{display:block}
.bf-note-input{width:100%;padding:.3rem .5rem;border-radius:4px;background:var(--bg-secondary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.72rem;font-family:inherit}
.bf-textarea{width:100%;padding:.55rem .65rem;border-radius:6px;background:var(--bg-primary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.85rem;font-family:inherit;resize:vertical;min-height:50px}
.bf-actions{display:flex;gap:.5rem;margin-top:1rem}
.bf-btn-submit{flex:1;padding:.75rem 1rem;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;transition:all .2s}
.bf-btn-submit:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(16,185,129,.3)}
.bf-btn-submit:disabled{opacity:.5;cursor:not-allowed;transform:none}
.bf-btn-reset{padding:.75rem 1rem;background:var(--bg-primary);color:var(--text-muted);border:1px solid var(--bg-tertiary);border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;text-align:center}
/* Sidebar */
.bf-side{background:var(--bg-secondary);border:1px solid var(--bg-tertiary);border-radius:12px;overflow:hidden;height:fit-content;position:sticky;top:1rem}
.bf-side-title{padding:.85rem 1rem;background:linear-gradient(135deg,var(--primary-color),var(--secondary-color));color:#fff;font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:.4rem}
.bf-side-count{background:rgba(255,255,255,.25);padding:.15rem .5rem;border-radius:10px;font-size:.7rem;margin-left:auto}
.bf-order{padding:.75rem 1rem;border-bottom:1px solid var(--bg-tertiary);transition:background .2s}
.bf-order:last-child{border-bottom:none}
.bf-order:hover{background:var(--bg-primary)}
.bf-order-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem}
.bf-order-time{font-size:.75rem;font-weight:700;color:var(--primary-color)}
.bf-order-pax{font-size:.65rem;padding:.2rem .4rem;background:var(--bg-tertiary);border-radius:4px;color:var(--text-muted)}
.bf-order-guest{font-size:.8rem;font-weight:600;color:var(--text-primary);margin-bottom:.25rem}
.bf-order-room{font-size:.7rem;color:var(--text-muted);margin-bottom:.35rem}
.bf-order-menus{display:flex;flex-wrap:wrap;gap:.25rem}
.bf-order-tag{font-size:.62rem;padding:.15rem .35rem;background:rgba(139,92,246,.15);color:#a78bfa;border-radius:3px}
.bf-order-foot{display:flex;justify-content:space-between;align-items:center;margin-top:.4rem;padding-top:.35rem;border-top:1px dashed var(--bg-tertiary)}
.bf-order-price{font-size:.72rem;font-weight:700;color:#10b981}
.bf-order-status{font-size:.6rem;padding:.2rem .4rem;border-radius:4px;font-weight:700;text-transform:uppercase}
.bf-order-status.pending{background:rgba(245,158,11,.2);color:#f59e0b}
.bf-order-status.preparing{background:rgba(99,102,241,.2);color:#6366f1}
.bf-order-status.served{background:rgba(16,185,129,.2);color:#10b981}
.bf-order-status.completed{background:rgba(107,114,128,.2);color:#9ca3af}
.bf-order-btns{display:flex;gap:.35rem;margin-top:.4rem}
.bf-order-btn{padding:.25rem .5rem;border-radius:4px;font-size:.65rem;font-weight:600;cursor:pointer;border:none;transition:all .2s}
.bf-order-btn.edit{background:rgba(99,102,241,.15);color:#6366f1}
.bf-order-btn.del{background:rgba(239,68,68,.15);color:#ef4444}
.bf-empty{padding:2rem 1rem;text-align:center;color:var(--text-muted)}
.bf-empty-icon{font-size:2rem;margin-bottom:.5rem}
.bf-alert{padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;font-weight:600}
.bf-alert.ok{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#10b981}
.bf-alert.err{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#ef4444}
.bf-no-guest{padding:1rem;text-align:center;font-size:.8rem;color:var(--text-muted);background:rgba(245,158,11,.08);border-radius:8px}
@media(max-width:900px){.bf-grid{grid-template-columns:1fr}.bf-side{position:static}.bf-menu-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.bf-row{grid-template-columns:1fr}.bf-menu-grid{grid-template-columns:1fr}.bf-radio-group{flex-direction:column}}
</style>

<div class="bf-wrap">
    <div class="bf-head">
        <h1>🍳 Breakfast Order</h1>
        <div class="bf-head-actions">
            <a href="breakfast.php" class="bf-head-btn">📋 Orders</a>
            <a href="in-house.php" class="bf-head-btn">👥 In House</a>
            <a href="dashboard.php" class="bf-head-btn">🏠 Dashboard</a>
        </div>
    </div>

    <?php if (!empty($_GET['success'])): ?>
    <div class="bf-alert ok">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>

    <div class="bf-grid">
        <!-- FORM -->
        <div class="bf-card">
            <form id="bfForm" autocomplete="off">
                <?php if ($editOrder): ?>
                <input type="hidden" name="edit_id" value="<?php echo $editOrder['id']; ?>">
                <?php endif; ?>

                <!-- Guest Selection -->
                <div class="bf-section">
                    <div class="bf-title">👤 Pilih Tamu In-House</div>
                    <?php if (count($inHouseGuests) > 0 || $editOrder): ?>
                    <div class="bf-group">
                        <label class="bf-label">Tamu & Kamar *</label>
                        <select name="guest_select" id="guestSelect" class="bf-select" required>
                            <option value="">-- Pilih Tamu --</option>
                            <?php foreach ($inHouseGuests as $g): 
                                $roomList = $g['rooms']; // e.g. "101,202,203"
                                $bookingIdFirst = explode(',', $g['booking_ids'])[0];
                            ?>
                            <option value="<?php echo $g['guest_id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($g['guest_name']); ?>" 
                                    data-rooms="<?php echo htmlspecialchars($roomList); ?>"
                                    data-booking="<?php echo $bookingIdFirst; ?>">
                                <?php echo htmlspecialchars($g['guest_name']); ?> — Room <?php echo $roomList; ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if ($editOrder): ?>
                            <?php 
                                $editRooms = json_decode($editOrder['room_number'], true);
                                $editRoomStr = is_array($editRooms) ? implode(', ', $editRooms) : $editOrder['room_number'];
                            ?>
                            <option value="edit_<?php echo $editOrder['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($editOrder['guest_name']); ?>"
                                    data-rooms="<?php echo htmlspecialchars($editRoomStr); ?>"
                                    data-booking="<?php echo $editOrder['booking_id']; ?>" selected>
                                <?php echo htmlspecialchars($editOrder['guest_name']); ?> — Room <?php echo htmlspecialchars($editRoomStr); ?> (editing)
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="bf-no-guest">🎉 Semua tamu in-house sudah order sarapan hari ini!</div>
                    <?php endif; ?>
                </div>

                <!-- Time & Details -->
                <div class="bf-section">
                    <div class="bf-title">⏰ Waktu & Detail</div>
                    <div class="bf-row">
                        <div class="bf-group">
                            <label class="bf-label">Jumlah Pax *</label>
                            <input type="number" name="total_pax" id="totalPax" class="bf-input" min="1" max="20" required value="<?php echo $editOrder ? (int)$editOrder['total_pax'] : ''; ?>">
                        </div>
                        <div class="bf-group">
                            <label class="bf-label">Jam *</label>
                            <input type="time" name="breakfast_time" id="bfTime" class="bf-input" required value="<?php echo $editOrder ? $editOrder['breakfast_time'] : ''; ?>">
                        </div>
                        <div class="bf-group">
                            <label class="bf-label">Tanggal</label>
                            <input type="date" name="breakfast_date" class="bf-input" value="<?php echo $editOrder ? $editOrder['breakfast_date'] : $today; ?>" readonly>
                        </div>
                    </div>
                    <div class="bf-row">
                        <div class="bf-group" style="grid-column:span 2">
                            <label class="bf-label">Lokasi *</label>
                            <div class="bf-radio-group">
                                <label class="bf-radio-label"><input type="radio" name="location" value="restaurant" <?php echo (!$editOrder || ($editOrder['location'] ?? '') === 'restaurant') ? 'checked' : ''; ?>> 🍽️ Restaurant</label>
                                <label class="bf-radio-label"><input type="radio" name="location" value="room_service" <?php echo ($editOrder && ($editOrder['location'] ?? '') === 'room_service') ? 'checked' : ''; ?>> 🛏️ Room Service</label>
                                <label class="bf-radio-label"><input type="radio" name="location" value="take_away" <?php echo ($editOrder && ($editOrder['location'] ?? '') === 'take_away') ? 'checked' : ''; ?>> 🥡 Take Away</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Menu -->
                <div class="bf-section">
                    <div class="bf-title">🍽️ Pilih Menu</div>

                    <?php if (count($freeMenus) > 0): ?>
                    <div style="margin-bottom:1rem">
                        <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem">✨ Free Breakfast</div>
                        <div class="bf-menu-grid">
                            <?php foreach ($freeMenus as $m): ?>
                            <div class="bf-menu-item">
                                <label class="bf-menu-cb">
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                    <div>
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                        <span class="bf-menu-cat"><?php echo $m['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size:.7rem;color:var(--text-muted)">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                </div>
                                <div class="bf-menu-note">
                                    <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($paidMenus) > 0): ?>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem">💰 Extra (Berbayar)</div>
                        <div class="bf-menu-grid">
                            <?php foreach ($paidMenus as $m): ?>
                            <div class="bf-menu-item">
                                <label class="bf-menu-cb">
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                    <div>
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                        <div class="bf-menu-price">Rp <?php echo number_format($m['price'], 0, ',', '.'); ?></div>
                                        <span class="bf-menu-cat"><?php echo $m['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size:.7rem;color:var(--text-muted)">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                </div>
                                <div class="bf-menu-note">
                                    <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Notes -->
                <div class="bf-section">
                    <div class="bf-title">📝 Catatan</div>
                    <textarea name="special_requests" class="bf-textarea" placeholder="Alergi, permintaan khusus, dll"><?php echo $editOrder ? htmlspecialchars($editOrder['special_requests'] ?? '') : ''; ?></textarea>
                </div>

                <div class="bf-actions">
                    <button type="submit" class="bf-btn-submit" id="btnSubmit"><?php echo $editOrder ? '✓ Update Order' : '✓ Simpan Order'; ?></button>
                    <?php if ($editOrder): ?>
                    <a href="breakfast.php" class="bf-btn-reset">✕ Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- SIDEBAR: Today's Orders -->
        <div class="bf-side">
            <div class="bf-side-title">
                📊 Today's Orders
                <span class="bf-side-count"><?php echo count($todayOrders); ?></span>
            </div>

            <?php if (count($todayOrders) > 0): ?>
                <?php foreach ($todayOrders as $order): ?>
                <div class="bf-order">
                    <div class="bf-order-head">
                        <span class="bf-order-time">🕐 <?php echo $order['breakfast_time'] ? date('H:i', strtotime($order['breakfast_time'])) : '-'; ?></span>
                        <span class="bf-order-pax"><?php echo $order['total_pax']; ?> pax</span>
                    </div>
                    <div class="bf-order-guest"><?php echo htmlspecialchars($order['guest_name']); ?></div>
                    <?php
                        $rooms = json_decode($order['room_number'], true);
                        $roomStr = is_array($rooms) ? implode(', ', $rooms) : ($order['room_number'] ?: '-');
                    ?>
                    <div class="bf-order-room">🛏️ Room <?php echo htmlspecialchars($roomStr); ?></div>
                    <div class="bf-order-room"><?php echo ($order['location'] ?? 'restaurant') === 'restaurant' ? '🍽️ Restaurant' : (($order['location'] ?? '') === 'take_away' ? '🥡 Take Away' : '🚪 Room Service'); ?></div>
                    <div class="bf-order-menus">
                        <?php foreach ($order['menu_items'] as $item): ?>
                        <span class="bf-order-tag">
                            <?php echo htmlspecialchars($item['menu_name'] ?? '?'); ?>
                            <?php if (($item['quantity'] ?? 1) > 1): ?>×<?php echo $item['quantity']; ?><?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="bf-order-foot">
                        <span class="bf-order-price"><?php echo $order['total_price'] > 0 ? 'Rp ' . number_format($order['total_price'], 0, ',', '.') : 'Free'; ?></span>
                        <span class="bf-order-status <?php echo $order['order_status']; ?>"><?php echo ucfirst($order['order_status']); ?></span>
                    </div>
                    <div class="bf-order-btns">
                        <a href="?edit=<?php echo $order['id']; ?>" class="bf-order-btn edit">✏️ Edit</a>
                        <button class="bf-order-btn del" onclick="hapusOrder(<?php echo $order['id']; ?>,'<?php echo htmlspecialchars(addslashes($order['guest_name'])); ?>')">🗑️ Hapus</button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="bf-empty">
                    <div class="bf-empty-icon">📭</div>
                    <p style="font-size:.8rem">Belum ada order hari ini</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Guest select → auto fill pax
document.getElementById('guestSelect')?.addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    if (opt.value) {
        document.getElementById('totalPax').value = document.getElementById('totalPax').value || 1;
    }
});

// Form submit via AJAX
var submitting = false;
document.getElementById('bfForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (submitting) return;

    var guestOpt = document.getElementById('guestSelect');
    if (!guestOpt || !guestOpt.value) { alert('Pilih tamu dulu!'); return; }

    var pax = document.getElementById('totalPax').value;
    var time = document.getElementById('bfTime').value;
    if (!pax || parseInt(pax) < 1) { alert('Isi jumlah pax!'); return; }
    if (!time) { alert('Isi jam sarapan!'); return; }

    var menus = document.querySelectorAll('input[name="menu_items[]"]:checked');
    if (menus.length === 0) { alert('Pilih minimal 1 menu!'); return; }

    var opt = guestOpt.options[guestOpt.selectedIndex];
    var roomsStr = opt.dataset.rooms || '';
    var roomArr = roomsStr ? roomsStr.split(',').map(function(r){ return r.trim(); }) : [];
    var data = {
        action: document.querySelector('input[name="edit_id"]') ? 'update_order' : 'create_order',
        booking_id: parseInt(opt.dataset.booking) || null,
        guest_id: parseInt(guestOpt.value) || null,
        guest_name: opt.dataset.name || '',
        room_number: roomArr,
        total_pax: parseInt(pax),
        breakfast_time: time,
        breakfast_date: document.querySelector('input[name="breakfast_date"]').value,
        location: (document.querySelector('input[name="location"]:checked') || {value:'restaurant'}).value,
        special_requests: document.querySelector('textarea[name="special_requests"]').value.trim(),
        menu_items: [],
        menu_qty: {},
        menu_note: {}
    };

    var editId = document.querySelector('input[name="edit_id"]');
    if (editId) data.edit_id = parseInt(editId.value);

    menus.forEach(function(cb) {
        var id = cb.value;
        data.menu_items.push(id);
        var q = document.querySelector('input[name="menu_qty[' + id + ']"]');
        data.menu_qty[id] = q ? parseInt(q.value) || 1 : 1;
        var n = document.querySelector('input[name="menu_note[' + id + ']"]');
        data.menu_note[id] = n ? n.value.trim() : '';
    });

    submitting = true;
    var btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.textContent = '⏳ Menyimpan...';

    fetch('../../api/breakfast-save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            // Redirect clean — tamu ini hilang dari dropdown, bisa pilih tamu lain
            window.location.href = 'breakfast.php?success=' + encodeURIComponent(res.message);
        } else {
            alert('❌ ' + (res.message || 'Gagal menyimpan'));
            submitting = false;
            btn.disabled = false;
            btn.textContent = data.edit_id ? '✓ Update Order' : '✓ Simpan Order';
        }
    })
    .catch(function(err) {
        alert('❌ Error koneksi: ' + err.message);
        submitting = false;
        btn.disabled = false;
        btn.textContent = data.edit_id ? '✓ Update Order' : '✓ Simpan Order';
    });
});

// Delete order
function hapusOrder(id, name) {
    if (!confirm('Hapus order sarapan "' + name + '"?')) return;
    fetch('<?php echo BASE_URL; ?>/api/breakfast-order-action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'delete', id: id})
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) location.reload();
        else alert('Gagal: ' + (d.message || '?'));
    })
    .catch(function() { alert('Error koneksi'); });
}
</script>

<?php include '../../includes/footer.php'; ?>
