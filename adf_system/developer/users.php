<?php
/**
 * Developer Panel - User Management
 * Create, Edit, Delete users and assign roles
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'User Management';

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$error = '';
$success = '';

// Get roles
$roles = [];
try {
    $roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    
    if ($formAction === 'create' || $formAction === 'update') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        
        // Validation
        if (empty($username) || empty($email) || empty($fullName) || $roleId === 0) {
            $error = 'Please fill all required fields';
        } else {
            try {
                if ($formAction === 'create') {
                    if (empty($password)) {
                        $error = 'Password is required for new user';
                    } else {
                        // Check duplicate
                        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
                        $check->execute([$username, $email]);
                        if ($check->fetchColumn() > 0) {
                            $error = 'Username or email already exists';
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO users (username, email, password, full_name, phone, role_id, is_active, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $stmt->execute([$username, $email, $hashedPassword, $fullName, $phone, $roleId, $isActive, $user['id']]);
                            
                            $auth->logAction('create_user', 'users', $pdo->lastInsertId(), null, ['username' => $username]);
                            $_SESSION['success_message'] = 'User created successfully!';
                            header('Location: users.php');
                            exit;
                        }
                    }
                } else {
                    // Update
                    $updateId = (int)$_POST['user_id'];
                    
                    if (!empty($password)) {
                        $stmt = $pdo->prepare("
                            UPDATE users SET username = ?, email = ?, password = ?, full_name = ?, phone = ?, role_id = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt->execute([$username, $email, $hashedPassword, $fullName, $phone, $roleId, $isActive, $updateId]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, role_id = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $email, $fullName, $phone, $roleId, $isActive, $updateId]);
                    }
                    
                    $auth->logAction('update_user', 'users', $updateId, null, ['username' => $username]);
                    $_SESSION['success_message'] = 'User updated successfully!';
                    header('Location: users.php');
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
        // Don't allow deleting self
        if ((int)$editId === (int)$user['id']) {
            $_SESSION['error_message'] = 'You cannot delete yourself!';
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$editId]);
            $auth->logAction('delete_user', 'users', $editId);
            $_SESSION['success_message'] = 'User deleted successfully!';
        }
        header('Location: users.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Failed to delete user: ' . $e->getMessage();
    }
}

// Get user for editing
$editUser = null;
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all users
$users = [];
try {
    $stmt = $pdo->query("
        SELECT u.*, r.role_name, r.role_code,
               (SELECT COUNT(*) FROM user_business_assignment WHERE user_id = u.id) as business_count
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <?php if ($action === 'add' || $action === 'edit'): ?>
    <!-- Add/Edit Form -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-person-<?php echo $action === 'add' ? 'plus' : 'gear'; ?> me-2"></i>
                        <?php echo $action === 'add' ? 'Add New User' : 'Edit User'; ?>
                    </h5>
                    <a href="users.php" class="btn btn-sm btn-outline-secondary">
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
                    
                    <form method="POST" action="">
                        <input type="hidden" name="form_action" value="<?php echo $action === 'add' ? 'create' : 'update'; ?>">
                        <?php if ($editUser): ?>
                        <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required
                                       value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" required
                                       value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" required
                                       value="<?php echo htmlspecialchars($editUser['full_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone"
                                       value="<?php echo htmlspecialchars($editUser['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role_id" required>
                                    <option value="">Select Role</option>
                                    <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" 
                                            <?php echo ($editUser['role_id'] ?? '') == $role['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role_name']); ?> (<?php echo $role['role_code']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password <?php echo $action === 'add' ? '<span class="text-danger">*</span>' : '(leave blank to keep current)'; ?></label>
                                <input type="password" class="form-control" name="password" <?php echo $action === 'add' ? 'required' : ''; ?>>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                       <?php echo ($editUser['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active User</label>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i><?php echo $action === 'add' ? 'Create User' : 'Update User'; ?>
                            </button>
                            <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Users List -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Users Management</h4>
                <a href="?action=add" class="btn btn-primary">
                    <i class="bi bi-person-plus me-1"></i>Add New User
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Assigned Businesses</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2"></i>
                            No users found. <a href="?action=add">Create one</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="user-avatar me-3" style="width:36px;height:36px;font-size:0.9rem;">
                                    <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                    <br><small class="text-muted">@<?php echo htmlspecialchars($u['username']); ?></small>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $u['role_code'] === 'developer' ? 'purple' : 
                                    ($u['role_code'] === 'owner' ? 'info' : 'secondary'); 
                            ?>" style="background-color: <?php 
                                echo $u['role_code'] === 'developer' ? '#6f42c1' : 
                                    ($u['role_code'] === 'owner' ? '#3b82f6' : '#6c757d'); 
                            ?>">
                                <?php echo htmlspecialchars($u['role_name']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['business_count'] > 0): ?>
                            <span class="badge bg-success"><?php echo $u['business_count']; ?> business(es)</span>
                            <?php else: ?>
                            <span class="text-muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $u['last_login'] ? date('M d, Y H:i', strtotime($u['last_login'])) : '<span class="text-muted">Never</span>'; ?>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="user-business.php?user_id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-info" title="Assign Business">
                                <i class="bi bi-building"></i>
                            </a>
                            <?php if ($u['id'] != $user['id']): ?>
                            <button onclick="confirmDelete('?action=delete&id=<?php echo $u['id']; ?>', '<?php echo addslashes($u['full_name']); ?>')" 
                                    class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
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
