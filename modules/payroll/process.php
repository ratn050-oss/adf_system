<?php
// modules/payroll/process.php - MODERN 2027 DESIGN WITH WORK HOURS LOGIC
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
$pageTitle = 'Process Salary';

// Ensure work_hours column exists
try {
    $db->query("ALTER TABLE payroll_slips ADD COLUMN IF NOT EXISTS work_hours DECIMAL(10,2) NOT NULL DEFAULT 200.00 AFTER position");
    $db->query("ALTER TABLE payroll_slips ADD COLUMN IF NOT EXISTS actual_base DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER work_hours");
} catch (Exception $e) {
    // Column may already exist or not supported
}

$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);

if (!$period && isset($_POST['create_period'])) {
    try {
        $label = $months[$month] . ' ' . $year;
        $db->query("INSERT INTO payroll_periods (period_month, period_year, period_label, status, created_by) VALUES (?, ?, ?, 'draft', ?)", 
                  [$month, $year, $label, $_SESSION['user_id']]);
        $period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
        
        $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1");
        foreach ($employees as $emp) {
            $db->query("INSERT INTO payroll_slips (period_id, employee_id, employee_name, position, base_salary, work_hours, actual_base) VALUES (?, ?, ?, ?, ?, 200, ?)",
                      [$period['id'], $emp['id'], $emp['full_name'], $emp['position'], $emp['base_salary'], $emp['base_salary']]);
        }
        
        setFlash('success', 'Payroll period created successfully');
        header("Location: process.php?month=$month&year=$year");
        exit;
    } catch (PDOException $e) {
        setFlash('error', 'Failed to create period: ' . $e->getMessage());
    }
}

$slips = [];
if ($period) {
    $slips = $db->fetchAll("
        SELECT s.*, e.employee_code, e.department 
        FROM payroll_slips s 
        JOIN payroll_employees e ON s.employee_id = e.id 
        WHERE s.period_id = ?
        ORDER BY s.employee_name ASC", 
        [$period['id']]
    );
}

