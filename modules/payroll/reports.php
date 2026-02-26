<?php
// modules/payroll/reports.php - MODERN 2027 ENGLISH VERSION
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
$pageTitle = 'Payroll Reports';

$tab = $_GET['tab'] ?? 'monthly';
$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Data Fetching
$data = [];
$totalNet = 0;
$period = null;

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

<style>
/* ══════════════════════════════════════════════════════════════════════════
   PAYROLL REPORTS 2027 - LUXE DESIGN
   ══════════════════════════════════════════════════════════════════════════ */
:root {
    --rp-gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --rp-gradient-2: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --rp-radius: 16px;
    --rp-radius-sm: 10px;
}

.rp-page-wrapper {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0;
}

/* Header Hero */
.rp-header {
    background: var(--rp-gradient-1);
    border-radius: var(--rp-radius);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    position: relative;
    overflow: hidden;
}

.rp-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.rp-header h1 {
    color: #fff;
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0;
    position: relative;
    z-index: 2;
}

.rp-header p {
    color: rgba(255,255,255,0.8);
    margin: 0.15rem 0 0;
    font-size: 0.85rem;
}

.rp-btn-print {
    background: rgba(255,255,255,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
    position: relative;
    z-index: 2;
}

.rp-btn-print:hover {
    background: rgba(255,255,255,0.35);
    transform: translateY(-1px);
}

/* Tabs */
.rp-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.25rem;
}

.rp-tab {
    padding: 0.6rem 1.25rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
}

.rp-tab:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
}

.rp-tab.active {
    background: var(--rp-gradient-1);
    border-color: transparent;
    color: #fff;
}

/* Filter Bar */
.rp-filter-bar {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--rp-radius-sm);
    padding: 0.85rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.rp-filter-bar label {
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text-secondary);
}

.rp-filter-bar select {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--bg-secondary);
}

.rp-filter-bar select:focus {
    outline: none;
    border-color: var(--primary-color);
}

.rp-filter-btn {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    border: none;
    background: var(--rp-gradient-1);
    color: #fff;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s;
}

.rp-filter-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102,126,234,0.3);
}

/* Table Card */
.rp-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--rp-radius);
    overflow: hidden;
}

.rp-card-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-color);
    text-align: center;
}

.rp-card-header h3 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
    color: var(--text-primary);
}

/* Elegant Table */
.rp-table {
    width: 100%;
    border-collapse: collapse;
}

.rp-table th {
    padding: 0.65rem 0.85rem;
    text-align: left;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-tertiary);
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
}

.rp-table td {
    padding: 0.65rem 0.85rem;
    border-bottom: 1px solid var(--border-light);
    vertical-align: middle;
    font-size: 0.83rem;
}

.rp-table tr:hover td { background: var(--bg-secondary); }
.rp-table tr:last-child td { border-bottom: none; }

.rp-table tfoot tr td {
    background: var(--bg-secondary);
    font-weight: 700;
    border-top: 2px solid var(--border-color);
}

.rp-amount {
    font-family: 'SF Mono', 'Monaco', monospace;
    font-size: 0.82rem;
}

