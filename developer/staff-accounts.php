<?php
/**
 * Developer Panel - Staff Accounts Management
 * View, manage, delete staff portal accounts across all businesses
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Staff Portal Accounts';

$error = '';
$success = '';

if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Get all businesses with payroll module
$businesses = [];
try {
    $businesses = $pdo->query("SELECT id, business_id, name, database_name FROM businesses WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Also load from config files as fallback
$configDir = dirname(dirname(__FILE__)) . '/config/businesses/';
$configBusinesses = [];
if (is_dir($configDir)) {
    foreach (glob($configDir . '*.php') as $file) {
        $slug = basename($file, '.php');
        $cfg = require $file;
        if (isset($cfg['database']) && in_array('payroll', $cfg['enabled_modules'] ?? [])) {
            $configBusinesses[$slug] = $cfg;
        }
    }
}

// Selected business
$selectedBiz = $_GET['business'] ?? $_POST['business'] ?? '';
$bizDb = null;
$bizName = '';
$bizSlug = '';
$staffAccounts = [];
$employees = [];

if ($selectedBiz) {
    // Try config file first
    if (isset($configBusinesses[$selectedBiz])) {
        $cfg = $configBusinesses[$selectedBiz];
        $bizName = $cfg['name'] ?? $selectedBiz;
        $bizSlug = $selectedBiz;
        try {
            require_once dirname(dirname(__FILE__)) . '/config/database.php';
            $bizDb = Database::switchDatabase($cfg['database']);
        } catch (Exception $e) {
            $error = 'Gagal konek ke database: ' . $e->getMessage();
        }
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bizDb) {
    $formAction = $_POST['form_action'] ?? '';
    $bizPdo = $bizDb->getConnection();

    // Ensure table exists
    $bizPdo->exec("CREATE TABLE IF NOT EXISTS `staff_accounts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `email` VARCHAR(255) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `last_login` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emp (employee_id),
        UNIQUE KEY uk_emp (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($formAction === 'delete' && !empty($_POST['account_id'])) {
        $accId = (int)$_POST['account_id'];
        $bizPdo->prepare("DELETE FROM staff_accounts WHERE id = ?")->execute([$accId]);
        $auth->logAction('delete_staff_account', 'staff_accounts', $accId, null, ['business' => $bizSlug]);
        $_SESSION['success_message'] = 'Akun staff berhasil dihapus.';
        header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
        exit;
    }

    if ($formAction === 'reset_password' && !empty($_POST['account_id'])) {
        $accId = (int)$_POST['account_id'];
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $bizPdo->prepare("UPDATE staff_accounts SET password_hash = ? WHERE id = ?")->execute([$hash, $accId]);
            $auth->logAction('reset_staff_password', 'staff_accounts', $accId, null, ['business' => $bizSlug]);
            $_SESSION['success_message'] = 'Password berhasil direset.';
        } else {
            $_SESSION['success_message'] = 'Password minimal 6 karakter.';
        }
        header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
        exit;
    }

    if ($formAction === 'create_account') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($empId && $username && strlen($password) >= 6) {
            $exists = $bizDb->fetchOne("SELECT id FROM staff_accounts WHERE employee_id = ?", [$empId]);
            if ($exists) {
                $error = 'Karyawan sudah punya akun.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $bizPdo->prepare("INSERT INTO staff_accounts (employee_id, email, password_hash) VALUES (?, ?, ?)")
                    ->execute([$empId, $username, $hash]);
                $auth->logAction('create_staff_account', 'staff_accounts', $bizPdo->lastInsertId(), null, ['business' => $bizSlug, 'employee_id' => $empId]);
                $_SESSION['success_message'] = 'Akun staff berhasil dibuat.';
                header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
                exit;
            }
        } else {
            $error = 'Lengkapi semua field. Password minimal 6 karakter.';
        }
    }

    if ($formAction === 'delete_all') {
        $bizPdo->exec("TRUNCATE TABLE staff_accounts");
        $auth->logAction('delete_all_staff_accounts', 'staff_accounts', null, null, ['business' => $bizSlug]);
        $_SESSION['success_message'] = 'Semua akun staff berhasil dihapus.';
        header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
        exit;
    }
}

// Fetch data if business selected
if ($bizDb) {
    $bizPdo = $bizDb->getConnection();
    try {
        // Ensure table
        $bizPdo->exec("CREATE TABLE IF NOT EXISTS `staff_accounts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `last_login` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_emp (employee_id),
            UNIQUE KEY uk_emp (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $staffAccounts = $bizPdo->query("
            SELECT sa.*, pe.full_name, pe.employee_code, pe.position, pe.department 
            FROM staff_accounts sa 
            LEFT JOIN payroll_employees pe ON pe.id = sa.employee_id 
            ORDER BY sa.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $staffAccounts = [];
    }

    try {
        $employees = $bizPdo->query("
            SELECT pe.id, pe.employee_code, pe.full_name, pe.position, pe.department 
            FROM payroll_employees pe 
            WHERE pe.is_active = 1 
            AND pe.id NOT IN (SELECT employee_id FROM staff_accounts)
            ORDER BY pe.full_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $employees = [];
    }
}

// Staff portal URL
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$portalUrl = $baseUrl . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/modules/payroll/staff-portal.php?b=' . urlencode($bizSlug);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Business Selector -->
<div class="content-card mb-4">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0"><i class="bi bi-people me-2"></i>Staff Portal Accounts</h5>
            <small class="text-muted">Kelola akun staff portal per bisnis</small>
        </div>
    </div>
    <div class="card-body p-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-bold">Pilih Bisnis</label>
                <select name="business" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Pilih Bisnis --</option>
                    <?php foreach ($configBusinesses as $slug => $cfg): ?>
                    <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo $selectedBiz === $slug ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cfg['name'] ?? $slug); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($selectedBiz && $bizDb): ?>
            <div class="col-md-7">
                <label class="form-label fw-bold">🔗 Link Staff Portal</label>
                <div class="input-group">
                    <input type="text" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars($portalUrl); ?>" readonly id="portalLink">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('portalLink').value).then(()=>{this.textContent='✅ Copied!';setTimeout(()=>this.textContent='📋 Copy',1500)})">📋 Copy</button>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($selectedBiz && $bizDb): ?>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="content-card text-center p-3">
            <div class="fs-2 fw-bold text-primary"><?php echo count($staffAccounts); ?></div>
            <div class="text-muted small">Akun Terdaftar</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="content-card text-center p-3">
            <div class="fs-2 fw-bold text-success"><?php echo count(array_filter($staffAccounts, fn($a) => $a['last_login'])); ?></div>
            <div class="text-muted small">Pernah Login</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="content-card text-center p-3">
            <div class="fs-2 fw-bold text-warning"><?php echo count($employees); ?></div>
            <div class="text-muted small">Belum Punya Akun</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="content-card text-center p-3">
            <div class="fs-2 fw-bold text-info"><?php echo count($staffAccounts) + count($employees); ?></div>
            <div class="text-muted small">Total Karyawan Aktif</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Accounts Table -->
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h6 class="mb-0">📋 Daftar Akun Staff (<?php echo count($staffAccounts); ?>)</h6>
                <?php if (count($staffAccounts) > 0): ?>
                <form method="POST" onsubmit="return confirm('HAPUS SEMUA akun staff? Tindakan ini tidak bisa dibatalkan!')">
                    <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                    <input type="hidden" name="form_action" value="delete_all">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Hapus Semua</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Karyawan</th>
                            <th>Username</th>
                            <th>Last Login</th>
                            <th>Dibuat</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($staffAccounts)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada akun staff terdaftar</td></tr>
                    <?php else: ?>
                        <?php foreach ($staffAccounts as $i => $acc): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($acc['full_name'] ?? 'Unknown'); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($acc['employee_code'] ?? ''); ?> · <?php echo htmlspecialchars($acc['position'] ?? ''); ?></small>
                            </td>
                            <td><code><?php echo htmlspecialchars($acc['email']); ?></code></td>
                            <td>
                                <?php if ($acc['last_login']): ?>
                                    <span class="badge bg-success"><?php echo date('d M H:i', strtotime($acc['last_login'])); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Belum pernah</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo date('d M Y', strtotime($acc['created_at'])); ?></small></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-warning" onclick="resetPassword(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars(addslashes($acc['full_name'] ?? '')); ?>')" title="Reset Password">
                                    <i class="bi bi-key"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus akun <?php echo htmlspecialchars(addslashes($acc['full_name'] ?? '')); ?>?')">
                                    <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create Account Card -->
    <div class="col-lg-4">
        <div class="content-card">
            <div class="card-header-custom">
                <h6 class="mb-0">➕ Buat Akun Baru</h6>
            </div>
            <div class="card-body p-4">
                <?php if (empty($employees)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-check-circle fs-1 text-success"></i>
                        <p class="mt-2 mb-0">Semua karyawan sudah punya akun!</p>
                    </div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                    <input type="hidden" name="form_action" value="create_account">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Karyawan</label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>">
                                <?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="nama / email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Password</label>
                        <input type="text" name="password" class="form-control" placeholder="Min 6 karakter" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-person-plus me-1"></i>Buat Akun
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                <input type="hidden" name="form_action" value="reset_password">
                <input type="hidden" name="account_id" id="resetAccountId">
                <div class="modal-header">
                    <h6 class="modal-title">🔑 Reset Password</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Reset password untuk: <strong id="resetName"></strong></p>
                    <input type="text" name="new_password" class="form-control" placeholder="Password baru (min 6)" required minlength="6">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning btn-sm">Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetPassword(id, name) {
    document.getElementById('resetAccountId').value = id;
    document.getElementById('resetName').textContent = name;
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
