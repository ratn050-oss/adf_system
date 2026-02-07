<?php
/**
 * Developer Panel - Audit Logs
 * View system activity logs
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Audit Logs';

// Filters
$filterAction = $_GET['action_type'] ?? '';
$filterUser = $_GET['user_id'] ?? '';
$filterDate = $_GET['date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get unique actions for filter
$actions = [];
try {
    $actions = $pdo->query("SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Get users for filter
$users = [];
try {
    $users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Build query
$where = [];
$params = [];

if ($filterAction) {
    $where[] = "a.action_type = ?";
    $params[] = $filterAction;
}
if ($filterUser) {
    $where[] = "a.user_id = ?";
    $params[] = $filterUser;
}
if ($filterDate) {
    $where[] = "DATE(a.created_at) = ?";
    $params[] = $filterDate;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$countSql = "SELECT COUNT(*) FROM audit_logs a $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalLogs = $countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Get logs
$logs = [];
try {
    $sql = "
        SELECT a.*, u.full_name, u.username
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $whereClause
        ORDER BY a.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.json-data {
    max-width: 300px;
    max-height: 100px;
    overflow: auto;
    font-size: 11px;
    background: #1a1a2e;
    padding: 5px;
    border-radius: 4px;
}
.log-action {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.log-action.create { background: rgba(25, 135, 84, 0.2); color: #4ade80; }
.log-action.update { background: rgba(13, 110, 253, 0.2); color: #60a5fa; }
.log-action.delete { background: rgba(220, 53, 69, 0.2); color: #f87171; }
.log-action.login { background: rgba(111, 66, 193, 0.2); color: #a78bfa; }
.log-action.logout { background: rgba(108, 117, 125, 0.2); color: #9ca3af; }
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>Audit Logs</h4>
                <span class="badge bg-primary"><?php echo number_format($totalLogs); ?> records</span>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="content-card mb-4">
        <div class="p-3">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Action Type</label>
                    <select class="form-select form-select-sm" name="action_type">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $filterAction === $act ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($act); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">User</label>
                    <select class="form-select form-select-sm" name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Date</label>
                    <input type="date" class="form-control form-control-sm" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="audit.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Logs Table -->
    <div class="content-card">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>IP Address</th>
                        <th>New Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-journal fs-1 d-block mb-2"></i>
                            No audit logs found
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <?php 
                        $actionClass = 'default';
                        if (strpos($log['action_type'], 'create') !== false) $actionClass = 'create';
                        elseif (strpos($log['action_type'], 'update') !== false) $actionClass = 'update';
                        elseif (strpos($log['action_type'], 'delete') !== false) $actionClass = 'delete';
                        elseif (strpos($log['action_type'], 'login') !== false) $actionClass = 'login';
                        elseif (strpos($log['action_type'], 'logout') !== false) $actionClass = 'logout';
                    ?>
                    <tr>
                        <td class="text-muted small text-nowrap">
                            <?php echo date('d M Y', strtotime($log['created_at'])); ?>
                            <br><?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                        </td>
                        <td>
                            <?php if ($log['full_name']): ?>
                            <strong><?php echo htmlspecialchars($log['full_name']); ?></strong>
                            <br><small class="text-muted">@<?php echo $log['username']; ?></small>
                            <?php else: ?>
                            <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="log-action <?php echo $actionClass; ?>">
                                <?php echo htmlspecialchars($log['action_type']); ?>
                            </span>
                        </td>
                        <td><code class="small"><?php echo htmlspecialchars($log['table_name'] ?? '-'); ?></code></td>
                        <td><?php echo $log['record_id'] ?? '-'; ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                        <td>
                            <?php if ($log['new_data']): ?>
                            <div class="json-data">
                                <pre class="mb-0 text-info"><?php echo htmlspecialchars(json_encode(json_decode($log['new_data']), JSON_PRETTY_PRINT)); ?></pre>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="p-3 border-top">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
