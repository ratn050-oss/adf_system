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
    logWebhook($pdo, $cloudId, $type, null, null, null, null, null, 0, "Cloud ID mismatch: expected {$registeredCloudId}", $rawBody);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Cloud ID not authorized']);
    exit;
}

// ── Process attlog type ──
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

    // Determine: check-in or check-out
    // Priority: status_scan from machine. Fallback: first scan = in, second = out
    $isCheckIn = true;
    $statusScanLower = strtolower($statusScan ?? '');
    if (strpos($statusScanLower, 'out') !== false) {
        $isCheckIn = false;
    } elseif (strpos($statusScanLower, 'in') !== false) {
        $isCheckIn = true;
    } else {
        // Auto-detect: if no attendance today → check-in, else → check-out
        $existStmt = $pdo->prepare("SELECT id, check_in_time, check_out_time FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?");
        $existStmt->execute([$empId, $scanDate]);
        $existAtt = $existStmt->fetch(PDO::FETCH_ASSOC);
        $isCheckIn = !$existAtt || empty($existAtt['check_in_time']);
    }

    // Get checkin_start for late detection
    $checkinEnd = '10:00:00';
    try {
        $cfgTime = $pdo->query("SELECT checkin_end FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $checkinEnd = $cfgTime['checkin_end'] ?? '10:00:00';
    } catch (Exception $e) {}

    $result = '';
    try {
        if ($isCheckIn) {
            // Determine status: present or late
            $status = ($scanTimeOnly > $checkinEnd) ? 'late' : 'present';

            // Check for duplicate
            $dupStmt = $pdo->prepare("SELECT id FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?");
            $dupStmt->execute([$empId, $scanDate]);
            $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing (in case re-scan)
                $pdo->prepare("UPDATE payroll_attendance SET check_in_time = ?, status = ?, check_in_device = ?, notes = CONCAT(IFNULL(notes,''), ' [FP re-scan in]') WHERE id = ?")
                    ->execute([$scanTimeOnly, $status, 'fingerprint:' . $verify, $existing['id']]);
                $result = "Updated check-in for {$employee['full_name']} at {$scanTimeOnly} ({$status})";
            } else {
                $pdo->prepare("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, status, check_in_device, notes) VALUES (?,?,?,?,?,?)")
                    ->execute([$empId, $scanDate, $scanTimeOnly, $status, 'fingerprint:' . $verify, 'Fingerspot.io webhook']);
                $result = "Check-in {$employee['full_name']} at {$scanTimeOnly} ({$status})";
            }
        } else {
            // Check-out: update existing attendance
            $existStmt = $pdo->prepare("SELECT id, check_in_time FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?");
            $existStmt->execute([$empId, $scanDate]);
            $existAtt = $existStmt->fetch(PDO::FETCH_ASSOC);

            if ($existAtt) {
                // Calculate work hours
                $workHours = null;
                if ($existAtt['check_in_time']) {
                    $inSec = strtotime("2000-01-01 " . $existAtt['check_in_time']);
                    $outSec = strtotime("2000-01-01 " . $scanTimeOnly);
                    if ($outSec > $inSec) {
                        $workHours = round(($outSec - $inSec) / 3600, 2);
                    }
                }
                $pdo->prepare("UPDATE payroll_attendance SET check_out_time = ?, work_hours = ?, check_out_device = ? WHERE id = ?")
                    ->execute([$scanTimeOnly, $workHours, 'fingerprint:' . $verify, $existAtt['id']]);
                $result = "Check-out {$employee['full_name']} at {$scanTimeOnly}" . ($workHours ? " ({$workHours}h)" : '');
            } else {
                // No check-in yet, create record with check-out only
                $pdo->prepare("INSERT INTO payroll_attendance (employee_id, attendance_date, check_out_time, status, check_out_device, notes) VALUES (?,?,?,?,?,?)")
                    ->execute([$empId, $scanDate, $scanTimeOnly, 'present', 'fingerprint:' . $verify, 'Fingerspot checkout without checkin']);
                $result = "Check-out (no check-in) {$employee['full_name']} at {$scanTimeOnly}";
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
