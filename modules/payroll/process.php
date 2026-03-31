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
    $db->query("ALTER TABLE payroll_slips ADD COLUMN IF NOT EXISTS is_paid TINYINT(1) NOT NULL DEFAULT 0");
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

// ── Helper: Get attendance hours from fingerprint/GPS data for a month ──
function getAttendanceHours($db, $empId, $month, $year) {
    $monthStr = sprintf('%04d-%02d', $year, $month);
    $rows = $db->fetchAll(
        "SELECT work_hours FROM payroll_attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? AND work_hours > 0",
        [$empId, $monthStr]
    );
    $totalHours = 0;
    $totalOvertimeHours = 0;
    foreach ($rows as $r) {
        $wh = (float)$r['work_hours'];
        $totalHours += $wh;
        if ($wh > 8) {
            $otRaw = $wh - 8;
            $otUnits = floor($otRaw / 0.75); // per 45-min block
            $totalOvertimeHours += $otUnits * 0.75;
        }
    }
    return [
        'work_hours' => round($totalHours, 2),
        'overtime_hours' => round($totalOvertimeHours, 2),
        'days_worked' => count($rows)
    ];
}

// ── Helper: Sync all slips with attendance data ──
function syncSlipsWithAttendance($db, $periodId, $month, $year) {
    $slipsToSync = $db->fetchAll("SELECT id, employee_id, base_salary FROM payroll_slips WHERE period_id = ?", [$periodId]);
    foreach ($slipsToSync as $slip) {
        $att = getAttendanceHours($db, $slip['employee_id'], $month, $year);
        $workH = $att['work_hours'];
        $otH = $att['overtime_hours'];
        $baseSalary = (float)$slip['base_salary'];
        $hourlyRate = $baseSalary / 200;
        $actualBase = ($workH >= 200) ? $baseSalary : round($workH * $hourlyRate, 2);
        $otRate = $hourlyRate;
        $otAmount = round($otH * $otRate, 2);

        // Read current addon values
        $cur = $db->fetchOne("SELECT incentive, allowance, uang_makan, bonus, other_income, deduction_loan, deduction_absence, deduction_tax, deduction_bpjs, deduction_other FROM payroll_slips WHERE id = ?", [$slip['id']]);
        $incentive = (float)($cur['incentive'] ?? 0);
        $allowance = (float)($cur['allowance'] ?? 0);
        $uang_makan = (float)($cur['uang_makan'] ?? 0);
        $bonus = (float)($cur['bonus'] ?? 0);
        $other = (float)($cur['other_income'] ?? 0);
        $totalEarn = $actualBase + $otAmount + $incentive + $allowance + $uang_makan + $bonus + $other;
        $loan = (float)($cur['deduction_loan'] ?? 0);
        $absence = (float)($cur['deduction_absence'] ?? 0);
        $tax = (float)($cur['deduction_tax'] ?? 0);
        $bpjs = (float)($cur['deduction_bpjs'] ?? 0);
        $dedOther = (float)($cur['deduction_other'] ?? 0);
        $totalDed = $loan + $absence + $tax + $bpjs + $dedOther;
        $netSalary = $totalEarn - $totalDed;

        $db->query("UPDATE payroll_slips SET work_hours=?, overtime_hours=?, actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=?, uang_makan=? WHERE id=?",
            [$workH, $otH, $actualBase, $otRate, $otAmount, $totalEarn, $totalDed, $netSalary, $uang_makan, $slip['id']]);
    }
    // Update period totals
    $db->query("UPDATE payroll_periods p LEFT JOIN (SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt FROM payroll_slips WHERE period_id = ?) s ON p.id = s.period_id SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt WHERE p.id = ?", [$periodId, $periodId]);
}

