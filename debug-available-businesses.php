<?php
/**
 * Debug: Check what getUserAvailableBusinesses() returns
 */

define('APP_ACCESS', true);
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'adf_system');
define('DB_CHARSET', 'utf8mb4');
define('MASTER_DB_NAME', 'adf_system');

session_start();
$_SESSION['username'] = 'lucca'; // Simulate lucca logged in

// Need getBusinessCodeToSlugMap function  
require_once __DIR__ . '/includes/business_access.php';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Debug getUserAvailableBusinesses</title>\n";
echo "<style>body{font-family:Arial;padding:20px;}\n";
echo ".business{background:#f0f0f0;padding:10px;margin:5px 0;border-left:3px solid #333;}\n";
echo ".dup{border-left-color:red;background:#ffe0e0;}\n";
echo "</style></head><body>\n";

require_once __DIR__ . '/includes/business_helper.php';
require_once __DIR__ . '/includes/business_access.php';

echo "<h1>User Available Businesses for 'lucca'</h1>\n";

echo "<h2>Step 1: getAvailableBusinesses()</h2>\n";
$allBusinesses = getAvailableBusinesses();
echo "<p>All available config files: " . count($allBusinesses) . "</p>\n";
foreach ($allBusinesses as $id => $config) {
    echo "<div style='background:#e0e0e0;padding:5px;margin:3px 0;'>" . htmlspecialchars($id) . " => " . htmlspecialchars($config['name']) . "</div>\n";
}

echo "<h2>Step 2: getUserAvailableBusinesses()</h2>\n";
$businesses = getUserAvailableBusinesses();

echo "<p>Total: " . count($businesses) . " businesses</p>\n";

$names = [];
foreach ($businesses as $bizId => $config) {
    $isDuplicate = in_array($config['name'], $names);
    $names[] = $config['name'];
    
    echo "<div class='business" . ($isDuplicate ? " dup" : "") . "'>\n";
    echo "  <strong>ID:</strong> <code>" . htmlspecialchars($bizId) . "</code><br>\n";
    echo "  <strong>Name:</strong> " . htmlspecialchars($config['name']) . ($isDuplicate ? " <span style='color:red;'>(DUPLICATE)</span>" : "") . "<br>\n";
    echo "  <strong>Database:</strong> <code>" . htmlspecialchars($config['database']) . "</code><br>\n";
    echo "</div>\n";
}

// Check database assignments
echo "<h2>user_business_assignment for lucca</h2>\n";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=adf_system", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("SELECT u.id, u.username FROM users u WHERE u.username = ? LIMIT 1");
    $stmt->execute(['lucca']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "Lucca user ID: " . $user['id'] . "<br>\n";
        
        echo "<h3>Code to Slug Mapping</h3>\n";
        $map = getBusinessCodeToSlugMap($pdo);
        foreach ($map as $code => $slug) {
            echo "<div style='background:#fff0f0;padding:5px;'>" . htmlspecialchars($code) . " => " . htmlspecialchars($slug) . "</div>\n";
        }
        
        $assignments = $pdo->prepare("
            SELECT b.id, b.business_code, b.business_name 
            FROM user_business_assignment uba
            JOIN businesses b ON b.id = uba.business_id
            WHERE uba.user_id = ?
            ORDER BY b.business_name
        ");
        $assignments->execute([$user['id']]);
        $rows = $assignments->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Assigned Businesses from DB (" . count($rows) . ")</h3>\n";
        
        echo "<h4>Mapping attempt:</h4>\n";
        foreach ($rows as $row) {
            $slug = $map[$row['business_code']] ?? strtolower($row['business_code']);
            $found = isset($allBusinesses[$slug]) ? '✅ FOUND' : '❌ NOT FOUND';
            echo "<div style='background:#f0f0f0;padding:10px;margin:5px 0;'>\n";
            echo "  Code: <code>" . $row['business_code'] . "</code> => Slug: <code>" . $slug . "</code> => " . $found . "<br>\n";
            echo "  Name: " . $row['business_name'] . "\n";
            echo "</div>\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "</body></html>\n";
?>