.rp-amount.positive { color: #22c55e; }
.rp-amount.negative { color: #ef4444; }
.rp-amount.highlight {
    font-weight: 700;
    background: var(--rp-gradient-2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.rp-status {
    padding: 0.25rem 0.65rem;
    border-radius: 50px;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
}

.rp-status.draft { background: rgba(156,163,175,0.15); color: #6b7280; }
.rp-status.submitted { background: rgba(59,130,246,0.15); color: #3b82f6; }
.rp-status.approved { background: rgba(34,197,94,0.15); color: #22c55e; }
.rp-status.paid { background: rgba(139,92,246,0.15); color: #8b5cf6; }

/* Empty State */
.rp-empty {
    text-align: center;
    padding: 2.5rem 1.5rem;
}

.rp-empty-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 0.75rem;
    border-radius: 50%;
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-tertiary);
}

/* Grand Total */
.rp-grand-total {
    background: var(--rp-gradient-1);
    color: #fff;
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.rp-grand-total span {
    font-weight: 600;
}

.rp-grand-total strong {
    font-size: 1.25rem;
}

@media print {
    .rp-header, .rp-tabs, .rp-filter-bar, .sidebar, .header-navbar, .rp-btn-print {
        display: none !important;
    }
    .rp-page-wrapper { max-width: 100%; margin: 0; }
    .rp-card { border: 1px solid #ddd; }
    body { background: white; -webkit-print-color-adjust: exact; }
}

@media (max-width: 768px) {
    .rp-header { flex-direction: column; text-align: center; }
    .rp-filter-bar { flex-direction: column; align-items: stretch; }
    .rp-tabs { justify-content: center; }
}
</style>

<div class="rp-page-wrapper">
    
    <!-- Header -->
    <div class="rp-header fade-in-up">
        <div>
            <h1>Payroll Reports</h1>
            <p>Analyze salary expenditure</p>
        </div>
        <?php if (!empty($data)): ?>
        <button class="rp-btn-print" onclick="window.print()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Print Report
        </button>
        <?php endif; ?>
    </div>

    <!-- Tabs -->
    <div class="rp-tabs fade-in-up" style="animation-delay: 0.05s">
        <a href="?tab=monthly" class="rp-tab <?php echo $tab === 'monthly' ? 'active' : ''; ?>">Monthly Report</a>
        <a href="?tab=yearly" class="rp-tab <?php echo $tab === 'yearly' ? 'active' : ''; ?>">Yearly Report</a>
    </div>

    <!-- Filter -->
    <form method="GET" class="rp-filter-bar fade-in-up" style="animation-delay: 0.1s">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">
        
        <?php if ($tab === 'monthly'): ?>
        <label>Month:</label>
        <select name="month">
            <?php foreach($months as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $k == $month ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        
        <label>Year:</label>
        <select name="year">
            <?php for($y = 2024; $y <= date('Y')+1; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
        
        <button type="submit" class="rp-filter-btn">Apply</button>
    </form>

    <!-- Report Card -->
    <div class="rp-card fade-in-up" style="animation-delay: 0.15s">
        
        <?php if ($tab === 'monthly'): ?>
            <div class="rp-card-header">
                <h3>Monthly Payroll Report - <?php echo $months[$month] . ' ' . $year; ?></h3>
            </div>
            
            <?php if (empty($data)): ?>
            <div class="rp-empty">
                <div class="rp-empty-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                </div>
                <h4 style="margin: 0 0 0.5rem; color: var(--text-secondary);">No Data</h4>
                <p style="margin: 0; color: var(--text-tertiary);">Payroll data for this period is not available.</p>
            </div>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table class="rp-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Position</th>
                        <th style="text-align: right;">Base Salary</th>
                        <th style="text-align: right;">Overtime</th>
                        <th style="text-align: right;">Incentive</th>
                        <th style="text-align: right;">Allowance</th>
                        <th style="text-align: right;">Deductions</th>
                        <th style="text-align: right;">Net Pay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no=1; foreach($data as $row): ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['employee_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td style="text-align: right;"><span class="rp-amount">Rp <?php echo number_format($row['base_salary'], 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;">
                            <span class="rp-amount">Rp <?php echo number_format($row['overtime_amount'], 0, ',', '.'); ?></span>
                            <div style="font-size: 0.68rem; color: var(--text-tertiary);">(<?php echo $row['overtime_hours']; ?> hrs)</div>
                        </td>
                        <td style="text-align: right;"><span class="rp-amount">Rp <?php echo number_format($row['incentive'], 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;"><span class="rp-amount">Rp <?php echo number_format($row['allowance'], 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;"><span class="rp-amount negative">Rp <?php echo number_format($row['total_deductions'], 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;"><span class="rp-amount highlight">Rp <?php echo number_format($row['net_salary'], 0, ',', '.'); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right;">TOTAL</td>
                        <td style="text-align: right;">Rp <?php echo number_format(array_sum(array_column($data, 'base_salary')), 0, ',', '.'); ?></td>
                        <td style="text-align: right;">Rp <?php echo number_format(array_sum(array_column($data, 'overtime_amount')), 0, ',', '.'); ?></td>
                        <td style="text-align: right;">Rp <?php echo number_format(array_sum(array_column($data, 'incentive')), 0, ',', '.'); ?></td>
                        <td style="text-align: right;">Rp <?php echo number_format(array_sum(array_column($data, 'allowance')), 0, ',', '.'); ?></td>
                        <td style="text-align: right;"><span class="negative">Rp <?php echo number_format(array_sum(array_column($data, 'total_deductions')), 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;"><span class="highlight">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></span></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            
            <div class="rp-grand-total">
                <span>Total Payroll Net:</span>
                <strong>Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></strong>
            </div>
            <?php endif; ?>

        <?php elseif ($tab === 'yearly'): ?>
            <div class="rp-card-header">
                <h3>Yearly Payroll Report - <?php echo $year; ?></h3>
            </div>
            
            <?php if (empty($data)): ?>
            <div class="rp-empty">
                <div class="rp-empty-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                </div>
                <h4 style="margin: 0 0 0.5rem; color: var(--text-secondary);">No Data</h4>
                <p style="margin: 0; color: var(--text-tertiary);">No payroll data for this year.</p>
            </div>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table class="rp-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Status</th>
                        <th style="text-align: center;">Employees</th>
                        <th style="text-align: right;">Total Gross</th>
                        <th style="text-align: right;">Total Deductions</th>
                        <th style="text-align: right;">Total Net</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data as $row): ?>
                    <tr>
                        <td><strong><?php echo $months[$row['period_month']]; ?></strong></td>
                        <td><span class="rp-status <?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                        <td style="text-align: center;"><?php echo $row['total_employees']; ?></td>
                        <td style="text-align: right;"><span class="rp-amount">Rp <?php echo number_format($row['total_gross'], 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;"><span class="rp-amount negative">Rp <?php echo number_format($row['total_deductions'], 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;"><span class="rp-amount highlight">Rp <?php echo number_format($row['total_net'], 0, ',', '.'); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right;">GRAND TOTAL <?php echo $year; ?></td>
                        <td style="text-align: right;">Rp <?php echo number_format(array_sum(array_column($data, 'total_gross')), 0, ',', '.'); ?></td>
                        <td style="text-align: right;"><span class="negative">Rp <?php echo number_format(array_sum(array_column($data, 'total_deductions')), 0, ',', '.'); ?></span></td>
                        <td style="text-align: right;"><span class="highlight">Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></span></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            
            <div class="rp-grand-total">
                <span>Grand Total Year <?php echo $year; ?>:</span>
                <strong>Rp <?php echo number_format($totalNet, 0, ',', '.'); ?></strong>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
