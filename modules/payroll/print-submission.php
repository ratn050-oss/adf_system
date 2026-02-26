<?php
// modules/payroll/print-submission.php - OWNER PAYROLL SUBMISSION
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$period_id = $_GET['period_id'] ?? 0;

$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE id = ?", [$period_id]);
if (!$period) die("Period not found");

$slips = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ? ORDER BY employee_name ASC", [$period_id]);

// Calculate totals
$totalBase = array_sum(array_column($slips, 'base_salary'));
$totalOvertime = array_sum(array_column($slips, 'overtime_amount'));
$totalIncentive = array_sum(array_column($slips, 'incentive'));
$totalAllowance = array_sum(array_column($slips, 'allowance'));
$totalBonus = array_sum(array_column($slips, 'bonus'));
$totalOther = array_sum(array_column($slips, 'other_income'));
$totalGross = $period['total_gross'];
$totalDeductions = $period['total_deductions'];
$totalNet = $period['total_net'];

$companyName = BUSINESS_NAME;
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$periodLabel = $monthNames[$period['period_month']] . ' ' . $period['period_year'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Submission - <?php echo $periodLabel; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; 
            font-size: 11pt;
            color: #1a1a2e;
            background: #f8fafc;
            padding: 20px;
        }
        
        .document {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        /* Header */
        .doc-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 24px 32px;
            text-align: center;
        }
        
        .doc-header h1 { 
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: 1px;
            margin: 0 0 4px;
            text-transform: uppercase;
        }
        
        .doc-header h2 {
            font-size: 18pt;
            font-weight: 700;
            margin: 0 0 6px;
        }
        
        .doc-header .period {
            font-size: 11pt;
            opacity: 0.9;
        }
        
        /* Summary Cards */
        .summary-section {
            padding: 24px 32px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .summary-title {
            font-size: 10pt;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        
        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .summary-card label {
            display: block;
            font-size: 9pt;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .summary-card .value {
            font-size: 14pt;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .summary-card.highlight {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            border: none;
        }
        
        .summary-card.highlight label { color: rgba(255,255,255,0.8); }
        .summary-card.highlight .value { color: #fff; }
        
        /* Employee Table */
        .table-section {
            padding: 24px 32px;
        }
        
        .table-title {
            font-size: 10pt;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        
        th {
            background: #f1f5f9;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        
        tr:hover td { background: #f8fafc; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .emp-name { font-weight: 600; color: #1a1a2e; }
        .emp-position { font-size: 8pt; color: #64748b; }
        
        .amount { font-family: 'SF Mono', Monaco, monospace; }
        .amount.positive { color: #10b981; }
        .amount.negative { color: #ef4444; }
        .amount.net { font-weight: 700; color: #667eea; }
        
        tfoot td {
            background: #1a1a2e;
            color: #fff;
            font-weight: 700;
            padding: 12px;
        }
        
        tfoot .amount { color: #fff; }
        
        /* Total Box */
        .total-box {
            margin: 24px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
        }
        
        .total-box .label {
            font-size: 11pt;
            font-weight: 500;
        }
        
        .total-box .amount {
            font-size: 20pt;
            font-weight: 700;
        }
        
        .total-box .words {
            font-size: 9pt;
            opacity: 0.8;
            margin-top: 4px;
        }
        
        /* Signatures */
        .signature-section {
            padding: 32px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            page-break-inside: avoid;
        }
        
        .sig-block {
            text-align: center;
        }
        
        .sig-title {
            font-size: 9pt;
            color: #64748b;
            margin-bottom: 60px;
        }
        
        .sig-line {
            border-bottom: 1px solid #1a1a2e;
            margin-bottom: 6px;
        }
        
        .sig-name {
            font-size: 10pt;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        /* Print Styles */
        @media print {
            body { 
                background: #fff; 
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .document { 
                box-shadow: none; 
                border-radius: 0;
                max-width: 100%;
            }
            .doc-header, .summary-card.highlight, .total-box, tfoot td {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page { 
                size: A4 landscape; 
                margin: 10mm; 
            }
        }
        
        /* Button for screen */
        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(102,126,234,0.4);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .print-btn:hover { transform: translateY(-2px); }
        
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>

<div class="document">
    
    <!-- Header -->
    <div class="doc-header">
        <h1><?php echo strtoupper($companyName); ?></h1>
        <h2>Payroll Submission Request</h2>
        <div class="period">Period: <?php echo $periodLabel; ?></div>
    </div>
    
    <!-- Summary Section -->
    <div class="summary-section">
        <div class="summary-title">Payroll Summary</div>
        <div class="summary-grid">
            <div class="summary-card">
                <label>Total Employees</label>
                <div class="value"><?php echo count($slips); ?></div>
            </div>
            <div class="summary-card">
                <label>Total Gross Salary</label>
                <div class="value">Rp <?php echo number_format($totalGross, 0, ',', '.'); ?></div>
            </div>
            <div class="summary-card">
                <label>Total Deductions</label>
                <div class="value" style="color: #ef4444;">Rp <?php echo number_format($totalDeductions, 0, ',', '.'); ?></div>
            </div>
            <div class="summary-card highlight">
                <label>Net Amount Required</label>
                <div class="value">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Employee Detail Table -->
    <div class="table-section">
        <div class="table-title">Employee Salary Details</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Employee</th>
                    <th class="text-right">Base Salary</th>
                    <th class="text-right">Overtime</th>
                    <th class="text-right">Incentive</th>
                    <th class="text-right">Allowance</th>
                    <th class="text-right">Bonus/Other</th>
                    <th class="text-right">Deductions</th>
                    <th class="text-right">Net Salary</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach($slips as $slip): ?>
                <tr>
                    <td class="text-center"><?php echo $no++; ?></td>
                    <td>
                        <div class="emp-name"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                        <div class="emp-position"><?php echo htmlspecialchars($slip['position']); ?></div>
                    </td>
                    <td class="text-right"><span class="amount"><?php echo number_format($slip['base_salary'], 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount positive"><?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($slip['incentive'], 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($slip['allowance'], 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($slip['bonus'] + ($slip['other_income'] ?? 0), 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount negative"><?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount net"><?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="text-right">TOTAL</td>
                    <td class="text-right"><span class="amount"><?php echo number_format($totalBase, 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($totalOvertime, 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($totalIncentive, 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($totalAllowance, 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($totalBonus + $totalOther, 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($totalDeductions, 0, ',', '.'); ?></span></td>
                    <td class="text-right"><span class="amount"><?php echo number_format($totalNet, 0, ',', '.'); ?></span></td>
                </tr>
            </tfoot>
        </table>
    </div>
    
    <!-- Grand Total Box -->
    <div class="total-box">
        <div>
            <div class="label">Total Amount Required for Payroll Disbursement:</div>
            <div class="words">(Bank Transfer)</div>
        </div>
        <div style="text-align: right;">
            <div class="amount">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></div>
        </div>
    </div>
    
    <!-- Signatures -->
    <div class="signature-section">
        <div class="sig-block">
            <div class="sig-title">Prepared by</div>
            <div class="sig-line"></div>
            <div class="sig-name">Admin / HR</div>
        </div>
        <div class="sig-block">
            <div class="sig-title">Reviewed by</div>
            <div class="sig-line"></div>
            <div class="sig-name">Finance Manager</div>
        </div>
        <div class="sig-block">
            <div class="sig-title">Approved by</div>
            <div class="sig-line"></div>
            <div class="sig-name">Owner</div>
        </div>
    </div>
    
</div>

<button class="print-btn" onclick="window.print()">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
    Print Document
</button>

</body>
</html>
