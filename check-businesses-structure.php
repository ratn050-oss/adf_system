<?php
/**
 * Check BUSINESSES table structure
 */

header('Content-Type: text/html; charset=utf-8');

$isHosting = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$dbHost = 'localhost';
$dbUser = $isHosting ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isHosting ? '@Nnoc2025' : '';
$masterDb = $isHosting ? 'adfb2574_adf' : 'adf_system';

echo "<h2>🔍 BUSINESSES Table Structure</h2>\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get columns
    echo "<h3>Columns in BUSINESSES table:</h3>\n";
    $cols = $pdo->query("SHOW COLUMNS FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<ul>\n";
    foreach ($cols as $col) {
        echo "<li><code>" . $col['Field'] . "</code> - " . $col['Type'] . "</li>\n";
    }
    echo "</ul>\n";
    
    // Get all data
    echo "<h3>All data in BUSINESSES table:</h3>\n";
    $data = $pdo->query("SELECT * FROM businesses")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($data)) {
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px;'>\n";
        print_r($data);
        echo "</pre>\n";
    } else {
        echo "<p>❌ No data in businesses table</p>\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "\n";
}
?>
<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; }
h2, h3 { color: #333; }
code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
</style>
