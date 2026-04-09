<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$token = isset($_GET['token']) ? $_GET['token'] : '';
if ($token !== 'adf-deploy-2025-secure') {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain');

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Force Narayana Hotel database
if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', 2);

$db = Database::getInstance();

echo "=== PAYROLL DEBUG ===\n\n";

// 1. Check payroll_attendance data for this month
$month = isset($_GET['m']) ? $_GET['m'] : date('n');
$year = isset($_GET['y']) ? $_GET['y'] : date('Y');
$monthStr = sprintf('%04d-%02d', $year, $month);

echo "--- All employees with attendance in $monthStr ---\n";
$empAtt = $db->fetchAll(
    "SELECT pa.employee_id, pe.full_name, 
            COUNT(*) as total_days,
            SUM(pa.work_hours) as total_hours,
            GROUP_CONCAT(CONCAT(pa.attendance_date, '=', COALESCE(pa.work_hours,0), 'h') ORDER BY pa.attendance_date SEPARATOR ', ') as daily_detail
     FROM payroll_attendance pa
     LEFT JOIN payroll_employees pe ON pe.id = pa.employee_id
     WHERE DATE_FORMAT(pa.attendance_date, '%Y-%m') = ?
     GROUP BY pa.employee_id
     ORDER BY pe.full_name",
    [$monthStr]
);

foreach ($empAtt as $e) {
    echo "\n[{$e['employee_id']}] {$e['full_name']}: {$e['total_days']} days, {$e['total_hours']} total hours\n";
    echo "  Daily: {$e['daily_detail']}\n";
}

// 2. Check payroll_slips for this period
echo "\n\n--- Payroll Slips ---\n";
$period = $db->fetchOne(
    "SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?",
    [$month, $year]
);

if ($period) {
    echo "Period: {$period['period_label']} (ID: {$period['id']}, Status: {$period['status']})\n\n";

    $slips = $db->fetchAll(
        "SELECT ps.id, ps.employee_id, ps.employee_name, ps.work_hours, ps.overtime_hours, ps.hours_locked, ps.actual_base, ps.base_salary
         FROM payroll_slips ps
         WHERE ps.period_id = ?
         ORDER BY ps.employee_name",
        [$period['id']]
    );

    foreach ($slips as $s) {
        echo "[Slip {$s['id']}] {$s['employee_name']} (emp:{$s['employee_id']}): work_hours={$s['work_hours']}, overtime={$s['overtime_hours']}, locked={$s['hours_locked']}, base={$s['base_salary']}, actual_base={$s['actual_base']}\n";
    }
} else {
    echo "No period found for month $month, year $year\n";
}

// 3. Test getAttendanceHours for a specific employee (Dela Auliya)
echo "\n\n--- Test getAttendanceHours ---\n";
$dela = $db->fetchOne("SELECT id, full_name FROM payroll_employees WHERE full_name LIKE '%dela%'");
if ($dela) {
    echo "Testing for: {$dela['full_name']} (ID: {$dela['id']})\n";

    $rows = $db->fetchAll(
        "SELECT attendance_date, check_in_time, check_out_time, scan_3, scan_4, work_hours, shift_1_hours, shift_2_hours
         FROM payroll_attendance 
         WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
         ORDER BY attendance_date",
        [$dela['id'], $monthStr]
    );

    echo "Found " . count($rows) . " attendance records:\n";
    $total = 0;
    foreach ($rows as $r) {
        $wh = (float)$r['work_hours'];
        $total += $wh;
        echo "  {$r['attendance_date']}: in={$r['check_in_time']} out={$r['check_out_time']} s3={$r['scan_3']} s4={$r['scan_4']} wh={$r['work_hours']} s1h={$r['shift_1_hours']} s2h={$r['shift_2_hours']}\n";
    }
    echo "  TOTAL: $total hours\n";
}

// 4. Check version marker
echo "\n\n--- Version Check ---\n";
$processFile = __DIR__ . '/modules/payroll/process.php';
if (file_exists($processFile)) {
    $content = file_get_contents($processFile);
    if (preg_match('/VERSION:\s*([^\s]+)/', $content, $m)) {
        echo "process.php version: {$m[1]}\n";
    }
    echo "File size: " . filesize($processFile) . " bytes\n";
    echo "Last modified: " . date('Y-m-d H:i:s', filemtime($processFile)) . "\n";
}
