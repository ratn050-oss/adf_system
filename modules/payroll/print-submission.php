<?php
// modules/payroll/print-submission.php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/print-helper.php'; // Helper for layout

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$period_id = $_GET['period_id'] ?? 0;

$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE id = ?", [$period_id]);
if (!$period) die("Periode tidak ditemukan");

$slips = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ? ORDER BY employee_name ASC", [$period_id]);

// Print Setup
$companyName = BUSINESS_NAME;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pengajuan Gaji - <?php echo $period['period_label']; ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        .header { text-align: center; margin-bottom: 20px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #333; padding: 6px; }
        th { background-color: #eee; }
        .amount { text-align: right; }
        .total-row { font-weight: bold; background-color: #f0f0f0; }
        
        .signature-section { 
            margin-top: 40px; 
            display: flex; 
            justify-content: space-between;
            page-break-inside: avoid;
        }
        .sig-block { text-align: center; width: 30%; }
        .sig-line { border-bottom: 1px solid black; margin-top: 60px; }
        
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="header">
        <h3><?php echo $companyName; ?></h3>
        <h4>PENGAJUAN PENGGAJIAN KARYAWAN</h4>
        <p>PERIODE: <?php echo $period['period_label']; ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th width="3%">No</th>
                <th>Nama Karyawan</th>
                <th>Jabatan</th>
                <th width="10%">Gaji Pokok</th>
                <th width="8%">Lembur<br>(Jam)</th>
                <th width="8%">Lembur<br>(Rp)</th>
                <th width="8%">Insentif</th>
                <th width="8%">Tunjangan</th>
                <th width="8%">Bonus/Lain</th>
                <th width="8%">Potongan</th>
                <th width="12%">Gaji Bersih</th>
                <th>Tanda Tangan</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach($slips as $slip): 
            ?>
            <tr>
                <td align="center"><?php echo $no++; ?></td>
                <td><?php echo $slip['employee_name']; ?></td>
                <td><?php echo $slip['position']; ?></td>
                <td class="amount"><?php echo number_format($slip['base_salary'], 0, ',', '.'); ?></td>
                <td align="center"><?php echo $slip['overtime_hours']; ?></td>
                <td class="amount"><?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format($slip['incentive'], 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format($slip['allowance'], 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format($slip['bonus'] + $slip['other_income'], 0, ',', '.'); ?></td>
                <td class="amount text-danger"><?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?></td>
                <td class="amount fw-bold"><?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></td>
                <td></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3" align="center">TOTAL</td>
                <td class="amount"><?php echo number_format(array_sum(array_column($slips, 'base_salary')), 0, ',', '.'); ?></td>
                <td></td>
                <td class="amount"><?php echo number_format(array_sum(array_column($slips, 'overtime_amount')), 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format(array_sum(array_column($slips, 'incentive')), 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format(array_sum(array_column($slips, 'allowance')), 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format(array_sum(array_column($slips, 'bonus')) + array_sum(array_column($slips, 'other_income')), 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format(array_sum(array_column($slips, 'total_deductions')), 0, ',', '.'); ?></td>
                <td class="amount"><?php echo number_format(array_sum(array_column($slips, 'net_salary')), 0, ',', '.'); ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    
    <div style="margin-top: 20px; font-weight: bold; border: 1px solid #333; padding: 10px; display: inline-block;">
        TOTAL YANG HARUS DIBAYARKAN: Rp <?php echo number_format($period['total_net'], 0, ',', '.'); ?>
        <br>
        <span style="font-weight: normal; font-style: italic; font-size: 0.9em;">
            (<?php echo terbilang($period['total_net']); ?> Rupiah)
        </span>
    </div>

    <div class="signature-section">
        <div class="sig-block">
            <p>Dibuat Oleh,</p>
            <div class="sig-line"></div>
            <p>Admin / HRD</p>
        </div>
        <div class="sig-block">
            <p>Diperiksa Oleh,</p>
            <div class="sig-line"></div>
            <p>Manager Keuangan</p>
        </div>
        <div class="sig-block">
            <p>Disetujui Oleh,</p>
            <div class="sig-line"></div>
            <p>Owner</p>
        </div>
    </div>

</body>
</html>
