<?php
/**
 * BREAKFAST ORDER FORM
 * Create breakfast orders with menu selection
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================
// SECURITY & AUTHENTICATION
// ============================================
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Verify permission
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/403.php');
    exit;
}

$pdo = $db->getConnection();
$today = date('Y-m-d');
$message = '';
$error = '';

// ==================== VALIDATE USER ID ====================
// Check if current user exists in database before using in FK constraints
$validUserId = null;
if (!empty($_SESSION['user_id'])) {
    $userCheck = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if ($userCheck) {
        $validUserId = $_SESSION['user_id'];
    } else {
        // User doesn't exist - log the issue and continue gracefully
        error_log("Warning: SessionUser ID {$_SESSION['user_id']} not found in users table for breakfast order");
        // Try to find ANY admin/staff user to use as fallback
        $adminUser = $db->fetchOne("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1");
        if ($adminUser) {
            $validUserId = $adminUser['id'];
        }
    }
}

// ==================== HANDLE BREAKFAST ORDER ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'create_order') {
            // Validate user exists
            if (!$validUserId) {
                throw new Exception("❌ Sistem error: User tidak ditemukan di database. Hubungi administrator.");
            }
            
            // Create breakfast_orders table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
                id INT PRIMARY KEY AUTO_INCREMENT,
                booking_id INT NULL,
                guest_name VARCHAR(100) NOT NULL,
                room_number VARCHAR(20),
                total_pax INT NOT NULL,
                breakfast_time TIME NOT NULL,
                breakfast_date DATE NOT NULL,
                location ENUM('restaurant', 'room_service') DEFAULT 'restaurant',
                menu_items TEXT COMMENT 'JSON array of menu items with quantities',
                special_requests TEXT,
                total_price DECIMAL(10,2) DEFAULT 0.00,
                order_status ENUM('pending', 'preparing', 'served', 'completed', 'cancelled') DEFAULT 'pending',
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id),
                INDEX idx_date (breakfast_date),
                INDEX idx_status (order_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // ===== VALIDATION =====
            // Validate required fields
            if (empty($_POST['guest_name'])) {
                throw new Exception("Guest name is required");
            }
            if (empty($_POST['total_pax'])) {
                throw new Exception("Total pax is required");
            }
            if (empty($_POST['breakfast_time'])) {
                throw new Exception("Breakfast time is required");
            }
            if (empty($_POST['breakfast_date'])) {
                throw new Exception("Breakfast date is required");
            }
            
            // CRITICAL: Validate that at least ONE menu item is selected
            if (empty($_POST['menu_items']) || !is_array($_POST['menu_items']) || count($_POST['menu_items']) === 0) {
                throw new Exception("❌ Pilih minimal 1 menu item untuk breakfast order");
            }
            
            // Parse menu items from form
            $menuItems = [];
            $totalPrice = 0;
            
            foreach ($_POST['menu_items'] as $menuId) {
                $qty = (int)($_POST['menu_qty'][$menuId] ?? 1);
                if ($qty > 0) {
                    // Get menu price
                    $menuStmt = $pdo->prepare("SELECT menu_name, price, is_free FROM breakfast_menus WHERE id = ?");
                    $menuStmt->execute([$menuId]);
                    $menu = $menuStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($menu) {
                        $menuItems[] = [
                            'menu_id' => $menuId,
                            'menu_name' => $menu['menu_name'],
                            'quantity' => $qty,
                            'price' => $menu['price'],
                            'is_free' => $menu['is_free']
                        ];
                        
                        if (!$menu['is_free']) {
                            $totalPrice += ($menu['price'] * $qty);
                        }
                    }
                }
            }
            
            // Verify we have at least one valid menu item after processing
            if (count($menuItems) === 0) {
                throw new Exception("❌ No valid menu items selected");
            }
            
            // Insert order
            $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
                (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, location, 
                 menu_items, special_requests, total_price, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $orderId = $stmt->execute([
                !empty($_POST['booking_id']) ? (int)$_POST['booking_id'] : null,
                trim($_POST['guest_name']),
                !empty($_POST['room_number']) ? trim($_POST['room_number']) : null,
                (int)$_POST['total_pax'],
                $_POST['breakfast_time'],
                $_POST['breakfast_date'],
                $_POST['location'] ?? 'restaurant',
                json_encode($menuItems),
                !empty($_POST['special_requests']) ? trim($_POST['special_requests']) : null,
                $totalPrice,
                $validUserId
            ]);
            
            $lastOrderId = $pdo->lastInsertId();
            
            // Build detailed message
            $itemscount = count($menuItems);
            $guestName = trim($_POST['guest_name']);
            $message = "✅ Berhasil! Pesanan sarapan untuk <strong>$guestName</strong> ($itemscount item menu) telah tersimpan dengan ID #$lastOrderId";
            
            // Don't redirect - show success and keep form visible for more entries
            // header('Location: ' . $_SERVER['PHP_SELF']);
            // exit;
        }
    } catch (Exception $e) {
        $error = "❌ Error: " . $e->getMessage();
        error_log("Breakfast Order Error: " . $e->getMessage());
    }
}

// ==================== GET DATA FOR FORM ====================
try {
    // Create breakfast menus table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_menus (
        id INT PRIMARY KEY AUTO_INCREMENT,
        menu_name VARCHAR(100) NOT NULL,
        description TEXT,
        category ENUM('western', 'indonesian', 'asian', 'drinks', 'beverages', 'extras') DEFAULT 'western',
        price DECIMAL(10,2) DEFAULT 0.00,
        is_free BOOLEAN DEFAULT TRUE COMMENT 'TRUE = Free breakfast, FALSE = Extra/Paid',
        is_available BOOLEAN DEFAULT TRUE,
        image_url VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_category (category),
        INDEX idx_available (is_available),
        INDEX idx_free (is_free)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Get available breakfast menus (Free and Paid separately)
$freeMenus = [];
$paidMenus = [];
try {
    $stmt = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available = TRUE AND is_free = TRUE ORDER BY category, menu_name");
    $freeMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available = TRUE AND is_free = FALSE ORDER BY category, menu_name");
    $paidMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get in-house guests for dropdown
$inHouseGuests = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            b.id as booking_id,
            g.guest_name,
            r.room_number
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
    ");
    $stmt->execute();
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$pageTitle = 'Breakfast Order';
include '../../includes/header.php';
?>

<style>
.bf-container { max-width: 1300px; margin: 0 auto; }

.bf-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.bf-header h1 {
    font-size: 1.5rem;
    font-weight: 800;
    background: linear-gradient(135deg, #f59e0b, #f97316);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.bf-header-actions {
    display: flex;
    gap: 0.5rem;
}

.bf-header-btn {
    padding: 0.5rem 0.875rem;
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.2s;
}

.bf-header-btn:hover {
    border-color: var(--primary-color);
    background: rgba(99, 102, 241, 0.1);
}

.bf-layout {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 1.25rem;
}

.bf-alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    font-weight: 600;
}

.bf-alert.success {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #10b981;
}

.bf-alert.error {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

/* Form Card */
.bf-form-card {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: 12px;
    padding: 1rem;
}

