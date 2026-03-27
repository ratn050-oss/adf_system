<?php
/**
 * Business Setup Tool
 * Automatically setup database tables for new business
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

// Only admin/owner can access
if (!$auth->hasRole('owner') && !$auth->hasRole('admin')) {
    die('Unauthorized');
}

$db = Database::getInstance();
$pageTitle = 'Business Setup Manager';

// Handle business setup request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'setup_tables') {
        $businessId = $_POST['business_id'] ?? '';
        $setupResult = setupBusinessTables($businessId);
        
        if ($setupResult['success']) {
            $_SESSION['success'] = $setupResult['message'];
        } else {
            $_SESSION['error'] = $setupResult['message'];
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

/**
 * Setup required tables for a business
 * Creates divisions, categories based on business type
 */
function setupBusinessTables($businessId) {
    global $db;
    
    $businessFile = __DIR__ . '/../config/businesses/' . $businessId . '.php';
    if (!file_exists($businessFile)) {
        return ['success' => false, 'message' => 'Business config not found'];
    }
    
    $config = require $businessFile;
    $businessType = $config['business_type'];
    $businessName = $config['name'];
    
    try {
        // Check if business already has divisions
        $existing = $db->fetchOne(
            "SELECT COUNT(*) as count FROM divisions WHERE division_code LIKE :prefix",
            ['prefix' => strtoupper(substr($businessId, 0, 3)) . '%']
        );
        
        if ($existing['count'] > 0) {
            return ['success' => false, 'message' => 'Business already setup with ' . $existing['count'] . ' divisions'];
        }
        
        // Setup based on business type
        $divisionsCreated = 0;
        $categoriesCreated = 0;
        
        switch ($businessType) {
            case 'cafe':
                // Divisions for cafe
                $divisions = [
                    ['name' => 'Beverage Sales', 'code' => substr(strtoupper($businessId), 0, 3) . '-BEV'],
                    ['name' => 'Food Sales', 'code' => substr(strtoupper($businessId), 0, 3) . '-FOOD'],
                    ['name' => 'Operations', 'code' => substr(strtoupper($businessId), 0, 3) . '-OPS']
                ];
                
                // Categories for cafe
                $categories = [
                    ['name' => 'Coffee Sales', 'type' => 'income'],
                    ['name' => 'Tea Sales', 'type' => 'income'],
                    ['name' => 'Snacks Sales', 'type' => 'income'],
                    ['name' => 'Ingredient Purchase', 'type' => 'expense'],
                    ['name' => 'Staff Salary', 'type' => 'expense'],
                    ['name' => 'Utilities', 'type' => 'expense']
                ];
                break;
                
            case 'hotel':
                $divisions = [
                    ['name' => 'Front Desk', 'code' => substr(strtoupper($businessId), 0, 3) . '-FD'],
                    ['name' => 'Housekeeping', 'code' => substr(strtoupper($businessId), 0, 3) . '-HK'],
                    ['name' => 'F&B', 'code' => substr(strtoupper($businessId), 0, 3) . '-FB']
                ];
                
                $categories = [
                    ['name' => 'Room Revenue', 'type' => 'income'],
                    ['name' => 'F&B Revenue', 'type' => 'income'],
                    ['name' => 'Staff Salary', 'type' => 'expense'],
                    ['name' => 'Maintenance', 'type' => 'expense'],
                    ['name' => 'Utilities', 'type' => 'expense']
                ];
                break;
                
            case 'restaurant':
                $divisions = [
                    ['name' => 'Dine In', 'code' => substr(strtoupper($businessId), 0, 3) . '-DIN'],
                    ['name' => 'Take Away', 'code' => substr(strtoupper($businessId), 0, 3) . '-TAK'],
                    ['name' => 'Kitchen', 'code' => substr(strtoupper($businessId), 0, 3) . '-KIT']
                ];
                
                $categories = [
                    ['name' => 'Food Sales', 'type' => 'income'],
                    ['name' => 'Beverage Sales', 'type' => 'income'],
                    ['name' => 'Ingredient Purchase', 'type' => 'expense'],
                    ['name' => 'Staff Salary', 'type' => 'expense'],
                    ['name' => 'Gas & Utilities', 'type' => 'expense']
                ];
                break;
                
            case 'manufacturing':
                $divisions = [
                    ['name' => 'Production', 'code' => substr(strtoupper($businessId), 0, 3) . '-PRD'],
                    ['name' => 'Sales', 'code' => substr(strtoupper($businessId), 0, 3) . '-SAL'],
                    ['name' => 'Procurement', 'code' => substr(strtoupper($businessId), 0, 3) . '-PRC']
                ];
                
                $categories = [
                    ['name' => 'Product Sales', 'type' => 'income'],
                    ['name' => 'Project Revenue', 'type' => 'income'],
                    ['name' => 'Raw Material', 'type' => 'expense'],
                    ['name' => 'Labor Cost', 'type' => 'expense'],
                    ['name' => 'Equipment', 'type' => 'expense']
                ];
                break;
                
            case 'furniture':
                $divisions = [
                    ['name' => 'Workshop', 'code' => substr(strtoupper($businessId), 0, 3) . '-WS'],
                    ['name' => 'Showroom', 'code' => substr(strtoupper($businessId), 0, 3) . '-SR'],
                    ['name' => 'Delivery', 'code' => substr(strtoupper($businessId), 0, 3) . '-DLV']
                ];
                
                $categories = [
                    ['name' => 'Furniture Sales', 'type' => 'income'],
                    ['name' => 'Custom Order', 'type' => 'income'],
                    ['name' => 'Wood Material', 'type' => 'expense'],
                    ['name' => 'Tools & Equipment', 'type' => 'expense'],
                    ['name' => 'Craftsman Salary', 'type' => 'expense']
                ];
                break;
                
            case 'tourism':
                $divisions = [
                    ['name' => 'Booking', 'code' => substr(strtoupper($businessId), 0, 3) . '-BKG'],
                    ['name' => 'Operations', 'code' => substr(strtoupper($businessId), 0, 3) . '-OPS'],
                    ['name' => 'Boat Fleet', 'code' => substr(strtoupper($businessId), 0, 3) . '-FLT']
                ];
                
                $categories = [
                    ['name' => 'Trip Package', 'type' => 'income'],
                    ['name' => 'Charter Revenue', 'type' => 'income'],
                    ['name' => 'Fuel Cost', 'type' => 'expense'],
                    ['name' => 'Boat Maintenance', 'type' => 'expense'],
                    ['name' => 'Crew Salary', 'type' => 'expense']
                ];
                break;
                
            default:
                // Generic setup
                $divisions = [
                    ['name' => 'General', 'code' => substr(strtoupper($businessId), 0, 3) . '-GEN']
                ];
                $categories = [
                    ['name' => 'Revenue', 'type' => 'income'],
                    ['name' => 'Expense', 'type' => 'expense']
                ];
        }
        
        // Insert divisions
        foreach ($divisions as $div) {
            $db->insert('divisions', [
                'division_name' => $businessName . ' - ' . $div['name'],
                'division_code' => $div['code'],
                'description' => 'Auto-generated for ' . $businessName,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $divisionsCreated++;
        }
        
        // Insert categories (if not exists)
        foreach ($categories as $cat) {
            $exists = $db->fetchOne(
                "SELECT id FROM categories WHERE category_name = :name AND category_type = :type",
                ['name' => $cat['name'], 'type' => $cat['type']]
            );
            
            if (!$exists) {
                $db->insert('categories', [
                    'category_name' => $cat['name'],
                    'category_type' => $cat['type'],
                    'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $categoriesCreated++;
            }
        }
        
        return [
            'success' => true,
            'message' => "Setup completed! Created {$divisionsCreated} divisions and {$categoriesCreated} categories for {$businessName}"
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// Get all businesses
$businesses = getAvailableBusinesses();

include '../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">🔧 Business Setup Manager</h1>
    <p class="page-subtitle">Setup database tables untuk bisnis baru</p>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        ✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        ❌ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Available Businesses</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
            <?php foreach ($businesses as $bizId => $config): ?>
                <?php
                // Check if already setup
                $prefix = strtoupper(substr($bizId, 0, 3));
                $setupCheck = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM divisions WHERE division_code LIKE :prefix",
                    ['prefix' => $prefix . '%']
                );
                $isSetup = $setupCheck['count'] > 0;
                ?>
                
                <div style="border: 2px solid var(--bg-tertiary); border-radius: var(--radius-lg); padding: 1.5rem; background: var(--bg-secondary);">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                        <div style="width: 60px; height: 60px; background: linear-gradient(135deg, <?php echo $config['theme']['color_primary']; ?>, <?php echo $config['theme']['color_secondary']; ?>); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: 2rem;">
                            <?php echo $config['theme']['icon']; ?>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="margin: 0; font-size: 1.125rem;"><?php echo htmlspecialchars($config['name']); ?></h4>
                            <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.875rem;">
                                <?php echo ucfirst($config['business_type']); ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if ($isSetup): ?>
                        <div style="padding: 0.75rem; background: var(--success-bg); border: 1px solid var(--success-border); border-radius: var(--radius-md); color: var(--success-text); font-size: 0.875rem; text-align: center;">
                            ✅ Already Setup (<?php echo $setupCheck['count']; ?> divisions)
                        </div>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Setup database tables untuk <?php echo htmlspecialchars($config['name']); ?>?');">
                            <input type="hidden" name="action" value="setup_tables">
                            <input type="hidden" name="business_id" value="<?php echo htmlspecialchars($bizId); ?>">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                🚀 Setup Tables
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 2rem;">
    <div class="card-header">
        <h3>📋 What This Tool Does</h3>
    </div>
    <div class="card-body">
        <p>Tool ini akan otomatis membuat:</p>
        <ul style="margin-left: 1.5rem; color: var(--text-secondary);">
            <li>✅ <strong>Divisions</strong> sesuai jenis bisnis (Front Desk, Kitchen, Workshop, etc)</li>
            <li>✅ <strong>Categories</strong> untuk income & expense yang relevan</li>
            <li>✅ <strong>Unique codes</strong> untuk setiap bisnis (misal: BEN-BEV untuk Bens Cafe - Beverage)</li>
        </ul>
        <p style="margin-top: 1rem; padding: 1rem; background: var(--warning-bg); border-left: 4px solid var(--warning-border); color: var(--warning-text); border-radius: var(--radius-md);">
            <strong>⚠️ Note:</strong> Setelah setup, Anda bisa edit divisions dan categories sesuai kebutuhan di menu Settings.
        </p>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
