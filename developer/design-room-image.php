<?php
/**
 * Room Number Image Generator
 * Generates PNG image of room number sign with wood texture
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

// Parameters
$room_number = $_GET['no'] ?? '301';
$hotel_name = $_GET['hotel'] ?? 'NARAYANA';
$sub_text = $_GET['sub'] ?? 'HOTEL';
$download = isset($_GET['download']);

// Sanitize inputs
$room_number = preg_replace('/[^A-Za-z0-9\-]/', '', substr($room_number, 0, 5));
$hotel_name = preg_replace('/[^A-Za-z0-9\s\-\.]/', '', substr($hotel_name, 0, 30));
$sub_text = preg_replace('/[^A-Za-z0-9\s\-\.]/', '', substr($sub_text, 0, 20));

if (!$room_number) $room_number = '301';
if (!$hotel_name) $hotel_name = 'NARAYANA';

// Check assets
$assetsDir = __DIR__ . '/assets/room-design/';
$font_path = $assetsDir . 'font.ttf';
$wood_path = $assetsDir . 'wood_texture.jpg';

if (!extension_loaded('gd') || !file_exists($font_path) || !file_exists($wood_path)) {
    // Return placeholder image
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(400, 300);
    $bg = imagecolorallocate($img, 200, 200, 200);
    $txt = imagecolorallocate($img, 100, 100, 100);
    imagefill($img, 0, 0, $bg);
    imagestring($img, 5, 100, 140, 'Assets not found', $txt);
    imagepng($img);
    imagedestroy($img);
    exit;
}

// Set headers
if ($download) {
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="room-' . $room_number . '.png"');
} else {
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

// Create Canvas (800x600 for high resolution)
$width = 800;
$height = 600;
$image = imagecreatetruecolor($width, $height);

// Enable Alpha Blending
imagealphablending($image, true);
imagesavealpha($image, true);

// Transparent background
$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
imagefill($image, 0, 0, $transparent);

// Load Wood Texture & Create Arch Masking
$wood = imagecreatefromjpeg($wood_path);
$wood_resized = imagescale($wood, $width, $height);

// Create arch shape masking (half-circle top + rectangle bottom)
for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        $centerX = $width / 2;
        $centerY = $height * 0.45;
        $radius = $width * 0.45;

        $dist = sqrt(pow($x - $centerX, 2) + pow($y - $centerY, 2));
        
        $isInsideArch = ($dist <= $radius && $y <= $centerY) || 
                         ($x >= ($centerX - $radius) && $x <= ($centerX + $radius) && $y > $centerY && $y < $height * 0.85);

        if ($isInsideArch) {
            $color = imagecolorat($wood_resized, $x, $y);
            imagesetpixel($image, $x, $y, $color);
        }
    }
}

// Colors - Gold & Dark
$gold_bright = imagecolorallocate($image, 230, 190, 100);
$gold_shadow = imagecolorallocate($image, 140, 100, 40);
$black_text = imagecolorallocate($image, 30, 30, 30);

// Draw Room Number (with drop shadow for emboss effect)
$fontSizeNo = 120;
$bbox = imagettfbbox($fontSizeNo, 0, $font_path, $room_number);
$xNo = ($width - ($bbox[2] - $bbox[0])) / 2;

// Shadow (dark gold)
imagettftext($image, $fontSizeNo, 0, $xNo + 3, 253, $gold_shadow, $font_path, $room_number);
// Main text (bright gold)
imagettftext($image, $fontSizeNo, 0, $xNo, 250, $gold_bright, $font_path, $room_number);

// Draw Hotel Name (dark/burned wood look)
$fontSizeHotel = 35;
$bboxH = imagettfbbox($fontSizeHotel, 0, $font_path, $hotel_name);
$xH = ($width - ($bboxH[2] - $bboxH[0])) / 2;
imagettftext($image, $fontSizeHotel, 0, $xH, 420, $black_text, $font_path, $hotel_name);

// Draw Sub Text
$fontSizeSub = 20;
$bboxS = imagettfbbox($fontSizeSub, 0, $font_path, $sub_text);
$xS = ($width - ($bboxS[2] - $bboxS[0])) / 2;
imagettftext($image, $fontSizeSub, 0, $xS, 460, $black_text, $font_path, $sub_text);

// Output
imagepng($image);
imagedestroy($image);
imagedestroy($wood);
if ($wood_resized) imagedestroy($wood_resized);
