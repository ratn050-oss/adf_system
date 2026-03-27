<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get logo file - use absolute path from document root
$baseDir = $_SERVER['DOCUMENT_ROOT'] . '/narayana/uploads/logos/';
$logoFile = '';

echo "<!-- Debug Info -->\n";
echo "<!-- Document Root: " . $_SERVER['DOCUMENT_ROOT'] . " -->\n";
echo "<!-- Base Dir: $baseDir -->\n";
echo "<!-- Dir Exists: " . (is_dir($baseDir) ? 'Yes' : 'No') . " -->\n";

if (is_dir($baseDir)) {
    $logoFiles = glob($baseDir . 'hotel_logo_*.png');
    echo "<!-- Found " . count($logoFiles) . " files -->\n";
    if (!empty($logoFiles)) {
        sort($logoFiles);
        $logoFile = basename(end($logoFiles));
        echo "<!-- Selected: $logoFile -->\n";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logo Test - Dashboard Style</title>
    <style>
        body { margin: 0; font-family: Arial; }
        .mobile-header {
            background: linear-gradient(135deg, #1e1b4b, #4338ca);
            padding: 1rem;
            color: white;
        }
        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
    </style>
</head>
<body>
    <div class="mobile-header">
        <div class="header-content">
            <?php if (!empty($logoFile)): ?>
            <img src="/narayana/uploads/logos/<?php echo htmlspecialchars($logoFile); ?>" 
                 alt="Narayana Hotel" 
                 style="height: 45px; width: auto; max-width: 60px; object-fit: contain; background: rgba(255,255,255,0.95); padding: 0.3rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"
                 onerror="console.error('Failed to load:', this.src); this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div style="width: 45px; height: 45px; background: rgba(255,255,255,0.2); border-radius: 8px; display: none; align-items: center; justify-content: center; font-size: 1.5rem;">
                🏨
            </div>
            <?php else: ?>
            <div style="width: 45px; height: 45px; background: rgba(255,255,255,0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                🏨
            </div>
            <p style="color: yellow;">No logo file found!</p>
            <?php endif; ?>
            <div>
                <div style="font-size: 1.25rem; font-weight: 700;">Narayana Hotel</div>
                <div style="font-size: 0.875rem; opacity: 0.9;">Logo Test Page</div>
            </div>
        </div>
    </div>
    
    <div style="padding: 20px;">
        <h2>Debug Information</h2>
        <table border="1" cellpadding="5">
            <tr><td>Document Root</td><td><?php echo $_SERVER['DOCUMENT_ROOT']; ?></td></tr>
            <tr><td>Base Dir</td><td><?php echo $baseDir; ?></td></tr>
            <tr><td>Dir Exists</td><td><?php echo is_dir($baseDir) ? 'Yes' : 'No'; ?></td></tr>
            <tr><td>Logo File</td><td><?php echo $logoFile ?: 'Not found'; ?></td></tr>
            <tr><td>Full Path</td><td>/narayana/uploads/logos/<?php echo $logoFile; ?></td></tr>
        </table>
        
        <h3>Direct Image Test:</h3>
        <img src="/narayana/uploads/logos/hotel_logo_1769036600.png" style="max-width: 200px; border: 2px solid red;">
        
        <h3>Relative Path Test:</h3>
        <img src="../../uploads/logos/hotel_logo_1769036600.png" style="max-width: 200px; border: 2px solid blue;">
    </div>
</body>
</html>
