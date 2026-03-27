<?php
/**
 * BREAKFAST LIST - In-house Guests
 * Mark breakfast status untuk tamu yang sedang menginap
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

$db = Database::getInstance();
$pdo = $db->getConnection();

$today = date('Y-m-d');
$message = '';
$error = '';

// ==================== HANDLE BREAKFAST ORDER ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'create_order') {
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
                FOREIGN KEY (booking_id) REFERENCES bookings(id),
                INDEX idx_date (breakfast_date),
                INDEX idx_status (order_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Parse menu items from form
            $menuItems = [];
            $totalPrice = 0;
            
            if (!empty($_POST['menu_items'])) {
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
            }
            
            // Insert order
            $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
                (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, location, 
                 menu_items, special_requests, total_price, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                !empty($_POST['booking_id']) ? $_POST['booking_id'] : null,
                $_POST['guest_name'],
                $_POST['room_number'] ?? null,
                $_POST['total_pax'],
                $_POST['breakfast_time'],
                $_POST['breakfast_date'],
                $_POST['location'],
                json_encode($menuItems),
                $_POST['special_requests'] ?? null,
                $totalPrice,
                $_SESSION['user_id']
            ]);
            
            $message = "✓ Breakfast order created successfully!";
        }
    } catch (Exception $e) {
        $error = "❌ Error: " . $e->getMessage();
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
        AND DATE(b.check_in_date) <= ?
        AND DATE(b.check_out_date) > ?
        ORDER BY r.room_number ASC
    ");
    $stmt->execute([$today, $today]);
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<style>
:root {
    --primary: #6366f1;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --info: #3b82f6;
    --bg-secondary: rgba(255, 255, 255, 0.08);
    --border-color: rgba(255, 255, 255, 0.15);
    --text-primary: var(--text-color);
    --text-secondary: rgba(255, 255, 255, 0.7);
}

.breakfast-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
}

.breakfast-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    gap: 2rem;
}

.breakfast-header h1 {
    font-size: 2.5rem;
    font-weight: 950;
    background: linear-gradient(135deg, #f59e0b, #f97316);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 1rem;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
}

.message {
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    font-weight: 600;
    animation: slideIn 0.3s ease;
}

.message.success {
    background: rgba(16, 185, 129, 0.2);
    border: 1px solid rgba(16, 185, 129, 0.5);
    color: #6ee7b7;
}

.message.error {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.5);
    color: #fca5a5;
}

@keyframes slideIn {
    from {
        transform: translateY(-10px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--bg-secondary);
    backdrop-filter: blur(30px);
    border: 1px solid var(--border-color);
    border-radius: 15px;
    padding: 1.5rem;
    text-align: center;
}

.stat-value {
    font-size: 2.5rem;
    font-weight: 950;
    margin: 0.5rem 0;
}

.stat-label {
    color: var(--text-secondary);
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-taken {
    border-left: 4px solid var(--success);
}

.stat-taken .stat-value {
    color: #6ee7b7;
}

.stat-not-taken {
    border-left: 4px solid var(--warning);
}

.stat-not-taken .stat-value {
    color: #fbbf24;
}

.stat-skipped {
    border-left: 4px solid var(--danger);
}

.stat-skipped .stat-value {
    color: #fca5a5;
}

.stat-total {
    border-left: 4px solid var(--info);
}

.stat-total .stat-value {
    color: #93c5fd;
}

/* Guest Cards Grid */
.guest-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
}

.guest-card {
    background: var(--bg-secondary);
    backdrop-filter: blur(30px);
    border: 1px solid var(--border-color);
    border-radius: 15px;
    padding: 1.5rem;
    transition: all 0.3s ease;
    animation: fadeIn 0.5s ease;
}

.guest-card:hover {
    transform: translateY(-4px);
    border-color: var(--primary);
    box-shadow: 0 12px 24px rgba(99, 102, 241, 0.2);
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.guest-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
    gap: 1rem;
}

.room-badge {
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    color: white;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}

