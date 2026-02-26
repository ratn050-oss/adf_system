<?php
// modules/payroll/employees.php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Ensure module is enabled
if (!isModuleEnabled('payroll')) {
    include '../../includes/header.php';
    echo '<div class="main-content"><div class="alert alert-warning">Modul Gaji (Payroll) belum diaktifkan untuk bisnis ini.</div></div>';
    include '../../includes/footer.php';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Auto-create tables if missing
$check = $db->query("SHOW TABLES LIKE 'payroll_employees'");
if ($check->rowCount() === 0) {
    $sqlFile = __DIR__ . '/../../database-payroll.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        // Split by semicolon and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        $pdo = $db->getConnection();
        foreach ($statements as $stmt) {
            if (!empty($stmt) && stripos($stmt, 'CREATE TABLE') !== false) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // Table might already exist, continue
                }
            }
        }
    }
    header("Refresh:0");
    exit;
}

$pageTitle = 'Data Karyawan';
$pageSubtitle = 'Kelola data karyawan dan gaji pokok';

// Handle Form Submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $employee_code = $_POST['employee_code'] ?? 'EMP-' . time();
        $full_name = trim($_POST['full_name']);
        $position = trim($_POST['position']);
        $department = trim($_POST['department']);
        $phone = trim($_POST['phone']);
        $join_date = $_POST['join_date'];
        $base_salary = str_replace(['.', ','], '', $_POST['base_salary']); // Remove formatting
        $bank_name = $_POST['bank_name'];
        $bank_account = $_POST['bank_account'];
        
        try {
            if ($action === 'create') {
                // Auto generate code if empty
                if (empty($employee_code)) {
                    $count = $db->fetchOne("SELECT COUNT(*) as c FROM payroll_employees");
                    $employee_code = 'EMP-' . str_pad($count['c'] + 1, 3, '0', STR_PAD_LEFT);
                }
                
                $sql = "INSERT INTO payroll_employees (employee_code, full_name, position, department, phone, join_date, base_salary, bank_name, bank_account, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db->query($sql, [$employee_code, $full_name, $position, $department, $phone, $join_date, $base_salary, $bank_name, $bank_account, $_SESSION['user_id']]);
                setFlash('success', 'Karyawan berhasil ditambahkan');
            } else {
                $sql = "UPDATE payroll_employees SET full_name=?, position=?, department=?, phone=?, join_date=?, base_salary=?, bank_name=?, bank_account=? WHERE id=?";
                $db->query($sql, [$full_name, $position, $department, $phone, $join_date, $base_salary, $bank_name, $bank_account, $id]);
                setFlash('success', 'Data karyawan diperbarui');
            }
        } catch (PDOException $e) {
            setFlash('error', 'Gagal menyimpan data: ' . $e->getMessage());
        }
        header('Location: employees.php');
        exit;
    }
    
    if ($action === 'delete') {
        $id = $_POST['id'];
        try {
            // Soft delete or hard delete? Let's do soft delete (is_active=0)
            $db->query("UPDATE payroll_employees SET is_active = 0 WHERE id = ?", [$id]);
            setFlash('success', 'Karyawan dinonaktifkan');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus: ' . $e->getMessage());
        }
        header('Location: employees.php');
        exit;
    }
}

// Get Employees
$employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1 ORDER BY full_name ASC");

include '../../includes/header.php';
?>

