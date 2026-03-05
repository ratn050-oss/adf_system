<?php
/**
 * Quick Cloudinary Test - Access via browser
 * DELETE THIS FILE after testing!
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/CloudinaryHelper.php';

header('Content-Type: text/html; charset=utf-8');

$cl = CloudinaryHelper::getInstance();
$status = $cl->isEnabled();
$uploadOk = false;
$url = '';

if ($status) {
    // Upload tiny test image
    $testFile = tempnam(sys_get_temp_dir(), 'cltest') . '.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
    file_put_contents($testFile, $png);
    
    $result = $cl->upload($testFile, 'test', 'hosting_test_' . time());
    @unlink($testFile);
    
    if ($result && isset($result['url'])) {
        $uploadOk = true;
        $url = $result['url'];
        // Cleanup
        $cl->delete($result['public_id']);
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Cloudinary Test</title></head>
<body style="font-family:Arial;max-width:500px;margin:40px auto;padding:20px;">
<h2>☁️ Cloudinary Test</h2>
<table style="width:100%;border-collapse:collapse;">
<tr><td style="padding:8px;border:1px solid #ddd;">Koneksi</td>
    <td style="padding:8px;border:1px solid #ddd;"><?= $status ? '✅ Terhubung' : '❌ Tidak terhubung' ?></td></tr>
<tr><td style="padding:8px;border:1px solid #ddd;">Upload Test</td>
    <td style="padding:8px;border:1px solid #ddd;"><?= $uploadOk ? '✅ Berhasil' : '❌ Gagal' ?></td></tr>
<?php if ($url): ?>
<tr><td style="padding:8px;border:1px solid #ddd;">URL Test</td>
    <td style="padding:8px;border:1px solid #ddd;word-break:break-all;font-size:11px;"><?= $url ?></td></tr>
<?php endif; ?>
</table>
<?php if ($uploadOk): ?>
<p style="color:green;font-weight:bold;margin-top:20px;">🎉 Cloudinary siap digunakan! Hapus file ini setelah testing.</p>
<?php elseif ($status): ?>
<p style="color:orange;">⚠️ Terhubung tapi upload gagal. Cek API Key/Secret.</p>
<?php else: ?>
<p style="color:red;">❌ Cloudinary belum terhubung. Pastikan file <code>.env</code> sudah benar (bukan <code>.env.example</code>).</p>
<?php endif; ?>
</body></html>
