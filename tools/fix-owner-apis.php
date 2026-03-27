<?php
/**
 * QUICK FIX: Update APIs to use single database approach
 * All businesses use narayana_db, not separate databases
 */

$apiFiles = [
    'owner-stats.php',
    'owner-chart-data.php',
    'owner-recent-transactions.php',
    'owner-occupancy.php'
];

echo "<h1>🔧 Fixing Owner APIs</h1>";
echo "<p>Updating APIs to use single database (narayana_db) instead of multi-database...</p>";

foreach ($apiFiles as $file) {
    $path = __DIR__ . '/../api/' . $file;
    
    if (file_exists($path)) {
        echo "<p>✅ Found: $file</p>";
    } else {
        echo "<p>❌ Not found: $file</p>";
    }
}

echo "<hr>";
echo "<h2>📋 What needs to be fixed:</h2>";
echo "<ul>";
echo "<li>Remove: <code>\$dbName = \$business['database'];</code></li>";
echo "<li>Remove: <code>\$businessDb = new Database(\$dbName);</code></li>";
echo "<li>Use: <code>\$db = Database::getInstance();</code> (main narayana_db)</li>";
echo "<li>Add business_id filter in WHERE clause</li>";
echo "</ul>";

echo "<hr>";
echo "<p><strong>Manual fix required:</strong> The APIs are using multi-database approach but system uses single database.</p>";
echo "<p><a href='../modules/owner/dashboard.php'>← Back to Dashboard</a></p>";
?>