<div class="main-content">
    <div class="header-container fade-in-up">
        <div class="header-content">
            <h1 class="page-title">Data Karyawan</h1>
            <p class="page-subtitle">Kelola database karyawan untuk penggajian</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openModal('create')">
                <i data-feather="plus"></i> Tambah Karyawan
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid mb-4 fade-in-up" style="animation-delay: 0.1s">
        <div class="stat-card">
            <div class="stat-icon bg-primary-subtle text-primary">
                <i data-feather="users"></i>
            </div>
            <div class="stat-content">
                <p class="stat-label">Total Karyawan</p>
                <h3 class="stat-value"><?php echo count($employees); ?></h3>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success-subtle text-success">
                <i data-feather="dollar-sign"></i>
            </div>
            <div class="stat-content">
                <p class="stat-label">Total Gaji Pokok</p>
                <h3 class="stat-value">
                    <?php 
                    $totalBase = array_sum(array_column($employees, 'base_salary'));
                    echo 'Rp ' . number_format($totalBase, 0, ',', '.'); 
                    ?>
                </h3>
            </div>
        </div>
    </div>

    <!-- Employee Table -->
    <div class="card fade-in-up" style="animation-delay: 0.2s">
        <div class="card-header">
            <h3 class="card-title">Daftar Karyawan Aktif</h3>
            <div class="card-actions">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i data-feather="search"></i></span>
                    <input type="text" id="tableSearch" class="form-control" placeholder="Cari karyawan..." onkeyup="filterTable()">
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover" id="employeeTable">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama Lengkap</th>
                            <th>Jabatan</th>
                            <th>Tgl Masuk</th>
                            <th>Gaji Pokok</th>
                            <th>Rekening</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($employees)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">Belum ada data karyawan</td></tr>
                        <?php else: ?>
                            <?php foreach($employees as $emp): ?>
                            <tr>
                                <td><span class="badge bg-secondary-subtle text-secondary"><?php echo $emp['employee_code']; ?></span></td>
                                <td class="fw-medium"><?php echo htmlspecialchars($emp['full_name']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($emp['position']); ?>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($emp['department'] ?? '-'); ?></small>
                                </td>
                                <td><?php echo date('d M Y', strtotime($emp['join_date'])); ?></td>
                                <td class="fw-bold text-success">Rp <?php echo number_format($emp['base_salary'], 0, ',', '.'); ?></td>
                                <td>
                                    <small><?php echo htmlspecialchars($emp['bank_name']); ?></small><br>
                                    <span class="text-monospace"><?php echo htmlspecialchars($emp['bank_account']); ?></span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-icon btn-ghost-primary" onclick='editEmployee(<?php echo json_encode($emp); ?>)'>
                                        <i data-feather="edit-2"></i>
                                    </button>
                                    <button class="btn btn-sm btn-icon btn-ghost-danger" onclick="deleteEmployee(<?php echo $emp['id']; ?>)">
                                        <i data-feather="trash-2"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Form -->
<div class="modal-backdrop" id="modalBackdrop" style="display: none;"></div>
<div class="modal" id="employeeModal" style="display: none;">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="employeeForm">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Tambah Karyawan</h3>
                <button type="button" class="btn-close" onclick="closeModal()">
                    <i data-feather="x"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="employeeId">
                
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="form-label required">Nama Lengkap</label>
                        <input type="text" name="full_name" id="fullName" class="form-control" required>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="form-label">Kode Karyawan</label>
                        <input type="text" name="employee_code" id="employeeCode" class="form-control" placeholder="Auto-generated" readonly>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="form-label required">Jabatan</label>
                        <input type="text" name="position" id="position" class="form-control" required list="positions">
                        <datalist id="positions">
                            <option value="Manager">
                            <option value="Supervisor">
                            <option value="Chef">
                            <option value="Cook Helper">
                            <option value="Waiter">
                            <option value="Cashier">
                            <option value="Housekeeping">
                            <option value="Front Desk">
                        </datalist>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="form-label">Departemen</label>
                        <select name="department" id="department" class="form-select">
                            <option value="">- Pilih -</option>
                            <option value="Kitchen">Kitchen</option>
                            <option value="Service">Service</option>
                            <option value="Front Office">Front Office</option>
                            <option value="Admin">Admin</option>
                            <option value="Housekeeping">Housekeeping</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="form-label required">Tanggal Masuk</label>
                        <input type="date" name="join_date" id="joinDate" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="form-label">No. Telepon</label>
                        <input type="text" name="phone" id="phone" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required">Gaji Pokok (Rp)</label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" name="base_salary" id="baseSalary" class="form-control" required onkeyup="formatCurrency(this)">
                    </div>
                    <small class="text-muted">Digunakan sebagai dasar perhitungan lembur (1/200).</small>
                </div>

                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="form-label">Nama Bank</label>
                        <input type="text" name="bank_name" id="bankName" class="form-control" list="banks">
                        <datalist id="banks">
                            <option value="BCA">
                            <option value="BRI">
                            <option value="BNI">
                            <option value="Mandiri">
                        </datalist>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="form-label">No. Rekening</label>
                        <input type="text" name="bank_account" id="bankAccount" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<script>
feather.replace();

function openModal(mode) {
    document.getElementById('modalBackdrop').style.display = 'block';
    document.getElementById('employeeModal').style.display = 'block';
    
    if (mode === 'create') {
        document.getElementById('modalTitle').innerText = 'Tambah Karyawan';
        document.getElementById('formAction').value = 'create';
        document.getElementById('employeeForm').reset();
        document.getElementById('joinDate').valueAsDate = new Date();
    }
}

function closeModal() {
    document.getElementById('modalBackdrop').style.display = 'none';
    document.getElementById('employeeModal').style.display = 'none';
}

function formatCurrency(input) {
    let value = input.value.replace(/\D/g, '');
    if (value === '') {
        input.value = '';
        return;
    }
    input.value = new Intl.NumberFormat('id-ID').format(value);
}

function editEmployee(data) {
    openModal('edit');
    document.getElementById('modalTitle').innerText = 'Edit Karyawan';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('employeeId').value = data.id;
    document.getElementById('employeeCode').value = data.employee_code;
    document.getElementById('fullName').value = data.full_name;
    document.getElementById('position').value = data.position;
    document.getElementById('department').value = data.department;
    document.getElementById('joinDate').value = data.join_date;
    document.getElementById('phone').value = data.phone;
    
    document.getElementById('baseSalary').value = data.base_salary;
    formatCurrency(document.getElementById('baseSalary'));
    
    document.getElementById('bankName').value = data.bank_name;
    document.getElementById('bankAccount').value = data.bank_account;
}

function deleteEmployee(id) {
    if (confirm('Yakin ingin menonaktifkan karyawan ini?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function filterTable() {
    const input = document.getElementById('tableSearch');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('employeeTable');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        const tdName = tr[i].getElementsByTagName('td')[1];
        const tdPos = tr[i].getElementsByTagName('td')[2];
        if (tdName || tdPos) {
            const txtValueName = tdName.textContent || tdName.innerText;
            const txtValuePos = tdPos.textContent || tdPos.innerText;
            if (txtValueName.toLowerCase().indexOf(filter) > -1 || txtValuePos.toLowerCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }       
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
