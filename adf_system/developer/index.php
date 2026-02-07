<?php
/**
 * Developer Panel - Main Dashboard
 * Overview of system statistics and quick actions
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();

// Get statistics
$stats = [
    'users' => 0,
    'businesses' => 0,
    'active_businesses' => 0,
    'menus' => 0
];

try {
    $stats['users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['businesses'] = $pdo->query("SELECT COUNT(*) FROM businesses")->fetchColumn();
    $stats['active_businesses'] = $pdo->query("SELECT COUNT(*) FROM businesses WHERE is_active = 1")->fetchColumn();
    $stats['menus'] = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE is_active = 1")->fetchColumn();
} catch (Exception $e) {
    // Tables might not exist yet
}

// Get recent audit logs
$auditLogs = [];
try {
    $stmt = $pdo->query("
        SELECT al.*, u.username 
        FROM audit_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        ORDER BY al.created_at DESC 
        LIMIT 10
    ");
    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist yet
}

// Get recent users
$recentUsers = [];
try {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
    $recentUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

// Get businesses list
$businesses = [];
try {
    $stmt = $pdo->query("
        SELECT b.*, u.full_name as owner_name 
        FROM businesses b 
        LEFT JOIN users u ON b.owner_id = u.id 
        ORDER BY b.created_at DESC
    ");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="welcome-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
                        <p class="text-muted mb-0">Developer Control Panel - Full System Access</p>
                    </div>
                    <div class="welcome-actions">
                        <a href="users.php?action=add" class="btn btn-primary me-2">
                            <i class="bi bi-person-plus me-1"></i>Add User
                        </a>
                        <a href="businesses.php?action=add" class="btn btn-success">
                            <i class="bi bi-building-add me-1"></i>Add Business
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-users">
                <div class="stat-icon">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['users']); ?></h3>
                    <p>Total Users</p>
                </div>
                <a href="users.php" class="stat-link">View All <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-businesses">
                <div class="stat-icon">
                    <i class="bi bi-building"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['businesses']); ?></h3>
                    <p>Total Businesses</p>
                </div>
                <a href="businesses.php" class="stat-link">View All <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-active">
                <div class="stat-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['active_businesses']); ?></h3>
                    <p>Active Businesses</p>
                </div>
                <a href="businesses.php?filter=active" class="stat-link">View Active <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="stat-card stat-menus">
                <div class="stat-icon">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['menus']); ?></h3>
                    <p>System Menus</p>
                </div>
                <a href="menus.php" class="stat-link">Configure <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
    
    <!-- Main Content Row -->
    <div class="row">
        <!-- Businesses List -->
        <div class="col-lg-8 mb-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-building me-2"></i>Businesses</h5>
                    <a href="businesses.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Business Name</th>
                                <th>Type</th>
                                <th>Database</th>
                                <th>Owner</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($businesses)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No businesses yet. <a href="businesses.php?action=add">Create one</a>
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
                                    <?php if ($biz['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="businesses.php?action=edit&id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="permissions.php?business_id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-info" title="Permissions">
                                        <i class="bi bi-shield-lock"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions & Recent Activity -->
        <div class="col-lg-4 mb-4">
            <!-- Quick Actions -->
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h5><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
                </div>
                <div class="quick-actions">
                    <a href="users.php?action=add" class="quick-action-btn">
                        <i class="bi bi-person-plus"></i>
                        <span>Add User</span>
                    </a>
                    <a href="businesses.php?action=add" class="quick-action-btn">
                        <i class="bi bi-building-add"></i>
                        <span>Add Business</span>
                    </a>
                    <a href="menus.php" class="quick-action-btn">
                        <i class="bi bi-grid-3x3-gap"></i>
                        <span>Configure Menus</span>
                    </a>
                    <a href="permissions.php" class="quick-action-btn">
                        <i class="bi bi-shield-lock"></i>
                        <span>Permissions</span>
                    </a>
                    <a href="database.php" class="quick-action-btn">
                        <i class="bi bi-database"></i>
                        <span>Database</span>
                    </a>
                    <a href="settings.php" class="quick-action-btn">
                        <i class="bi bi-gear"></i>
                        <span>Settings</span>
                    </a>
                </div>
            </div>
            
            <!-- Recent Users -->
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-clock-history me-2"></i>Recent Users</h5>
                    <a href="users.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="recent-list">
                    <?php if (empty($recentUsers)): ?>
                    <p class="text-muted text-center py-3">No users yet</p>
                    <?php else: ?>
                    <?php foreach ($recentUsers as $ru): ?>
                    <div class="recent-item">
                        <div class="recent-avatar">
                            <?php echo strtoupper(substr($ru['full_name'], 0, 1)); ?>
                        </div>
                        <div class="recent-info">
                            <strong><?php echo htmlspecialchars($ru['full_name']); ?></strong>
                            <small><?php echo htmlspecialchars($ru['username']); ?></small>
                        </div>
                        <div class="recent-status">
                            <?php if ($ru['is_active']): ?>
                            <span class="status-dot active"></span>
                            <?php else: ?>
                            <span class="status-dot inactive"></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Audit Logs -->
    <div class="row">
        <div class="col-12">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-journal-text me-2"></i>Recent Activity</h5>
                    <a href="audit.php" class="btn btn-sm btn-outline-primary">View All Logs</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($auditLogs)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    No activity logs yet
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($auditLogs as $log): ?>
                            <tr>
                                <td><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                                <td><span class="badge bg-info"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?? '-'); ?></td>
                                <td><code><?php echo htmlspecialchars($log['ip_address']); ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
