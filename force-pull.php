<?php
/**
 * Force Pull from Git Remote
 * Accessed via: https://yourdomain.com/force-pull.php?key=adf2026deploy
 * 
 * This resets local changes and pulls latest from remote.
 * DELETE THIS FILE AFTER USE!
 */

// Simple security key
$secret = 'adf2026deploy';

if (!isset($_GET['key']) || $_GET['key'] !== $secret) {
    http_response_code(403);
    die('Forbidden. Use ?key=YOUR_SECRET');
}

header('Content-Type: text/plain; charset=utf-8');

$repoDir = __DIR__;

echo "=== Force Pull Script ===\n\n";
echo "Working directory: $repoDir\n\n";

// Step 1: Stash local changes
echo "--- Step 1: Git stash ---\n";
exec("cd " . escapeshellarg($repoDir) . " && git stash 2>&1", $output1, $code1);
echo implode("\n", $output1) . "\n";
echo "Exit code: $code1\n\n";

// Step 2: Git fetch
echo "--- Step 2: Git fetch ---\n";
exec("cd " . escapeshellarg($repoDir) . " && git fetch origin 2>&1", $output2, $code2);
echo implode("\n", $output2) . "\n";
echo "Exit code: $code2\n\n";

// Step 3: Git reset to match remote
echo "--- Step 3: Git reset --hard origin/main ---\n";
exec("cd " . escapeshellarg($repoDir) . " && git reset --hard origin/main 2>&1", $output3, $code3);
echo implode("\n", $output3) . "\n";
echo "Exit code: $code3\n\n";

// Step 4: Show status
echo "--- Step 4: Git status ---\n";
exec("cd " . escapeshellarg($repoDir) . " && git status 2>&1", $output4, $code4);
echo implode("\n", $output4) . "\n";
echo "Exit code: $code4\n\n";

// Step 5: Show current commit
echo "--- Step 5: Current HEAD ---\n";
exec("cd " . escapeshellarg($repoDir) . " && git log --oneline -3 2>&1", $output5, $code5);
echo implode("\n", $output5) . "\n\n";

echo "=== DONE ===\n";
echo "IMPORTANT: Delete this file (force-pull.php) after use!\n";