// Handle AJAX Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    $slip_id = $_POST['slip_id'];
    
    $base_salary = (float)$_POST['base_salary'];
    $work_hours = (float)$_POST['work_hours'];
    $overtime_hours = (float)$_POST['overtime_hours'];
    $incentive = (float)$_POST['incentive'];
    $allowance = (float)$_POST['allowance'];
    $bonus = (float)$_POST['bonus'];
    $other = (float)$_POST['other_income'];
    
    $loan = (float)$_POST['deduction_loan'];
    $absence = (float)$_POST['deduction_absence'];
    $tax = (float)$_POST['deduction_tax'];
    $bpjs = (float)$_POST['deduction_bpjs'];
    $ded_other = (float)$_POST['deduction_other'];
    
    // NEW LOGIC: If work_hours >= 200, full base. If < 200, calculate hourly
    $hourly_rate = $base_salary / 200;
    if ($work_hours >= 200) {
        $actual_base = $base_salary;
    } else {
        $actual_base = $work_hours * $hourly_rate;
    }
    
    // Overtime still uses same rate
    $overtime_rate = $hourly_rate;
    $overtime_amount = $overtime_hours * $overtime_rate;
    
    $total_earnings = $actual_base + $overtime_amount + $incentive + $allowance + $bonus + $other;
    $total_deductions = $loan + $absence + $tax + $bpjs + $ded_other;
    $net_salary = $total_earnings - $total_deductions;
    
    try {
        $sql = "UPDATE payroll_slips SET 
                base_salary = ?, work_hours = ?, actual_base = ?,
                overtime_hours = ?, overtime_rate = ?, overtime_amount = ?,
                incentive = ?, allowance = ?, bonus = ?, other_income = ?,
                deduction_loan = ?, deduction_absence = ?, deduction_tax = ?, deduction_bpjs = ?, deduction_other = ?,
                total_earnings = ?, total_deductions = ?, net_salary = ?
                WHERE id = ?";
        
        $db->query($sql, [
            $base_salary, $work_hours, $actual_base,
            $overtime_hours, $overtime_rate, $overtime_amount,
            $incentive, $allowance, $bonus, $other,
            $loan, $absence, $tax, $bpjs, $ded_other,
            $total_earnings, $total_deductions, $net_salary,
            $slip_id
        ]);
        
        $period_id = $period['id'];
        $db->query("UPDATE payroll_periods p
                    LEFT JOIN (
                        SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt 
                        FROM payroll_slips WHERE period_id = ?
                    ) s ON p.id = s.period_id
                    SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt
                    WHERE p.id = ?", [$period_id, $period_id]);
                    
        echo json_encode(['status' => 'success', 'net_salary' => $net_salary, 'actual_base' => $actual_base]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Submit Period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_period'])) {
    $db->query("UPDATE payroll_periods SET status = 'submitted', submitted_at = NOW(), submitted_by = ? WHERE id = ?", 
              [$_SESSION['user_id'], $period['id']]);
    setFlash('success', 'Payroll submitted to Owner for approval');
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Approve Period (Owner) - Record to Cashbook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_period'])) {
    try {
        $db->query("UPDATE payroll_periods SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?", 
                  [$_SESSION['user_id'], $period['id']]);
        
        $periodLabel = $months[$period['period_month']] . ' ' . $period['period_year'];
        $description = 'Payroll ' . $periodLabel . ' - Bank Transfer';
        $amount = $period['total_net'];
        
        $bankAccount = $db->fetchOne("SELECT id FROM cash_accounts WHERE (account_name LIKE '%Bank%' OR account_name LIKE '%BCA%' OR account_name LIKE '%BRI%') AND is_active = 1 LIMIT 1");
        $accountId = $bankAccount ? $bankAccount['id'] : null;
        
        $db->query(
            "INSERT INTO cashbook_transactions (transaction_date, transaction_type, account_id, category, description, amount, payment_method, reference_number, created_by) 
             VALUES (CURDATE(), 'expense', ?, 'Payroll', ?, ?, 'transfer', ?, ?)",
            [$accountId, $description, $amount, 'PAYROLL-' . $period['id'], $_SESSION['user_id']]
        );
        
        setFlash('success', 'Payroll approved! Rp ' . number_format($amount, 0, ',', '.') . ' recorded to cashbook.');
    } catch (Exception $e) {
        setFlash('error', 'Error approving payroll: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Mark as Paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $db->query("UPDATE payroll_periods SET status = 'paid', paid_at = NOW() WHERE id = ?", [$period['id']]);
    setFlash('success', 'Payroll marked as Paid');
    header("Location: process.php?month=$month&year=$year");
    exit;
}

include '../../includes/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════
   PROCESS SALARY 2027 - MODERN DESIGN
   ══════════════════════════════════════════════════════════════════════════ */
:root {
    --ps-gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --ps-gradient-2: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --ps-radius: 16px;
    --ps-radius-sm: 10px;
}

.ps-page-wrapper {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0;
}

/* Header Hero */
.ps-header {
    background: var(--ps-gradient-1);
    border-radius: var(--ps-radius);
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

.ps-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.ps-header h1 {
    color: #fff;
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0;
    position: relative;
    z-index: 2;
}

.ps-header p {
    color: rgba(255,255,255,0.8);
    margin: 0.15rem 0 0;
    font-size: 0.85rem;
}

.ps-filter {
    display: flex;
    gap: 0.5rem;
    position: relative;
    z-index: 2;
}

.ps-filter select {
    padding: 0.5rem 0.75rem;
    border: none;
    border-radius: 8px;
    background: rgba(255,255,255,0.2);
    color: #fff;
    font-size: 0.85rem;
    cursor: pointer;
    backdrop-filter: blur(10px);
}

.ps-filter select option { color: #333; }

/* Status Bar */
.ps-status-bar {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--ps-radius-sm);
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.ps-status-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.ps-status-badge {
    padding: 0.4rem 0.85rem;
    border-radius: 50px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ps-status-badge.draft { background: rgba(156,163,175,0.2); color: #6b7280; }
.ps-status-badge.submitted { background: rgba(245,158,11,0.2); color: #d97706; }
.ps-status-badge.approved { background: rgba(34,197,94,0.2); color: #22c55e; }
.ps-status-badge.paid { background: rgba(139,92,246,0.2); color: #8b5cf6; }

.ps-total-net {
    font-size: 1.5rem;
    font-weight: 700;
    background: var(--ps-gradient-2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.ps-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.ps-btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.2s;
    text-decoration: none;
}

.ps-btn-warning { background: linear-gradient(135deg, #f59e0b, #d97706); color: #1a1a2e; }
.ps-btn-success { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; }
.ps-btn-primary { background: var(--ps-gradient-1); color: #fff; }
.ps-btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); }
.ps-btn-outline:hover { border-color: var(--primary-color); color: var(--primary-color); }

/* Table Card */
.ps-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--ps-radius);
    overflow: hidden;
}

.ps-table-container {
    overflow-x: auto;
    max-height: 65vh;
}

.ps-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.ps-table th {
    padding: 0.65rem 0.6rem;
    text-align: center;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-tertiary);
    background: var(--bg-secondary);
    border-bottom: 2px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
}

.ps-table th.col-employee {
    text-align: left;
    min-width: 180px;
    position: sticky;
    left: 0;
    z-index: 15;
    background: var(--bg-secondary);
}

.ps-table td {
    padding: 0.5rem 0.4rem;
    border-bottom: 1px solid var(--border-light);
    vertical-align: middle;
    text-align: center;
}

.ps-table td.col-employee {
    text-align: left;
    position: sticky;
    left: 0;
    background: var(--bg-primary);
    z-index: 5;
    border-right: 1px solid var(--border-color);
}

.ps-table tr:hover td { background: var(--bg-secondary); }
.ps-table tr:hover td.col-employee { background: var(--bg-secondary); }

.ps-emp-name { font-weight: 600; color: var(--text-primary); font-size: 0.82rem; }
.ps-emp-pos { font-size: 0.7rem; color: var(--text-tertiary); }

/* Input Styling */
.ps-input {
    width: 100%;
    padding: 0.35rem 0.4rem;
    border: 1px solid transparent;
    border-radius: 6px;
    background: transparent;
    font-size: 0.82rem;
    text-align: right;
    transition: all 0.2s;
    color: var(--text-primary);
}

.ps-input:hover { background: var(--bg-tertiary); }
.ps-input:focus { 
    outline: none; 
    border-color: var(--primary-color); 
    background: var(--bg-secondary);
    box-shadow: 0 0 0 2px rgba(102,126,234,0.2);
}

.ps-input.readonly { color: var(--text-tertiary); cursor: default; }
.ps-input.highlight-hours { background: rgba(245,158,11,0.15); border-color: rgba(245,158,11,0.3); text-align: center; font-weight: 600; }
.ps-input.negative { color: #ef4444; }

.ps-cell-calc {
    font-family: 'SF Mono', Monaco, monospace;
    font-size: 0.78rem;
    color: var(--text-secondary);
}

.ps-cell-net {
    font-weight: 700;
    font-size: 0.85rem;
    background: var(--ps-gradient-2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Empty State */
.ps-empty {
    text-align: center;
    padding: 3rem 1.5rem;
}

.ps-empty-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 1rem;
    border-radius: 50%;
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-tertiary);
}

.ps-empty h3 { margin: 0 0 0.5rem; color: var(--text-secondary); }
.ps-empty p { margin: 0 0 1.5rem; color: var(--text-tertiary); }

/* Info Tooltip */
.ps-info {
    font-size: 0.65rem;
    color: var(--text-tertiary);
    margin-top: 0.15rem;
    font-weight: 400;
}

/* Modal */
.ps-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.ps-modal-overlay.active { display: flex; }

.ps-modal {
    background: var(--bg-primary);
    border-radius: var(--ps-radius);
    width: 90%;
    max-width: 450px;
    padding: 1.5rem;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.ps-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
}

.ps-modal-title { font-size: 1rem; font-weight: 700; margin: 0; }
.ps-modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-tertiary); }

.ps-form-group { margin-bottom: 0.85rem; }
.ps-form-label { display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--text-secondary); }
.ps-form-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--bg-secondary);
}
.ps-form-input:focus { outline: none; border-color: var(--primary-color); }

@media (max-width: 768px) {
    .ps-header { flex-direction: column; align-items: stretch; text-align: center; }
    .ps-filter { justify-content: center; }
    .ps-status-bar { flex-direction: column; text-align: center; }
    .ps-actions { justify-content: center; }
}
</style>

<div class="ps-page-wrapper">
    
    <!-- Header -->
    <div class="ps-header fade-in-up">
        <div>
            <h1>Process Salary</h1>
            <p>Calculate monthly payroll with work hours logic</p>
        </div>
        <form method="GET" class="ps-filter">
            <select name="month" onchange="this.form.submit()">
                <?php foreach($months as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $k == $month ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" onchange="this.form.submit()">
                <?php for($y = 2024; $y <= date('Y')+1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <?php if (!$period): ?>
    <!-- Empty State -->
    <div class="ps-card fade-in-up">
        <div class="ps-empty">
            <div class="ps-empty-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            </div>
            <h3>No Payroll Period</h3>
            <p>Create a new period for <?php echo $months[$month] . ' ' . $year; ?></p>
            <form method="POST">
                <input type="hidden" name="create_period" value="1">
                <button type="submit" class="ps-btn ps-btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create Period
                </button>
            </form>
        </div>
    </div>
    
    <?php else: ?>
    
    <!-- Status Bar -->
    <div class="ps-status-bar fade-in-up" style="animation-delay: 0.1s">
        <div class="ps-status-info">
            <span class="ps-status-badge <?php echo $period['status']; ?>"><?php echo $period['status']; ?></span>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-tertiary);">Total Net Salary</span>
                <div class="ps-total-net">Rp <?php echo number_format($period['total_net'], 0, ',', '.'); ?></div>
            </div>
        </div>
        
        <div class="ps-actions">
            <?php if($period['status'] == 'draft'): ?>
                <form method="POST" onsubmit="return confirm('Submit this payroll to Owner?')">
                    <input type="hidden" name="submit_period" value="1">
                    <button type="submit" class="ps-btn ps-btn-warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        Submit to Owner
                    </button>
                </form>
            <?php elseif($period['status'] == 'submitted'): ?>
                <form method="POST" onsubmit="return confirm('Approve and record to Cashbook?')">
                    <input type="hidden" name="approve_period" value="1">
                    <button type="submit" class="ps-btn ps-btn-success">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Approve & Record
                    </button>
                </form>
            <?php elseif($period['status'] == 'approved'): ?>
                <form method="POST" onsubmit="return confirm('Mark as Paid?')">
                    <input type="hidden" name="mark_paid" value="1">
                    <button type="submit" class="ps-btn ps-btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Mark as Paid
                    </button>
                </form>
            <?php endif; ?>
            
            <a href="print-submission.php?period_id=<?php echo $period['id']; ?>" target="_blank" class="ps-btn ps-btn-outline">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print
            </a>
        </div>
    </div>

    <!-- Info Box -->
    <div style="background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.8rem; color: #b45309;">
        <strong>Work Hours Logic:</strong> Target = 200 hours. If Work Hrs &ge; 200, employee gets full Base Salary. If Work Hrs &lt; 200, calculated as (Base/200) &times; Work Hrs.
    </div>

    <!-- Payroll Table -->
    <div class="ps-card fade-in-up" style="animation-delay: 0.15s">
        <div class="ps-table-container">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th class="col-employee">Employee</th>
                        <th style="width: 100px;">Base Salary<div class="ps-info">(Full Rate)</div></th>
                        <th style="width: 80px; background: rgba(245,158,11,0.1);">Work Hrs<div class="ps-info">(Target: 200)</div></th>
                        <th style="width: 100px;">Actual Base<div class="ps-info">(Auto Calc)</div></th>
                        <th style="width: 70px; background: rgba(59,130,246,0.1);">OT Hours</th>
                        <th style="width: 90px;">OT Amount</th>
                        <th style="width: 90px;">Incentive</th>
                        <th style="width: 90px;">Allowance</th>
                        <th style="width: 90px;">Bonus</th>
                        <th style="width: 90px; color: #ef4444;">Deductions</th>
                        <th style="width: 110px;">Net Salary</th>
                        <th style="width: 40px;">#</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($slips as $slip): 
                        $workHours = $slip['work_hours'] ?? 200;
                        $actualBase = $slip['actual_base'] ?? $slip['base_salary'];
                    ?>
                    <tr id="row-<?php echo $slip['id']; ?>" 
                        data-loan="<?php echo $slip['deduction_loan']; ?>"
                        data-absence="<?php echo $slip['deduction_absence']; ?>"
                        data-bpjs="<?php echo $slip['deduction_bpjs']; ?>"
                        data-other="<?php echo $slip['deduction_other']; ?>">
                        
                        <td class="col-employee">
                            <div class="ps-emp-name"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                            <div class="ps-emp-pos"><?php echo htmlspecialchars($slip['position']); ?></div>
                        </td>
                        
                        <td>
                            <input type="text" class="ps-input currency-input" 
                                   value="<?php echo number_format($slip['base_salary'], 0, ',', '.'); ?>"
                                   data-field="base_salary" data-id="<?php echo $slip['id']; ?>"
                                   onchange="calculateRow(<?php echo $slip['id']; ?>)">
                        </td>
                        
                        <td>
                            <input type="number" class="ps-input highlight-hours" 
                                   value="<?php echo $workHours; ?>" step="1" min="0" max="300"
                                   data-field="work_hours" data-id="<?php echo $slip['id']; ?>"
                                   onchange="calculateRow(<?php echo $slip['id']; ?>)">
                        </td>
                        
                        <td>
                            <span id="actual-base-<?php echo $slip['id']; ?>" class="ps-cell-calc">
                                <?php echo number_format($actualBase, 0, ',', '.'); ?>
                            </span>
                        </td>
                        
                        <td>
                            <input type="number" class="ps-input" style="background: rgba(59,130,246,0.1);"
                                   value="<?php echo $slip['overtime_hours']; ?>" step="0.5" min="0"
                                   data-field="overtime_hours" data-id="<?php echo $slip['id']; ?>"
                                   onchange="calculateRow(<?php echo $slip['id']; ?>)">
                        </td>
                        
                        <td>
                            <span id="ot-amount-<?php echo $slip['id']; ?>" class="ps-cell-calc">
                                <?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?>
                            </span>
                        </td>
                        
                        <td>
                            <input type="text" class="ps-input currency-input" 
                                   value="<?php echo number_format($slip['incentive'], 0, ',', '.'); ?>"
                                   data-field="incentive" data-id="<?php echo $slip['id']; ?>"
                                   onchange="calculateRow(<?php echo $slip['id']; ?>)">
                        </td>
                        
                        <td>
                            <input type="text" class="ps-input currency-input" 
                                   value="<?php echo number_format($slip['allowance'], 0, ',', '.'); ?>"
                                   data-field="allowance" data-id="<?php echo $slip['id']; ?>"
                                   onchange="calculateRow(<?php echo $slip['id']; ?>)">
                        </td>
                        
                        <td>
                            <input type="text" class="ps-input currency-input" 
                                   value="<?php echo number_format($slip['bonus'] + $slip['other_income'], 0, ',', '.'); ?>"
                                   data-field="bonus" data-id="<?php echo $slip['id']; ?>"
                                   onchange="calculateRow(<?php echo $slip['id']; ?>)">
                        </td>
                        
                        <td>
                            <input type="text" class="ps-input currency-input negative" 
                                   value="<?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?>"
                                   data-field="total_deductions" data-id="<?php echo $slip['id']; ?>"
                                   readonly onclick="openDeductionModal(<?php echo $slip['id']; ?>, '<?php echo htmlspecialchars(addslashes($slip['employee_name'])); ?>')"
                                   style="cursor: pointer;">
                        </td>
                        
                        <td>
                            <span id="net-<?php echo $slip['id']; ?>" class="ps-cell-net">
                                <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?>
                            </span>
                        </td>
                        
                        <td>
                            <button type="button" class="ps-btn ps-btn-outline" style="padding: 0.3rem 0.5rem;"
                                    onclick="openDeductionModal(<?php echo $slip['id']; ?>, '<?php echo htmlspecialchars(addslashes($slip['employee_name'])); ?>')">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<!-- Deduction Modal -->
<div class="ps-modal-overlay" id="deductionModal">
    <div class="ps-modal">
        <div class="ps-modal-header">
            <h4 class="ps-modal-title">Deductions: <span id="modalEmpName"></span></h4>
            <button type="button" class="ps-modal-close" onclick="closeDeductionModal()">&times;</button>
        </div>
        <input type="hidden" id="modalSlipId">
        
        <div class="ps-form-group">
            <label class="ps-form-label">Loan / Cash Advance</label>
            <input type="text" class="ps-form-input currency-input" id="modalLoan">
        </div>
        <div class="ps-form-group">
            <label class="ps-form-label">Absence Deduction</label>
            <input type="text" class="ps-form-input currency-input" id="modalAbsence">
        </div>
        <div class="ps-form-group">
            <label class="ps-form-label">BPJS</label>
            <input type="text" class="ps-form-input currency-input" id="modalBpjs">
        </div>
        <div class="ps-form-group">
            <label class="ps-form-label">Other Deductions</label>
            <input type="text" class="ps-form-input currency-input" id="modalOther">
        </div>
        
        <div style="text-align: right; margin-top: 1rem;">
            <button type="button" class="ps-btn ps-btn-primary" onclick="saveDeduction()">Save Deductions</button>
        </div>
    </div>
</div>

<script>
// Format Currency Input
document.querySelectorAll('.currency-input').forEach(input => {
    input.addEventListener('keyup', function(e) {
        let val = this.value.replace(/\D/g, '');
        this.value = new Intl.NumberFormat('id-ID').format(val);
    });
});

function getValByRow(id, field) {
    let el = document.querySelector(`input[data-id="${id}"][data-field="${field}"]`);
    if (!el) return 0;
    if (field === 'overtime_hours' || field === 'work_hours') return parseFloat(el.value) || 0;
    return parseFloat(el.value.replace(/\./g, '').replace(/,/g, '')) || 0;
}

function calculateRow(id) {
    let base = getValByRow(id, 'base_salary');
    let workHours = getValByRow(id, 'work_hours');
    let otHours = getValByRow(id, 'overtime_hours');
    
    // Hourly rate = Base / 200
    let hourlyRate = base / 200;
    
    // NEW LOGIC: If work >= 200, full base. If < 200, hourly calc
    let actualBase;
    if (workHours >= 200) {
        actualBase = base;
    } else {
        actualBase = Math.round(workHours * hourlyRate);
    }
    
    // Update Actual Base Display
    document.getElementById(`actual-base-${id}`).innerText = new Intl.NumberFormat('id-ID').format(actualBase);
    
    // Overtime Amount
    let otAmount = Math.round(otHours * hourlyRate);
    document.getElementById(`ot-amount-${id}`).innerText = new Intl.NumberFormat('id-ID').format(otAmount);
    
    // Other incomes
    let incentive = getValByRow(id, 'incentive');
    let allowance = getValByRow(id, 'allowance');
    let bonus = getValByRow(id, 'bonus'); // combined bonus+other
    
    // Deductions
    let row = document.getElementById(`row-${id}`);
    let loan = parseFloat(row.getAttribute('data-loan')) || 0;
    let absence = parseFloat(row.getAttribute('data-absence')) || 0;
    let bpjs = parseFloat(row.getAttribute('data-bpjs')) || 0;
    let dedOther = parseFloat(row.getAttribute('data-other')) || 0;
    let totalDed = loan + absence + bpjs + dedOther;
    
    // Update deductions input display
    let dedInput = document.querySelector(`input[data-id="${id}"][data-field="total_deductions"]`);
    if (dedInput) dedInput.value = new Intl.NumberFormat('id-ID').format(totalDed);
    
    // Calculate Net
    let totalEarn = actualBase + otAmount + incentive + allowance + bonus;
    let net = totalEarn - totalDed;
    document.getElementById(`net-${id}`).innerText = new Intl.NumberFormat('id-ID').format(net);
    
    // Auto-save via Ajax
    saveRow(id);
}

function saveRow(id) {
    const row = document.getElementById(`row-${id}`);
    const data = new FormData();
    data.append('ajax_update', 1);
    data.append('slip_id', id);
    
    data.append('base_salary', getValByRow(id, 'base_salary'));
    data.append('work_hours', getValByRow(id, 'work_hours'));
    data.append('overtime_hours', getValByRow(id, 'overtime_hours'));
    data.append('incentive', getValByRow(id, 'incentive'));
    data.append('allowance', getValByRow(id, 'allowance'));
    data.append('bonus', getValByRow(id, 'bonus'));
    data.append('other_income', 0);
    
    data.append('deduction_loan', row.getAttribute('data-loan') || 0);
    data.append('deduction_absence', row.getAttribute('data-absence') || 0);
    data.append('deduction_tax', 0);
    data.append('deduction_bpjs', row.getAttribute('data-bpjs') || 0);
    data.append('deduction_other', row.getAttribute('data-other') || 0);
    
    fetch('process.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>', {
        method: 'POST',
        body: data
    }).then(res => res.json())
      .then(res => {
          // Silent save - no reload needed, totals update on next page visit
      });
}

// Modal Functions
function openDeductionModal(id, name) {
    document.getElementById('modalSlipId').value = id;
    document.getElementById('modalEmpName').innerText = name;
    
    const row = document.getElementById(`row-${id}`);
    document.getElementById('modalLoan').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-loan') || 0);
    document.getElementById('modalAbsence').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-absence') || 0);
    document.getElementById('modalBpjs').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-bpjs') || 0);
    document.getElementById('modalOther').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-other') || 0);
    
    document.getElementById('deductionModal').classList.add('active');
}

function closeDeductionModal() {
    document.getElementById('deductionModal').classList.remove('active');
}

function getVal(selector) {
    let el = document.querySelector(selector);
    if (!el) return 0;
    return parseFloat(el.value.replace(/\./g, '').replace(/,/g, '')) || 0;
}

function saveDeduction() {
    let id = document.getElementById('modalSlipId').value;
    let loan = getVal('#modalLoan');
    let abs = getVal('#modalAbsence');
    let bpjs = getVal('#modalBpjs');
    let other = getVal('#modalOther');
    
    let row = document.getElementById(`row-${id}`);
    row.setAttribute('data-loan', loan);
    row.setAttribute('data-absence', abs);
    row.setAttribute('data-bpjs', bpjs);
    row.setAttribute('data-other', other);
    
    closeDeductionModal();
    calculateRow(id);
}

// Close modal on backdrop click
document.getElementById('deductionModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDeductionModal();
});
</script>

<?php include '../../includes/footer.php'; ?>
