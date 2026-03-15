<?php
/**
 * Fingerspot.io Webhook Receiver
 * Receives attendance data from Revo N830 via Fingerspot.io cloud
 * 
 * POST JSON format:
 * {
 *   "type": "attlog",
 *   "cloud_id": "XXXXXX",
 *   "data": {
 *     "pin": "1",
 *     "scan": "2020-07-21 10:11",
 *     "verify": "finger",
 *     "status_scan": "scan in"
 *   }
 * }
 */
define('APP_ACCESS', true);

// Load config — determine business from ?b= parameter
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// Resolve business context from ?b= slug
$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizCfg = null;
if ($bizSlug) {
    $bizFile = __DIR__ . '/../config/businesses/' . $bizSlug . '.php';
    if (file_exists($bizFile)) {
        $bizCfg = require $bizFile;
        if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizCfg['business_id']);
    }
}
if (!$bizCfg) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Business parameter (?b=) invalid']);
    exit;
}

// Connect to business database
$db = isset($bizCfg['database']) ? Database::switchDatabase($bizCfg['database']) : Database::getInstance();
$pdo = $db->getConnection();

// ── Auto-create fingerprint tables if needed ──
try {
    $pdo->query("SELECT 1 FROM fingerprint_log LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fingerprint_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `cloud_id` VARCHAR(50) NOT NULL,
        `type` VARCHAR(32) NOT NULL DEFAULT 'attlog',
        `pin` VARCHAR(20) DEFAULT NULL,
        `scan_time` DATETIME DEFAULT NULL,
        `verify_method` VARCHAR(30) DEFAULT NULL,
        `status_scan` VARCHAR(30) DEFAULT NULL,
        `employee_id` INT DEFAULT NULL,
        `processed` TINYINT(1) DEFAULT 0,
        `process_result` VARCHAR(255) DEFAULT NULL,
        `raw_data` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cloud (cloud_id),
        INDEX idx_pin (pin),
        INDEX idx_scan (scan_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Ensure fingerspot config columns exist in attendance_config
try {
    $pdo->query("SELECT fingerspot_cloud_id FROM payroll_attendance_config LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE payroll_attendance_config 
        ADD COLUMN `fingerspot_cloud_id` VARCHAR(50) DEFAULT NULL,
        ADD COLUMN `fingerspot_enabled` TINYINT(1) DEFAULT 0");
}

// Ensure finger_id column exists in payroll_employees
try {
    $pdo->query("SELECT finger_id FROM payroll_employees LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE payroll_employees ADD COLUMN `finger_id` VARCHAR(20) DEFAULT NULL");
}

// ── Read raw POST body ──
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!$data || !isset($data['type']) || !isset($data['cloud_id'])) {
    // Log even invalid requests for debugging
    try {
        $pdo->prepare("INSERT INTO fingerprint_log (cloud_id, type, raw_data, process_result) VALUES (?,?,?,?)")
            ->execute(['unknown', 'invalid', substr($rawBody, 0, 2000), 'Invalid JSON payload']);
    } catch (Exception $e) {}
    
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$type = $data['type'];
$cloudId = $data['cloud_id'];

// ── Validate cloud_id against config ──
$cfgStmt = $pdo->query("SELECT fingerspot_cloud_id, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1");
$cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC);
$registeredCloudId = $cfg['fingerspot_cloud_id'] ?? '';
$fpEnabled = (int)($cfg['fingerspot_enabled'] ?? 0);

if (!$fpEnabled) {
    logWebhook($pdo, $cloudId, $type, null, null, null, null, null, 0, 'Fingerspot disabled in settings', $rawBody);
    echo json_encode(['success' => false, 'message' => 'Fingerspot integration disabled']);
    exit;
}

if (!empty($registeredCloudId) && $cloudId !== $registeredCloudId) {
    logWebhook($pdo, $cloudId, $type, null, null, null, null, null, 0, "Cloud ID mismatch: received '{$cloudId}', expected '{$registeredCloudId}'", $rawBody);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => "Cloud ID mismatch: received '{$cloudId}', expected '{$registeredCloudId}'"]);
    exit;
}

// ── Process attlog type ──
// SPLIT SHIFT LOGIC: up to 4 scans/day, paired as shift 1 (scan 1-2) & shift 2 (scan 3-4)
// Double scan filter: ignore scans < 30 min from last scan
if ($type === 'attlog' && isset($data['data'])) {
    $pin = $data['data']['pin'] ?? null;
    $scanStr = $data['data']['scan'] ?? null;
    $verify = $data['data']['verify'] ?? null;
    $statusScan = $data['data']['status_scan'] ?? null;

    if (!$pin || !$scanStr) {
        logWebhook($pdo, $cloudId, $type, $pin, null, $verify, $statusScan, null, 0, 'Missing pin or scan time', $rawBody);
        echo json_encode(['success' => false, 'message' => 'Missing required data']);
        exit;
    }

    // Parse scan datetime
    $scanTime = date('Y-m-d H:i:s', strtotime($scanStr));
    $scanDate = date('Y-m-d', strtotime($scanStr));
    $scanTimeOnly = date('H:i:s', strtotime($scanStr));

    // Find employee by finger_id
    $empStmt = $pdo->prepare("SELECT id, full_name, employee_code FROM payroll_employees WHERE finger_id = ? AND is_active = 1");
    $empStmt->execute([$pin]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, null, 0, "No employee with finger_id={$pin}", $rawBody);
        echo json_encode(['success' => false, 'message' => "Employee with PIN {$pin} not found"]);
        exit;
    }

    $empId = (int)$employee['id'];

    // Auto-add scan_3, scan_4 columns if missing
    try { $pdo->query("SELECT scan_3 FROM payroll_attendance LIMIT 1"); } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE payroll_attendance 
            ADD COLUMN `scan_3` TIME DEFAULT NULL AFTER `check_out_time`,
            ADD COLUMN `scan_4` TIME DEFAULT NULL AFTER `scan_3`,
            ADD COLUMN `shift_1_hours` DECIMAL(5,2) DEFAULT NULL AFTER `work_hours`,
            ADD COLUMN `shift_2_hours` DECIMAL(5,2) DEFAULT NULL AFTER `shift_1_hours`");
    }

    // Get existing attendance record for today
    $existStmt = $pdo->prepare("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?");
    $existStmt->execute([$empId, $scanDate]);
    $att = $existStmt->fetch(PDO::FETCH_ASSOC);

    // Determine which scans are already filled
    $scan1 = $att['check_in_time'] ?? null;
    $scan2 = $att['check_out_time'] ?? null;
    $scan3 = $att['scan_3'] ?? null;
    $scan4 = $att['scan_4'] ?? null;
    $filledScans = array_filter([$scan1, $scan2, $scan3, $scan4], fn($s) => !empty($s));

    // DOUBLE SCAN FILTER: if last scan was < 30 min ago, skip
    if (!empty($filledScans)) {
        $lastScan = end($filledScans);
        $lastScanSec = strtotime("2000-01-01 " . $lastScan);
        $newScanSec = strtotime("2000-01-01 " . $scanTimeOnly);
        $diffMinutes = abs($newScanSec - $lastScanSec) / 60;
        
        if ($diffMinutes < 30) {
            $result = "Double scan ignored for {$employee['full_name']} (last: {$lastScan}, new: {$scanTimeOnly}, diff: " . round($diffMinutes) . "min < 30min)";
            logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 1, $result, $rawBody);
            echo json_encode(['success' => true, 'message' => $result]);
            exit;
        }
    }

    // Check if all 4 scans are full
    if (count($filledScans) >= 4) {
        $result = "Max 4 scans reached for {$employee['full_name']} on {$scanDate}";
        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 0, $result, $rawBody);
        echo json_encode(['success' => false, 'message' => $result]);
        exit;
    }

    // Get checkin_end for late detection
    $checkinEnd = '10:00:00';
    try {
        $cfgTime = $pdo->query("SELECT checkin_end FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $checkinEnd = $cfgTime['checkin_end'] ?? '10:00:00';
    } catch (Exception $e) {}

    $result = '';
    $scanLabels = ['Scan 1 (Masuk Shift 1)', 'Scan 2 (Pulang Shift 1)', 'Scan 3 (Masuk Shift 2)', 'Scan 4 (Pulang Shift 2)'];
    
    try {
        if (!$att) {
            // No record yet — this is Scan 1
            $status = ($scanTimeOnly > $checkinEnd) ? 'late' : 'present';
            $pdo->prepare("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, status, check_in_device, notes) VALUES (?,?,?,?,?,?)")
                ->execute([$empId, $scanDate, $scanTimeOnly, $status, 'fingerprint:' . $verify, 'Fingerspot split-shift']);
            $scanNum = 1;
            $result = "{$scanLabels[0]}: {$employee['full_name']} at {$scanTimeOnly} ({$status})";
        } else {
            // Determine next empty slot
            $scanNum = 0;
            $updateCol = '';
            if (empty($scan1)) { $updateCol = 'check_in_time'; $scanNum = 1; }
            elseif (empty($scan2)) { $updateCol = 'check_out_time'; $scanNum = 2; }
            elseif (empty($scan3)) { $updateCol = 'scan_3'; $scanNum = 3; }
            elseif (empty($scan4)) { $updateCol = 'scan_4'; $scanNum = 4; }

            if ($scanNum > 0) {
                // Calculate shift hours when completing a pair
                $shift1Hours = null;
                $shift2Hours = null;
                $totalHours = null;

                if ($scanNum === 2 && $scan1) {
                    // Completing Shift 1
                    $s1 = strtotime("2000-01-01 " . $scan1);
                    $s2 = strtotime("2000-01-01 " . $scanTimeOnly);
                    $shift1Hours = ($s2 > $s1) ? round(($s2 - $s1) / 3600, 2) : null;
                }
                if ($scanNum === 4 && $scan3) {
                    // Completing Shift 2
                    $s3 = strtotime("2000-01-01 " . $scan3);
                    $s4 = strtotime("2000-01-01 " . $scanTimeOnly);
                    $shift2Hours = ($s4 > $s3) ? round(($s4 - $s3) / 3600, 2) : null;
                }

                // Build update query
                $updates = ["{$updateCol} = ?"];
                $params = [$scanTimeOnly];

                if ($shift1Hours !== null) {
                    $updates[] = "shift_1_hours = ?";
                    $params[] = $shift1Hours;
                }
                if ($shift2Hours !== null) {
                    $updates[] = "shift_2_hours = ?";
                    $params[] = $shift2Hours;
                }

                // Recalculate total work_hours
                // Need current shift values + new one
                $curShift1 = ($shift1Hours !== null) ? $shift1Hours : (float)($att['shift_1_hours'] ?? 0);
                $curShift2 = ($shift2Hours !== null) ? $shift2Hours : (float)($att['shift_2_hours'] ?? 0);
                $totalHours = $curShift1 + $curShift2;
                if ($totalHours > 0) {
                    $updates[] = "work_hours = ?";
                    $params[] = round($totalHours, 2);
                }

                $updates[] = "notes = ?";
                $params[] = "Split-shift: {$scanNum}/4 scans";
                $params[] = $att['id'];

                $sql = "UPDATE payroll_attendance SET " . implode(', ', $updates) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($params);

                $hoursTxt = '';
                if ($scanNum === 2 && $shift1Hours) $hoursTxt = " (shift1: {$shift1Hours}h)";
                if ($scanNum === 4 && $shift2Hours) $hoursTxt = " (shift2: {$shift2Hours}h, total: {$totalHours}h)";

                $result = "{$scanLabels[$scanNum-1]}: {$employee['full_name']} at {$scanTimeOnly}{$hoursTxt}";
            }
        }

        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 1, $result, $rawBody);
        echo json_encode(['success' => true, 'message' => $result]);

    } catch (Exception $e) {
        $errMsg = 'DB Error: ' . $e->getMessage();
        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 0, $errMsg, $rawBody);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $errMsg]);
    }

} else {
    // Non-attlog type — just log it
    logWebhook($pdo, $cloudId, $type, null, null, null, null, null, 0, "Unhandled type: {$type}", $rawBody);
    echo json_encode(['success' => true, 'message' => "Logged type: {$type}"]);
}

// ── Helper: Log webhook data ──
function logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, $processed, $result, $raw) {
    try {
        $pdo->prepare("INSERT INTO fingerprint_log (cloud_id, type, pin, scan_time, verify_method, status_scan, employee_id, processed, process_result, raw_data) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, $processed, $result, substr($raw, 0, 5000)]);
    } catch (Exception $e) {
        error_log('Fingerprint log error: ' . $e->getMessage());
    }
}
