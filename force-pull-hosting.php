<?php
/**
 * Force Pull from New Git Remote
 * Run this ONCE on hosting to reset and pull from new repo
 * DELETE this file after use!
 */

// Security
$key = $_GET['key'] ?? '';
if ($key !== 'adf2026') {
    die('Access denied');
}

echo "<pre style='font-family:monospace;font-size:13px;background:#1a1a2e;color:#00ff88;padding:20px;'>";
echo "=== FORCE PULL FROM NEW REMOTE ===\n\n";

$baseDir = __DIR__;
chdir($baseDir);

$commands = [
    'git remote -v',
    'git remote set-url origin https://github.com/ratn050-oss/adf_system.git',
    'git fetch origin',
    'git reset --hard origin/main',
    'git pull origin main',
    'git log --oneline -5',
];

foreach ($commands as $cmd) {
    echo "$ $cmd\n";
    $output = shell_exec($cmd . ' 2>&1');
    echo $output . "\n";
}

echo "\n✅ DONE! Hapus file ini sekarang (force-pull-hosting.php)\n";
echo "</pre>";