.guest-name {
    font-size: 1.3rem;
    font-weight: 950;
    color: var(--text-primary);
    margin: 0;
}

.guest-info {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.9rem;
}

.info-label {
    color: var(--text-secondary);
    font-weight: 600;
}

.info-value {
    color: var(--text-primary);
    font-weight: 700;
}

.info-phone {
    color: var(--primary);
    text-decoration: none;
    font-weight: 700;
}

.info-phone:hover {
    text-decoration: underline;
}

.dates {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    background: rgba(99, 102, 241, 0.1);
    border-radius: 8px;
    font-size: 0.85rem;
    margin-bottom: 1.5rem;
    color: var(--text-secondary);
}

.dates-info {
    font-weight: 600;
}

/* Breakfast Status Form */
.breakfast-form {
    border-top: 1px solid var(--border-color);
    padding-top: 1rem;
}

.breakfast-options {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.breakfast-radio {
    display: none;
}

.breakfast-label {
    flex: 1;
    padding: 0.75rem;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 700;
    font-size: 0.9rem;
}

.breakfast-radio:checked + .breakfast-label {
    border-color: currentColor;
    background: currentColor;
    color: white;
}

.breakfast-radio[value="taken"] + .breakfast-label {
    color: var(--success);
}

.breakfast-radio[value="taken"]:checked + .breakfast-label {
    background: var(--success);
    color: white;
}

.breakfast-radio[value="not_taken"] + .breakfast-label {
    color: var(--warning);
}

.breakfast-radio[value="not_taken"]:checked + .breakfast-label {
    background: var(--warning);
    color: white;
}

.breakfast-radio[value="skipped"] + .breakfast-label {
    color: var(--danger);
}

.breakfast-radio[value="skipped"]:checked + .breakfast-label {
    background: var(--danger);
    color: white;
}

.breakfast-notes {
    width: 100%;
    padding: 0.5rem;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-primary);
    font-size: 0.85rem;
    margin-bottom: 1rem;
    font-family: inherit;
}

.breakfast-notes::placeholder {
    color: var(--text-secondary);
}

.breakfast-btn {
    width: 100%;
    padding: 0.75rem;
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
}

.breakfast-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
}

.no-guests {
    text-align: center;
    padding: 3rem 2rem;
    color: var(--text-secondary);
}

.no-guests-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
}

