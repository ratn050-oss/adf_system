<?php
/**
 * Staff Portal PWA Manifest
 * Makes the staff portal installable as mobile app
 */
define('APP_ACCESS', true);
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');

$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizName = 'Staff Portal';

if ($bizSlug) {
    $bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
    if (file_exists($bizFile)) {
        $cfg = require $bizFile;
        $bizName = ($cfg['name'] ?? 'Staff Portal');
    }
}

$startUrl = 'staff-portal.php' . ($bizSlug ? '?b=' . urlencode($bizSlug) : '');

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
            'src'     => 'absen-icon.php?size=192',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'absen-icon.php?size=512',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'absen-icon.php?size=192',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
