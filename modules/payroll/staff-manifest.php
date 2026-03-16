<?php
/**
 * Staff Portal PWA Manifest
 * Makes the staff portal installable as mobile app
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=300');

$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizName = 'Staff Portal';

if ($bizSlug) {
    $bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
    if (file_exists($bizFile)) {
        $cfg = require $bizFile;
        $bizName = ($cfg['name'] ?? 'Staff Portal');
    }
}

// Resolve icon: pwa_app_icon > login_logo > fallback GD icon
$iconUrl192 = 'absen-icon.php?size=192';
$iconUrl512 = 'absen-icon.php?size=512';
$iconType = 'image/png';
$rootDir = dirname(dirname(__DIR__));
$baseHttpUrl = defined('BASE_URL') ? BASE_URL : '';
try {
    // Must use master DB — settings table is NOT in business DB
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : 'adf_system');
    $mdb = Database::switchDatabase($masterDbName);
    // Each key has a local directory prefix for when stored as filename
    $iconKeys = [
        'pwa_app_icon' => 'uploads/icons/',
        'login_logo'   => 'uploads/logos/',
    ];
    foreach ($iconKeys as $iconKey => $localPrefix) {
        $iconRow = $mdb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$iconKey]);
        $iconVal = $iconRow['setting_value'] ?? null;
        if (!$iconVal) continue;
        if (strpos($iconVal, 'http') === 0) {
            // Full URL (Cloudinary) — use directly
            $iconUrl192 = $iconVal;
            $iconUrl512 = $iconVal;
            if (preg_match('/\.(png)$/i', $iconVal)) $iconType = 'image/png';
            elseif (preg_match('/\.(jpe?g)$/i', $iconVal)) $iconType = 'image/jpeg';
            else $iconType = 'image/png';
            break;
        } else {
            // Local filename — prepend directory prefix
            $fullPath = $rootDir . '/' . $localPrefix . $iconVal;
            if (file_exists($fullPath)) {
                $iconUrl192 = $baseHttpUrl . '/' . $localPrefix . $iconVal;
                $iconUrl512 = $iconUrl192;
                $ext = strtolower(pathinfo($iconVal, PATHINFO_EXTENSION));
                $iconType = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
                break;
            }
            // File not found — continue to next key
        }
    }
} catch (Exception $e) {}

$startUrl = 'staff-portal.php' . ($bizSlug ? '?b=' . urlencode($bizSlug) : '');

while (ob_get_level()) ob_end_clean();
echo json_encode([
    'name'             => $bizName . ' — Staff Portal',
    'short_name'       => 'Staff Portal',
    'description'      => 'Portal karyawan: absensi, monitoring, cuti, occupancy, breakfast',
    'start_url'        => $startUrl,
    'scope'            => '.',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'theme_color'      => '#0d1f3c',
    'background_color' => '#0d1f3c',
    'lang'             => 'id',
    'categories'       => ['business', 'productivity'],
    'icons'            => [
        [
            'src'     => $iconUrl192,
            'sizes'   => '192x192',
            'type'    => $iconType,
            'purpose' => 'any',
        ],
        [
            'src'     => $iconUrl512,
            'sizes'   => '512x512',
            'type'    => $iconType,
            'purpose' => 'any',
        ],
        [
            'src'     => $iconUrl192,
            'sizes'   => '192x192',
            'type'    => $iconType,
            'purpose' => 'maskable',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
