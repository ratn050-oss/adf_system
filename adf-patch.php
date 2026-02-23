<?php
/**
 * ADF System Patch — Downloads latest web-settings.php from GitHub
 * Upload to hosting at /public_html/ via cPanel File Manager
 * Run: https://adfsystem.online/adf-patch.php
 * Auto-deletes after running.
 */
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><body style="font-family:monospace;background:#1a1a1a;color:#0f0;padding:20px;">';
echo '<h2>ADF System — Patch web-settings.php</h2>';

$GITHUB_RAW = 'https://raw.githubusercontent.com/marcellapratiknyo-rgb/adf_sytem/main';

$files = [
    'developer/web-settings.php' => 'developer/web-settings.php',
];

foreach ($files as $src => $dest) {
    $url = $GITHUB_RAW . '/' . $src;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'ADFPatch/1.0',
    ]);
    $content = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code === 200 && $content) {
        $destPath = __DIR__ . '/' . $dest;
        $dir = dirname($destPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($destPath, $content);
        $size = strlen($content);
        echo "<p style='color:#0f0'>✅ $dest — $size bytes</p>";
    } else {
        echo "<p style='color:#f00'>❌ Failed: $dest (HTTP $code)</p>";
    }
}

// Also create upload directories
$dirs = ['uploads/logo', 'uploads/favicon', 'uploads/destinations'];
foreach ($dirs as $d) {
    $p = __DIR__ . '/' . $d;
    if (!is_dir($p)) { @mkdir($p, 0755, true); echo "<p style='color:#0f0'>✅ Created dir: $d/</p>"; }
    else { echo "<p style='color:#ff0'>⚠️ Dir exists: $d/</p>"; }
}

echo '<h3 style="color:#fff">✅ Patch complete!</h3>';
echo '<p><a href="/developer/web-settings.php" style="color:#0af">→ Open Web Settings</a></p>';

// Self-delete
@unlink(__FILE__);
echo '<p style="color:#666;font-size:11px">adf-patch.php auto-deleted.</p>';
echo '</body></html>';
