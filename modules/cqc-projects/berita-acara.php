<?php
/**
 * CQC Berita Acara Serah Terima Proyek
 * Official project completion certificate - printable A4
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once 'db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$autoPrint = isset($_GET['print']) && $_GET['print'] == '1';

if (!$id) {
    header('Location: dashboard.php');
    exit;
}

// Get project
$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    die("Proyek tidak ditemukan.");
}

// Get expenses summary
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cqc_project_expenses WHERE project_id = ?");
$stmt->execute([$id]);
$totalExpense = floatval($stmt->fetch()['total']);

// Get invoices
$stmt = $pdo->prepare("SELECT * FROM cqc_termin_invoices WHERE project_id = ? ORDER BY termin_number ASC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPaid = array_sum(array_column($invoices, 'paid_amount'));
$contractValue = !empty($invoices) ? floatval($invoices[0]['contract_value']) : floatval($project['budget_idr']);

// Company info
$db = Database::getInstance();
$companyName = 'CQC Enjiniring';
$companyTagline = 'Solar Panel Installation Contractor';
$companyAddress = '';
$companyCity = '';
$companyPhone = '-';
$companyEmail = '-';
$companyNPWP = '-';
$companyLogo = '';

try {
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM business_settings WHERE business_id = 7");
    foreach ($settings as $s) {
        switch ($s['setting_key']) {
            case 'business_name': if ($s['setting_value']) $companyName = $s['setting_value']; break;
            case 'tagline': if ($s['setting_value']) $companyTagline = $s['setting_value']; break;
            case 'address': if ($s['setting_value']) $companyAddress = $s['setting_value']; break;
            case 'city': if ($s['setting_value']) $companyCity = $s['setting_value']; break;
            case 'phone': if ($s['setting_value']) $companyPhone = $s['setting_value']; break;
            case 'email': if ($s['setting_value']) $companyEmail = $s['setting_value']; break;
            case 'npwp': if ($s['setting_value']) $companyNPWP = $s['setting_value']; break;
            case 'logo': if ($s['setting_value']) $companyLogo = $s['setting_value']; break;
        }
    }
} catch (Exception $e) {}

// Fallback from config
$configPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(dirname(__DIR__));
$configFile = $configPath . '/config/businesses/cqc.php';
if (file_exists($configFile)) {
    $cqcConfig = include $configFile;
    if ($companyName === 'CQC Enjiniring' && isset($cqcConfig['name'])) $companyName = $cqcConfig['name'];
    if (empty($companyLogo) && isset($cqcConfig['logo'])) $companyLogo = $cqcConfig['logo'];
    if (empty($companyAddress) && isset($cqcConfig['address'])) $companyAddress = $cqcConfig['address'];
    if (empty($companyCity) && isset($cqcConfig['city'])) $companyCity = $cqcConfig['city'];
}

$completionDate = $project['actual_completion'] ?: ($project['end_date'] ?: date('Y-m-d'));
$baNumber = 'BA/' . date('Y/m', strtotime($completionDate)) . '/' . str_pad($id, 3, '0', STR_PAD_LEFT) . '/CQC';

// Duration
$startDate = $project['start_date'] ? new DateTime($project['start_date']) : null;
$endDate = new DateTime($completionDate);
$duration = $startDate ? $startDate->diff($endDate)->days : 0;

$profit = $totalPaid - $totalExpense;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Berita Acara - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        @page { size: A4; margin: 15mm 20mm; }
        
        body {
            font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            font-size: 12px; line-height: 1.6; color: #1e293b;
            background: #f1f5f9;
        }

        .page {
            max-width: 210mm; margin: 20px auto; background: #fff;
            padding: 40px 50px; box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            min-height: 297mm;
        }

        /* Header */
        .ba-header {
            display: flex; justify-content: space-between; align-items: center;
            padding-bottom: 16px; border-bottom: 3px solid #0d1f3c; margin-bottom: 24px;
        }
        .ba-company { display: flex; align-items: center; gap: 14px; }
        .ba-logo {
            width: 55px; height: 55px; background: linear-gradient(135deg, #f0b429, #d4960d);
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 800; color: #0d1f3c;
        }
        .ba-logo img { width: 55px; height: 55px; border-radius: 10px; object-fit: cover; }
        .ba-company-name { font-size: 20px; font-weight: 800; color: #0d1f3c; }
        .ba-company-tagline { font-size: 10px; color: #64748b; font-weight: 500; }
        .ba-company-contact { font-size: 9px; color: #94a3b8; margin-top: 2px; }

        .ba-doc-info { text-align: right; }
        .ba-doc-number { font-size: 11px; font-weight: 700; color: #0d1f3c; }
        .ba-doc-date { font-size: 10px; color: #64748b; margin-top: 2px; }

        /* Title */
        .ba-title {
            text-align: center; margin-bottom: 24px; padding: 16px;
            background: linear-gradient(135deg, #0d1f3c, #1a3a5c); border-radius: 8px; color: #fff;
        }
        .ba-title h1 { font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; }
        .ba-title .ba-subtitle { font-size: 11px; opacity: 0.8; margin-top: 4px; }

        /* Sections */
        .ba-section { margin-bottom: 18px; }
        .ba-section-title {
            font-size: 12px; font-weight: 700; color: #0d1f3c; text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 8px; padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Info Grid */
        .ba-info-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; font-size: 11px;
        }
        .ba-info-row { display: flex; gap: 8px; }
        .ba-info-label { color: #64748b; min-width: 130px; flex-shrink: 0; }
        .ba-info-value { color: #1e293b; font-weight: 600; }

        /* Table */
        .ba-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 8px; }
        .ba-table th {
            padding: 8px 10px; text-align: left; background: #0d1f3c; color: #f0b429;
            font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .ba-table td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
        .ba-table tr:nth-child(even) { background: #f8fafc; }
        .ba-table .total-row { background: #f1f5f9; font-weight: 700; }
        .ba-table .total-row td { border-top: 2px solid #0d1f3c; }

        /* Financial Summary */
        .ba-financial {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 14px 18px; margin-top: 10px;
        }
        .ba-financial-row {
            display: flex; justify-content: space-between; padding: 5px 0; font-size: 12px;
        }
        .ba-financial-row.profit {
            border-top: 2px solid #0d1f3c; margin-top: 6px; padding-top: 8px;
            font-size: 14px; font-weight: 800;
        }

        /* Signatures */
        .ba-signatures {
            display: grid; grid-template-columns: 1fr 1fr; gap: 40px;
            margin-top: 40px; text-align: center;
        }
        .ba-sig-box { }
        .ba-sig-title { font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 4px; }
        .ba-sig-space { height: 70px; border-bottom: 1px solid #1e293b; margin: 0 20px; }
        .ba-sig-name { font-size: 12px; font-weight: 700; color: #1e293b; margin-top: 6px; }
        .ba-sig-role { font-size: 10px; color: #64748b; }

        /* Footer */
        .ba-footer {
            margin-top: 30px; padding-top: 12px; border-top: 1px solid #e2e8f0;
            text-align: center; font-size: 9px; color: #94a3b8;
        }

        /* Print */
        @media print {
            body { background: #fff; }
            .page { box-shadow: none; margin: 0; padding: 0; }
            .no-print { display: none !important; }
        }

        /* Action Bar */
        .action-bar {
            max-width: 210mm; margin: 0 auto 16px; display: flex; gap: 8px; justify-content: flex-end;
        }
        .action-btn {
            padding: 8px 18px; border-radius: 8px; text-decoration: none; font-size: 12px;
            font-weight: 600; cursor: pointer; border: none; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .action-btn-back { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
        .action-btn-back:hover { background: #f8fafc; }
        .action-btn-print { background: #f0b429; color: #0d1f3c; }
        .action-btn-print:hover { background: #d4960d; }
    </style>
</head>
<body>

<!-- Action Bar (no print) -->
<div class="action-bar no-print">
    <a href="report.php?id=<?php echo $id; ?>" class="action-btn action-btn-back">← Kembali ke Laporan</a>
    <a href="dashboard.php" class="action-btn action-btn-back">Dashboard</a>
    <button onclick="window.print();" class="action-btn action-btn-print">🖨 Cetak Berita Acara</button>
</div>

<div class="page">
    <!-- Header -->
    <div class="ba-header">
        <div class="ba-company">
            <div class="ba-logo">
                <?php if ($companyLogo): ?>
                <img src="<?php echo BASE_URL . '/' . $companyLogo; ?>" alt="Logo">
                <?php else: ?>
                CQC
                <?php endif; ?>
            </div>
            <div>
                <div class="ba-company-name"><?php echo htmlspecialchars($companyName); ?></div>
                <div class="ba-company-tagline"><?php echo htmlspecialchars($companyTagline); ?></div>
                <div class="ba-company-contact">
                    <?php echo htmlspecialchars($companyAddress); ?>
                    <?php if ($companyCity): ?>, <?php echo htmlspecialchars($companyCity); ?><?php endif; ?>
                    <?php if ($companyPhone !== '-'): ?> | <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="ba-doc-info">
            <div class="ba-doc-number">No: <?php echo $baNumber; ?></div>
            <div class="ba-doc-date"><?php echo date('d F Y', strtotime($completionDate)); ?></div>
        </div>
    </div>

    <!-- Title -->
    <div class="ba-title">
        <h1>Berita Acara Serah Terima Pekerjaan</h1>
        <div class="ba-subtitle">Project Completion & Handover Certificate</div>
    </div>

    <!-- Opening -->
    <div class="ba-section">
        <p style="text-align: justify; margin-bottom: 10px;">
            Pada hari ini, <strong><?php echo date('l', strtotime($completionDate)); ?></strong>, 
            tanggal <strong><?php echo date('d F Y', strtotime($completionDate)); ?></strong>, 
            kami yang bertanda tangan di bawah ini menyatakan bahwa pekerjaan proyek berikut telah 
            diselesaikan sesuai dengan lingkup pekerjaan yang telah disepakati:
        </p>
    </div>

    <!-- Project Info -->
    <div class="ba-section">
        <div class="ba-section-title">I. Informasi Proyek</div>
        <div class="ba-info-grid">
            <div class="ba-info-row">
                <span class="ba-info-label">Nama Proyek</span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['project_name']); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Kode Proyek</span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['project_code']); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Nama Klien</span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['client_name'] ?? '-'); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Lokasi</span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['location'] ?? '-'); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Kapasitas</span>
                <span class="ba-info-value">: <?php echo number_format($project['solar_capacity_kwp'] ?? 0, 1); ?> kWp</span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Jumlah Panel</span>
                <span class="ba-info-value">: <?php echo $project['panel_count'] ?? '-'; ?> unit</span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Tanggal Mulai</span>
                <span class="ba-info-value">: <?php echo $project['start_date'] ? date('d F Y', strtotime($project['start_date'])) : '-'; ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Tanggal Selesai</span>
                <span class="ba-info-value">: <?php echo date('d F Y', strtotime($completionDate)); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Durasi Pengerjaan</span>
                <span class="ba-info-value">: <?php echo $duration; ?> hari</span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label">Status</span>
                <span class="ba-info-value" style="color: #059669;">: ✓ SELESAI</span>
            </div>
        </div>
    </div>

    <!-- Financial Summary -->
    <div class="ba-section">
        <div class="ba-section-title">II. Rekapitulasi Keuangan</div>
        
        <?php if (!empty($invoices)): ?>
        <p style="margin-bottom: 8px; font-size: 11px; color: #64748b;">Rincian Pembayaran Termin:</p>
        <table class="ba-table">
            <thead>
                <tr>
                    <th style="width: 35px;">No</th>
                    <th>Invoice</th>
                    <th>Termin</th>
                    <th style="text-align: right;">Nilai</th>
                    <th style="text-align: right;">Dibayar</th>
                    <th style="width: 60px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $i => $inv): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                    <td>Termin <?php echo $inv['termin_number']; ?> (<?php echo $inv['percentage']; ?>%)</td>
                    <td style="text-align: right; font-family: monospace;">Rp <?php echo number_format($inv['total_amount'], 0, ',', '.'); ?></td>
                    <td style="text-align: right; font-family: monospace;">Rp <?php echo number_format($inv['paid_amount'], 0, ',', '.'); ?></td>
                    <td style="text-align: center;">
                        <span style="font-size: 9px; padding: 2px 6px; border-radius: 3px;
                            <?php echo $inv['payment_status'] === 'paid' ? 'background: #dcfce7; color: #166534;' : 'background: #fef3c7; color: #92400e;'; ?>">
                            <?php echo ucfirst($inv['payment_status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="ba-financial">
            <div class="ba-financial-row">
                <span>Nilai Kontrak</span>
                <span style="font-weight: 600;">Rp <?php echo number_format($contractValue, 0, ',', '.'); ?></span>
            </div>
            <div class="ba-financial-row">
                <span style="color: #059669;">Total Pemasukan (Dibayar)</span>
                <span style="font-weight: 600; color: #059669;">Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></span>
            </div>
            <div class="ba-financial-row">
                <span style="color: #dc2626;">Total Pengeluaran</span>
                <span style="font-weight: 600; color: #dc2626;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></span>
            </div>
            <div class="ba-financial-row profit">
                <span><?php echo $profit >= 0 ? 'PROFIT' : 'RUGI'; ?></span>
                <span style="color: <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <?php echo $profit >= 0 ? '+' : ''; ?>Rp <?php echo number_format($profit, 0, ',', '.'); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Scope -->
    <div class="ba-section">
        <div class="ba-section-title">III. Lingkup Pekerjaan</div>
        <p style="text-align: justify; font-size: 11px;">
            Pekerjaan yang telah diselesaikan meliputi instalasi sistem pembangkit listrik tenaga surya (PLTS) 
            dengan kapasitas <strong><?php echo number_format($project['solar_capacity_kwp'] ?? 0, 1); ?> kWp</strong>
            <?php if ($project['panel_count']): ?>, terdiri dari <strong><?php echo $project['panel_count']; ?> unit</strong> panel surya<?php endif; ?>
            <?php if ($project['panel_type']): ?> tipe <strong><?php echo htmlspecialchars($project['panel_type']); ?></strong><?php endif; ?>
            <?php if ($project['inverter_type']): ?> dengan inverter <strong><?php echo htmlspecialchars($project['inverter_type']); ?></strong><?php endif; ?>.
            Instalasi telah dilakukan di lokasi <strong><?php echo htmlspecialchars($project['location'] ?? '-'); ?></strong> 
            dan telah melalui proses pengujian (commissioning) sesuai standar yang berlaku.
        </p>
    </div>

    <!-- Declaration -->
    <div class="ba-section">
        <div class="ba-section-title">IV. Pernyataan</div>
        <p style="text-align: justify; font-size: 11px; margin-bottom: 8px;">
            Dengan ditandatanganinya Berita Acara ini, kedua belah pihak menyatakan bahwa:
        </p>
        <ol style="font-size: 11px; padding-left: 20px; line-height: 1.8;">
            <li>Seluruh pekerjaan instalasi telah diselesaikan sesuai dengan spesifikasi dan lingkup pekerjaan yang disepakati.</li>
            <li>Sistem telah diuji dan berfungsi dengan baik sesuai standar teknis yang berlaku.</li>
            <li>Penyerahan hasil pekerjaan telah dilakukan dari pihak kontraktor kepada pihak pemberi kerja.</li>
            <li>Masa garansi dimulai sejak tanggal penandatanganan Berita Acara ini.</li>
        </ol>
    </div>

    <!-- Signatures -->
    <div class="ba-signatures">
        <div class="ba-sig-box">
            <div class="ba-sig-title">Pihak Pertama (Kontraktor)</div>
            <div class="ba-sig-space"></div>
            <div class="ba-sig-name">(........................................)</div>
            <div class="ba-sig-role"><?php echo htmlspecialchars($companyName); ?></div>
        </div>
        <div class="ba-sig-box">
            <div class="ba-sig-title">Pihak Kedua (Pemberi Kerja)</div>
            <div class="ba-sig-space"></div>
            <div class="ba-sig-name"><?php echo htmlspecialchars($project['client_name'] ?? '(........................................)'); ?></div>
            <div class="ba-sig-role">Klien / Pemilik</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="ba-footer">
        <p>Dokumen ini dicetak secara elektronik oleh <?php echo htmlspecialchars($companyName); ?> pada <?php echo date('d F Y H:i'); ?> WIB</p>
        <p style="margin-top: 4px;"><?php echo $baNumber; ?> | <?php echo htmlspecialchars($project['project_code']); ?></p>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>

</body>
</html>
