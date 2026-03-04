<?php
/**
 * Dynamic PWA Manifest — includes ?b=slug so installed app always opens
 * the correct business without relying on browser URL bar
 */
define('APP_ACCESS', true);
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');

$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizName  = 'Absensi Karyawan';
$bizShort = 'Absensi';

if ($bizSlug) {
    $bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
    if (file_exists($bizFile)) {
        $cfg     = require $bizFile;
        $bizName  = ($cfg['name'] ?? 'Absensi') . ' — Absensi';
        $bizShort = 'Absensi';
    }
}

$startUrl = 'absen.php' . ($bizSlug ? '?b=' . urlencode($bizSlug) : '');

echo json_encode([
    'name'             => $bizName,
    'short_name'       => $bizShort,
    'description'      => 'Absensi GPS dan deteksi wajah karyawan',
    'start_url'        => $startUrl,
    'scope'            => '.',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'theme_color'      => '#0d1f3c',
    'background_color' => '#0d1f3c',
    'lang'             => 'id',
    'icons'            => [
        [
            'src'     => '../../assets/icons/absen-icon-192.svg',
            'sizes'   => '192x192',
            'type'    => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => '../../assets/icons/absen-icon-512.svg',
            'sizes'   => '512x512',
            'type'    => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
    ],
    'categories'                 => ['productivity', 'utilities'],
    'prefer_related_applications' => false,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
