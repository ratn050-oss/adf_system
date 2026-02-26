<?php
// modules/payroll/index.php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Payroll requires module check
if (!isModuleEnabled('payroll')) {
    include '../../includes/header.php';
    echo '<div class="main-content"><div class="alert alert-warning">Modul Gaji (Payroll) belum diaktifkan.</div></div>';
    include '../../includes/footer.php';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Dashboard Gaji';
$pageSubtitle = 'Ringkasan aktivitas penggajian';

// Auto-create Payroll Tables on first access
try {
    $db->query("SELECT 1 FROM payroll_employees LIMIT 1");
} catch (Exception $e) {
    if (file_exists('../../database-payroll.sql')) {
        $sql = file_get_contents('../../database-payroll.sql');
        $db->exec($sql);
        setFlash('success', 'Database Payroll berhasil diinisialisasi.');
        header("Refresh:0");
        exit;
    }
}

// Stats
$stats = [
    'employees' => $db->fetchOne("SELECT COUNT(*) as c FROM payroll_employees WHERE is_active = 1")['c'] ?? 0,
    'last_period' => $db->fetchOne("SELECT * FROM payroll_periods ORDER BY id DESC LIMIT 1"),
];

// Recent Periods
$periods = $db->fetchAll("SELECT * FROM payroll_periods ORDER BY id DESC LIMIT 5");

include '../../includes/header.php';
?>

<div class="main-content">
    <div class="header-container fade-in-up">
        <div class="header-content">
            <h1 class="page-title">Dashboard Payroll</h1>
            <p class="page-subtitle">Selamat datang di modul penggajian</p>
        </div>
        <div class="header-actions">
            <a href="process.php" class="btn btn-primary">
                <i data-feather="plus"></i> Proses Gaji Baru
            </a>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid mb-4 fade-in-up" style="animation-delay: 0.1s">
        <div class="stat-card">
            <div class="stat-icon bg-primary-subtle text-primary">
                <i data-feather="users"></i>
            </div>
            <div class="stat-content">
                <p class="stat-label">Total Karyawan</p>
                <h3 class="stat-value"><?php echo $stats['employees']; ?></h3>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-success-subtle text-success">
                <i data-feather="dollar-sign"></i>
            </div>
            <div class="stat-content">
                <p class="stat-label">Pengeluaran Terakhir</p>
                <h3 class="stat-value">
                    <?php echo $stats['last_period'] ? 'Rp ' . number_format($stats['last_period']['total_net'], 0, ',', '.') : '-'; ?>
                </h3>
                <small class="text-muted"><?php echo $stats['last_period']['period_label'] ?? ''; ?></small>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-info-subtle text-info">
                <i data-feather="calendar"></i>
            </div>
            <div class="stat-content">
                <p class="stat-label">Periode Terakhir</p>
                <h3 class="stat-value text-capitalize">
                    <?php echo $stats['last_period']['status'] ?? '-'; ?>
                </h3>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-4 mb-4 fade-in-up" style="animation-delay: 0.2s">
        <div class="col-md-4">
            <a href="employees.php" class="card h-100 text-decoration-none hover-card">
                <div class="card-body text-center p-4">
                    <div class="avatar avatar-lg bg-primary-subtle text-primary mb-3 mx-auto">
                        <i data-feather="users"></i>
                    </div>
                    <h5 class="card-title text-dark">Data Karyawan</h5>
                    <p class="text-muted small">Kelola data karyawan, jabatan, dan gaji pokok.</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="process.php" class="card h-100 text-decoration-none hover-card">
                <div class="card-body text-center p-4">
                    <div class="avatar avatar-lg bg-success-subtle text-success mb-3 mx-auto">
                        <i data-feather="monitor"></i>
                    </div>
                    <h5 class="card-title text-dark">Proses Gaji</h5>
                    <p class="text-muted small">Input lembur, hitung gaji bulanan, dan ajukan pembayaran.</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="reports.php" class="card h-100 text-decoration-none hover-card">
                <div class="card-body text-center p-4">
                    <div class="avatar avatar-lg bg-warning-subtle text-warning mb-3 mx-auto">
                        <i data-feather="bar-chart-2"></i>
                    </div>
                    <h5 class="card-title text-dark">Laporan Gaji</h5>
                    <p class="text-muted small">Lihat rekapitulasi pengeluaran gaji bulanan dan tahunan.</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent History -->
    <div class="card fade-in-up" style="animation-delay: 0.3s">
        <div class="card-header">
            <h3 class="card-title">Riwayat Penggajian</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th>Status</th>
                            <th>Jml Karyawan</th>
                            <th class="text-end">Total Gaji Bersih</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($periods)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat penggajian</td></tr>
                        <?php else: ?>
                            <?php foreach($periods as $p): ?>
                            <tr>
                                <td class="fw-medium">
                                    <i data-feather="calendar" class="icon-xs me-2 text-muted"></i>
                                    <?php echo htmlspecialchars($p['period_label']); ?>
                                </td>
                                <td>
                                    <?php 
                                    $badgeClass = match($p['status']) {
                                        'draft' => 'bg-secondary',
                                        'submitted' => 'bg-warning',
                                        'approved' => 'bg-info',
                                        'paid' => 'bg-success',
                                        default => 'bg-light text-dark'
                                    };
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($p['status']); ?></span>
                                </td>
                                <td><?php echo $p['total_employees']; ?> Orang</td>
                                <td class="text-end fw-bold text-success">Rp <?php echo number_format($p['total_net'], 0, ',', '.'); ?></td>
                                <td class="text-end">
                                    <a href="process.php?month=<?php echo $p['period_month']; ?>&year=<?php echo $p['period_year']; ?>" class="btn btn-sm btn-outline-primary">Detail</a>
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

<script>feather.replace();</script>
<?php include '../../includes/footer.php'; ?>
