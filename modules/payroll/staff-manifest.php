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

// Icon cache-busting: check when custom icon was last changed
$iconVer = '';
try {
    $mdb = Database::getInstance();
    $iconRow = $mdb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'pwa_app_icon'");
    if (!empty($iconRow['setting_value'])) {
        $iconVer = '&v=' . substr(md5($iconRow['setting_value']), 0, 8);
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
            'src'     => 'absen-icon.php?size=192' . $iconVer,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'absen-icon.php?size=512' . $iconVer,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'absen-icon.php?size=192' . $iconVer,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
