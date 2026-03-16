<?php
/**
 * Deploy Status & Webhook
 * - GET with token: show current deploy status
 * - POST with token: trigger git pull (if exec available)
 */
$validToken = 'adf-deploy-2025-secure';
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';

if (!hash_equals($validToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$deployDir = dirname(__FILE__);
$result = ['dir' => $deployDir, 'time' => date('Y-m-d H:i:s')];

// Read current git HEAD
$headFile = $deployDir . '/.git/HEAD';
if (file_exists($headFile)) {
    $head = trim(file_get_contents($headFile));
    if (strpos($head, 'ref:') === 0) {
        $refPath = $deployDir . '/.git/' . trim(substr($head, 4));
        $result['branch'] = trim(substr($head, 4));
        $result['commit'] = file_exists($refPath) ? trim(file_get_contents($refPath)) : 'unknown';
    } else {
        $result['commit'] = $head;
    }
} else {
    $result['git'] = 'no .git/HEAD found';
}

// Check if key files exist and their modification time
$checkFiles = [
    'modules/payroll/staff-manifest.php',
    'modules/payroll/staff-portal.php',
    'modules/payroll/sw.js',
];
foreach ($checkFiles as $f) {
    $fp = $deployDir . '/' . $f;
    if (file_exists($fp)) {
        $result['files'][$f] = [
            'exists' => true,
            'modified' => date('Y-m-d H:i:s', filemtime($fp)),
            'size' => filesize($fp),
        ];
    } else {
        $result['files'][$f] = ['exists' => false];
    }
}

// Check if manifest has absolute URLs (quick content check)
$manifestPath = $deployDir . '/modules/payroll/staff-manifest.php';
if (file_exists($manifestPath)) {
    $content = file_get_contents($manifestPath);
    $result['manifest_has_absolute_urls'] = strpos($content, '$moduleUrl') !== false;
    $result['manifest_has_id_field'] = strpos($content, "'id'") !== false;
}

// POST = try to deploy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('exec')) {
        chdir($deployDir);
        $output = [];
        $code = 0;
        exec('git pull origin main 2>&1', $output, $code);
        $result['pull'] = ['status' => $code === 0 ? 'success' : 'error', 'output' => implode("\n", $output)];
    } else {
        $result['pull'] = ['status' => 'error', 'output' => 'exec() is disabled on this server'];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
