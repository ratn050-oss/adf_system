<?php

/**
 * One-time script: reset local changes and git pull
 * DELETE THIS FILE AFTER USE
 */
$token = $_GET['token'] ?? '';
if (!hash_equals('adf-deploy-2025-secure', $token)) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');
$dir = dirname(__FILE__);
chdir($dir);

$commands = [
    "cd $dir && git checkout -- database-payroll.sql modules/payroll/process.php 2>&1",
    "cd $dir && rm -f uploads/logos/cqc_invoice_logo.png uploads/logos/narayana-hotel_logo.png 2>&1",
    "cd $dir && git pull origin main 2>&1",
    "cd $dir && git log --oneline -3 2>&1",
];

$methods = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open'];
$available = [];
foreach ($methods as $m) {
    $available[$m] = function_exists($m) && !in_array($m, explode(',', ini_get('disable_functions')));
}
echo "Available methods:\n";
foreach ($available as $m => $ok) {
    echo "  $m: " . ($ok ? 'YES' : 'NO') . "\n";
}
echo "\n";

foreach ($commands as $cmd) {
    echo "CMD: $cmd\n";
    $output = '';

    if ($available['exec']) {
        $lines = [];
        $code = 0;
        @exec($cmd, $lines, $code);
        $output = implode("\n", $lines);
        echo "OUT: $output\nCODE: $code\n\n";
    } elseif ($available['shell_exec']) {
        $output = @shell_exec($cmd);
        echo "OUT: $output\n\n";
    } elseif ($available['proc_open']) {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            echo "OUT: $output\nCODE: $code\n\n";
        } else {
            echo "proc_open failed\n\n";
        }
    } else {
        echo "No shell method available!\n\n";
    }
}
echo "DONE\n";
