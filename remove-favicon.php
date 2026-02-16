<?php
/**
 * QUICK FAVICON REMOVAL
 * Langsung hapus semua favicon agar browser tab clean tanpa icon
 */

try {
    $db = new PDO('mysql:host=localhost;dbname=adf_system;charset=utf8mb4', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "🔧 Removing all favicons...\n";
    
    // 1. Delete from database
    $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = 'site_favicon'");
    $stmt->execute();
    echo "✅ Removed favicon setting from database\n";
    
    // 2. Remove favicon files from uploads/icons/
    $iconsDir = 'uploads/icons/';
    if (file_exists($iconsDir)) {
        $removed = 0;
        foreach (glob($iconsDir . 'favicon.*') as $file) {
            unlink($file);
            $removed++;
        }
        if ($removed > 0) {
            echo "✅ Removed $removed favicon files from uploads/icons/\n";
        } else {
            echo "ℹ️ No favicon files found in uploads/icons/\n";
        }
    }
    
    // 3. Remove root favicon.ico if exists
    if (file_exists('favicon.ico')) {
        unlink('favicon.ico');
        echo "✅ Removed favicon.ico from root directory\n";
    } else {
        echo "ℹ️ No favicon.ico found in root directory\n";
    }
    
    echo "\n🎉 SUCCESS! All favicons removed.\n";
    echo "Browser tabs will no longer show any favicon icon.\n";
    echo "Refresh your browser to see the change.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>