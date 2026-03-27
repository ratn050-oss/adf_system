<?php
/**
 * Developer/Super Admin Panel
 * All-in-one management page for system configuration
 */

session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/businesses.php';

// Simple auth check
$isLoggedIn = isset($_SESSION['user_id']);
$db = Database::getInstance();

// Handle login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    try {
        // Use PDO directly instead of Database class
        $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = MD5(?) AND is_active = 1");
        $stmt->execute([$username, $password]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && in_array($user['role'], ['admin', 'superadmin', 'owner'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            header('Location: developer-panel.php');
            exit;
        } else {
            if (!$user) {
                $loginError = "Username atau password salah!";
            } else {
                $loginError = "Role '{$user['role']}' tidak memiliki akses ke panel ini!";
            }
        }
    } catch (Exception $e) {
        $loginError = "Database error: " . $e->getMessage();
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: developer-panel.php');
    exit;
}

// Login form if not logged in
if (!$isLoggedIn) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Developer Panel Login</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .login-box {
                background: white;
                padding: 40px;
                border-radius: 16px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                width: 400px;
            }
            .login-box h1 {
                margin-bottom: 30px;
                color: #1a202c;
            }
            .form-group {
                margin-bottom: 20px;
            }
            label {
                display: block;
                margin-bottom: 8px;
                color: #4a5568;
                font-weight: 600;
            }
            input {
                width: 100%;
                padding: 12px;
                border: 2px solid #e2e8f0;
                border-radius: 8px;
                font-size: 14px;
            }
            input:focus {
                outline: none;
                border-color: #667eea;
            }
            button {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
            }
            button:hover {
                opacity: 0.9;
            }
            .error {
                background: #fee;
                color: #c00;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 20px;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔧 Developer Panel</h1>
            <?php if (isset($loginError)): ?>
                <div class="error"><?= $loginError ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" name="login">Login as Admin</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Get current user
$currentUser = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// If user not found, logout
if (!$currentUser) {
    session_destroy();
    header('Location: developer-panel.php');
    exit;
}

// Get all users
$allUsers = $db->fetchAll("SELECT * FROM users ORDER BY role, full_name");

// Get all businesses
$allBusinesses = $BUSINESSES;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Panel - Narayana System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f7fafc;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header h1 {
            font-size: 24px;
        }
        .header-right {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        .logout-btn {
            padding: 8px 20px;
            background: rgba(255,255,255,0.2);
            border: 2px solid white;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
        }
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Container */
        .container {
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 40px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e2e8f0;
        }
        .tab {
            padding: 15px 30px;
            background: white;
            border: none;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s;
        }
        .tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .tab:hover:not(.active) {
            background: #edf2f7;
        }
        
        /* Tab Content */
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .card h2 {
            margin-bottom: 20px;
            color: #1a202c;
            font-size: 20px;
        }
        
        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background: #f7fafc;
            font-weight: 600;
            color: #4a5568;
        }
        tr:hover {
            background: #f7fafc;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-success {
            background: #48bb78;
            color: white;
        }
        .btn-danger {
            background: #f56565;
            color: white;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        /* Business checkbox grid */
        .business-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .business-item {
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
        }
        .business-item:hover {
            border-color: #667eea;
            background: #f7fafc;
        }
        .business-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .business-item label {
            cursor: pointer;
            flex: 1;
        }
        
        /* Form */
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        /* Badge */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-admin {
            background: #e3f2fd;
            color: #1976d2;
        }
        .badge-owner {
            background: #fff3e0;
            color: #f57c00;
        }
        .badge-staff {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        /* Quick Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            font-size: 36px;
            color: #667eea;
            margin-bottom: 10px;
        }
        .stat-card p {
            color: #718096;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>🔧 Developer Panel</h1>
            <p style="opacity: 0.9; font-size: 14px;">Narayana Multi-Business Management System</p>
        </div>
        <div class="header-right">
            <span>👤 <?= htmlspecialchars($currentUser['full_name'] ?? 'User') ?> (<?= $currentUser['role'] ?? 'unknown' ?>)</span>
            <a href="?logout=1" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?= count($allUsers) ?></h3>
                <p>Total Users</p>
            </div>
            <div class="stat-card">
                <h3><?= count($allBusinesses) ?></h3>
                <p>Total Businesses</p>
            </div>
            <div class="stat-card">
                <h3><?= count(array_filter($allUsers, fn($u) => $u['role'] == 'owner')) ?></h3>
                <p>Owners</p>
            </div>
            <div class="stat-card">
                <h3><?= count(array_filter($allUsers, fn($u) => $u['role'] == 'staff')) ?></h3>
                <p>Staff</p>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('owners')">👔 Owner Management</button>
            <button class="tab" onclick="showTab('users')">👥 System Users</button>
            <button class="tab" onclick="showTab('businesses')">🏢 Business Management</button>
            <button class="tab" onclick="showTab('settings')">⚙️ System Settings</button>
            <button class="tab" onclick="showTab('tools')">🛠️ Developer Tools</button>
            <a href="../modules/owner/dashboard.php" class="tab" style="text-decoration: none;">📊 Owner Dashboard</a>
            <a href="../modules/payroll/process.php" class="tab" style="text-decoration: none;">💼 Payroll</a>
        </div>
        
        <!-- Tab: Owner Management -->
        <div id="tab-owners" class="tab-content active">
            <div class="alert alert-info">
                <strong>ℹ️ Owner Management:</strong> Kelola owner bisnis yang bisa monitoring dan manage bisnis tertentu.
            </div>
            
            <div class="card">
                <h2>➕ Add New Owner</h2>
                <form id="addOwnerForm">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" required placeholder="owner_name">
                        </div>
                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" name="password" required placeholder="min 6 karakter">
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" required placeholder="Nama Lengkap Owner">
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" placeholder="owner@email.com">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>🏢 Bisnis yang Bisa Dikelola *</label>
                        <div class="business-grid" id="newOwnerBusinesses">
                            <?php foreach ($allBusinesses as $business): ?>
                            <div class="business-item">
                                <input type="checkbox" id="new_owner_business_<?= $business['id'] ?>" 
                                       name="businesses[]" value="<?= $business['id'] ?>" checked>
                                <label for="new_owner_business_<?= $business['id'] ?>"><?= $business['name'] ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Owner</button>
                </form>
            </div>
            
            <div class="card">
                <h2>📋 All Owners</h2>
                <div id="ownerMessage"></div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Business Access</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $owners = array_filter($allUsers, function($u) { return $u['role'] == 'owner'; });
                        foreach ($owners as $owner): 
                            $accessIds = $owner['business_access'] ? json_decode($owner['business_access'], true) : [];
                            $accessCount = count($accessIds);
                        ?>
                        <tr data-user-id="<?= $owner['id'] ?>">
                            <td><?= $owner['id'] ?></td>
                            <td><strong><?= htmlspecialchars($owner['username']) ?></strong></td>
                            <td><?= htmlspecialchars($owner['full_name']) ?></td>
                            <td><?= htmlspecialchars($owner['email'] ?? '-') ?></td>
                            <td>
                                <span class="badge" style="background: #e3f2fd; color: #1976d2;">
                                    <?= $accessCount ?> bisnis
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-secondary btn-sm" onclick="editOwnerAccess(<?= $owner['id'] ?>)">Edit Access</button>
                                <button class="btn btn-danger btn-sm" onclick="deleteUser(<?= $owner['id'] ?>, 'owner')">Delete</button>
                            </td>
                        </tr>
                        <tr id="edit-owner-row-<?= $owner['id'] ?>" style="display:none;">
                            <td colspan="6" style="background: #f7fafc; padding: 20px;">
                                <strong>Edit Business Access: <?= $owner['username'] ?></strong>
                                <div class="business-grid" style="margin-top: 15px;">
                                    <?php foreach ($allBusinesses as $business): ?>
                                    <div class="business-item">
                                        <input type="checkbox" 
                                               id="owner_<?= $owner['id'] ?>_business_<?= $business['id'] ?>"
                                               value="<?= $business['id'] ?>"
                                               <?= in_array($business['id'], $accessIds) ? 'checked' : '' ?>
                                               onchange="updateUserAccess(<?= $owner['id'] ?>, this, 'owner')">
                                        <label for="owner_<?= $owner['id'] ?>_business_<?= $business['id'] ?>"><?= $business['name'] ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="btn btn-secondary" onclick="closeEditOwnerAccess(<?= $owner['id'] ?>)" style="margin-top: 15px;">Close</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($owners)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                                Belum ada owner. Buat owner baru di form di atas.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Tab: System Users -->
        <div id="tab-users" class="tab-content">
            <div class="alert alert-info">
                <strong>ℹ️ System Users:</strong> Kelola user admin dan staff untuk akses sistem (bukan owner bisnis).
            </div>
            
            <div class="card">
                <h2>➕ Add New System User</h2>
                <form id="addUserForm">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" required placeholder="username">
                        </div>
                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" name="password" required placeholder="min 6 karakter">
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" required placeholder="Nama Lengkap">
                        </div>
                        <div class="form-group">
                            <label>Role *</label>
                            <select name="role" required>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="accountant">Accountant</option>
                                <option value="staff">Staff</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>🏢 Business Access (Optional - untuk staff tertentu)</label>
                        <div class="business-grid" id="newUserBusinesses">
                            <?php foreach ($allBusinesses as $business): ?>
                            <div class="business-item">
                                <input type="checkbox" id="new_user_business_<?= $business['id'] ?>" 
                                       name="businesses[]" value="<?= $business['id'] ?>">
                                <label for="new_user_business_<?= $business['id'] ?>"><?= $business['name'] ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="font-size: 12px; color: #666; margin-top: 10px;">
                            💡 Admin bisa akses semua bisnis. Staff/Manager hanya bisa akses bisnis yang dipilih.
                        </p>
                    </div>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </form>
            </div>
            
            <div class="card">
                <h2>📋 All System Users</h2>
                <div id="userMessage"></div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Business Access</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $systemUsers = array_filter($allUsers, function($u) { return $u['role'] != 'owner'; });
                        foreach ($systemUsers as $user): 
                            $accessIds = $user['business_access'] ? json_decode($user['business_access'], true) : [];
                            $accessCount = count($accessIds);
                        ?>
                        <tr data-user-id="<?= $user['id'] ?>">
                            <td><?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= htmlspecialchars($user['full_name']) ?></td>
                            <td><span class="badge badge-<?= $user['role'] ?>"><?= strtoupper($user['role']) ?></span></td>
                            <td>
                                <?php if ($user['role'] == 'admin'): ?>
                                    <span class="badge" style="background: #4caf50; color: white;">All Access</span>
                                <?php else: ?>
                                    <?= $accessCount ?> bisnis
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['role'] != 'admin'): ?>
                                    <button class="btn btn-secondary btn-sm" onclick="editUserAccess(<?= $user['id'] ?>)">Edit Access</button>
                                <?php endif; ?>
                                <button class="btn btn-danger btn-sm" onclick="deleteUser(<?= $user['id'] ?>, 'user')">Delete</button>
                            </td>
                        </tr>
                        <?php if ($user['role'] != 'admin'): ?>
                        <tr id="edit-row-<?= $user['id'] ?>" style="display:none;">
                            <td colspan="6" style="background: #f7fafc; padding: 20px;">
                                <strong>Edit Business Access: <?= $user['username'] ?></strong>
                                <div class="business-grid" style="margin-top: 15px;">
                                    <?php foreach ($allBusinesses as $business): ?>
                                    <div class="business-item">
                                        <input type="checkbox" 
                                               id="user_<?= $user['id'] ?>_business_<?= $business['id'] ?>"
                                               value="<?= $business['id'] ?>"
                                               <?= in_array($business['id'], $accessIds) ? 'checked' : '' ?>
                                               onchange="updateUserAccess(<?= $user['id'] ?>, this, 'user')">
                                        <label for="user_<?= $user['id'] ?>_business_<?= $business['id'] ?>"><?= $business['name'] ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="btn btn-secondary" onclick="closeEditAccess(<?= $user['id'] ?>)" style="margin-top: 15px;">Close</button>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Tab: Business Management -->
        <div id="tab-businesses" class="tab-content">
            <div class="card">
                <h2>➕ Add New Business</h2>
                <form id="addBusinessForm">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Business Name *</label>
                            <input type="text" name="business_name" required placeholder="e.g., Restaurant ABC">
                        </div>
                        <div class="form-group">
                            <label>Database Name *</label>
                            <input type="text" name="database_name" required placeholder="e.g., narayana_restaurant">
                        </div>
                        <div class="form-group">
                            <label>Business Type</label>
                            <select name="business_type">
                                <option value="restaurant">Restaurant</option>
                                <option value="hotel">Hotel</option>
                                <option value="retail">Retail</option>
                                <option value="manufacture">Manufacture</option>
                                <option value="tourism">Tourism</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Business</button>
                </form>
            </div>
            
            <div class="card">
                <h2>🏢 Existing Businesses</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Business Name</th>
                            <th>Database</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allBusinesses as $business): ?>
                        <tr>
                            <td><?= $business['id'] ?></td>
                            <td><strong><?= $business['name'] ?></strong></td>
                            <td><code><?= $business['database'] ?></code></td>
                            <td><?= ucfirst($business['type']) ?></td>
                            <td>
                                <?php if ($business['active']): ?>
                                    <span class="badge" style="background: #d4edda; color: #155724;">Active</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #f8d7da; color: #721c24;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-secondary btn-sm" onclick="syncBusinessTables(<?= $business['id'] ?>)">Sync Tables</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Tab: System Settings -->
        <div id="tab-settings" class="tab-content">
            <div class="card">
                <h2>🔐 Change Developer Password</h2>
                <div id="passwordMessage"></div>
                <form id="changePasswordForm">
                    <div class="form-group">
                        <label>Current Password *</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="new_password" required minlength="4">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password *</label>
                        <input type="password" name="confirm_password" required minlength="4">
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
            
            <div class="card">
                <h2>🎨 Branding & Logo</h2>
                <form id="brandingForm">
                    <div class="form-group">
                        <label>System Name</label>
                        <input type="text" name="system_name" value="Narayana Multi-Business System">
                    </div>
                    <div class="form-group">
                        <label>Developer/Company Name</label>
                        <input type="text" name="developer_name" value="Your Company Name">
                    </div>
                    <div class="form-group">
                        <label>Logo Upload</label>
                        <input type="file" name="logo" accept="image/*">
                        <p style="font-size: 12px; color: #718096; margin-top: 5px;">Current logo will be replaced</p>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </div>
            
            <div class="card">
                <h2>🔐 Security Settings</h2>
                <div class="alert alert-info">
                    <strong>Info:</strong> Database host: localhost, Port: 3306
                </div>
                <p>Main Database: <code>narayana</code></p>
                <p>Total Business Databases: <strong><?= count($allBusinesses) ?></strong></p>
                <p>Current User: <strong><?= htmlspecialchars($currentUser['username']) ?></strong></p>
            </div>
        </div>
        
        <!-- Tab: Developer Tools -->
        <div id="tab-tools" class="tab-content">
            <div class="card">
                <h2>🛠️ Quick Actions</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <button class="btn btn-primary" onclick="syncAllTables()">🔄 Sync All Tables</button>
                    <button class="btn btn-success" onclick="createPOTables()">📦 Create PO Tables</button>
                    <button class="btn btn-secondary" onclick="checkDatabases()">🔍 Check Databases</button>
                    <button class="btn btn-secondary" onclick="exportConfig()">💾 Export Config</button>
                </div>
            </div>
            
            <div class="card">
                <h2>📊 System Information</h2>
                <table>
                    <tr>
                        <td><strong>PHP Version:</strong></td>
                        <td><?= phpversion() ?></td>
                    </tr>
                    <tr>
                        <td><strong>System Time:</strong></td>
                        <td><?= date('Y-m-d H:i:s') ?></td>
                    </tr>
                    <tr>
                        <td><strong>XAMPP Path:</strong></td>
                        <td>C:\xampp\htdocs\narayana</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Tab navigation
        function showTab(tabName) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.style.display = 'none');
            
            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.tab');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Show selected tab
            document.getElementById('tab-' + tabName).style.display = 'block';
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        // Add owner form
        document.getElementById('addOwnerForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            // Get selected businesses
            const businesses = Array.from(document.querySelectorAll('#newOwnerBusinesses input:checked'))
                .map(cb => cb.value);
            
            if (businesses.length === 0) {
                alert('❌ Pilih minimal 1 bisnis untuk owner!');
                return;
            }
            
            const data = {
                username: formData.get('username'),
                password: formData.get('password'),
                full_name: formData.get('full_name'),
                email: formData.get('email'),
                role: 'owner',
                business_ids: businesses
            };
            
            const response = await fetch('../api/create-user.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            if (result.success) {
                alert('✅ Owner berhasil dibuat!');
                window.location.href = window.location.pathname + '?t=' + Date.now();
            } else {
                alert('❌ Gagal: ' + result.message);
            }
        });
        
        // Add System User form  
        document.getElementById('addUserForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            // Get selected businesses (optional for staff)
            const businesses = Array.from(document.querySelectorAll('#newUserBusinesses input:checked'))
                .map(cb => cb.value);
            
            const role = formData.get('role');
            
            // Admin gets all access, others get selected businesses
            const businessIds = (role === 'admin') ? [1,2,3,4,5,6] : businesses;
            
            const data = {
                username: formData.get('username'),
                password: formData.get('password'),
                full_name: formData.get('full_name'),
                role: role,
                business_ids: businessIds
            };
            
            const response = await fetch('../api/create-user.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            if (result.success) {
                alert('✅ User berhasil dibuat!');
                window.location.href = window.location.pathname + '?t=' + Date.now();
            } else {
                alert('❌ Gagal: ' + result.message);
            }
        });
        
        // Edit owner access
        function editOwnerAccess(userId) {
            document.getElementById('edit-owner-row-' + userId).style.display = 'table-row';
        }
        
        function closeEditOwnerAccess(userId) {
            document.getElementById('edit-owner-row-' + userId).style.display = 'none';
        }
        
        // Edit user access
        function editUserAccess(userId) {
            document.getElementById('edit-row-' + userId).style.display = 'table-row';
        }
        
        function closeEditAccess(userId) {
            document.getElementById('edit-row-' + userId).style.display = 'none';
        }
        
        // Update user access
        async function updateUserAccess(userId, checkbox, type) {
            const rowId = type === 'owner' ? 'edit-owner-row-' + userId : 'edit-row-' + userId;
            const row = document.getElementById(rowId);
            const checkboxes = row.querySelectorAll('input[type="checkbox"]:checked');
            const businessIds = Array.from(checkboxes).map(cb => cb.value);
            
            if (type === 'owner' && businessIds.length === 0) {
                alert('❌ Owner harus punya minimal 1 bisnis!');
                checkbox.checked = true;
                return;
            }
            
            const response = await fetch('../api/update-user-business-access.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    user_id: userId,
                    business_ids: businessIds
                })
            });
            
            const result = await response.json();
            if (result.success) {
                const messageDiv = type === 'owner' ? 'ownerMessage' : 'userMessage';
                document.getElementById(messageDiv).innerHTML = 
                    '<div class="alert alert-success">✅ Access updated successfully!</div>';
                setTimeout(() => {
                    document.getElementById(messageDiv).innerHTML = '';
                }, 3000);
            } else {
                alert('❌ Error: ' + result.message);
                checkbox.checked = !checkbox.checked;
            }
        }
        
        // Delete user
        async function deleteUser(userId, type) {
            const confirmMsg = type === 'owner' ? 
                'Hapus owner ini? Owner akan kehilangan akses ke semua bisnis.' :
                'Hapus user ini dari sistem?';
                
            if (!confirm(confirmMsg)) return;
            
            const response = await fetch('../api/delete-user.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({user_id: userId})
            });
            
            const result = await response.json();
            if (result.success) {
                alert('✅ User deleted!');
                window.location.href = window.location.pathname + '?t=' + Date.now();
            } else {
                alert('❌ Error: ' + result.message);
            }
        }
        
        // Edit user access
        function editUserAccess(userId) {
            document.getElementById('edit-row-' + userId).style.display = 'table-row';
        }
        
        function closeEditAccess(userId) {
            document.getElementById('edit-row-' + userId).style.display = 'none';
        }
        
        // Update user access
        async function updateUserAccess(userId, checkbox) {
            const row = document.getElementById('edit-row-' + userId);
            const checkboxes = row.querySelectorAll('input[type="checkbox"]:checked');
            const businessIds = Array.from(checkboxes).map(cb => cb.value);
            
            const response = await fetch('../api/update-user-business-access.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    user_id: userId,
                    business_ids: businessIds
                })
            });
            
            const result = await response.json();
            if (result.success) {
                document.getElementById('userMessage').innerHTML = 
                    '<div class="alert alert-success">✅ Access updated successfully!</div>';
                setTimeout(() => {
                    document.getElementById('userMessage').innerHTML = '';
                }, 3000);
            } else {
                alert('❌ Error: ' + result.message);
                checkbox.checked = !checkbox.checked;
            }
        }
        
        // Add business
        document.getElementById('addBusinessForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const data = {
                name: formData.get('business_name'),
                database: formData.get('database_name'),
                type: formData.get('business_type')
            };
            
            const response = await fetch('../api/add-business.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            if (result.success) {
                alert('✅ Business added! Don\'t forget to sync tables.');
                location.reload();
            } else {
                alert('❌ Error: ' + result.message);
            }
        });
        
        // Developer tools
        async function syncAllTables() {
            if (!confirm('Sync all tables to all business databases?')) return;
            window.open('../tools/sync-all-tables.php', '_blank');
        }
        
        async function createPOTables() {
            if (!confirm('Create Purchase Order tables in all databases?')) return;
            window.open('../tools/create-purchase-orders-tables.php', '_blank');
        }
        
        async function checkDatabases() {
            alert('Opening phpMyAdmin...');
            window.open('http://localhost:8080/phpmyadmin/', '_blank');
        }
        
        async function exportConfig() {
            window.location.href = '../api/export-config.php';
        }
        
        function syncBusinessTables(businessId) {
            window.open('../tools/sync-all-tables.php?business_id=' + businessId, '_blank');
        }
        
        // Change password form
        document.getElementById('changePasswordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const newPass = formData.get('new_password');
            const confirmPass = formData.get('confirm_password');
            
            if (newPass !== confirmPass) {
                document.getElementById('passwordMessage').innerHTML = 
                    '<div class="alert alert-error">❌ New password and confirmation do not match!</div>';
                return;
            }
            
            const data = {
                current_password: formData.get('current_password'),
                new_password: newPass
            };
            
            const response = await fetch('../api/change-password.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            if (result.success) {
                document.getElementById('passwordMessage').innerHTML = 
                    '<div class="alert alert-success">✅ Password changed successfully! Please login again.</div>';
                setTimeout(() => {
                    window.location.href = '?logout=1';
                }, 2000);
            } else {
                document.getElementById('passwordMessage').innerHTML = 
                    '<div class="alert alert-error">❌ Error: ' + result.message + '</div>';
            }
        });
    </script>
</body>
</html>
