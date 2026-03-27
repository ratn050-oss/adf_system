<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Logo Detection Test</h2>";

// Test 1: Current file location
echo "<p><strong>Current file:</strong> " . __FILE__ . "</p>";
echo "<p><strong>Current dir:</strong> " . __DIR__ . "</p>";

// Test 2: Calculate path
$uploadsDir = dirname(dirname(__DIR__)) . '/uploads/logos/';
echo "<p><strong>Calculated uploads dir:</strong> $uploadsDir</p>";
echo "<p><strong>Dir exists:</strong> " . (is_dir($uploadsDir) ? 'Yes' : 'No') . "</p>";

// Test 3: List files
if (is_dir($uploadsDir)) {
    $logos = glob($uploadsDir . 'hotel_logo_*.png');
    echo "<p><strong>Found logos:</strong> " . count($logos) . "</p>";
    echo "<ul>";
    foreach ($logos as $logo) {
        echo "<li>" . basename($logo) . " - Size: " . filesize($logo) . " bytes</li>";
    }
    echo "</ul>";
    
    if (!empty($logos)) {
        $logoFile = basename(end($logos));
        echo "<h3>Selected Logo: $logoFile</h3>";
        
        $webPath = '../../uploads/logos/' . $logoFile;
        echo "<p><strong>Web path:</strong> $webPath</p>";
        echo '<img src="' . $webPath . '?t=' . time() . '" style="max-width: 300px; border: 3px solid blue; background: white; padding: 10px;">';
    }
} else {
    echo "<p style='color: red;'>Directory not found!</p>";
}
?>
