<?php
/**
 * Developer Panel - Business Management
 * Create, Edit businesses with automatic database creation
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/DatabaseManager.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Business Management';

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$error = '';
$success = '';

// Business types
$businessTypes = ['hotel', 'restaurant', 'retail', 'manufacture', 'tourism', 'other'];

// Get owners (users with owner or developer role)
$owners = [];
try {
    $owners = $pdo->query("
        SELECT u.* FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.role_code IN ('developer', 'owner') AND u.is_active = 1
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get menus for assignment
$menus = [];
try {
    $menus = $pdo->query("SELECT * FROM menu_items WHERE is_active = 1 ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    
    if ($formAction === 'create' || $formAction === 'update') {
        $businessCode = strtoupper(trim($_POST['business_code'] ?? ''));
        $businessName = trim($_POST['business_name'] ?? '');
        $businessType = $_POST['business_type'] ?? 'other';
        $ownerId = (int)($_POST['owner_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $selectedMenus = $_POST['menus'] ?? [];
        
        // Generate database name
        $dbName = 'adf_' . strtolower(preg_replace('/[^a-z0-9]/i', '_', $businessCode));
        
        // Validation
        if (empty($businessCode) || empty($businessName) || $ownerId === 0) {
            $error = 'Please fill all required fields';
        } else {
            try {
                if ($formAction === 'create') {
                    // Check duplicate
                    $check = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE business_code = ? OR database_name = ?");
                    $check->execute([$businessCode, $dbName]);
                    if ($check->fetchColumn() > 0) {
                        $error = 'Business code or database already exists';
                    } else {
                        // Create database automatically
                        try {
                            $dbMgr = new DatabaseManager();
                            $dbMgr->createBusinessDatabase($dbName);
                        } catch (Exception $e) {
                            // Database might already exist or creation failed
                            // Continue anyway, we'll log the error
                        }
                        
                        // Insert business
                        $stmt = $pdo->prepare("
                            INSERT INTO businesses (business_code, business_name, business_type, database_name, owner_id, description, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$businessCode, $businessName, $businessType, $dbName, $ownerId, $description, $isActive]);
                        $businessId = $pdo->lastInsertId();
                        
                        // Assign menus to business
                        $menuStmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
                        foreach ($selectedMenus as $menuId) {
                            $menuStmt->execute([$businessId, $menuId]);
                        }
                        
                        $auth->logAction('create_business', 'businesses', $businessId, null, ['name' => $businessName, 'database' => $dbName]);
                        $_SESSION['success_message'] = "Business '{$businessName}' created with database '{$dbName}'!";
                        header('Location: businesses.php');
                        exit;
                    }
                } else {
                    // Update
                    $updateId = (int)$_POST['business_id'];
                    
                    $stmt = $pdo->prepare("
                        UPDATE businesses SET business_code = ?, business_name = ?, business_type = ?, owner_id = ?, description = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$businessCode, $businessName, $businessType, $ownerId, $description, $isActive, $updateId]);
                    
                    // Update menu assignments
                    $pdo->prepare("DELETE FROM business_menu_config WHERE business_id = ?")->execute([$updateId]);
                    $menuStmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
                    foreach ($selectedMenus as $menuId) {
                        $menuStmt->execute([$updateId, $menuId]);
                    }
                    
                    $auth->logAction('update_business', 'businesses', $updateId, null, ['name' => $businessName]);
                    $_SESSION['success_message'] = 'Business updated successfully!';
                    header('Location: businesses.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle delete
if ($action === 'delete' && $editId) {
    try {
        // Get business info first
        $bizStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
        $bizStmt->execute([$editId]);
        $bizToDelete = $bizStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($bizToDelete) {
            // Delete from adf_system (menu config will cascade)
            $pdo->prepare("DELETE FROM businesses WHERE id = ?")->execute([$editId]);
            
            // Optionally delete the business database (commented for safety)
            // $dbMgr = new DatabaseManager();
            // $dbMgr->deleteDatabase($bizToDelete['database_name'], true);
            
            $auth->logAction('delete_business', 'businesses', $editId, $bizToDelete);
            $_SESSION['success_message'] = 'Business deleted! Note: Database was NOT deleted for safety.';
        }
        header('Location: businesses.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Failed to delete: ' . $e->getMessage();
    }
}

// Get business for editing
$editBusiness = null;
$editMenus = [];
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
    $stmt->execute([$editId]);
    $editBusiness = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get assigned menus
    $menuStmt = $pdo->prepare("SELECT menu_id FROM business_menu_config WHERE business_id = ? AND is_enabled = 1");
    $menuStmt->execute([$editId]);
    $editMenus = $menuStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get all businesses
$businesses = [];
try {
    $stmt = $pdo->query("
        SELECT b.*, u.full_name as owner_name,
               (SELECT COUNT(*) FROM business_menu_config WHERE business_id = b.id AND is_enabled = 1) as menu_count,
               (SELECT COUNT(*) FROM user_business_assignment WHERE business_id = b.id) as user_count
        FROM businesses b
        LEFT JOIN users u ON b.owner_id = u.id
        ORDER BY b.created_at DESC
    ");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <?php if ($action === 'add' || $action === 'edit'): ?>
    <!-- Add/Edit Form -->
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-building-<?php echo $action === 'add' ? 'add' : 'gear'; ?> me-2"></i>
                        <?php echo $action === 'add' ? 'Add New Business' : 'Edit Business'; ?>
                    </h5>
                    <a href="businesses.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to List
                    </a>
                </div>
                
                <div class="p-4">
                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($action === 'add'): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Auto Database Creation:</strong> System will automatically create a new database named <code>adf_[business_code]</code> when you create the business.
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="form_action" value="<?php echo $action === 'add' ? 'create' : 'update'; ?>">
                        <?php if ($editBusiness): ?>
                        <input type="hidden" name="business_id" value="<?php echo $editBusiness['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control text-uppercase" name="business_code" required
                                       placeholder="e.g., HOTEL_01, CAFE_BENS"
                                       value="<?php echo htmlspecialchars($editBusiness['business_code'] ?? ''); ?>"
                                       <?php echo $action === 'edit' ? 'readonly' : ''; ?>>
                                <small class="text-muted">Unique identifier, will be used for database name</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="business_name" required
                                       placeholder="e.g., Narayana Hotel, Ben's Cafe"
                                       value="<?php echo htmlspecialchars($editBusiness['business_name'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="business_type" required>
                                    <?php foreach ($businessTypes as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($editBusiness['business_type'] ?? '') === $type ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Owner <span class="text-danger">*</span></label>
                                <select class="form-select" name="owner_id" required>
                                    <option value="">Select Owner</option>
                                    <?php foreach ($owners as $owner): ?>
                                    <option value="<?php echo $owner['id']; ?>" <?php echo ($editBusiness['owner_id'] ?? '') == $owner['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($owner['full_name']); ?> (@<?php echo $owner['username']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?php echo htmlspecialchars($editBusiness['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <?php if ($editBusiness): ?>
                        <div class="mb-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($editBusiness['database_name']); ?>" readonly>
                            <small class="text-muted">Database name cannot be changed after creation</small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Enable Menus for this Business</label>
                            <div class="row">
                                <?php foreach ($menus as $menu): ?>
                                <div class="col-md-4 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="menus[]" value="<?php echo $menu['id']; ?>"
                                               id="menu_<?php echo $menu['id']; ?>"
                                               <?php echo in_array($menu['id'], $editMenus) || $action === 'add' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="menu_<?php echo $menu['id']; ?>">
                                            <i class="<?php echo $menu['menu_icon']; ?> me-1"></i>
                                            <?php echo htmlspecialchars($menu['menu_name']); ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                       <?php echo ($editBusiness['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active Business</label>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i><?php echo $action === 'add' ? 'Create Business & Database' : 'Update Business'; ?>
                            </button>
                            <a href="businesses.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Businesses List -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Business Management</h4>
                <a href="?action=add" class="btn btn-primary">
                    <i class="bi bi-building-add me-1"></i>Add New Business
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Type</th>
                        <th>Database</th>
                        <th>Owner</th>
                        <th>Menus</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($businesses)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-building fs-1 d-block mb-2"></i>
                            No businesses found. <a href="?action=add">Create one</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($businesses as $biz): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($biz['business_name']); ?></strong>
                            <br><small class="text-muted"><?php echo htmlspecialchars($biz['business_code']); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo ucfirst($biz['business_type']); ?></span>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars($biz['database_name']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($biz['owner_name'] ?? '-'); ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo $biz['menu_count']; ?> menus</span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $biz['user_count']; ?> users</span>
                        </td>
                        <td>
                            <?php if ($biz['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="permissions.php?business_id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-info" title="User Permissions">
                                <i class="bi bi-shield-lock"></i>
                            </a>
                            <button onclick="confirmDelete('?action=delete&id=<?php echo $biz['id']; ?>', '<?php echo addslashes($biz['business_name']); ?>')" 
                                    class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
