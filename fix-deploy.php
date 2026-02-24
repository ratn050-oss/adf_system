<?php
/**
 * Fix deployment - Reset local changes and pull latest from GitHub
 * DELETE THIS FILE AFTER USE!
 */
echo "<h2>🔧 Fix Deploy</h2><pre>";

$dir = '/home/adfb2574/public_html';
chdir($dir);
echo "Working directory: " . getcwd() . "\n\n";

// Step 1: Show current status
echo "=== GIT STATUS ===\n";
echo shell_exec('git status 2>&1');

// Step 2: Reset index.php to match last commit
echo "\n=== RESET LOCAL CHANGES ===\n";
echo shell_exec('git checkout -- index.php 2>&1');
echo "Done resetting index.php\n";

// Step 3: Pull latest from remote
echo "\n=== GIT PULL ===\n";
echo shell_exec('git pull origin main 2>&1');

// Step 4: Show new status
echo "\n=== NEW STATUS ===\n";
echo shell_exec('git status 2>&1');

// Step 5: Show latest commit
echo "\n=== LATEST COMMIT ===\n";
echo shell_exec('git log --oneline -3 2>&1');

// Step 6: Verify index.php has the fix
$content = file_get_contents($dir . '/index.php');
echo "\n=== VERIFY FIX ===\n";
if (strpos($content, 'hasCashAccountIdCol') !== false) {
    echo "✅ index.php has cash_account_id check (FIXED version)\n";
} else {
    echo "❌ index.php is OLD version\n";
}

echo "\n</pre>";
echo "<p><strong>⚠️ DELETE THIS FILE after deployment is fixed!</strong></p>";
echo '<p><a href="index.php">→ Go to Dashboard</a></p>';
?>
