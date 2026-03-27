<?php
/**
 * Migrate Local Images to Cloudinary
 * This script uploads all existing local images to Cloudinary
 * and updates the database with cloud URLs
 */

// Load .env for Cloudinary credentials
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Direct PDO connection for local
$pdo = new PDO('mysql:host=localhost;dbname=adf_narayana_hotel;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

require_once __DIR__ . '/includes/CloudinaryHelper.php';

echo "=== Migrate Local Images to Cloudinary ===\n\n";

$cloudinary = CloudinaryHelper::getInstance();

if (!$cloudinary->isEnabled()) {
    die("ERROR: Cloudinary is not configured! Check .env file.\n");
}

echo "✓ Cloudinary is configured\n";
echo "  Cloud Name: " . getenv('CLOUDINARY_CLOUD_NAME') . "\n\n";

// Get all web image settings
$query = "SELECT setting_key, setting_value FROM settings 
          WHERE (setting_key LIKE 'web_hero_background%' 
             OR setting_key LIKE 'web_room_primary%' 
             OR setting_key LIKE 'web_room_gallery%'
             OR setting_key LIKE 'web_logo%'
             OR setting_key LIKE 'web_favicon%')
          AND setting_value != '' 
          AND setting_value NOT LIKE 'http%'";

$stmt = $pdo->query($query);
$settings = $stmt->fetchAll() ?: [];

if (empty($settings)) {
    echo "No local images found to migrate.\n";
    exit;
}

echo "Found " . count($settings) . " settings with local images:\n\n";

$migrated = 0;
$failed = 0;

foreach ($settings as $setting) {
    $key = $setting['setting_key'];
    $value = $setting['setting_value'];
    
    echo "Processing: $key\n";
    
    // Check if it's a JSON array (gallery) or single path
    $isGallery = (strpos($key, 'gallery') !== false);
    
    if ($isGallery) {
        // Handle gallery (JSON array of paths)
        $paths = json_decode($value, true);
        if (!is_array($paths)) {
            echo "  ⚠ Invalid JSON, skipping\n\n";
            $failed++;
            continue;
        }
        
        $newPaths = [];
        foreach ($paths as $path) {
            $result = migrateImage($path, $cloudinary);
            if ($result) {
                $newPaths[] = $result;
                echo "  ✓ Uploaded: " . basename($path) . "\n";
            } else {
                echo "  ✗ Failed: " . basename($path) . "\n";
                $newPaths[] = $path; // Keep original if failed
            }
        }
        
        // Update database with new URLs
        $newValue = json_encode($newPaths);
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$newValue, $key]);
        $migrated++;
        
    } else {
        // Handle single image path
        $result = migrateImage($localPath = $value, $cloudinary);
        if ($result) {
            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$result, $key]);
            echo "  ✓ Uploaded to: $result\n";
            $migrated++;
        } else {
            echo "  ✗ Failed to upload\n";
            $failed++;
        }
    }
    
    echo "\n";
}

echo "=== Migration Complete ===\n";
echo "Migrated: $migrated settings\n";
echo "Failed: $failed settings\n";

// Clean up this script
// unlink(__FILE__);

function migrateImage($localPath, $cloudinary) {
    // Build full path
    $fullPath = __DIR__ . '/' . $localPath;
    
    if (!file_exists($fullPath)) {
        echo "    File not found: $fullPath\n";
        return null;
    }
    
    // Check file size - if > 9MB, resize it first
    $fileSize = filesize($fullPath);
    $maxSize = 9 * 1024 * 1024; // 9MB
    
    $uploadPath = $fullPath;
    $tempFile = null;
    
    if ($fileSize > $maxSize) {
        echo "    File too large (" . round($fileSize/1024/1024, 1) . "MB), resizing...\n";
        $uploadPath = resizeImage($fullPath);
        if (!$uploadPath) {
            echo "    Failed to resize image\n";
            return null;
        }
        $tempFile = $uploadPath;
        echo "    Resized to " . round(filesize($uploadPath)/1024/1024, 1) . "MB\n";
    }
    
    // Determine folder based on path
    if (strpos($localPath, 'hero') !== false) {
        $folder = 'website/hero';
    } elseif (strpos($localPath, 'rooms/king') !== false) {
        $folder = 'website/rooms/king';
    } elseif (strpos($localPath, 'rooms/queen') !== false) {
        $folder = 'website/rooms/queen';
    } elseif (strpos($localPath, 'rooms/twin') !== false) {
        $folder = 'website/rooms/twin';
    } elseif (strpos($localPath, 'logo') !== false) {
        $folder = 'website/logo';
    } elseif (strpos($localPath, 'favicon') !== false) {
        $folder = 'website/favicon';
    } else {
        $folder = 'website/misc';
    }
    
    // Upload to Cloudinary
    try {
        $result = $cloudinary->upload($uploadPath, $folder);
        
        // Clean up temp file
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }
        
        if ($result && isset($result['url'])) {
            return $result['url'];
        }
    } catch (Exception $e) {
        echo "    Error: " . $e->getMessage() . "\n";
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
    
    return null;
}

function resizeImage($sourcePath) {
    $info = getimagesize($sourcePath);
    if (!$info) return null;
    
    $mime = $info['mime'];
    $width = $info[0];
    $height = $info[1];
    
    // Load image
    switch ($mime) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = imagecreatefrompng($sourcePath);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($sourcePath);
            break;
        default:
            return null;
    }
    
    if (!$source) return null;
    
    // Calculate new dimensions (max 2000px width, maintain aspect ratio)
    $maxWidth = 2000;
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int)($height * ($maxWidth / $width));
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }
    
    // Create resized image
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG
    if ($mime === 'image/png') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
    }
    
    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Save to temp file
    $tempPath = sys_get_temp_dir() . '/migrate_' . basename($sourcePath) . '.jpg';
    
    // Save as JPEG with 85% quality
    imagejpeg($resized, $tempPath, 85);
    
    imagedestroy($source);
    imagedestroy($resized);
    
    return $tempPath;
}
