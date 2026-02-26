<?php
// modules/payroll/slips.php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Rincian Slip Gaji';
$pageSubtitle = 'Cetak dan lihat detail slip gaji karyawan';

$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');

$months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Get Period
$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
$slips = [];

if ($period) {
    if (isset($_GET['employee_id'])) {
        $slips = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ? AND employee_id = ?", [$period['id'], $_GET['employee_id']]);
    } else {
        $slips = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ? ORDER BY employee_name ASC", [$period['id']]);
    }
}

// Get All Employees (for filter)
$employees = $db->fetchAll("SELECT id, full_name FROM payroll_employees ORDER BY full_name ASC");

include '../../includes/header.php';
?>

<div class="main-content">
    <div class="header-container fade-in-up">
        <div class="header-content">
            <h1 class="page-title">Rincian Slip Gaji</h1>
            <p class="page-subtitle">Periode <?php echo $months[$month] . ' ' . $year; ?></p>
        </div>
        <div class="header-actions">
            <!-- Filter Form -->
            <form method="GET" class="d-flex gap-2">
                <select name="month" class="form-select" onchange="this.form.submit()">
                    <?php foreach($months as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $k == $month ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <?php for($y = 2024; $y <= date('Y')+1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>
    
    <?php if (empty($slips) && !$period): ?>
        <div class="alert alert-warning fade-in-up">Periode gaji belum dibuat. Silakan ke menu <a href="process.php">Proses Gaji</a>.</div>
    <?php elseif (empty($slips)): ?>
        <div class="alert alert-info fade-in-up">Tidak ada data slip gaji untuk filter ini.</div>
    <?php else: ?>
    
        <div class="row g-4 fade-in-up" style="animation-delay: 0.1s">
            <?php foreach($slips as $slip): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="card-title fw-bold mb-1"><?php echo htmlspecialchars($slip['employee_name']); ?></h5>
                                <span class="badge bg-secondary-subtle text-secondary"><?php echo htmlspecialchars($slip['position']); ?></span>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-icon btn-ghost-secondary" type="button" data-bs-toggle="dropdown">
                                    <i data-feather="more-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="print-slip.php?id=<?php echo $slip['id']; ?>" target="_blank"><i data-feather="printer" class="icon-sm me-2"></i> Cetak Slip</a></li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Gaji Pokok</span>
                            <span class="fw-medium">Rp <?php echo number_format($slip['base_salary'], 0, ',', '.'); ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Lembur (<?php echo $slip['overtime_hours']; ?> jam)</span>
                            <span class="fw-medium text-primary">Rp <?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Tunjangan & Lain</span>
                            <span class="fw-medium">Rp <?php echo number_format($slip['incentive'] + $slip['allowance'] + $slip['bonus'], 0, ',', '.'); ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Potongan</span>
                            <span class="fw-medium text-danger">- Rp <?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?></span>
                        </div>
                        
                        <div class="pt-3 border-top">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-uppercase small text-muted">Gaji Bersih</span>
                                <h4 class="mb-0 text-success fw-bold">Rp <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
    <?php endif; ?>
</div>

<script>feather.replace();</script>
<?php include '../../includes/footer.php'; ?>
