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

// ==================== FLASH MESSAGE (PRG Pattern) ====================
if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ==================== AUTO-CLEANUP DUPLICATES ON PAGE LOAD ====================
try {
    // First, delete duplicates
    $cleanupQuery = "
        DELETE bo1 FROM breakfast_orders bo1
        INNER JOIN breakfast_orders bo2 
        ON bo1.guest_name = bo2.guest_name 
           AND bo1.breakfast_date = bo2.breakfast_date 
           AND bo1.breakfast_time = bo2.breakfast_time
           AND bo1.menu_items = bo2.menu_items
           AND bo1.id > bo2.id
        WHERE bo1.breakfast_date = ?
    ";
    $cleanStmt = $pdo->prepare($cleanupQuery);
    $cleanStmt->execute([$today]);
    
    // Update existing rows that don't have order_hash
    $pdo->exec("UPDATE breakfast_orders SET order_hash = MD5(CONCAT(guest_name, breakfast_date, breakfast_time, menu_items)) WHERE order_hash IS NULL OR order_hash = ''");
} catch (Exception $e) {
    error_log("Breakfast cleanup error: " . $e->getMessage());
}

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
    
    // ========== SIMPLE TOKEN CHECK ==========
    $formToken = $_POST['_form_token'] ?? '';
    $sessionToken = $_SESSION['bf_form_token'] ?? '';
    
    if (empty($formToken) || $formToken !== $sessionToken) {
        $_SESSION['flash_message'] = "⚠️ Form expired, silakan coba lagi.";
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    // Clear token immediately to prevent resubmit
    unset($_SESSION['bf_form_token']);
    
    try {
        if ($_POST['action'] === 'update_order') {
            if (!$validUserId) {
                throw new Exception("❌ Sistem error: User tidak ditemukan di database.");
            }
            $editId = (int)($_POST['edit_id'] ?? 0);
            if ($editId <= 0) throw new Exception("❌ ID order tidak valid");
            
            if (empty($_POST['guest_name']) || empty($_POST['total_pax']) || empty($_POST['breakfast_time']) || empty($_POST['breakfast_date'])) {
                throw new Exception("❌ Semua field wajib harus diisi");
            }
            if (empty($_POST['menu_items']) || !is_array($_POST['menu_items'])) {
                throw new Exception("❌ Pilih minimal 1 menu item");
            }
            
            $menuItems = [];
            $totalPrice = 0;
            foreach ($_POST['menu_items'] as $menuId) {
                $qty = (int)($_POST['menu_qty'][$menuId] ?? 1);
                $note = isset($_POST['menu_note'][$menuId]) ? trim($_POST['menu_note'][$menuId]) : '';
                if ($qty > 0) {
                    $menuStmt = $pdo->prepare("SELECT menu_name, price, is_free FROM breakfast_menus WHERE id = ?");
                    $menuStmt->execute([$menuId]);
                    $menu = $menuStmt->fetch(PDO::FETCH_ASSOC);
                    if ($menu) {
                        $item = [
                            'menu_id' => $menuId,
                            'menu_name' => $menu['menu_name'],
                            'quantity' => $qty,
                            'price' => $menu['price'],
                            'is_free' => $menu['is_free']
                        ];
                        if ($note !== '') $item['note'] = $note;
                        $menuItems[] = $item;
                        if (!$menu['is_free']) $totalPrice += ($menu['price'] * $qty);
                    }
                }
            }
            if (count($menuItems) === 0) throw new Exception("❌ No valid menu items selected");
            
            // Handle multiple room_number as array
            $roomNumbers = isset($_POST['room_number']) ? $_POST['room_number'] : [];
            if (!is_array($roomNumbers)) {
                $roomNumbers = [$roomNumbers];
            }
            $stmt = $pdo->prepare("UPDATE breakfast_orders SET 
                booking_id=?, guest_name=?, room_number=?, total_pax=?, breakfast_time=?, 
                breakfast_date=?, location=?, menu_items=?, special_requests=?, total_price=?
                WHERE id=?");
            $stmt->execute([
                !empty($_POST['booking_id']) ? (int)$_POST['booking_id'] : null,
                trim($_POST['guest_name']),
                json_encode($roomNumbers),
                (int)$_POST['total_pax'],
                $_POST['breakfast_time'],
                $_POST['breakfast_date'],
                $_POST['location'] ?? 'restaurant',
                json_encode($menuItems),
                !empty($_POST['special_requests']) ? trim($_POST['special_requests']) : null,
                $totalPrice,
                $editId
            ]);
            
            $_SESSION['flash_message'] = "✅ Order #$editId berhasil diupdate!";
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
            
        } elseif ($_POST['action'] === 'create_order') {
            // Validate user exists
            if (!$validUserId) {
                throw new Exception("❌ Sistem error: User tidak ditemukan di database. Hubungi administrator.");
            }
            
            // Create breakfast_orders table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
                id INT PRIMARY KEY AUTO_INCREMENT,
                booking_id INT NULL,
                guest_name VARCHAR(100) NOT NULL,
                room_number TEXT,
                total_pax INT NOT NULL,
                breakfast_time TIME NOT NULL,
                breakfast_date DATE NOT NULL,
                location ENUM('restaurant', 'room_service', 'take_away') DEFAULT 'restaurant',
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

            // Add take_away to location enum if not exists
            try {
                $pdo->exec("ALTER TABLE breakfast_orders MODIFY COLUMN location ENUM('restaurant', 'room_service', 'take_away') DEFAULT 'restaurant'");
            } catch (Exception $e) { /* already updated */ }
            
            // Upgrade room_number to TEXT for JSON storage
            try {
                $pdo->exec("ALTER TABLE breakfast_orders MODIFY COLUMN room_number TEXT");
            } catch (Exception $e) { /* already updated */ }
            
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
                $note = isset($_POST['menu_note'][$menuId]) ? trim($_POST['menu_note'][$menuId]) : '';
                if ($qty > 0) {
                    // Get menu price
                    $menuStmt = $pdo->prepare("SELECT menu_name, price, is_free FROM breakfast_menus WHERE id = ?");
                    $menuStmt->execute([$menuId]);
                    $menu = $menuStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($menu) {
                        $item = [
                            'menu_id' => $menuId,
                            'menu_name' => $menu['menu_name'],
                            'quantity' => $qty,
                            'price' => $menu['price'],
                            'is_free' => $menu['is_free']
                        ];
                        if ($note !== '') $item['note'] = $note;
                        $menuItems[] = $item;
                        
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
            // Handle multiple room_number as array
            $roomNumbers = isset($_POST['room_number']) ? $_POST['room_number'] : [];
            if (!is_array($roomNumbers)) {
                $roomNumbers = [$roomNumbers];
            }
            
            // === CREATE UNIQUE INDEX TO PREVENT DUPLICATES AT DATABASE LEVEL ===
            try {
                $pdo->exec("ALTER TABLE breakfast_orders ADD COLUMN order_hash VARCHAR(32)");
            } catch (Exception $e) { /* Column already exists */ }
            try {
                $pdo->exec("CREATE UNIQUE INDEX idx_order_unique ON breakfast_orders (order_hash)");
            } catch (Exception $e) { /* Index already exists */ }
            
            // === INSERT with database-level uniqueness ===
            $menuJson = json_encode($menuItems);
            $orderHash = md5(trim($_POST['guest_name']) . $_POST['breakfast_date'] . $_POST['breakfast_time'] . $menuJson);
            
            try {
                $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
                    (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, location, 
                     menu_items, special_requests, total_price, created_by, order_hash) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    !empty($_POST['booking_id']) ? (int)$_POST['booking_id'] : null,
                    trim($_POST['guest_name']),
                    json_encode($roomNumbers),
                    (int)$_POST['total_pax'],
                    $_POST['breakfast_time'],
                    $_POST['breakfast_date'],
                    $_POST['location'] ?? 'restaurant',
                    $menuJson,
                    !empty($_POST['special_requests']) ? trim($_POST['special_requests']) : null,
                    $totalPrice,
                    $validUserId,
                    $orderHash
                ]);
                
                $lastOrderId = $pdo->lastInsertId();
                
                $itemscount = count($menuItems);
                $guestName = trim($_POST['guest_name']);
                $_SESSION['flash_message'] = "✅ Berhasil! Pesanan untuk <strong>" . htmlspecialchars($guestName) . "</strong> tersimpan (ID #$lastOrderId)";
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
                
            } catch (PDOException $dupEx) {
                // Error code 23000 is duplicate key violation
                if ($dupEx->getCode() == 23000 || strpos($dupEx->getMessage(), 'Duplicate') !== false) {
                    $_SESSION['flash_message'] = "⚠️ Order untuk " . htmlspecialchars(trim($_POST['guest_name'])) . " sudah ada.";
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                }
                throw $dupEx; // Re-throw if it's a different error
            }
        }
    } catch (Exception $e) {
        $error = "❌ Error: " . $e->getMessage();
        error_log("Breakfast Order Error: " . $e->getMessage());
    }
}

// ==================== EDIT MODE ====================
$editOrder = null;
$editMenuIds = [];
$editMenuQty = [];
$editMenuNotes = [];
$editRooms = [];
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editOrder = $db->fetchOne("SELECT * FROM breakfast_orders WHERE id = ?", [$editId]);
    if ($editOrder) {
        $items = json_decode($editOrder['menu_items'], true) ?: [];
        foreach ($items as $item) {
            $editMenuIds[] = $item['menu_id'];
            $editMenuQty[$item['menu_id']] = $item['quantity'];
            if (!empty($item['note'])) $editMenuNotes[$item['menu_id']] = $item['note'];
        }
        // Decode room_number JSON array for edit mode
        $decoded = json_decode($editOrder['room_number'], true);
        $editRooms = is_array($decoded) ? $decoded : ($editOrder['room_number'] ? [$editOrder['room_number']] : []);
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

.bf-menu-note {
    display: none;
    margin-top: 0.35rem;
}

.bf-menu-item:has(input[type="checkbox"]:checked) .bf-menu-note {
    display: block;
}

.bf-note-input {
    width: 100%;
    padding: 0.3rem 0.5rem;
    border-radius: 4px;
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 0.72rem;
    font-family: inherit;
}

.bf-note-input::placeholder {
    color: var(--text-muted);
    font-style: italic;
}

.bf-order-note {
    font-size: 0.58rem;
    color: #f59e0b;
    font-style: italic;
    display: block;
}

.bf-order-actions {
    display: flex;
    gap: 0.35rem;
    margin-top: 0.4rem;
}

.bf-order-btn {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.bf-order-btn.edit {
    background: rgba(99, 102, 241, 0.15);
    color: #6366f1;
}

.bf-order-btn.edit:hover {
    background: rgba(99, 102, 241, 0.3);
}

.bf-order-btn.delete {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.bf-order-btn.delete:hover {
    background: rgba(239, 68, 68, 0.3);
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

/* Multi-select Guest/Room Dropdown */
.bf-guest-multiselect {
    position: relative;
}

.bf-multiselect-toggle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.55rem 0.65rem;
    border-radius: 6px;
    background: var(--bg-primary);
    border: 1px solid var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 0.85rem;
    cursor: pointer;
    transition: border-color 0.2s;
    min-height: 38px;
}

.bf-multiselect-toggle:hover {
    border-color: var(--primary-color);
}

.bf-multiselect-toggle.active {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
}

.bf-multiselect-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--bg-secondary);
    border: 1px solid var(--primary-color);
    border-radius: 0 0 8px 8px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 100;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}

.bf-multiselect-dropdown.show {
    display: block;
}

.bf-guest-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 0.75rem;
    cursor: pointer;
    font-size: 0.82rem;
    color: var(--text-primary);
    border-bottom: 1px solid var(--bg-tertiary);
    transition: background 0.15s;
}

.bf-guest-option:last-child {
    border-bottom: none;
}

.bf-guest-option:hover {
    background: rgba(99, 102, 241, 0.1);
}

.bf-guest-option input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
    flex-shrink: 0;
}