.no-guests-text {
    font-size: 1.1rem;
    font-weight: 600;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-taken {
    background: rgba(16, 185, 129, 0.2);
    color: #6ee7b7;
}

.status-not-taken {
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
}

.status-skipped {
    background: rgba(239, 68, 68, 0.2);
    color: #fca5a5;
}

.status-pending {
    background: rgba(99, 102, 241, 0.2);
    color: #a5b4fc;
}

/* Responsive */
@media (max-width: 768px) {
    .breakfast-container {
        padding: 1rem;
    }

    .breakfast-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .breakfast-header h1 {
        font-size: 1.75rem;
    }

    .guest-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .breakfast-options {
        flex-wrap: wrap;
    }

    .breakfast-label {
        flex: 1;
        min-width: 80px;
    }
}
</style>

<div class="breakfast-container">
    <!-- Header -->
    <div class="breakfast-header">
        <div>
            <h1>🍽️ Breakfast List</h1>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                Mark breakfast status untuk in-house guests • <?php echo date('l, d F Y'); ?>
            </p>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" class="btn btn-primary">🏠 Dashboard</a>
            <a href="reservasi.php" class="btn btn-primary">📋 Reservasi</a>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="message success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="message error"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Statistics -->
    <?php if ($stats['total'] > 0 || count($inHouseGuests) > 0): ?>
    <div class="stats-grid">
        <div class="stat-card stat-taken">
            <div class="stat-icon">✓</div>
            <div class="stat-value"><?php echo $stats['taken'] ?? 0; ?></div>
            <div class="stat-label">Breakfast Taken</div>
        </div>
        <div class="stat-card stat-not-taken">
            <div class="stat-icon">⚠️</div>
            <div class="stat-value"><?php echo $stats['not_taken'] ?? 0; ?></div>
            <div class="stat-label">Not Taken</div>
        </div>
        <div class="stat-card stat-skipped">
            <div class="stat-icon">✕</div>
            <div class="stat-value"><?php echo $stats['skipped'] ?? 0; ?></div>
            <div class="stat-label">Skipped</div>
        </div>
        <div class="stat-card stat-total">
            <div class="stat-icon">📊</div>
            <div class="stat-value"><?php echo count($inHouseGuests); ?></div>
            <div class="stat-label">Total Guests</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Guest Cards -->
    <?php if (count($inHouseGuests) > 0): ?>
    <div class="guest-grid">
        <?php foreach ($inHouseGuests as $guest): ?>
        <div class="guest-card">
            <!-- Header -->
            <div class="guest-header">
                <div>
                    <h3 class="guest-name"><?php echo htmlspecialchars($guest['guest_name']); ?></h3>
                </div>
                <div class="room-badge">
                    🚪 <?php echo htmlspecialchars($guest['room_number']); ?>
                </div>
            </div>

            <!-- Guest Info -->
            <div class="guest-info">
                <div class="info-row">
                    <span class="info-label">Type</span>
                    <span class="info-value"><?php echo htmlspecialchars($guest['type_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone</span>
                    <a href="tel:<?php echo htmlspecialchars($guest['phone']); ?>" class="info-phone">
                        <?php echo htmlspecialchars($guest['phone'] ?? '-'); ?>
                    </a>
                </div>
                <div class="info-row">
                    <span class="info-label">Booking Code</span>
                    <span class="info-value" style="font-family: monospace;">
                        <?php echo htmlspecialchars($guest['booking_code']); ?>
                    </span>
                </div>
            </div>

            <!-- Check-in/out Dates -->
            <div class="dates">
                <span class="dates-info">
                    📅 <?php echo date('d M', strtotime($guest['check_in_date'])); ?> → 
                    <?php echo date('d M', strtotime($guest['check_out_date'])); ?>
                </span>
            </div>

            <!-- Current Status -->
            <?php if ($guest['breakfast_display'] !== 'pending'): ?>
            <div style="margin-bottom: 1rem; text-align: center;">
                <span class="status-badge status-<?php echo $guest['breakfast_display']; ?>">
                    <?php 
                    if ($guest['breakfast_display'] === 'taken') echo '✓ Taken';
                    elseif ($guest['breakfast_display'] === 'not_taken') echo '⚠️ Not Taken';
                    else echo '✕ Skipped';
                    ?>
                </span>
                <?php if (!empty($guest['menu_name'])): ?>
                <div style="margin-top: 0.5rem; font-size: 0.85rem; color: var(--text-secondary);">
                    <strong>Menu:</strong> <?php echo htmlspecialchars($guest['menu_name']); ?>
                    <?php if ($guest['quantity'] > 1): ?>
                        <span>(x<?php echo $guest['quantity']; ?>)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Breakfast Form -->
            <form method="POST" class="breakfast-form">
                <input type="hidden" name="action" value="mark_breakfast">
                <input type="hidden" name="booking_id" value="<?php echo $guest['booking_id']; ?>">
                <input type="hidden" name="guest_id" value="<?php echo $guest['guest_id']; ?>">

                <!-- Menu Selection -->
                <?php if (!empty($freeMenus) || !empty($paidMenus)): ?>
                
                <!-- Free Breakfast -->
                <?php if (!empty($freeMenus)): ?>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 700; color: var(--text-primary);">
                        🆓 Free Breakfast Menu (Included)
                    </label>
                    <select name="menu_id" class="form-input" style="width: 100%; padding: 0.75rem; border-radius: 8px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--text-primary);">
                        <option value="">-- Pilih menu gratis --</option>
                        <?php 
                        $currentCategory = '';
                        foreach ($freeMenus as $menu):
                            if ($currentCategory !== $menu['category']):
                                if ($currentCategory !== '') echo '</optgroup>';
                                $categoryLabels = [
                                    'western' => '🍳 Western',
                                    'indonesian' => '🍛 Indonesian',
                                    'asian' => '🍜 Asian',
                                    'drinks' => '🥤 Drinks',
                                    'beverages' => '☕ Beverages'
                                ];
                                echo '<optgroup label="' . ($categoryLabels[$menu['category']] ?? $menu['category']) . '">';
                                $currentCategory = $menu['category'];
                            endif;
                        ?>
                            <option value="<?php echo $menu['id']; ?>" <?php echo ($guest['menu_id'] == $menu['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($menu['menu_name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentCategory !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Extra/Paid Breakfast -->
                <?php if (!empty($paidMenus)): ?>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 700; color: var(--text-primary);">
                        💰 Extra Breakfast (Berbayar)
                    </label>
                    <select name="extra_menu_id" class="form-input" style="width: 100%; padding: 0.75rem; border-radius: 8px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: var(--text-primary);">
                        <option value="">-- Tambahan berbayar (optional) --</option>
                        <?php 
                        $currentCategory = '';
                        foreach ($paidMenus as $menu):
                            if ($currentCategory !== $menu['category']):
                                if ($currentCategory !== '') echo '</optgroup>';
                                echo '<optgroup label="➕ Extra Breakfast">';
                                $currentCategory = $menu['category'];
                            endif;
                        ?>
                            <option value="<?php echo $menu['id']; ?>">
                                <?php echo htmlspecialchars($menu['menu_name']); ?> 
                                - Rp <?php echo number_format($menu['price'], 0, ',', '.'); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentCategory !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 700; color: var(--text-primary);">
                        🔢 Quantity
                    </label>
                    <input type="number" name="quantity" min="1" max="10" value="<?php echo $guest['quantity'] ?? 1; ?>" 
                           class="form-input" style="width: 100%; padding: 0.75rem; border-radius: 8px; background: rgba(99, 102, 241, 0.1); border: 1px solid var(--border-color); color: var(--text-primary);">
                </div>
                <?php endif; ?>

                <div class="breakfast-options">
                    <input type="radio" id="taken-<?php echo $guest['booking_id']; ?>" 
                           name="status" value="taken" class="breakfast-radio"
                           <?php echo $guest['breakfast_display'] === 'taken' ? 'checked' : ''; ?>>
                    <label for="taken-<?php echo $guest['booking_id']; ?>" class="breakfast-label">
                        ✓ Taken
                    </label>

                    <input type="radio" id="not_taken-<?php echo $guest['booking_id']; ?>" 
                           name="status" value="not_taken" class="breakfast-radio"
                           <?php echo $guest['breakfast_display'] === 'not_taken' ? 'checked' : ''; ?>>
                    <label for="not_taken-<?php echo $guest['booking_id']; ?>" class="breakfast-label">
                        ⚠️ Not Taken
                    </label>

                    <input type="radio" id="skipped-<?php echo $guest['booking_id']; ?>" 
                           name="status" value="skipped" class="breakfast-radio"
                           <?php echo $guest['breakfast_display'] === 'skipped' ? 'checked' : ''; ?>>
                    <label for="skipped-<?php echo $guest['booking_id']; ?>" class="breakfast-label">
                        ✕ Skipped
                    </label>
                </div>

                <textarea name="notes" class="breakfast-notes" 
                         placeholder="Add notes (optional)"><?php echo htmlspecialchars($guest['breakfast_notes'] ?? ''); ?></textarea>

                <button type="submit" class="breakfast-btn">
                    💾 Update Breakfast Status
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- No Guests -->
    <div class="no-guests">
        <div class="no-guests-icon">🛏️</div>
        <div class="no-guests-text">No in-house guests today</div>
        <p style="margin-top: 0.5rem; color: var(--text-secondary); font-size: 0.9rem;">
            Come back when guests check in!
        </p>
    </div>
    <?php endif; ?>

</div>

<?php include '../../includes/footer.php'; ?>
