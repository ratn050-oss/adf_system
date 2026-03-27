<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

// Check settings permission
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Kelola User';

// Handle actions
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'add') {
            $data = [
                'username' => $_POST['username'],
                'full_name' => $_POST['full_name'],
                'email' => $_POST['email'],
                'role' => $_POST['role'],
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'is_trial' => isset($_POST['is_trial']) ? 1 : 0
            ];
            
            // Set trial expiry if trial is checked
            if (isset($_POST['is_trial']) && !empty($_POST['trial_days'])) {
                $days = intval($_POST['trial_days']);
                $data['trial_expires_at'] = date('Y-m-d H:i:s', strtotime("+{$days} days"));
            }
            
            // Handle business access
            if (isset($_POST['business_access']) && is_array($_POST['business_access'])) {
                $data['business_access'] = json_encode(array_values($_POST['business_access']));
            } else {
                // Owner & admin get all access by default
                if (in_array($_POST['role'], ['owner', 'admin'])) {
                    require_once '../../includes/business_helper.php';
                    $allBusinesses = getAvailableBusinesses();
                    $businessIds = array_column($allBusinesses, 'id');
                    $data['business_access'] = json_encode($businessIds);
                } else {
                    $data['business_access'] = json_encode([]);
                }
            }
            
            // Hash password
            if (!empty($_POST['password'])) {
                $data['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $userId = $db->insert('users', $data);
            
            // Save permissions
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                foreach ($_POST['permissions'] as $permission) {
                    $db->insert('user_permissions', ['user_id' => $userId, 'permission' => $permission]);
                }
            }
            
            setFlashMessage('success', 'User berhasil ditambahkan!');
        } elseif ($action === 'edit' && $id > 0) {
            $data = [
                'username' => $_POST['username'],
                'full_name' => $_POST['full_name'],
                'email' => $_POST['email'],
                'role' => $_POST['role'],
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'is_trial' => isset($_POST['is_trial']) ? 1 : 0
            ];
            
            // Set trial expiry if trial is checked
            if (isset($_POST['is_trial']) && !empty($_POST['trial_days'])) {
                $days = intval($_POST['trial_days']);
                $data['trial_expires_at'] = date('Y-m-d H:i:s', strtotime("+{$days} days"));
            } elseif (!isset($_POST['is_trial'])) {
                // Reset trial if unchecked
                $data['trial_expires_at'] = null;
            }
            
            // Handle business access
            if (isset($_POST['business_access']) && is_array($_POST['business_access'])) {
                $data['business_access'] = json_encode(array_values($_POST['business_access']));
            } else {
                // Owner & admin get all access by default
                if (in_array($_POST['role'], ['owner', 'admin'])) {
                    require_once '../../includes/business_helper.php';
                    $allBusinesses = getAvailableBusinesses();
                    $businessIds = array_column($allBusinesses, 'id');
                    $data['business_access'] = json_encode($businessIds);
                } else {
                    $data['business_access'] = json_encode([]);
                }
            }
            
            // Only update password if provided
            if (!empty($_POST['password'])) {
                $data['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $db->update('users', $data, 'id = :id', ['id' => $id]);
            
            // Update permissions - delete old and insert new
            $db->delete('user_permissions', 'user_id = :user_id', ['user_id' => $id]);
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                foreach ($_POST['permissions'] as $permission) {
                    $db->insert('user_permissions', ['user_id' => $id, 'permission' => $permission]);
                }
            }
            
            // Reload session if updating current user
            if ($id == $currentUser['id']) {
                $_SESSION['user'] = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
            }
            
            setFlashMessage('success', 'User berhasil diupdate!');
        }
        header('Location: users.php');
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Handle delete
if ($action === 'delete' && $id > 0) {
    if ($id == $currentUser['id']) {
        setFlashMessage('error', 'Tidak dapat menghapus akun sendiri!');
    } else {
        try {
            $db->delete('users', 'id = :id', ['id' => $id]);
            setFlashMessage('success', 'User berhasil dihapus!');
        } catch (Exception $e) {
            setFlashMessage('error', 'Error: ' . $e->getMessage());
        }
    }
    header('Location: users.php');
    exit;
}

// Get user for edit
$editUser = null;
$userPermissions = [];
$userBusinessAccess = [];
if ($action === 'edit' && $id > 0) {
    $editUser = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    // Get user permissions
    $permRows = $db->fetchAll("SELECT permission FROM user_permissions WHERE user_id = ?", [$id]);
    $userPermissions = array_column($permRows, 'permission');
    // Get business access
    if (!empty($editUser['business_access'])) {
        $userBusinessAccess = json_decode($editUser['business_access'], true) ?: [];
    }
}

// Get all available businesses
$allBusinesses = getAvailableBusinesses();

// Get all users
$users = $db->fetchAll("SELECT * FROM users ORDER BY full_name");

include '../../includes/header.php';
?>

<div style="max-width: 1400px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
        <button onclick="toggleForm()" class="btn btn-primary btn-sm" id="addBtn">
            <i data-feather="user-plus" style="width: 14px; height: 14px;"></i> Tambah User
        </button>
    </div>

    <!-- Add/Edit Form -->
    <div class="card" id="userForm" style="margin-bottom: 1rem; display: <?php echo $action === 'add' || $action === 'edit' ? 'block' : 'none'; ?>;">
        <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                <?php echo $action === 'edit' ? 'Edit User' : 'Tambah User Baru'; ?>
            </h3>
        </div>
        <form method="POST" action="?action=<?php echo $action === 'edit' ? 'edit&id=' . $id : 'add'; ?>" style="padding: 1rem;">
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" 
                           value="<?php echo $editUser['username'] ?? ''; ?>" 
                           placeholder="username" required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Nama Lengkap *</label>
                    <input type="text" name="full_name" class="form-control" 
                           value="<?php echo $editUser['full_name'] ?? ''; ?>" 
                           placeholder="John Doe" required>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" 
                           value="<?php echo $editUser['email'] ?? ''; ?>" 
                           placeholder="user@example.com">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Role *</label>
                    <select name="role" class="form-control" required>
                        <option value="staff" <?php echo ($editUser['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                        <option value="manager" <?php echo ($editUser['role'] ?? '') === 'manager' ? 'selected' : ''; ?>>Manager</option>
                        <option value="admin" <?php echo ($editUser['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="owner" <?php echo ($editUser['role'] ?? '') === 'owner' ? 'selected' : ''; ?>>Owner (Read-Only)</option>
                    </select>
                    <small style="color: var(--text-muted); font-size: 0.75rem;">
                        Owner = Hanya bisa melihat dashboard & laporan
                    </small>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">
                        Password <?php echo $action === 'edit' ? '(kosongkan jika tidak ingin mengubah)' : '*'; ?>
                    </label>
                    <div style="position: relative;">
                        <input type="password" name="password" id="passwordInput" class="form-control" 
                               placeholder="<?php echo $action === 'edit' ? 'Kosongkan jika tidak ingin mengubah' : 'Minimal 6 karakter'; ?>" 
                               style="padding-right: 2.5rem;"
                               <?php echo $action === 'add' ? 'required' : ''; ?>>
                        <button type="button" onclick="togglePassword('passwordInput', this)" 
                                style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 0.25rem; color: var(--text-muted);" 
                                title="Lihat password">
                            <i data-feather="eye" style="width: 18px; height: 18px;"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Status</label>
                    <div style="padding-top: 0.65rem;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" 
                                   <?php echo ($editUser['is_active'] ?? 1) ? 'checked' : ''; ?> 
                                   style="width: 16px; height: 16px; margin-right: 0.5rem;">
                            <span>User Aktif</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 0.75rem; background: var(--bg-secondary); border-radius: 8px;">
                <div class="form-group" style="margin: 0;">
                    <label style="display: flex; align-items: center; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="is_trial" id="isTrialCheckbox" value="1" 
                               <?php echo ($editUser['is_trial'] ?? 0) ? 'checked' : ''; ?>
                               onchange="document.getElementById('trialDaysInput').disabled = !this.checked"
                               style="width: 16px; height: 16px; margin-right: 0.5rem;">
                        <span>Akun Trial/Demo</span>
                    </label>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0.25rem 0 0 1.5rem;">
                        User akan mendapat akses terbatas waktu
                    </p>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Durasi Trial (hari)</label>
                    <input type="number" name="trial_days" id="trialDaysInput" class="form-control" 
                           value="<?php 
                               if ($editUser && $editUser['is_trial'] && $editUser['trial_expires_at']) {
                                   $now = new DateTime();
                                   $expires = new DateTime($editUser['trial_expires_at']);
                                   $diff = $now->diff($expires);
                                   echo max(0, $diff->days);
                               } else {
                                   echo '30';
                               }
                           ?>" 
                           min="1" max="365"
                           placeholder="30"
                           <?php echo ($editUser['is_trial'] ?? 0) ? '' : 'disabled'; ?>>
                    <small style="color: var(--text-muted); font-size: 0.75rem;">
                        <?php if ($editUser && $editUser['is_trial'] && $editUser['trial_expires_at']): ?>
                            Berakhir: <?php echo date('d/m/Y H:i', strtotime($editUser['trial_expires_at'])); ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            
            <!-- Permission Checkboxes -->
            <div style="padding: 1rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(139, 92, 246, 0.05)); border-radius: 8px; border: 1px solid var(--bg-tertiary);">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                    <i data-feather="shield" style="width: 18px; height: 18px; color: var(--primary-color);"></i>
                    <h4 style="margin: 0; font-size: 0.938rem; font-weight: 700; color: var(--text-primary);">
                        Hak Akses Menu
                    </h4>
                </div>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0 0 1rem 0;">
                    Centang menu yang dapat diakses oleh user ini. Admin selalu punya akses penuh.
                </p>
                
                <?php
                $availablePermissions = [
                    'dashboard' => ['label' => 'Dashboard', 'icon' => 'home', 'desc' => 'Monitoring real-time accounting'],
                    'cashbook' => ['label' => 'Buku Kas Besar', 'icon' => 'book-open', 'desc' => 'Kelola transaksi kas besar'],
                    'divisions' => ['label' => 'Per Divisi', 'icon' => 'grid', 'desc' => 'Manajemen divisi dan pendapatan'],
                    'frontdesk' => ['label' => 'Front Desk', 'icon' => 'home', 'desc' => 'Hotel front desk operations'],
                    'sales_invoice' => ['label' => 'Sales Invoice', 'icon' => 'file-text', 'desc' => 'Kelola invoice penjualan'],
                    'procurement' => ['label' => 'Procurement', 'icon' => 'shopping-cart', 'desc' => 'Purchase orders & suppliers'],
                    'reports' => ['label' => 'Laporan', 'icon' => 'bar-chart-2', 'desc' => 'Laporan keuangan lengkap'],
                    'users' => ['label' => 'Kelola User', 'icon' => 'users', 'desc' => 'Manajemen user & permissions'],
                    'settings' => ['label' => 'Pengaturan', 'icon' => 'settings', 'desc' => 'Konfigurasi sistem'],
                    'investor' => ['label' => 'Investor', 'icon' => 'briefcase', 'desc' => 'Manajemen investor dan modal'],
                    'project' => ['label' => 'Project', 'icon' => 'layers', 'desc' => 'Manajemen project dan pengeluaran']
                ];
                ?>
                
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem;">
                    <?php foreach ($availablePermissions as $key => $perm): ?>
                        <label style="display: flex; align-items: start; gap: 0.625rem; padding: 0.75rem; background: var(--bg-primary); border: 1px solid var(--bg-tertiary); border-radius: 6px; cursor: pointer; transition: all 0.2s;" 
                               onmouseover="this.style.borderColor='var(--primary-color)'; this.style.background='var(--bg-secondary)';" 
                               onmouseout="this.style.borderColor='var(--bg-tertiary)'; this.style.background='var(--bg-primary)';">
                            <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>" 
                                   <?php echo in_array($key, $userPermissions) ? 'checked' : ''; ?>
                                   style="width: 16px; height: 16px; margin-top: 0.125rem; flex-shrink: 0;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 0.375rem; margin-bottom: 0.25rem;">
                                    <i data-feather="<?php echo $perm['icon']; ?>" style="width: 14px; height: 14px; color: var(--primary-color);"></i>
                                    <span style="font-weight: 600; font-size: 0.813rem; color: var(--text-primary);">
                                        <?php echo $perm['label']; ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.688rem; color: var(--text-muted); line-height: 1.4;">
                                    <?php echo $perm['desc']; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 0.75rem; padding: 0.625rem; background: rgba(245, 158, 11, 0.1); border-radius: 6px; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-feather="info" style="width: 14px; height: 14px; color: var(--warning);"></i>
                    <span style="font-size: 0.75rem; color: var(--text-secondary);">
                        <strong>Note:</strong> Role Admin akan mengabaikan permission dan tetap punya akses penuh ke semua menu.
                    </span>
                </div>
            </div>
            
            <!-- Business Access Checkboxes -->
            <div style="padding: 1rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(34, 197, 94, 0.05)); border-radius: 8px; border: 1px solid var(--bg-tertiary);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i data-feather="briefcase" style="width: 18px; height: 18px; color: var(--success);"></i>
                        <h4 style="margin: 0; font-size: 0.938rem; font-weight: 700; color: var(--text-primary);">
                            Hak Akses Bisnis
                        </h4>
                    </div>
                    <button type="button" onclick="toggleAllBusinesses(this)" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.75rem;">
                        <i data-feather="check-square" style="width: 12px; height: 12px;"></i> Pilih Semua
                    </button>
                </div>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0 0 1rem 0;">
                    Pilih bisnis mana yang dapat diakses oleh user ini. Owner & Admin secara otomatis mendapat akses ke semua bisnis.
                </p>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem;">
                    <?php foreach ($allBusinesses as $business): ?>
                        <label style="display: flex; align-items: start; gap: 0.75rem; padding: 0.875rem; background: var(--bg-primary); border: 1px solid var(--bg-tertiary); border-radius: 6px; cursor: pointer; transition: all 0.2s;" 
                               onmouseover="this.style.borderColor='var(--success)'; this.style.background='var(--bg-secondary)';" 
                               onmouseout="this.style.borderColor='var(--bg-tertiary)'; this.style.background='var(--bg-primary)';">
                            <input type="checkbox" name="business_access[]" value="<?php echo $business['id']; ?>" 
                                   <?php echo in_array($business['id'], $userBusinessAccess) ? 'checked' : ''; ?>
                                   class="business-checkbox"
                                   style="width: 18px; height: 18px; margin-top: 0.125rem; flex-shrink: 0;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.375rem;">
                                    <img src="<?php echo getBusinessLogo($business['id']); ?>" 
                                         alt="<?php echo $business['name']; ?>" 
                                         style="width: 24px; height: 24px; object-fit: contain; border-radius: 4px;">
                                    <span style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                                        <?php echo $business['name']; ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.4;">
                                    <?php 
                                    $typeLabels = [
                                        'hotel' => '🏨 Hotel Management',
                                        'restaurant' => '🍽️ Restaurant & Cafe',
                                        'manufacturing' => '🏭 Manufacturing',
                                        'furniture' => '🪑 Furniture Production',
                                        'cafe' => '☕ Coffee Shop',
                                        'tourism' => '⛵ Tourism & Boat Services'
                                    ];
                                    $businessType = $business['business_type'] ?? $business['type'] ?? 'unknown';
                                    echo $typeLabels[$businessType] ?? $businessType;
                                    ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 0.75rem; padding: 0.625rem; background: rgba(59, 130, 246, 0.1); border-radius: 6px; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-feather="info" style="width: 14px; height: 14px; color: var(--primary-color);"></i>
                    <span style="font-size: 0.75rem; color: var(--text-secondary);">
                        User hanya dapat melihat dan mengakses bisnis yang dipilih di dropdown header. Jika tidak ada bisnis yang dipilih, user tidak dapat login.
                    </span>
                </div>
            </div>
            
            <div style="display: flex; gap: 0.5rem; padding-top: 0.75rem; border-top: 1px solid var(--bg-tertiary);">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i data-feather="save" style="width: 14px; height: 14px;"></i> Simpan
                </button>
                <button type="button" onclick="toggleForm()" class="btn btn-secondary btn-sm">Batal</button>
            </div>
        </form>
    </div>

    <!-- Users List -->
    <div class="card">
        <div style="padding: 0.875rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                Daftar User (<?php echo count($users); ?>)
            </h3>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Trial</th>
                        <th>Business Access</th>
                        <th>Permissions</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php
                        // Get user permissions
                        $userPerms = $db->fetchAll("SELECT permission FROM user_permissions WHERE user_id = ?", [$user['id']]);
                        $permKeys = array_column($userPerms, 'permission');
                        
                        // Get business access
                        $businessAccess = [];
                        if (!empty($user['business_access'])) {
                            $businessAccess = json_decode($user['business_access'], true) ?: [];
                        }
                        ?>
                        <tr>
                            <td>
                                <span style="font-weight: 700; color: var(--primary-color);"><?php echo $user['username']; ?></span>
                                <?php if ($user['id'] == $currentUser['id']): ?>
                                    <span class="badge" style="background: rgba(251, 191, 36, 0.15); color: #fbbf24; margin-left: 0.25rem;">You</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600;"><?php echo $user['full_name']; ?></td>
                            <td style="font-size: 0.813rem; color: var(--text-muted);"><?php echo $user['email'] ?: '-'; ?></td>
                            <td>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="badge" style="background: rgba(168, 85, 247, 0.15); color: #a855f7;">Admin</span>
                                <?php elseif ($user['role'] === 'manager'): ?>
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">Manager</span>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted);">Staff</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="background: <?php echo $user['is_active'] ? 'rgba(16, 185, 129, 0.15)' : 'rgba(148, 163, 184, 0.15)'; ?>; color: <?php echo $user['is_active'] ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['is_trial'] ?? false): ?>
                                    <?php 
                                        $now = new DateTime();
                                        $expires = new DateTime($user['trial_expires_at'] ?? 'now');
                                        $isExpired = $now > $expires;
                                        $diff = $now->diff($expires);
                                        $daysLeft = $isExpired ? 0 : $diff->days;
                                    ?>
                                    <div>
                                        <span class="badge" style="background: <?php echo $isExpired ? 'rgba(239, 68, 68, 0.15)' : 'rgba(251, 191, 36, 0.15)'; ?>; color: <?php echo $isExpired ? 'var(--danger)' : '#fbbf24'; ?>;">
                                            <i data-feather="clock" style="width: 12px; height: 12px;"></i>
                                            <?php echo $isExpired ? 'Expired' : 'Trial'; ?>
                                        </span>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                            <?php if ($isExpired): ?>
                                                <?php echo date('d/m/Y', strtotime($user['trial_expires_at'])); ?>
                                            <?php else: ?>
                                                <?php echo $daysLeft; ?> hari lagi
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.875rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($businessAccess)): ?>
                                    <span style="font-size: 0.75rem; color: var(--danger);">
                                        <i data-feather="alert-circle" style="width: 12px; height: 12px; vertical-align: middle;"></i>
                                        No Access
                                    </span>
                                <?php elseif ($user['role'] === 'owner' || $user['role'] === 'admin'): ?>
                                    <span style="font-size: 0.75rem; color: var(--success); font-weight: 600;">
                                        <i data-feather="check-circle" style="width: 12px; height: 12px; vertical-align: middle;"></i>
                                        All Businesses
                                    </span>
                                <?php else: ?>
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; max-width: 180px;">
                                        <?php 
                                        $businessMap = array_column($allBusinesses, 'name', 'id');
                                        foreach ($businessAccess as $bizId): 
                                            if (isset($businessMap[$bizId])):
                                        ?>
                                            <span style="font-size: 0.688rem; padding: 0.125rem 0.375rem; background: rgba(16, 185, 129, 0.1); color: var(--success); border-radius: 4px; white-space: nowrap;" title="<?php echo $businessMap[$bizId]; ?>">
                                                <?php 
                                                // Shorten name for display
                                                $shortName = $businessMap[$bizId];
                                                if (strlen($shortName) > 15) {
                                                    $shortName = substr($shortName, 0, 12) . '...';
                                                }
                                                echo $shortName;
                                                ?>
                                            </span>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">
                                        <i data-feather="shield" style="width: 12px; height: 12px; vertical-align: middle;"></i>
                                        Full Access
                                    </span>
                                <?php elseif (count($permKeys) > 0): ?>
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; max-width: 200px;">
                                        <?php
                                        $permIcons = [
                                            'dashboard' => '🏠', 'cashbook' => '📖', 'divisions' => '🏢',
                                            'frontdesk' => '🛎️', 'sales_invoice' => '📄', 'procurement' => '🛒',
                                            'reports' => '📊', 'users' => '👥', 'settings' => '⚙️'
                                        ];
                                        foreach ($permKeys as $pk):
                                        ?>
                                            <span style="font-size: 0.688rem; padding: 0.125rem 0.375rem; background: rgba(99, 102, 241, 0.1); color: var(--primary-color); border-radius: 4px; white-space: nowrap;" title="<?php echo ucfirst(str_replace('_', ' ', $pk)); ?>">
                                                <?php echo $permIcons[$pk] ?? '✓'; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; color: var(--danger);">
                                        <i data-feather="alert-circle" style="width: 12px; height: 12px; vertical-align: middle;"></i>
                                        No Access
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                    <a href="?action=edit&id=<?php echo $user['id']; ?>" class="btn btn-sm" style="padding: 0.35rem 0.6rem; background: var(--bg-tertiary);">
                                        <i data-feather="edit-2" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <?php if ($user['id'] != $currentUser['id']): ?>
                                        <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>')" 
                                                class="btn btn-sm" style="padding: 0.35rem 0.6rem; background: rgba(239, 68, 68, 0.15); color: var(--danger);">
                                            <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    feather.replace();
    
    function togglePassword(inputId, button) {
        const input = document.getElementById(inputId);
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.setAttribute('data-feather', 'eye-off');
        } else {
            input.type = 'password';
            icon.setAttribute('data-feather', 'eye');
        }
        feather.replace();
    }
    
    function toggleAllBusinesses(button) {
        const checkboxes = document.querySelectorAll('.business-checkbox');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        
        checkboxes.forEach(cb => {
            cb.checked = !allChecked;
        });
        
        // Update button text
        const icon = button.querySelector('i');
        const text = button.childNodes[1];
        if (allChecked) {
            icon.setAttribute('data-feather', 'check-square');
            button.innerHTML = '<i data-feather="check-square" style="width: 12px; height: 12px;"></i> Pilih Semua';
        } else {
            icon.setAttribute('data-feather', 'x-square');
            button.innerHTML = '<i data-feather="x-square" style="width: 12px; height: 12px;"></i> Hapus Semua';
        }
        feather.replace();
    }
    
    function toggleForm() {
        const form = document.getElementById('userForm');
        const btn = document.getElementById('addBtn');
        if (form.style.display === 'none') {
            form.style.display = 'block';
            btn.style.display = 'none';
        } else {
            form.style.display = 'none';
            btn.style.display = 'inline-flex';
            window.location.href = 'users.php';
        }
    }
    
    function confirmDelete(id, username) {
        if (confirm(`Hapus user "${username}"?\n\nUser yang dihapus tidak dapat dikembalikan!`)) {
            window.location.href = `?action=delete&id=${id}`;
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
