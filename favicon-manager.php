<?php
/**
 * FAVICON MANAGER - Remove building icon and set developer logo
 * Option 1: Remove favicon completely (no icon in browser tab)
 * Option 2: Set simple developer favicon
 */

echo "<h1>🔧 Favicon Manager - Developer Logo Only</h1>";

$db = new PDO('mysql:host=localhost;dbname=adf_system;charset=utf8mb4', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'remove_favicon') {
        // Option 1: Remove favicon completely
        try {
            // Delete from database
            $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = 'site_favicon'");
            $stmt->execute();
            
            // Remove any favicon files
            $iconsDir = 'uploads/icons/';
            if (file_exists($iconsDir)) {
                foreach (glob($iconsDir . 'favicon.*') as $file) {
                    unlink($file);
                }
            }
            
            // Remove root favicon.ico if exists
            if (file_exists('favicon.ico')) {
                unlink('favicon.ico');
            }
            
            echo "<div style='background: #d1fae5; color: #065f46; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
            echo "✅ <strong>Favicon berhasil dihapus!</strong><br>";
            echo "Browser tab akan menampilkan default browser icon (tidak ada icon khusus).<br>";
            echo "Refresh halaman untuk melihat perubahan.";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<div style='background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
            echo "❌ Error: " . $e->getMessage();
            echo "</div>";
        }
    }
    
    if ($action === 'create_dev_favicon') {
        // Option 2: Create simple developer favicon
        try {
            // Create uploads/icons directory if not exists
            $iconsDir = 'uploads/icons/';
            if (!file_exists($iconsDir)) {
                mkdir($iconsDir, 0755, true);
            }
            
            // Create simple SVG favicon with "DEV" text
            $svgContent = '<?xml version="1.0" encoding="UTF-8"?>
<svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
  <rect width="32" height="32" fill="#1e1b4b"/>
  <text x="16" y="21" font-family="Arial, sans-serif" font-size="10" font-weight="bold" text-anchor="middle" fill="#ffffff">DEV</text>
</svg>';
            
            $faviconPath = $iconsDir . 'favicon.svg';
            file_put_contents($faviconPath, $svgContent);
            
            // Save to database
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_favicon', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute(['favicon.svg', 'favicon.svg']);
            
            echo "<div style='background: #dbeafe; color: #1e40af; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
            echo "✅ <strong>Developer favicon berhasil dibuat!</strong><br>";
            echo "Browser tab sekarang akan menampilkan icon 'DEV' dengan background biru.<br>";
            echo "Refresh halaman untuk melihat perubahan.";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<div style='background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
            echo "❌ Error: " . $e->getMessage();
            echo "</div>";
        }
    }
}

// Check current status
$currentFavicon = null;
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentFavicon = $result['setting_value'] ?? null;
} catch (Exception $e) {
    echo "Error checking current favicon: " . $e->getMessage();
}

?>

<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
    max-width: 700px; 
    margin: 2rem auto; 
    padding: 2rem;
    background: #f8fafc;
}
h1 { 
    color: #1e293b; 
    border-bottom: 3px solid #3b82f6;
    padding-bottom: 0.5rem;
}
.option-card {
    background: white;
    padding: 1.5rem;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    margin: 1.5rem 0;
    border-left: 4px solid #e2e8f0;
}
.option-card.recommended {
    border-left-color: #10b981;
}
.btn {
    background: #3b82f6;
    color: white;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    margin-top: 1rem;
}
.btn-success { background: #10b981; }
.btn-danger { background: #ef4444; }
.btn:hover { opacity: 0.9; }
</style>

<div class="option-card">
    <h3>📊 Status Saat Ini</h3>
    <?php if ($currentFavicon): ?>
        <p>✅ Favicon aktif: <code><?php echo htmlspecialchars($currentFavicon); ?></code></p>
        <?php if (file_exists('uploads/icons/' . $currentFavicon)): ?>
            <div style="display: flex; align-items: center; gap: 10px; margin: 10px 0;">
                <img src="uploads/icons/<?php echo htmlspecialchars($currentFavicon); ?>?v=<?php echo time(); ?>" 
                     style="width: 32px; height: 32px; border: 1px solid #ddd; border-radius: 4px;">
                <span>Preview favicon</span>
            </div>
        <?php else: ?>
            <p style="color: #ef4444;">⚠️ File tidak ditemukan</p>
        <?php endif; ?>
    <?php else: ?>
        <p>❌ Tidak ada favicon yang diset - browser menggunakan default icon</p>
    <?php endif; ?>
</div>

<div class="option-card recommended">
    <h3>🚫 Option 1: Hapus Favicon Sepenuhnya (Recommended)</h3>
    <p>Menghilangkan semua favicon, browser tab akan kosong tanpa icon apapun. Solusi paling bersih.</p>
    <form method="POST">
        <input type="hidden" name="action" value="remove_favicon">
        <button type="submit" class="btn btn-success">
            🗑️ Hapus Semua Favicon (Clean)
        </button>
    </form>
</div>

<div class="option-card">
    <h3>💻 Option 2: Buat Developer Favicon</h3>
    <p>Buat favicon sederhana dengan teks "DEV" dan background biru. Minimalis dan professional.</p>
    <form method="POST">
        <input type="hidden" name="action" value="create_dev_favicon">
        <button type="submit" class="btn">
            ⚡ Buat Favicon "DEV"
        </button>
    </form>
</div>

<div style="background: #f1f5f9; padding: 15px; border-radius: 8px; margin-top: 30px;">
    <h4>ℹ️ Info:</h4>
    <ul style="margin: 10px 0; padding-left: 20px;">
        <li>Setelah memilih opsi, <strong>refresh browser</strong> untuk melihat perubahan</li>
        <li>Perubahan akan terlihat di semua halaman sistem</li>
        <li>Option 1 paling clean - tidak ada icon sama sekali</li>
        <li>Option 2 akan tampilkan "DEV" di browser tab</li>
    </ul>
    <a href="index.php" style="color: #3b82f6; text-decoration: none; font-weight: 600;">← Kembali ke Dashboard</a>
</div>