.bf-form-section {
    margin-bottom: 1rem;
}

.bf-form-title {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.65rem;
    padding-bottom: 0.4rem;
    border-bottom: 2px solid var(--bg-tertiary);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.bf-form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.bf-form-group {
    display: flex;
    flex-direction: column;
}

.bf-label {
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 0.3rem;
}

.bf-input {
    padding: 0.55rem 0.65rem;
    border-radius: 6px;
    background: var(--bg-primary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 0.85rem;
}

.bf-input:focus {
    outline: none;
    border-color: var(--primary-color);
}

.bf-radio-group {
    display: flex;
    gap: 0.5rem;
}

.bf-radio-label {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    padding: 0.5rem;
    background: var(--bg-primary);
    border: 2px solid var(--bg-tertiary);
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.78rem;
    font-weight: 600;
    transition: all 0.2s;
}

.bf-radio-label:hover { border-color: var(--primary-color); }

.bf-radio-label:has(input:checked) {
    border-color: var(--primary-color);
    background: rgba(99, 102, 241, 0.15);
}

.bf-radio-label input { display: none; }

/* Menu Section */
.bf-menu-section {
    margin-top: 1rem;
}

.bf-menu-category {
    margin-bottom: 1rem;
}

.bf-menu-category-title {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.bf-menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.5rem;
}

.bf-menu-item {
    background: var(--bg-primary);
    border: 1px solid var(--bg-tertiary);
    border-radius: 8px;
    padding: 0.65rem;
    transition: all 0.2s;
    cursor: pointer;
}

.bf-menu-item:hover {
    border-color: var(--primary-color);
}

.bf-menu-item:has(input:checked) {
    border-color: #10b981;
    background: rgba(16, 185, 129, 0.1);
}

.bf-menu-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.bf-menu-checkbox input[type="checkbox"] {
    margin-top: 0.15rem;
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.bf-menu-info { flex: 1; }

.bf-menu-name {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.2rem;
}

.bf-menu-price {
    font-size: 0.72rem;
    font-weight: 700;
    color: #10b981;
}

.bf-menu-cat {
    display: inline-block;
    padding: 0.15rem 0.4rem;
    background: rgba(99, 102, 241, 0.15);
    border-radius: 4px;
    font-size: 0.6rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--primary-color);
}

.bf-menu-qty {
    display: none;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px dashed var(--bg-tertiary);
}

.bf-menu-item:has(input:checked) .bf-menu-qty {
    display: flex;
}

.bf-qty-input {
    width: 50px;
    padding: 0.3rem;
    border-radius: 4px;
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 0.8rem;
    text-align: center;
}

.bf-textarea {
    width: 100%;
    padding: 0.55rem 0.65rem;
    border-radius: 6px;
    background: var(--bg-primary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 0.85rem;
    font-family: inherit;
    resize: vertical;
    min-height: 60px;
}

.bf-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}

.bf-btn-submit {
    flex: 1;
    padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}

.bf-btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.bf-btn-reset {
    padding: 0.75rem 1rem;
    background: var(--bg-primary);
    color: var(--text-muted);
    border: 1px solid var(--bg-tertiary);
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
}

/* Sidebar - Today's Orders */
.bf-sidebar {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: 12px;
    overflow: hidden;
    height: fit-content;
    position: sticky;
    top: 1rem;
}

.bf-sidebar-title {
    padding: 0.85rem 1rem;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    font-size: 0.9rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.bf-order-item {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--bg-tertiary);
    transition: background 0.2s;
}

.bf-order-item:last-child { border-bottom: none; }

.bf-order-item:hover { background: var(--bg-primary); }

.bf-order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.35rem;
}

.bf-order-time {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--primary-color);
}

