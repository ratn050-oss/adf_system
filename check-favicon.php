<?php
// Check current favicon setting
try {
    $db = new PDO('mysql:host=localhost;dbname=adf_system;charset=utf8mb4', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "Current favicon setting: " . $result['setting_value'] . "\n";
        
        // Check if file exists
        $faviconPath = 'uploads/icons/' . $result['setting_value'];
        if (file_exists($faviconPath)) {
            echo "File exists: YES\n";
            echo "File path: " . $faviconPath . "\n";
        } else {
            echo "File exists: NO (setting in DB but file missing)\n";
        }
    } else {
        echo "No favicon setting found in database\n";
    }
    
    // Check for default favicon.ico in root
    if (file_exists('favicon.ico')) {
        echo "Default favicon.ico exists in root directory\n";
    } else {
        echo "No favicon.ico in root directory\n";
    }
    
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>