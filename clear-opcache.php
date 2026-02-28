<?php
/**
 * Clear PHP OPcache
 * DELETE this file after use for security!
 */

// Security check - simple token
if (!isset($_GET['key']) || $_GET['key'] !== 'adf2026clear') {
    die('Access denied');
}

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache cleared successfully!<br>";
    echo "Time: " . date('Y-m-d H:i:s') . "<br>";
    echo "<br><a href='modules/owner/dashboard-2028.php'>Go to Dashboard 2028</a>";
} else {
    echo "❌ OPcache is not enabled or opcache_reset() is not available.";
}