.bf-order-pax {
    font-size: 0.65rem;
    padding: 0.2rem 0.4rem;
    background: var(--bg-tertiary);
    border-radius: 4px;
    color: var(--text-muted);
}

.bf-order-guest {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.bf-order-room {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-bottom: 0.35rem;
}

.bf-order-menus {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
}

.bf-order-menu-tag {
    font-size: 0.62rem;
    padding: 0.15rem 0.35rem;
    background: rgba(139, 92, 246, 0.15);
    color: #a78bfa;
    border-radius: 3px;
}

.bf-order-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0.4rem;
    padding-top: 0.35rem;
    border-top: 1px dashed var(--bg-tertiary);
}

.bf-order-price {
    font-size: 0.72rem;
    font-weight: 700;
    color: #10b981;
}

.bf-order-status {
    font-size: 0.6rem;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    font-weight: 700;
    text-transform: uppercase;
}

.bf-order-status.pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
.bf-order-status.preparing { background: rgba(99, 102, 241, 0.2); color: #6366f1; }
.bf-order-status.served { background: rgba(16, 185, 129, 0.2); color: #10b981; }
.bf-order-status.completed { background: rgba(107, 114, 128, 0.2); color: #9ca3af; }

.bf-empty {
    padding: 2rem 1rem;
    text-align: center;
    color: var(--text-muted);
}

.bf-empty-icon { font-size: 2rem; margin-bottom: 0.5rem; }

@media (max-width: 900px) {
    .bf-layout {
        grid-template-columns: 1fr;
    }
    
    .bf-sidebar {
        position: static;
    }
    
    .bf-menu-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 600px) {
    .bf-form-row { grid-template-columns: 1fr; }
    .bf-menu-grid { grid-template-columns: 1fr; }
    .bf-radio-group { flex-direction: column; }
}
</style>

<div class="bf-container">
    <!-- Header -->
    <div class="bf-header">
        <h1>🍳 Breakfast Order</h1>
        <div class="bf-header-actions">
            <a href="breakfast-orders.php" class="bf-header-btn">📋 Orders</a>
            <a href="in-house.php" class="bf-header-btn">👥 In House</a>
            <a href="dashboard.php" class="bf-header-btn">🏠 Dashboard</a>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="bf-alert success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="bf-alert error"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="bf-layout">
        <!-- Form -->
        <div class="bf-form-card">
            <form method="POST" action="" id="breakfastOrderForm">
                <input type="hidden" name="action" value="create_order">
                
                <!-- Guest Info Section -->
                <div class="bf-form-section">
                    <div class="bf-form-title">👤 Guest Information</div>
                    <div class="bf-form-row">
                        <div class="bf-form-group" style="grid-column: span 2;">
                            <label class="bf-label">Select Guest (In House)</label>
                            <select name="booking_id" id="guest_select" class="bf-input" onchange="fillGuestInfo(this)">
                                <option value="">-- Walk-in / Manual --</option>
                                <?php foreach ($inHouseGuests as $guest): ?>
                                <option value="<?php echo $guest['booking_id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($guest['guest_name']); ?>"
                                        data-room="<?php echo htmlspecialchars($guest['room_number']); ?>">
                                    Room <?php echo $guest['room_number']; ?> - <?php echo htmlspecialchars($guest['guest_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="bf-form-row">
                        <div class="bf-form-group">
                            <label class="bf-label">Guest Name *</label>
                            <input type="text" name="guest_name" id="guest_name" class="bf-input" required>
                        </div>
                        <div class="bf-form-group">
                            <label class="bf-label">Room Number</label>
                            <input type="text" name="room_number" id="room_number" class="bf-input">
                        </div>
                    </div>
                </div>
                
                <!-- Time & Details -->
                <div class="bf-form-section">
                    <div class="bf-form-title">⏰ Schedule & Details</div>
                    <div class="bf-form-row">
                        <div class="bf-form-group">
                            <label class="bf-label">Total Pax *</label>
                            <input type="number" name="total_pax" id="total_pax" class="bf-input" min="1" max="20" required>
                        </div>
                        <div class="bf-form-group">
                            <label class="bf-label">Time *</label>
                            <input type="time" name="breakfast_time" id="breakfast_time" class="bf-input" required>
                        </div>
                        <div class="bf-form-group">
                            <label class="bf-label">Date *</label>
                            <input type="date" name="breakfast_date" id="breakfast_date" class="bf-input" value="<?php echo $today; ?>" required>
                        </div>
                    </div>
                    <div class="bf-form-row">
                        <div class="bf-form-group" style="grid-column: span 2;">
                            <label class="bf-label">Location *</label>
                            <div class="bf-radio-group">
                                <label class="bf-radio-label">
                                    <input type="radio" name="location" value="restaurant" checked>
                                    🍽️ Restaurant
                                </label>
                                <label class="bf-radio-label">
                                    <input type="radio" name="location" value="room_service">
                                    🛏️ Room Service
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Menu Selection -->
                <div class="bf-menu-section">
                    <div class="bf-form-title">🍽️ Select Menu Items</div>
                    
                    <?php if (count($freeMenus) > 0): ?>
                    <div class="bf-menu-category">
                        <div class="bf-menu-category-title">✨ Complimentary (Free)</div>
                        <div class="bf-menu-grid">
                            <?php foreach ($freeMenus as $menu): ?>
                            <div class="bf-menu-item">
                                <label class="bf-menu-checkbox">
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $menu['id']; ?>">
                                    <div class="bf-menu-info">
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($menu['menu_name']); ?></div>
                                        <span class="bf-menu-cat"><?php echo $menu['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size: 0.7rem; color: var(--text-muted);">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $menu['id']; ?>]" min="1" max="20" value="1" class="bf-qty-input">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($paidMenus) > 0): ?>
                    <div class="bf-menu-category">
                        <div class="bf-menu-category-title">💰 Extra Items (Paid)</div>
                        <div class="bf-menu-grid">
                            <?php foreach ($paidMenus as $menu): ?>
                            <div class="bf-menu-item">
                                <label class="bf-menu-checkbox">
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $menu['id']; ?>">
                                    <div class="bf-menu-info">
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($menu['menu_name']); ?></div>
                                        <div class="bf-menu-price">Rp <?php echo number_format($menu['price'], 0, ',', '.'); ?></div>
                                        <span class="bf-menu-cat"><?php echo $menu['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size: 0.7rem; color: var(--text-muted);">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $menu['id']; ?>]" min="1" max="20" value="1" class="bf-qty-input">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Notes -->
                <div class="bf-form-section">
                    <div class="bf-form-title">📝 Notes</div>
                    <textarea name="special_requests" id="special_requests" class="bf-textarea" 
                              placeholder="Allergies, special preparation, etc."></textarea>
                </div>

                <div class="bf-actions">
                    <button type="submit" class="bf-btn-submit">✓ Create Order</button>
                    <button type="reset" class="bf-btn-reset">↺ Reset</button>
                </div>
            </form>
        </div>

        <!-- Sidebar - Today's Orders -->
        <div class="bf-sidebar">
            <div class="bf-sidebar-title">📊 Today's Orders</div>
            
            <?php
            try {
                $todayOrders = [];
                $stmt = $pdo->prepare("
                    SELECT bo.*, b.booking_code, r.room_number as actual_room
                    FROM breakfast_orders bo
                    LEFT JOIN bookings b ON bo.booking_id = b.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE bo.breakfast_date = ?
                    ORDER BY bo.breakfast_time ASC
                ");
                $stmt->execute([$today]);
                $todayOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($todayOrders as &$order) {
                    $order['menu_items'] = json_decode($order['menu_items'], true) ?: [];
                }
            } catch (Exception $e) {
                $todayOrders = [];
            }
            
            if (count($todayOrders) > 0):
                foreach ($todayOrders as $order):
            ?>
            <div class="bf-order-item">
                <div class="bf-order-header">
                    <span class="bf-order-time">🕐 <?php echo date('H:i', strtotime($order['breakfast_time'])); ?></span>
                    <span class="bf-order-pax"><?php echo $order['total_pax']; ?> pax</span>
                </div>
                <div class="bf-order-guest"><?php echo htmlspecialchars($order['guest_name']); ?></div>
                <?php if (!empty($order['room_number']) || !empty($order['actual_room'])): ?>
                <div class="bf-order-room">🛏️ Room <?php echo htmlspecialchars($order['room_number'] ?: $order['actual_room']); ?></div>
                <?php endif; ?>
                <div class="bf-order-menus">
                    <?php foreach (array_slice($order['menu_items'], 0, 3) as $item): ?>
                    <span class="bf-order-menu-tag">
                        <?php echo htmlspecialchars($item['menu_name']); ?>
                        <?php if ($item['quantity'] > 1): ?>×<?php echo $item['quantity']; ?><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                    <?php if (count($order['menu_items']) > 3): ?>
                    <span class="bf-order-menu-tag">+<?php echo count($order['menu_items']) - 3; ?> more</span>
                    <?php endif; ?>
                </div>
                <div class="bf-order-footer">
                    <span class="bf-order-price">
                        <?php echo $order['total_price'] > 0 ? 'Rp ' . number_format($order['total_price'], 0, ',', '.') : 'Free'; ?>
                    </span>
                    <span class="bf-order-status <?php echo $order['order_status']; ?>">
                        <?php echo ucfirst($order['order_status']); ?>
                    </span>
                </div>
            </div>
            <?php 
                endforeach;
            else: 
            ?>
            <div class="bf-empty">
                <div class="bf-empty-icon">📭</div>
                <p style="font-size: 0.8rem;">No orders today</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function fillGuestInfo(select) {
    const option = select.options[select.selectedIndex];
    if (option.value) {
        document.getElementById('guest_name').value = option.dataset.name || '';
        document.getElementById('room_number').value = option.dataset.room || '';
    } else {
        document.getElementById('guest_name').value = '';
        document.getElementById('room_number').value = '';
    }
}

function toggleQuantity(checkbox) {
    const menuItem = checkbox.closest('.bf-menu-item');
    const qtyDiv = menuItem.querySelector('.bf-menu-qty');
    
    if (checkbox.checked) {
        qtyDiv.style.display = 'flex';
    } else {
        qtyDiv.style.display = 'none';
    }
}

/**
 * CLEANER FORM VALIDATION
 * Validate form before submission
 */
function validateBreakfastForm(e) {
    const guestName = document.getElementById('guest_name').value.trim();
    const totalPax = document.getElementById('total_pax').value.trim();
    const breakfastTime = document.getElementById('breakfast_time').value.trim();
    const breakfastDate = document.getElementById('breakfast_date').value.trim();
    
    // Validate required fields
    if (!guestName) {
        alert('❌ Guest name harus diisi!');
        document.getElementById('guest_name').focus();
        e.preventDefault();
        return false;
    }
    
    if (!totalPax || parseInt(totalPax) < 1) {
        alert('❌ Total pax harus diisi (minimal 1)!');
        document.getElementById('total_pax').focus();
        e.preventDefault();
        return false;
    }
    
    if (!breakfastTime) {
        alert('❌ Breakfast time harus diisi!');
        document.getElementById('breakfast_time').focus();
        e.preventDefault();
        return false;
    }
    
    if (!breakfastDate) {
        alert('❌ Breakfast date harus diisi!');
        document.getElementById('breakfast_date').focus();
        e.preventDefault();
        return false;
    }
    
    // Check that at least one menu item is selected
    const selectedMenus = document.querySelectorAll('input[name="menu_items[]"]:checked');
    
    if (selectedMenus.length === 0) {
        alert('❌ PILIH MINIMAL 1 MENU ITEM!\n\nSelect menu dari "Complimentary Breakfast" atau "Extra Items"');
        e.preventDefault();
        return false;
    }
    
    console.log('✅ Validation PASSED. ' + selectedMenus.length + ' menu items selected. Form akan di-submit...');
    return true; // Allow form to submit
}

// Attach validation to form
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('breakfastOrderForm');
    if (form) {
        form.addEventListener('submit', validateBreakfastForm);
        console.log('✅ breakfast form validation attached');
    } else {
        console.error('❌ Form dengan ID breakfastOrderForm tidak ditemukan!');
    }
});

// Auto-scroll to error messages
window.addEventListener('load', function() {
    const errorMsg = document.querySelector('.message.error');
    if (errorMsg) {
        errorMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