.bf-guest-option.checked {
    background: rgba(16, 185, 129, 0.1);
}

/* Room Tags */
.bf-room-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.5rem;
}

.bf-room-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.6rem;
    background: rgba(99, 102, 241, 0.15);
    border: 1px solid rgba(99, 102, 241, 0.3);
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--primary-color);
}

.bf-room-tag .remove-room {
    cursor: pointer;
    font-size: 0.85rem;
    line-height: 1;
    opacity: 0.7;
    transition: opacity 0.15s;
}

.bf-room-tag .remove-room:hover {
    opacity: 1;
    color: #ef4444;
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
            <form method="POST" action="" id="breakfastOrderForm" autocomplete="off">
                <input type="hidden" name="action" value="<?php echo $editOrder ? 'update_order' : 'create_order'; ?>">
                <?php 
                // Generate unique form token to prevent duplicate submission
                $formToken = bin2hex(random_bytes(16));
                $_SESSION['bf_form_token'] = $formToken;
                ?>
                <input type="hidden" name="_form_token" value="<?php echo $formToken; ?>">
                <?php if ($editOrder): ?>
                <input type="hidden" name="edit_id" value="<?php echo $editOrder['id']; ?>">
                <?php endif; ?>
                
                <!-- Guest Info Section -->
                <div class="bf-form-section">
                    <div class="bf-form-title">👤 Guest Information</div>
                    <div class="bf-form-row">
                        <div class="bf-form-group" style="grid-column: span 2;">
                            <label class="bf-label">Pilih Kamar Tamu (bisa lebih dari 1)</label>
                            <div class="bf-guest-multiselect" id="guestMultiselect">
                                <div class="bf-multiselect-toggle" id="guestToggle" onclick="toggleGuestDropdown()">
                                    <span id="guestSelectLabel">-- Pilih Kamar / Walk-in --</span>
                                    <span style="font-size: 0.7rem;">▼</span>
                                </div>
                                <div class="bf-multiselect-dropdown" id="guestDropdown">
                                    <?php foreach ($inHouseGuests as $guest): ?>
                                    <label class="bf-guest-option">
                                        <input type="checkbox" class="guest-checkbox" 
                                               value="<?php echo $guest['booking_id']; ?>"
                                               data-name="<?php echo htmlspecialchars($guest['guest_name']); ?>"
                                               data-room="<?php echo htmlspecialchars($guest['room_number']); ?>"
                                               <?php echo (in_array($guest['room_number'], $editRooms)) ? 'checked' : ''; ?>
                                               onchange="updateSelectedGuests()">
                                        <span>🛏️ Room <?php echo $guest['room_number']; ?> — <?php echo htmlspecialchars($guest['guest_name']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                    <?php if (empty($inHouseGuests)): ?>
                                    <div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">Tidak ada tamu in-house</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div id="selectedRoomTags" class="bf-room-tags"></div>
                            <input type="hidden" name="booking_id" id="booking_id" value="<?php echo $editOrder ? (int)$editOrder['booking_id'] : ''; ?>">
                            <div id="roomInputsContainer"></div>
                        </div>
                    </div>
                    <div class="bf-form-row">
                        <div class="bf-form-group" style="grid-column: span 2;">
                            <label class="bf-label">Nama Tamu *</label>
                            <input type="text" name="guest_name" id="guest_name" class="bf-input" required 
                                   placeholder="Otomatis terisi saat pilih kamar, atau ketik manual"
                                   value="<?php echo $editOrder ? htmlspecialchars($editOrder['guest_name']) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <!-- Time & Details -->
                <div class="bf-form-section">
                    <div class="bf-form-title">⏰ Schedule & Details</div>
                    <div class="bf-form-row">
                        <div class="bf-form-group">
                            <label class="bf-label">Total Pax *</label>
                            <input type="number" name="total_pax" id="total_pax" class="bf-input" min="1" max="20" required value="<?php echo $editOrder ? (int)$editOrder['total_pax'] : ''; ?>">
                        </div>
                        <div class="bf-form-group">
                            <label class="bf-label">Time *</label>
                            <input type="time" name="breakfast_time" id="breakfast_time" class="bf-input" required value="<?php echo $editOrder ? $editOrder['breakfast_time'] : ''; ?>">
                        </div>
                        <div class="bf-form-group">
                            <label class="bf-label">Date *</label>
                            <input type="date" name="breakfast_date" id="breakfast_date" class="bf-input" value="<?php echo $editOrder ? $editOrder['breakfast_date'] : $today; ?>" required>
                        </div>
                    </div>
                    <div class="bf-form-row">
                        <div class="bf-form-group" style="grid-column: span 2;">
                            <label class="bf-label">Location *</label>
                            <div class="bf-radio-group">
                                <label class="bf-radio-label">
                                    <input type="radio" name="location" value="restaurant" <?php echo (!$editOrder || $editOrder['location'] === 'restaurant') ? 'checked' : ''; ?>>
                                    🍽️ Restaurant
                                </label>
                                <label class="bf-radio-label">
                                    <input type="radio" name="location" value="room_service" <?php echo ($editOrder && $editOrder['location'] === 'room_service') ? 'checked' : ''; ?>>
                                    🛏️ Room Service
                                </label>
                                <label class="bf-radio-label">
                                    <input type="radio" name="location" value="take_away" <?php echo ($editOrder && $editOrder['location'] === 'take_away') ? 'checked' : ''; ?>>
                                    🥡 Take Away
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
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $menu['id']; ?>" <?php echo in_array($menu['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                    <div class="bf-menu-info">
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($menu['menu_name']); ?></div>
                                        <span class="bf-menu-cat"><?php echo $menu['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size: 0.7rem; color: var(--text-muted);">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $menu['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$menu['id']] ?? 1; ?>" class="bf-qty-input">
                                </div>
                                <div class="bf-menu-note">
                                    <input type="text" name="menu_note[<?php echo $menu['id']; ?>]" class="bf-note-input" 
                                           placeholder="Ket: pedas/tidak, ice/hot, dll" 
                                           value="<?php echo htmlspecialchars($editMenuNotes[$menu['id']] ?? ''); ?>">
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
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $menu['id']; ?>" <?php echo in_array($menu['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                    <div class="bf-menu-info">
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($menu['menu_name']); ?></div>
                                        <div class="bf-menu-price">Rp <?php echo number_format($menu['price'], 0, ',', '.'); ?></div>
                                        <span class="bf-menu-cat"><?php echo $menu['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size: 0.7rem; color: var(--text-muted);">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $menu['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$menu['id']] ?? 1; ?>" class="bf-qty-input">
                                </div>
                                <div class="bf-menu-note">
                                    <input type="text" name="menu_note[<?php echo $menu['id']; ?>]" class="bf-note-input" 
                                           placeholder="Ket: pedas/tidak, ice/hot, dll" 
                                           value="<?php echo htmlspecialchars($editMenuNotes[$menu['id']] ?? ''); ?>">
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
                              placeholder="Allergies, special preparation, etc."><?php echo $editOrder ? htmlspecialchars($editOrder['special_requests'] ?? '') : ''; ?></textarea>
                </div>

                <div class="bf-actions">
                    <button type="submit" class="bf-btn-submit"><?php echo $editOrder ? '✓ Update Order' : '✓ Create Order'; ?></button>
                    <?php if ($editOrder): ?>
                    <a href="breakfast.php" class="bf-btn-reset" style="text-decoration:none; text-align:center;">✕ Cancel Edit</a>
                    <?php else: ?>
                    <button type="reset" class="bf-btn-reset">↺ Reset</button>
                    <?php endif; ?>
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
                <?php 
                    $roomDisplay = $order['room_number'] ?: $order['actual_room'];
                    $decodedRooms = json_decode($roomDisplay, true);
                    if (is_array($decodedRooms)) {
                        $roomDisplay = implode(', ', $decodedRooms);
                    }
                ?>
                <?php if (!empty($roomDisplay)): ?>
                <div class="bf-order-room">🛏️ Room <?php echo htmlspecialchars($roomDisplay); ?></div>
                <?php endif; ?>
                <div class="bf-order-room"><?php echo $order['location'] === 'restaurant' ? '🍽️ Restaurant' : ($order['location'] === 'take_away' ? '🥡 Take Away' : '🚪 Room Service'); ?></div>
                <div class="bf-order-menus">
                    <?php foreach ($order['menu_items'] as $item): ?>
                    <span class="bf-order-menu-tag">
                        <?php echo htmlspecialchars($item['menu_name']); ?>
                        <?php if ($item['quantity'] > 1): ?>×<?php echo $item['quantity']; ?><?php endif; ?>
                        <?php if (!empty($item['note'])): ?>
                        <span class="bf-order-note">(<?php echo htmlspecialchars($item['note']); ?>)</span>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <div class="bf-order-footer">
                    <span class="bf-order-price">
                        <?php echo $order['total_price'] > 0 ? 'Rp ' . number_format($order['total_price'], 0, ',', '.') : 'Free'; ?>
                    </span>
                    <span class="bf-order-status <?php echo $order['order_status']; ?>">
                        <?php echo ucfirst($order['order_status']); ?>
                    </span>
                </div>
                <div class="bf-order-actions">
                    <a href="?edit=<?php echo $order['id']; ?>" class="bf-order-btn edit">✏️ Edit</a>
                    <button class="bf-order-btn delete" onclick="deleteOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars(addslashes($order['guest_name'])); ?>')">🗑️ Hapus</button>
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
// ==================== MULTI-SELECT GUEST/ROOM ====================
let guestDropdownOpen = false;

function toggleGuestDropdown() {
    const dropdown = document.getElementById('guestDropdown');
    const toggle = document.getElementById('guestToggle');
    guestDropdownOpen = !guestDropdownOpen;
    dropdown.classList.toggle('show', guestDropdownOpen);
    toggle.classList.toggle('active', guestDropdownOpen);
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const multiselect = document.getElementById('guestMultiselect');
    if (multiselect && !multiselect.contains(e.target)) {
        guestDropdownOpen = false;
        document.getElementById('guestDropdown').classList.remove('show');
        document.getElementById('guestToggle').classList.remove('active');
    }
});

function updateSelectedGuests() {
    const checkboxes = document.querySelectorAll('.guest-checkbox:checked');
    const names = [];
    const rooms = [];
    let firstBookingId = '';

    // Update option styling
    document.querySelectorAll('.guest-option-item, .bf-guest-option').forEach(opt => opt.classList.remove('checked'));

    checkboxes.forEach(function(cb, idx) {
        cb.closest('.bf-guest-option').classList.add('checked');
        const name = cb.dataset.name;
        const room = cb.dataset.room;
        if (name && names.indexOf(name) === -1) names.push(name);
        if (room) rooms.push(room);
        if (idx === 0) firstBookingId = cb.value;
    });

    // Update label
    const label = document.getElementById('guestSelectLabel');
    if (rooms.length === 0) {
        label.textContent = '-- Pilih Kamar / Walk-in --';
    } else if (rooms.length === 1) {
        label.textContent = 'Room ' + rooms[0] + ' — ' + names[0];
    } else {
        label.textContent = rooms.length + ' kamar dipilih (Room ' + rooms.join(', ') + ')';
    }

    // Update guest name
    document.getElementById('guest_name').value = names.join(', ');

    // Update booking_id (first selected)
    document.getElementById('booking_id').value = firstBookingId;

    // Render room tags
    renderRoomTags(rooms, checkboxes);

    // Create hidden inputs for room_number[]
    const container = document.getElementById('roomInputsContainer');
    container.innerHTML = '';
    rooms.forEach(function(room) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'room_number[]';
        input.value = room;
        container.appendChild(input);
    });

    // Auto-calculate total pax (1 per room as default suggestion)
    const paxInput = document.getElementById('total_pax');
    if (rooms.length > 0 && (!paxInput.value || paxInput.dataset.autoset === '1')) {
        paxInput.value = rooms.length;
        paxInput.dataset.autoset = '1';
    }
    if (rooms.length === 0) {
        paxInput.dataset.autoset = '0';
    }
}