if (!$period && isset($_POST['create_period'])) {
    try {
        $label = $months[$month] . ' ' . $year;
        $db->query("INSERT INTO payroll_periods (period_month, period_year, period_label, status, created_by) VALUES (?, ?, ?, 'draft', ?)", 
                  [$month, $year, $label, $_SESSION['user_id']]);
        $period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
        
        $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1");
        foreach ($employees as $emp) {
            // Get real attendance hours for this month
            $att = getAttendanceHours($db, $emp['id'], $month, $year);
            $workH = $att['work_hours'] > 0 ? $att['work_hours'] : 0;
            $baseSalary = (float)$emp['base_salary'];
            $hourlyRate = $baseSalary / 200;
            $actualBase = ($workH >= 200) ? $baseSalary : round($workH * $hourlyRate, 2);
            $db->query("INSERT INTO payroll_slips (period_id, employee_id, employee_name, position, base_salary, work_hours, actual_base, overtime_hours, overtime_rate, overtime_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                      [$period['id'], $emp['id'], $emp['full_name'], $emp['position'], $baseSalary, $workH, $actualBase, $att['overtime_hours'], $hourlyRate, round($att['overtime_hours'] * $hourlyRate, 2)]);
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
    
    // ── Auto-sync slips with attendance data on every page load (draft only) ──
    if ($period['status'] === 'draft') {
        try {
            syncSlipsWithAttendance($db, $period['id'], $month, $year);
            // Re-fetch period after sync
            $period = $db->fetchOne("SELECT * FROM payroll_periods WHERE id = ?", [$period['id']]);
        } catch (Exception $e) { /* ignore sync errors */ }
    }

        // Ensure displayed period totals are in sync with payroll_slips sums
        try {
            $sums = $db->fetchOne("SELECT IFNULL(SUM(total_earnings),0) as gross, IFNULL(SUM(total_deductions),0) as ded, IFNULL(SUM(net_salary),0) as net, COUNT(id) as cnt FROM payroll_slips WHERE period_id = ?", [$period['id']]);
            if ($sums) {
                $period['total_gross'] = $sums['gross'];
                $period['total_deductions'] = $sums['ded'];
                $period['total_net'] = $sums['net'];
                $period['total_employees'] = $sums['cnt'];
                // Persist to payroll_periods to keep DB consistent
                $db->query("UPDATE payroll_periods SET total_gross = ?, total_deductions = ?, total_net = ?, total_employees = ? WHERE id = ?", [$sums['gross'], $sums['ded'], $sums['net'], $sums['cnt'], $period['id']]);
            }
        } catch (Exception $e) {
            // ignore sync errors; page can still render with existing period values
        }
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
    $uang_makan = isset($_POST['uang_makan']) ? (float)$_POST['uang_makan'] : 0;
    
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
    
    $total_earnings = $actual_base + $overtime_amount + $incentive + $allowance + $uang_makan + $bonus + $other;
    $total_deductions = $loan + $absence + $tax + $bpjs + $ded_other;
    $net_salary = $total_earnings - $total_deductions;
    
    try {
        $sql = "UPDATE payroll_slips SET 
                base_salary = ?, work_hours = ?, actual_base = ?,
                overtime_hours = ?, overtime_rate = ?, overtime_amount = ?,
                incentive = ?, allowance = ?, uang_makan = ?, bonus = ?, other_income = ?,
                deduction_loan = ?, deduction_absence = ?, deduction_tax = ?, deduction_bpjs = ?, deduction_other = ?,
                total_earnings = ?, total_deductions = ?, net_salary = ?
                WHERE id = ?";
    
        $db->query($sql, [
            $base_salary, $work_hours, $actual_base,
            $overtime_hours, $overtime_rate, $overtime_amount,
            $incentive, $allowance, $uang_makan, $bonus, $other,
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

// Handle Save/Proses Button (recalculate all slips and update period net total)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_proses'])) {
    if ($period) {
        $slips_recalc = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ?", [$period['id']]);
        foreach ($slips_recalc as $slip) {
            $base_salary = (float)$slip['base_salary'];
            $work_hours = (float)$slip['work_hours'];
            $overtime_hours = (float)$slip['overtime_hours'];
            $incentive = (float)$slip['incentive'];
            $allowance = (float)$slip['allowance'];
            $bonus = (float)$slip['bonus'];
            $other = (float)$slip['other_income'];
            $uang_makan = isset($slip['uang_makan']) ? (float)$slip['uang_makan'] : 0;
            $loan = (float)$slip['deduction_loan'];
            $absence = (float)$slip['deduction_absence'];
            $tax = (float)$slip['deduction_tax'];
            $bpjs = (float)$slip['deduction_bpjs'];
            $ded_other = (float)$slip['deduction_other'];
            $hourly_rate = $base_salary / 200;
            $actual_base = ($work_hours >= 200) ? $base_salary : $work_hours * $hourly_rate;
            $overtime_rate = $hourly_rate;
            $overtime_amount = $overtime_hours * $overtime_rate;
            $total_earnings = $actual_base + $overtime_amount + $incentive + $allowance + $uang_makan + $bonus + $other;
            $total_deductions = $loan + $absence + $tax + $bpjs + $ded_other;
            $net_salary = $total_earnings - $total_deductions;
            $db->query("UPDATE payroll_slips SET actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=?, uang_makan=? WHERE id=?",
                [$actual_base, $overtime_rate, $overtime_amount, $total_earnings, $total_deductions, $net_salary, $uang_makan, $slip['id']]);
        }
        $period_id = $period['id'];
        $db->query("UPDATE payroll_periods p LEFT JOIN ( SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt FROM payroll_slips WHERE period_id = ? ) s ON p.id = s.period_id SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt WHERE p.id = ?", [$period_id, $period_id]);
        setFlash('success', 'All slips recalculated and totals updated!');
        header("Location: process.php?month=$month&year=$year");
        exit;
    }
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

// Handle Quick Pay — Save + Approve + Record Cashbook + Mark Paid in one step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_pay'])) {
    try {
        // 1. Auto-save slips first
        $slips = $db->fetchAll("SELECT id, employee_id FROM payroll_slips WHERE period_id = ?", [$period['id']]) ?: [];
        $totalNet = 0;
        foreach ($slips as $sl) {
            $slip = $db->fetchOne("SELECT * FROM payroll_slips WHERE id = ?", [$sl['id']]);
            $totalNet += (float)($slip['net_salary'] ?? 0);
        }
        
        // 2. Update period totals
        $db->query("UPDATE payroll_periods SET total_net = ?, total_employees = ? WHERE id = ?", 
            [$totalNet, count($slips), $period['id']]);
        
        // 3. Skip to approved + cashbook
        $db->query("UPDATE payroll_periods SET status = 'approved', submitted_at = NOW(), submitted_by = ?, approved_at = NOW(), approved_by = ? WHERE id = ?", 
            [$_SESSION['user_id'], $_SESSION['user_id'], $period['id']]);
        
        $periodLabel = $months[$period['period_month']] . ' ' . $period['period_year'];
        $description = 'Payroll ' . $periodLabel . ' - Bank Transfer';
        $amount = $totalNet ?: $period['total_net'];
        
        $bankAccount = $db->fetchOne("SELECT id FROM cash_accounts WHERE (account_name LIKE '%Bank%' OR account_name LIKE '%BCA%' OR account_name LIKE '%BRI%') AND is_active = 1 LIMIT 1");
        $accountId = $bankAccount ? $bankAccount['id'] : null;
        
        // Check if cashbook entry already exists
        $existing = $db->fetchOne("SELECT id FROM cashbook_transactions WHERE reference_number = ?", ['PAYROLL-' . $period['id']]);
        if (!$existing) {
            $db->query(
                "INSERT INTO cashbook_transactions (transaction_date, transaction_type, account_id, category, description, amount, payment_method, reference_number, created_by) 
                 VALUES (CURDATE(), 'expense', ?, 'Payroll', ?, ?, 'transfer', ?, ?)",
                [$accountId, $description, $amount, 'PAYROLL-' . $period['id'], $_SESSION['user_id']]
            );
        }
        
        // 4. Mark as paid + mark all slips as is_paid
        $db->query("UPDATE payroll_periods SET status = 'paid', paid_at = NOW() WHERE id = ?", [$period['id']]);
        $db->query("UPDATE payroll_slips SET is_paid = 1 WHERE period_id = ?", [$period['id']]);
        
        setFlash('success', '✅ Payroll dibayar! Rp ' . number_format($amount, 0, ',', '.') . ' tercatat di cashbook. Slip gaji tersedia di Staff Portal.');
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Quick Pay Selected — pay individual employees
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_pay_selected'])) {
    $selectedIds = $_POST['selected_slips'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $selectedIds)));
    
    if (empty($ids)) {
        setFlash('error', 'Tidak ada staff yang dipilih');
        header("Location: process.php?month=$month&year=$year");
        exit;
    }
    
    try {
        // Ensure period is at least approved (so portal can see it)
        if ($period['status'] === 'draft') {
            $db->query("UPDATE payroll_periods SET status = 'approved', submitted_at = NOW(), submitted_by = ?, approved_at = NOW(), approved_by = ? WHERE id = ?", 
                [$_SESSION['user_id'], $_SESSION['user_id'], $period['id']]);
        } elseif ($period['status'] === 'submitted') {
            $db->query("UPDATE payroll_periods SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?", 
                [$_SESSION['user_id'], $period['id']]);
        }
        
        // Mark selected slips as paid
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$period['id']]);
        $db->query("UPDATE payroll_slips SET is_paid = 1 WHERE id IN ($placeholders) AND period_id = ?", $params);
        
        // Calculate total for selected
        $selectedSlips = $db->fetchAll("SELECT net_salary, employee_name FROM payroll_slips WHERE id IN ($placeholders)", $ids);
        $totalPaid = 0;
        $names = [];
        foreach ($selectedSlips as $s) {
            $totalPaid += (float)$s['net_salary'];
            $names[] = $s['employee_name'];
        }
        
        // Record to cashbook
        $periodLabel = $months[$period['period_month']] . ' ' . $period['period_year'];
        $description = 'Gaji ' . implode(', ', $names) . ' - ' . $periodLabel;
        if (strlen($description) > 200) $description = 'Gaji ' . count($names) . ' staff - ' . $periodLabel;
        
        $bankAccount = $db->fetchOne("SELECT id FROM cash_accounts WHERE (account_name LIKE '%Bank%' OR account_name LIKE '%BCA%' OR account_name LIKE '%BRI%') AND is_active = 1 LIMIT 1");
        $accountId = $bankAccount ? $bankAccount['id'] : null;
        
        $ref = 'PAYROLL-' . $period['id'] . '-' . implode('_', $ids);
        $db->query(
            "INSERT INTO cashbook_transactions (transaction_date, transaction_type, account_id, category, description, amount, payment_method, reference_number, created_by) 
             VALUES (CURDATE(), 'expense', ?, 'Payroll', ?, ?, 'transfer', ?, ?)",
            [$accountId, $description, $totalPaid, $ref, $_SESSION['user_id']]
        );
        
        // Check if ALL slips in this period are now paid
        $unpaid = $db->fetchOne("SELECT COUNT(*) as c FROM payroll_slips WHERE period_id = ? AND is_paid = 0", [$period['id']]);
        if ((int)($unpaid['c'] ?? 0) === 0) {
            $db->query("UPDATE payroll_periods SET status = 'paid', paid_at = NOW() WHERE id = ?", [$period['id']]);
        }
        
        setFlash('success', '✅ ' . count($ids) . ' staff dibayar! Rp ' . number_format($totalPaid, 0, ',', '.') . ' tercatat. Slip gaji tersedia di Staff Portal.');
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    header("Location: process.php?month=$month&year=$year");
    exit;
}

// Handle Refresh Employees (Sync: add new, remove deleted, update info)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_employees'])) {
    $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1");
    $activeEmpIds = array_column($employees, 'id');
    
    // Remove slips for employees not in active list
    $existingSlips = $db->fetchAll("SELECT id, employee_id FROM payroll_slips WHERE period_id = ?", [$period['id']]);
    $removed = 0;
    foreach ($existingSlips as $slip) {
        if (!in_array($slip['employee_id'], $activeEmpIds)) {
            $db->query("DELETE FROM payroll_slips WHERE id = ?", [$slip['id']]);
            $removed++;
        }
    }
    
    // Get updated existing IDs
    $existingEmpIds = $db->fetchAll("SELECT employee_id FROM payroll_slips WHERE period_id = ?", [$period['id']]);
    $existingIds = array_column($existingEmpIds, 'employee_id');
    
    // Add new employees
    $added = 0;
    foreach ($employees as $emp) {
        if (!in_array($emp['id'], $existingIds)) {
            $att = getAttendanceHours($db, $emp['id'], $month, $year);
            $workH = $att['work_hours'] > 0 ? $att['work_hours'] : 0;
            $baseSalary = (float)$emp['base_salary'];
            $hourlyRate = $baseSalary / 200;
            $actualBase = ($workH >= 200) ? $baseSalary : round($workH * $hourlyRate, 2);
            $db->query("INSERT INTO payroll_slips (period_id, employee_id, employee_name, position, base_salary, work_hours, actual_base, overtime_hours, overtime_rate, overtime_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                      [$period['id'], $emp['id'], $emp['full_name'], $emp['position'], $baseSalary, $workH, $actualBase, $att['overtime_hours'], $hourlyRate, round($att['overtime_hours'] * $hourlyRate, 2)]);
            $added++;
        }
    }
    
    // Update employee info (name, position) for existing slips
    foreach ($employees as $emp) {
        if (in_array($emp['id'], $existingIds)) {
            $db->query("UPDATE payroll_slips SET employee_name = ?, position = ? WHERE period_id = ? AND employee_id = ?",
                      [$emp['full_name'], $emp['position'], $period['id'], $emp['id']]);
        }
    }

    // Sync attendance hours for all slips
    syncSlipsWithAttendance($db, $period['id'], $month, $year);
    
    $msg = [];
    if ($added > 0) $msg[] = "$added added";
    if ($removed > 0) $msg[] = "$removed removed";
    if (empty($msg)) {
        setFlash('info', 'Employee list is up to date');
    } else {
        setFlash('success', 'Employees synced: ' . implode(', ', $msg));
    }
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
    --ps-gradient-2: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
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
    overflow-y: auto;
    max-height: 65vh;
}

.ps-table-container::-webkit-scrollbar { width: 6px; height: 6px; }
.ps-table-container::-webkit-scrollbar-track { background: var(--bg-secondary); }
.ps-table-container::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }

.ps-table {
    width: 100%;
    border-collapse: collapse;
    min-width: auto;
    table-layout: fixed;
}

.ps-table th {
    padding: 0.4rem 0.25rem;
    text-align: center;
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0;
    color: var(--text-tertiary);
    background: var(--bg-secondary);
    border-bottom: 2px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
    white-space: nowrap;
}

.ps-table th.col-employee {
    text-align: left;
    width: 130px;
    min-width: 130px;
    position: sticky;
    left: 0;
    z-index: 15;
    background: var(--bg-secondary);
}

.ps-table td {
    padding: 0.35rem 0.2rem;
    border-bottom: 1px solid var(--border-light);
    vertical-align: middle;
    text-align: center;
    font-size: 0.78rem;
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

.ps-emp-name { font-weight: 600; color: var(--text-primary); font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ps-emp-pos { font-size: 0.65rem; color: var(--text-tertiary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Input Styling */
.ps-input {
    width: 100%;
    padding: 0.25rem 0.2rem;
    border: 1px solid transparent;
    border-radius: 4px;
    background: transparent;
    font-size: 0.72rem;
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
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.ps-cell-net {
    font-weight: 700;
    font-size: 0.75rem;
    color: #f59e0b;
}

/* Save Indicator */
.save-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.6rem;
    color: var(--text-tertiary);
    margin-left: 0.25rem;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.save-indicator.saving,
.save-indicator.saved,
.save-indicator.error {
    opacity: 1;
}

.save-indicator.saving { color: #6366f1; }
.save-indicator.saved { color: #22c55e; }
.save-indicator.error { color: #ef4444; }

.save-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: pulse 1s infinite;
}

.save-dot.saved {
    animation: none;
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.8); }
}

/* Elegant Table Enhancements */
.ps-table tr {
    transition: background 0.15s ease;
}

.ps-table tr:nth-child(even) td {
    background: rgba(0,0,0,0.02);
}

.ps-table tr:nth-child(even):hover td {
    background: var(--bg-secondary);
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
    font-size: 0.55rem;
    color: var(--text-tertiary);
    margin-top: 0.1rem;
    font-weight: 400;
    line-height: 1;
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

.ps-modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.ps-modal-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    margin: 1rem 0;
    background: rgba(239, 68, 68, 0.08);
    border-radius: 8px;
    border: 1px solid rgba(239, 68, 68, 0.2);
}

.ps-modal-total-amount {
    font-size: 1.1rem;
    font-weight: 700;
    color: #ef4444;
}

.ps-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

/* Edit Button */
.ps-btn-edit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
}

.ps-btn-edit:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(102, 126, 234, 0.1);
    transform: scale(1.05);
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
                <form method="POST" id="saveProsesForm" style="display:inline;">
                    <input type="hidden" name="save_proses" value="1">
                    <button type="submit" class="ps-btn ps-btn-primary" style="margin-right:0.5rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Save/Proses
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('Submit this payroll to Owner?')" style="display:inline;">
                    <input type="hidden" name="submit_period" value="1">
                    <button type="submit" class="ps-btn ps-btn-warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        Submit to Owner
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('Bayar langsung & publish slip gaji ke Staff Portal?')" style="display:inline;">
                    <input type="hidden" name="quick_pay" value="1">
                    <button type="submit" class="ps-btn ps-btn-success" style="margin-left:0.5rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        💰 Bayar & Publish
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
            
            <form method="POST" style="display: inline;">
                <input type="hidden" name="refresh_employees" value="1">
                <button type="submit" class="ps-btn ps-btn-outline" title="Refresh: Add new employees to this period">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                    Refresh
                </button>
            </form>
            <a href="print-submission.php?period_id=<?php echo $period['id']; ?>" target="_blank" class="ps-btn ps-btn-outline">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print
            </a>
        </div>
    </div>

    <!-- Info Box -->
    <div style="background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.8rem; color: #b45309;">
        <strong>🔄 Auto-Sync Absensi:</strong> Jam kerja &amp; lembur otomatis diambil dari data fingerprint/GPS setiap hari. Target = 200 jam. Lembur = kelipatan 45 menit di atas 8 jam/hari. Status <code>draft</code> = auto-update realtime.
    </div>

    <!-- Payroll Table -->
    <div class="ps-card fade-in-up" style="animation-delay: 0.15s">
        <div class="ps-table-container">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th style="width:30px;text-align:center;"><input type="checkbox" id="paySelectAll" onchange="togglePaySelectAll(this)" title="Pilih Semua"></th>
                        <th class="col-employee">Employee</th>
                        <th style="width: 75px;">Base<div class="ps-info">Full</div></th>
                        <th style="width: 55px; background: rgba(245,158,11,0.1);">Hours<div class="ps-info">200</div></th>
                        <th style="width: 70px;">Actual<div class="ps-info">Calc</div></th>
                        <th style="width: 45px; background: rgba(59,130,246,0.1);">OT</th>
                        <th style="width: 65px;">OT Rp</th>
                        <th style="width: 60px;">Inctv</th>
                        <th style="width: 60px;">Allowc</th>
                        <th style="width: 70px;">Uang Makan</th>
                        <th style="width: 60px;">Bonus</th>
                        <th style="width: 65px; color: #ef4444;">Deduct</th>
                        <th style="width: 80px;">Net</th>
                        <th style="width: 30px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($slips as $slip): 
                        $workHours = floor($slip['work_hours']);
                        $baseSalary = (float)$slip['base_salary'];
                        $hourlyRate = $baseSalary / 200;
                        $actualBase = ($workHours >= 200) ? $baseSalary : round($workHours * $hourlyRate, 2);
                    ?>
                    <tr id="row-<?php echo $slip['id']; ?>"
                        data-loan="<?php echo $slip['deduction_loan'] ?? 0; ?>"
                        data-absence="<?php echo $slip['deduction_absence'] ?? 0; ?>"
                        data-tax="<?php echo $slip['deduction_tax'] ?? 0; ?>"
                        data-bpjs="<?php echo $slip['deduction_bpjs'] ?? 0; ?>"
                        data-other="<?php echo $slip['deduction_other'] ?? 0; ?>">
                        <td style="text-align:center;">
                            <?php if(empty($slip['is_paid'])): ?>
                            <input type="checkbox" class="pay-select-cb" value="<?php echo $slip['id']; ?>" data-net="<?php echo $slip['net_salary']; ?>" data-name="<?php echo htmlspecialchars($slip['employee_name']); ?>" onchange="updatePaySelection()">
                            <?php else: ?>
                            <span title="Sudah dibayar" style="color:#10b981;font-size:14px;">✅</span>
                            <?php endif; ?>
                        </td>
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
                                   value="<?php echo number_format($slip['uang_makan'] ?? 0, 0, ',', '.'); ?>"
                                   data-field="uang_makan" data-id="<?php echo $slip['id']; ?>"
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
                                   style="cursor: pointer;" title="Click to edit deductions">
                        </td>
                        
                        <td style="position: relative;">
                            <span id="net-<?php echo $slip['id']; ?>" class="ps-cell-net">
                                <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?>
                            </span>
                            <span id="save-indicator-<?php echo $slip['id']; ?>" class="save-indicator"></span>
                        </td>
                        
                        <td>
                            <button type="button" class="ps-btn-edit" title="Edit Deductions"
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
            <h4 class="ps-modal-title">Edit Deductions: <span id="modalEmpName"></span></h4>
            <button type="button" class="ps-modal-close" onclick="closeDeductionModal()">&times;</button>
        </div>
        <input type="hidden" id="modalSlipId">
        
        <div class="ps-modal-grid">
            <div class="ps-form-group">
                <label class="ps-form-label">Loan / Cash Advance</label>
                <input type="text" class="ps-form-input currency-input" id="modalLoan" placeholder="0">
            </div>
            <div class="ps-form-group">
                <label class="ps-form-label">Absence Deduction</label>
                <input type="text" class="ps-form-input currency-input" id="modalAbsence" placeholder="0">
            </div>
            <div class="ps-form-group">
                <label class="ps-form-label">Tax (PPh)</label>
                <input type="text" class="ps-form-input currency-input" id="modalTax" placeholder="0">
            </div>
            <div class="ps-form-group">
                <label class="ps-form-label">BPJS</label>
                <input type="text" class="ps-form-input currency-input" id="modalBpjs" placeholder="0">
            </div>
            <div class="ps-form-group" style="grid-column: span 2;">
                <label class="ps-form-label">Other Deductions</label>
                <input type="text" class="ps-form-input currency-input" id="modalOther" placeholder="0">
            </div>
        </div>
        
        <div class="ps-modal-total">
            <span>Total Deductions:</span>
            <span id="modalTotalDed" class="ps-modal-total-amount">Rp 0</span>
        </div>
        
        <div class="ps-modal-actions">
            <button type="button" class="ps-btn ps-btn-outline" onclick="closeDeductionModal()">Cancel</button>
            <button type="button" class="ps-btn ps-btn-primary" onclick="saveDeduction()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Save
            </button>
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
    let uangMakan = getValByRow(id, 'uang_makan');
    let bonus = getValByRow(id, 'bonus'); // combined bonus+other
    
    // Deductions
    let row = document.getElementById(`row-${id}`);
    let loan = parseFloat(row.getAttribute('data-loan')) || 0;
    let absence = parseFloat(row.getAttribute('data-absence')) || 0;
    let tax = parseFloat(row.getAttribute('data-tax')) || 0;
    let bpjs = parseFloat(row.getAttribute('data-bpjs')) || 0;
    let dedOther = parseFloat(row.getAttribute('data-other')) || 0;
    let totalDed = loan + absence + tax + bpjs + dedOther;
    
    // Update deductions input display
    let dedInput = document.querySelector(`input[data-id="${id}"][data-field="total_deductions"]`);
    if (dedInput) dedInput.value = new Intl.NumberFormat('id-ID').format(totalDed);
    
    // Calculate Net (include uang_makan)
    let totalEarn = actualBase + otAmount + incentive + allowance + uangMakan + bonus;
    let net = totalEarn - totalDed;
    document.getElementById(`net-${id}`).innerText = new Intl.NumberFormat('id-ID').format(net);
    
    // Show save indicator
    showSaveIndicator(id);
    
    // Auto-save via Ajax with debounce
    clearTimeout(window[`saveTimer_${id}`]);
    window[`saveTimer_${id}`] = setTimeout(() => saveRow(id), 500);
}

function showSaveIndicator(id) {
    let indicator = document.getElementById(`save-indicator-${id}`);
    if (indicator) {
        indicator.classList.add('saving');
        indicator.innerHTML = '<span class="save-dot"></span> Saving...';
    }
}

function saveRow(id) {
    const row = document.getElementById(`row-${id}`);
    if (!row) return;
    
    const data = new FormData();
    data.append('ajax_update', 1);
    data.append('slip_id', id);
    
    data.append('base_salary', getValByRow(id, 'base_salary'));
    data.append('work_hours', getValByRow(id, 'work_hours'));
    data.append('overtime_hours', getValByRow(id, 'overtime_hours'));
    data.append('incentive', getValByRow(id, 'incentive'));
    data.append('allowance', getValByRow(id, 'allowance'));
    data.append('uang_makan', getValByRow(id, 'uang_makan'));
    data.append('bonus', getValByRow(id, 'bonus'));
    data.append('other_income', 0);
    
    data.append('deduction_loan', row.getAttribute('data-loan') || 0);
    data.append('deduction_absence', row.getAttribute('data-absence') || 0);
    data.append('deduction_tax', row.getAttribute('data-tax') || 0);
    data.append('deduction_bpjs', row.getAttribute('data-bpjs') || 0);
    data.append('deduction_other', row.getAttribute('data-other') || 0);
    
    fetch('process.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>', {
        method: 'POST',
        body: data
    }).then(res => res.json())
      .then(res => {
          if (res.status === 'success') {
              // Update save indicator to saved
              let indicator = document.getElementById(`save-indicator-${id}`);
              if (indicator) {
                  indicator.classList.remove('saving');
                  indicator.classList.add('saved');
                  indicator.innerHTML = '<span class="save-dot saved"></span> Saved';
                  setTimeout(() => {
                      indicator.classList.remove('saved');
                      indicator.innerHTML = '';
                  }, 2000);
              }
              // Update totals in header
              updateTotals();
          }
      }).catch(err => {
          let indicator = document.getElementById(`save-indicator-${id}`);
          if (indicator) {
              indicator.classList.remove('saving');
              indicator.classList.add('error');
              indicator.innerHTML = '<span class="save-dot error"></span> Error!';
          }
      });
}

function updateTotals() {
    let totalNet = 0;
    document.querySelectorAll('[id^="net-"]').forEach(el => {
        totalNet += parseFloat(el.innerText.replace(/\./g, '').replace(/,/g, '')) || 0;
    });
    let totalDisplay = document.querySelector('.ps-total-net');
    if (totalDisplay) {
        totalDisplay.innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(totalNet);
    }
}

// Modal Functions
function openDeductionModal(id, name) {
    document.getElementById('modalSlipId').value = id;
    document.getElementById('modalEmpName').innerText = name;
    
    const row = document.getElementById(`row-${id}`);
    document.getElementById('modalLoan').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-loan') || 0);
    document.getElementById('modalAbsence').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-absence') || 0);
    document.getElementById('modalTax').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-tax') || 0);
    document.getElementById('modalBpjs').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-bpjs') || 0);
    document.getElementById('modalOther').value = new Intl.NumberFormat('id-ID').format(row.getAttribute('data-other') || 0);
    
    updateModalTotal();
    document.getElementById('deductionModal').classList.add('active');
    
    // Focus first input
    setTimeout(() => document.getElementById('modalLoan').focus(), 100);
}

function closeDeductionModal() {
    document.getElementById('deductionModal').classList.remove('active');
}

function getVal(selector) {
    let el = document.querySelector(selector);
    if (!el) return 0;
    return parseFloat(el.value.replace(/\./g, '').replace(/,/g, '')) || 0;
}

function updateModalTotal() {
    let loan = getVal('#modalLoan');
    let abs = getVal('#modalAbsence');
    let tax = getVal('#modalTax');
    let bpjs = getVal('#modalBpjs');
    let other = getVal('#modalOther');
    let total = loan + abs + tax + bpjs + other;
    document.getElementById('modalTotalDed').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(total);
}

// Add event listeners for real-time modal total
['modalLoan', 'modalAbsence', 'modalTax', 'modalBpjs', 'modalOther'].forEach(id => {
    document.getElementById(id)?.addEventListener('keyup', updateModalTotal);
});

function saveDeduction() {
    let id = document.getElementById('modalSlipId').value;
    let loan = getVal('#modalLoan');
    let abs = getVal('#modalAbsence');
    let tax = getVal('#modalTax');
    let bpjs = getVal('#modalBpjs');
    let other = getVal('#modalOther');
    
    let row = document.getElementById(`row-${id}`);
    row.setAttribute('data-loan', loan);
    row.setAttribute('data-absence', abs);
    row.setAttribute('data-tax', tax);
    row.setAttribute('data-bpjs', bpjs);
    row.setAttribute('data-other', other);
    
    closeDeductionModal();
    calculateRow(id);
}

// Close modal on backdrop click
document.getElementById('deductionModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDeductionModal();
});

// === Pay Selection Functions ===
function togglePaySelectAll(master) {
    document.querySelectorAll('.pay-select-cb').forEach(cb => { cb.checked = master.checked; });
    updatePaySelection();
}

function updatePaySelection() {
    const checked = document.querySelectorAll('.pay-select-cb:checked');
    const bar = document.getElementById('paySelectionBar');
    if (!bar) return;
    if (checked.length === 0) {
        bar.style.display = 'none';
        return;
    }
    let total = 0;
    checked.forEach(cb => { total += parseFloat(cb.getAttribute('data-net') || 0); });
    document.getElementById('paySelCount').textContent = checked.length;
    document.getElementById('paySelTotal').textContent = new Intl.NumberFormat('id-ID').format(total);
    bar.style.display = 'flex';
}

function paySelected() {
    const checked = document.querySelectorAll('.pay-select-cb:checked');
    if (checked.length === 0) return;
    let names = [];
    checked.forEach(cb => names.push(cb.getAttribute('data-name')));
    const preview = names.length <= 3 ? names.join(', ') : names.slice(0, 3).join(', ') + ' +' + (names.length - 3) + ' lainnya';
    if (!confirm('Bayar & publish slip gaji untuk ' + checked.length + ' staff?\n' + preview)) return;
    const ids = Array.from(checked).map(cb => cb.value).join(',');
    document.getElementById('paySelSlipIds').value = ids;
    document.getElementById('paySelForm').submit();
}
</script>

<!-- Floating Pay Selection Bar -->
<div id="paySelectionBar" style="display:none; position:fixed; bottom:1rem; left:50%; transform:translateX(-50%); background:linear-gradient(135deg,#059669,#10b981); color:#fff; padding:0.75rem 1.5rem; border-radius:50px; box-shadow:0 4px 20px rgba(0,0,0,0.3); z-index:1000; align-items:center; gap:1rem; font-size:0.85rem;">
    <span><strong id="paySelCount">0</strong> staff dipilih</span>
    <span style="opacity:0.8;">|</span>
    <span>Total: <strong>Rp <span id="paySelTotal">0</span></strong></span>
    <button onclick="paySelected()" style="background:#fff; color:#059669; border:none; padding:0.5rem 1rem; border-radius:25px; font-weight:600; cursor:pointer; margin-left:0.5rem;">
        💰 Bayar Selected
    </button>
</div>
<form id="paySelForm" method="POST" style="display:none;">
    <input type="hidden" name="quick_pay_selected" value="1">
    <input type="hidden" name="selected_slips" id="paySelSlipIds" value="">
</form>

<?php include '../../includes/footer.php'; ?>
