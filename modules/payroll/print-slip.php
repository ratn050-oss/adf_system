<?php
// modules/payroll/print-slip.php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/print-helper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$slip_id = $_GET['id'] ?? 0;

$slip = $db->fetchOne("
    SELECT s.*, p.period_label 
    FROM payroll_slips s 
    JOIN payroll_periods p ON s.period_id = p.id 
    WHERE s.id = ?", 
    [$slip_id]
);

if (!$slip) {
    die("Slip gaji tidak ditemukan.");
}

$businessName = BUSINESS_NAME;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Slip Gaji - <?php echo $slip['employee_name']; ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 10pt; color: #333; }
        .slip-container { width: 100%; max-width: 800px; margin: 0 auto; border: 1px solid #ccc; padding: 30px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
        .header h2 { margin: 0; color: #333; text-transform: uppercase; }
        .header p { margin: 5px 0; color: #666; }
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { padding: 5px; }
        .content-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .content-table th, .content-table td { padding: 8px; border-bottom: 1px solid #eee; }
        .amount { text-align: right; }
        .total-row { font-weight: bold; background-color: #f9f9f9; border-top: 2px solid #333; }
        .footer { margin-top: 50px; display: flex; justify-content: space-between; text-align: center; }
        .signature { width: 30%; border-top: 1px solid #333; padding-top: 5px; margin-top: 40px; }
        
        @media print {
            body { margin: 0; padding: 0; background: white; }
            .slip-container { border: none; padding: 0; }
            button { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

<div class="slip-container">
    <div class="header">
        <h2><?php echo $businessName; ?></h2>
        <p>SLIP GAJI KARYAWAN</p>
        <p><strong>Periode: <?php echo $slip['period_label']; ?></strong></p>
    </div>

    <table class="info-table">
        <tr>
            <td width="15%"><strong>Nama</strong></td>
            <td width="35%">: <?php echo $slip['employee_name']; ?></td>
            <td width="15%"><strong>Jabatan</strong></td>
            <td width="35%">: <?php echo $slip['position']; ?></td>
        </tr>
    </table>

    <table class="content-table">
        <thead>
            <tr>
                <th align="left">KOMPONEN PENDAPATAN</th>
                <th align="right">JUMLAH (Rp)</th>
                <th align="left" style="padding-left: 30px;">KOMPONEN POTONGAN</th>
                <th align="right">JUMLAH (Rp)</th>
            </tr>
        </thead>
        <tbody style="vertical-align: top;">
            <tr>
                <td>
                    Gaji Pokok<br>
                    Lembur (<?php echo $slip['overtime_hours']; ?> jam)<br>
                    Insentif<br>
                    Tunjangan<br>
                    Bonus / Lainnya
                </td>
                <td class="amount">
                    <?php echo number_format($slip['base_salary'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['incentive'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['allowance'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['bonus'] + $slip['other_income'], 0, ',', '.'); ?>
                </td>
                
                <td style="padding-left: 30px;">
                    Kasbon / Pinjaman<br>
                    Absensi / Alpha<br>
                    BPJS<br>
                    Pajak<br>
                    Potongan Lain
                </td>
                <td class="amount">
                    <?php echo number_format($slip['deduction_loan'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['deduction_absence'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['deduction_bpjs'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['deduction_tax'], 0, ',', '.'); ?><br>
                    <?php echo number_format($slip['deduction_other'], 0, ',', '.'); ?>
                </td>
            </tr>
            
            <tr class="total-row">
                <td>Total Pendapatan</td>
                <td class="amount"><?php echo number_format($slip['total_earnings'], 0, ',', '.'); ?></td>
                <td style="padding-left: 30px;">Total Potongan</td>
                <td class="amount"><?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?></td>
            </tr>
        </tbody>
    </table>

    <div style="background-color: #eee; padding: 15px; margin-top: 20px; border-radius: 5px;">
        <table width="100%">
            <tr>
                <td align="left" style="font-size: 14pt; font-weight: bold;">TOTAL GAJI BERSIH (THP)</td>
                <td align="right" style="font-size: 16pt; font-weight: bold;">Rp <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></td>
            </tr>
            <tr>
                <td colspan="2" style="font-style: italic; font-size: 9pt; padding-top: 5px;">
                    Terbilang: <?php echo terbilang($slip['net_salary']); ?> Rupiah
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <div>
            <br>
            Dibuat Oleh,<br>
            <div class="signature">Admin Keuangan</div>
        </div>
        <div>
            <br>
            Disetujui Oleh,<br>
            <div class="signature">Manager / Owner</div>
        </div>
        <div>
            Jepara, <?php echo date('d M Y'); ?><br>
            Diterima Oleh,<br>
            <div class="signature"><?php echo $slip['employee_name']; ?></div>
        </div>
    </div>
</div>

</body>
</html>