function renderRoomTags(rooms, checkboxes) {
    const container = document.getElementById('selectedRoomTags');
    container.innerHTML = '';
    rooms.forEach(function(room, idx) {
        const tag = document.createElement('span');
        tag.className = 'bf-room-tag';
        tag.innerHTML = '🛏️ Room ' + room + ' <span class="remove-room" onclick="removeRoom(' + idx + ')">&times;</span>';
        container.appendChild(tag);
    });
}

function removeRoom(idx) {
    const checkboxes = document.querySelectorAll('.guest-checkbox:checked');
    const arr = Array.from(checkboxes);
    if (arr[idx]) {
        arr[idx].checked = false;
    }
    updateSelectedGuests();
}

// ==================== FORM VALIDATION ====================
let formSubmitting = false; // Global flag

function validateBreakfastForm(e) {
    // CRITICAL: Prevent double submission
    if (formSubmitting) {
        e.preventDefault();
        return false;
    }
    
    const guestName = document.getElementById('guest_name').value.trim();
    const totalPax = document.getElementById('total_pax').value.trim();
    const breakfastTime = document.getElementById('breakfast_time').value.trim();
    const breakfastDate = document.getElementById('breakfast_date').value.trim();

    if (!guestName) {
        alert('❌ Nama tamu harus diisi!');
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
        alert('❌ Waktu sarapan harus diisi!');
        document.getElementById('breakfast_time').focus();
        e.preventDefault();
        return false;
    }

    if (!breakfastDate) {
        alert('❌ Tanggal sarapan harus diisi!');
        document.getElementById('breakfast_date').focus();
        e.preventDefault();
        return false;
    }

    const selectedMenus = document.querySelectorAll('input[name="menu_items[]"]:checked');
    if (selectedMenus.length === 0) {
        alert('❌ PILIH MINIMAL 1 MENU ITEM!\n\nPilih menu dari "Complimentary Breakfast" atau "Extra Items"');
        e.preventDefault();
        return false;
    }

    // Ensure room_number hidden inputs exist if rooms were selected
    const roomInputs = document.querySelectorAll('input[name="room_number[]"]');
    // If no rooms selected via checkboxes, that's okay (walk-in)

    // === ANTI-DUPLICATE: Disable submit button IMMEDIATELY on click ===
    formSubmitting = true; // Set flag FIRST
    
    const submitBtn = document.querySelector('.bf-btn-submit');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Menyimpan...';
        submitBtn.style.opacity = '0.6';
        submitBtn.style.pointerEvents = 'none';
    }
    
    // Also disable form to prevent any resubmission
    const form = document.getElementById('breakfastOrderForm');
    if (form) {
        form.style.pointerEvents = 'none';
        form.style.opacity = '0.7';
    }

    return true;
}

