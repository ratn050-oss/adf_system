<?php
/**
 * Quick Fix - Reset conflicting files then pull
 * URL: https://domain.com/quick-fix-pull.php?key=adf2026deploy
 * DELETE AFTER USE!
 */
if (!isset($_GET['key']) || $_GET['key'] !== 'adf2026deploy') {
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300); // 5 menit timeout

$dir = __DIR__;
echo "Dir: $dir\n\n";

// Step 1: Reset only the conflicting upload files
echo "--- Step 1: Remove conflicting files ---\n";
$conflicts = [
    'uploads/backgrounds/login-bg.png',
    'uploads/logos/cqc_logo.png'
];
foreach ($conflicts as $f) {
    $path = $dir . '/' . $f;
    if (file_exists($path)) {
        // Just move it aside so git doesn't complain
        rename($path, $path . '.bak');
        echo "Moved: $f -> $f.bak\n";
    } else {
        echo "Not found: $f\n";
    }
}

// Step 2: Git checkout those files to clear git's conflict
echo "\n--- Step 2: Git checkout conflicting files ---\n";
$cmd2 = "cd " . escapeshellarg($dir) . " && git checkout -- uploads/backgrounds/login-bg.png uploads/logos/cqc_logo.png 2>&1";
echo "CMD: $cmd2\n";
$out2 = shell_exec($cmd2);
echo $out2 . "\n";

// Step 3: Fetch
echo "--- Step 3: Git fetch ---\n";
$out3 = shell_exec("cd " . escapeshellarg($dir) . " && git fetch origin 2>&1");
echo $out3 . "\n";

// Step 4: Reset hard
echo "--- Step 4: Git reset --hard origin/main ---\n";
$out4 = shell_exec("cd " . escapeshellarg($dir) . " && git reset --hard origin/main 2>&1");
echo $out4 . "\n";

// Step 5: Restore the upload files from backup
echo "--- Step 5: Restore upload files ---\n";
foreach ($conflicts as $f) {
    $path = $dir . '/' . $f;
    $bakPath = $path . '.bak';
    if (file_exists($bakPath)) {
        if (!file_exists($path)) {
            rename($bakPath, $path);
            echo "Restored: $f\n";
        } else {
            unlink($bakPath);
            echo "Kept git version, removed backup: $f\n";
        }
    }
}

// Step 6: Status
echo "\n--- Step 6: Git log ---\n";
$out6 = shell_exec("cd " . escapeshellarg($dir) . " && git log --oneline -3 2>&1");
echo $out6 . "\n";

echo "\n=== DONE ===\n";
echo "DELETE this file and force-pull.php from server now!\n";
