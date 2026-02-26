<?php
// modules/payroll/reports.php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Payroll requires module check
if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Laporan Gaji';
$pageSubtitle = 'Ringkasan pengeluaran gaji karyawan';

$tab = $_GET['tab'] ?? 'monthly';
$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');

$months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Data Fetching
$data = [];
$totalNet = 0;

if ($tab === 'monthly') {
    $period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
    if ($period) {
        $data = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ? ORDER BY employee_name ASC", [$period['id']]);
        $totalNet = $period['total_net'];
    }
} elseif ($tab === 'yearly') {
    $data = $db->fetchAll("SELECT * FROM payroll_periods WHERE period_year = ? ORDER BY period_month ASC", [$year]);
    $totalNet = array_sum(array_column($data, 'total_net'));
}

include '../../includes/header.php';
?>

<div class="main-content">
    <div class="header-container fade-in-up">
        <div class="header-content">
            <h1 class="page-title">Laporan Gaji</h1>
            <p class="page-subtitle">Analisa pengeluaran gaji karyawan</p>
        </div>
        <div class="header-actions">
            <?php if (!empty($data)): ?>
            <button class="btn btn-outline-primary" onclick="window.print()">
                <i data-feather="printer"></i> Cetak Laporan
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs -->
    <div class="mb-4 fade-in-up">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'monthly' ? 'active' : ''; ?>" href="?tab=monthly">Laporan Bulanan</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'yearly' ? 'active' : ''; ?>" href="?tab=yearly">Laporan Tahunan</a>
            </li>
        </ul>
    </div>

    <!-- Filters -->
    <div class="card mb-4 fade-in-up" style="animation-delay: 0.1s">
        <div class="card-body py-3">
            <form method="GET" class="row g-3 align-items-center">
                <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                
                <?php if ($tab === 'monthly'): ?>
                <div class="col-auto">
                    <select name="month" class="form-select">
                        <?php foreach($months as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $k == $month ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-auto">
                    <select name="year" class="form-select">
                        <?php for($y = 2024; $y <= date('Y')+1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Tampilkan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Content -->
    <div class="card fade-in-up" style="animation-delay: 0.2s">
        <div class="card-body">
            
            <?php if ($tab === 'monthly'): ?>
                <h4 class="card-title text-center mb-4">Laporan Gaji Periode <?php echo $months[$month] . ' ' . $year; ?></h4>
                
                <?php if (empty($data)): ?>
                    <div class="alert alert-info text-center">Data gaji untuk periode ini belum tersedia.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="bg-light">
                                <tr>
                                    <th>No</th>
                                    <th>Nama Karyawan</th>
                                    <th>Jabatan</th>
                                    <th class="text-end">Gaji Pokok</th>
                                    <th class="text-end">Lembur</th>
                                    <th class="text-end">Insentif</th>
                                    <th class="text-end">Tunjangan</th>
                                    <th class="text-end text-danger">Potongan</th>
                                    <th class="text-end fw-bold">Gaji Bersih</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1; foreach($data as $row): ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                                    <td class="text-end">Rp <?php echo number_format($row['base_salary'], 0, ',', '.'); ?></td>
                                    <td class="text-end">
                                        Rp <?php echo number_format($row['overtime_amount'], 0, ',', '.'); ?>
                                        <div class="small text-muted">(<?php echo $row['overtime_hours']; ?> jam)</div>
                                    </td>
                                    <td class="text-end">Rp <?php echo number_format($row['incentive'], 0, ',', '.'); ?></td>
                                    <td class="text-end">Rp <?php echo number_format($row['allowance'], 0, ',', '.'); ?></td>
                                    <td class="text-end text-danger">Rp <?php echo number_format($row['total_deductions'], 0, ',', '.'); ?></td>
                                    <td class="text-end fw-bold text-success">Rp <?php echo number_format($row['net_salary'], 0, ',', '.'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Total Keseluruhan</td>
                                    <td class="text-end">Rp <?php echo number_format(array_sum(array_column($data, 'base_salary')), 0, ',', '.'); ?></td>
                                    <td class="text-end">Rp <?php echo number_format(array_sum(array_column($data, 'overtime_amount')), 0, ',', '.'); ?></td>
                                    <td class="text-end">Rp <?php echo number_format(array_sum(array_column($data, 'incentive')), 0, ',', '.'); ?></td>
                                    <td class="text-end">Rp <?php echo number_format(array_sum(array_column($data, 'allowance')), 0, ',', '.'); ?></td>
                                    <td class="text-end text-danger">Rp <?php echo number_format(array_sum(array_column($data, 'total_deductions')), 0, ',', '.'); ?></td>
                                    <td class="text-end text-success">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'yearly'): ?>
                <h4 class="card-title text-center mb-4">Rekapitulasi Gaji Tahun <?php echo $year; ?></h4>
                
                <?php if (empty($data)): ?>
                    <div class="alert alert-info text-center">Belum ada data gaji untuk tahun ini.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Bulan</th>
                                    <th>Status</th>
                                    <th class="text-center">Jml Karyawan</th>
                                    <th class="text-end">Total Gaji Kotor</th>
                                    <th class="text-end text-danger">Total Potongan</th>
                                    <th class="text-end fw-bold">Total Gaji Bersih</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($data as $row): ?>
                                <tr>
                                    <td><?php echo $months[$row['period_month']]; ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $row['status']; ?></span></td>
                                    <td class="text-center"><?php echo $row['total_employees']; ?></td>
                                    <td class="text-end">Rp <?php echo number_format($row['total_gross'], 0, ',', '.'); ?></td>
                                    <td class="text-end text-danger">Rp <?php echo number_format($row['total_deductions'], 0, ',', '.'); ?></td>
                                    <td class="text-end fw-bold text-success">Rp <?php echo number_format($row['total_net'], 0, ',', '.'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Grand Total Tahun <?php echo $year; ?></td>
                                    <td class="text-end">Rp <?php echo number_format(array_sum(array_column($data, 'total_gross')), 0, ',', '.'); ?></td>
                                    <td class="text-end text-danger">Rp <?php echo number_format(array_sum(array_column($data, 'total_deductions')), 0, ',', '.'); ?></td>
                                    <td class="text-end text-success">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<style>
@media print {
    .header-container, .nav-tabs, .card-header .row, form, button, .sidebar, .header-navbar {
        display: none !important;
    }
    .main-content { margin: 0; padding: 0; }
    .card { border: none; shadow: none; }
    .table-responsive { overflow: visible; }
    body { background: white; -webkit-print-color-adjust: exact; }
    .text-success { color: black !important; }
    .text-danger { color: black !important; }
}
</style>

<script>feather.replace();</script>
<?php include '../../includes/footer.php'; ?>