// ==================== INIT ====================
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('breakfastOrderForm');
    if (form) {
        form.addEventListener('submit', validateBreakfastForm);
    }

    const isEditMode = <?php echo $editOrder ? 'true' : 'false'; ?>;

    if (isEditMode) {
        // Edit mode: initialize selected guests from pre-checked checkboxes
        const preChecked = document.querySelectorAll('.guest-checkbox:checked');
        if (preChecked.length > 0) {
            updateSelectedGuests();
        }
        // Preserve edit guest name
        document.getElementById('guest_name').value = <?php echo $editOrder ? json_encode($editOrder['guest_name']) : '""'; ?>;
    } else {
        // === CREATE MODE: Force-reset form to prevent browser cache ===
        // Uncheck ALL guest checkboxes
        document.querySelectorAll('.guest-checkbox').forEach(function(cb) {
            cb.checked = false;
        });
        // Clear guest info
        document.getElementById('guest_name').value = '';
        document.getElementById('booking_id').value = '';
        document.getElementById('roomInputsContainer').innerHTML = '';
        document.getElementById('selectedRoomTags').innerHTML = '';
        document.getElementById('guestSelectLabel').textContent = '-- Pilih Kamar / Walk-in --';
        
        // Clear pax & time (keep date as today)
        document.getElementById('total_pax').value = '';
        document.getElementById('breakfast_time').value = '';
        
        // Uncheck ALL menu checkboxes
        document.querySelectorAll('input[name="menu_items[]"]').forEach(function(cb) {
            cb.checked = false;
        });
        // Reset all qty to 1
        document.querySelectorAll('.bf-qty-input').forEach(function(inp) {
            inp.value = 1;
        });
        // Clear all menu notes
        document.querySelectorAll('.bf-note-input').forEach(function(inp) {
            inp.value = '';
        });
        // Clear special requests
        var sr = document.getElementById('special_requests');
        if (sr) sr.value = '';
        
        // Reset location to restaurant
        var restRadio = document.querySelector('input[name="location"][value="restaurant"]');
        if (restRadio) restRadio.checked = true;
    }

    // Mark pax as manually set if user changes it
    const paxInput = document.getElementById('total_pax');
    if (paxInput) {
        paxInput.addEventListener('input', function() {
            this.dataset.autoset = '0';
        });
    }
});

// ==================== DELETE ORDER ====================
function deleteOrder(id, guestName) {
    if (!confirm('Hapus order sarapan untuk "' + guestName + '"?\n\nData tidak bisa dikembalikan.')) return;
    
    fetch('<?php echo BASE_URL; ?>/api/breakfast-order-action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Gagal hapus: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(() => alert('Gagal menghubungi server'));
}
</script>

<?php include '../../includes/footer.php'; ?>
