<?php
/**
 * Test Cloudinary Upload dari Hosting
 * SECURITY: Restricted to localhost only
 */

// Only allow from localhost
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true) || 
           (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
if (!$isLocal) {
    http_response_code(403);
    die('Forbidden');
}

$token = $_GET['token'] ?? '';
if ($token !== 'test2026') {
    die('Add ?token=test2026');
}

// Include CloudinaryHelper
require_once __DIR__ . '/includes/CloudinaryHelper.php';

echo "<h2>Cloudinary Upload Test</h2>";

$cloudinary = CloudinaryHelper::getInstance();

echo "<p><strong>Cloudinary Enabled:</strong> " . ($cloudinary->isEnabled() ? 'YES ✓' : 'NO ✗') . "</p>";

// Try to get credentials info (without exposing secrets)
$reflection = new ReflectionClass($cloudinary);
$prop = $reflection->getProperty('cloudName');
$prop->setAccessible(true);
$cloudName = $prop->getValue($cloudinary);

echo "<p><strong>Cloud Name:</strong> " . htmlspecialchars($cloudName) . "</p>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['testimg'])) {
    echo "<h3>Uploading...</h3>";
    
    if ($_FILES['testimg']['error'] === UPLOAD_ERR_OK) {
        $result = $cloudinary->upload($_FILES['testimg']['tmp_name'], 'test');
        
        if ($result && isset($result['url'])) {
            echo "<p style='color:green'>✓ Upload SUCCESS!</p>";
            echo "<p><strong>URL:</strong> " . htmlspecialchars($result['url']) . "</p>";
            echo "<p><strong>Public ID:</strong> " . htmlspecialchars($result['public_id'] ?? 'N/A') . "</p>";
            echo "<img src='" . htmlspecialchars($result['url']) . "' style='max-width:400px'>";
        } else {
            echo "<p style='color:red'>✗ Upload FAILED</p>";
            echo "<pre>" . print_r($result, true) . "</pre>";
        }
    } else {
        echo "<p style='color:red'>File upload error: " . $_FILES['testimg']['error'] . "</p>";
    }
}
?>

<hr>
<h3>Test Upload</h3>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="testimg" accept="image/*" required>
    <button type="submit" style="padding:10px 20px;background:green;color:white;border:none;cursor:pointer">
        Upload to Cloudinary
    </button>
</form>

<hr>
<p style='color:red'><strong>HAPUS FILE INI SETELAH TEST!</strong></p>
