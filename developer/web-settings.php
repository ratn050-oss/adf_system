<?php
/**
 * Developer Panel - Web Settings
 * Configure the Narayana Karimunjawa booking website
 * Manage hero content, room descriptions, SEO, contact info, and website toggle
 */

// Increase limits for image uploads
@ini_set('upload_max_filesize', '50M');
@ini_set('post_max_size', '60M');
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');
@ini_set('memory_limit', '256M');

require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions.php';

$devAuth = new DevAuth();
$devAuth->requireLogin();

$db = Database::getInstance();
$pageTitle = 'Web Settings';
$currentPage = 'web-settings';

$error = '';
$success = '';

// Define all web setting keys with defaults
$webSettings = [
    // General
    'web_enabled'           => '1',
    'web_site_name'         => 'Narayana Karimunjawa',
    'web_tagline'           => 'Island Paradise Resort',
    'web_description'       => 'Luxury beachfront resort in the heart of Karimunjawa Islands. Premium accommodations with stunning ocean views.',
    
    'web_favicon'            => '', // Path to favicon icon
    'web_logo'               => '', // Path to logo image

    // Destinations (JSON array of destination objects)
    'web_destinations'       => '[]',

    // Hero Section
    'web_hero_accent'       => 'Welcome to Paradise',
    'web_hero_title'        => 'Experience Karimunjawa<br>Like Never Before',
    'web_hero_subtitle'     => 'An exclusive island retreat where tropical luxury meets the pristine beauty of the Java Sea',
    'web_hero_background'   => '', // Path to hero background image
    
    // Contact & Social
    'web_whatsapp'          => '6281222228590',
    'web_instagram'         => 'narayanakarimunjawa',
    'web_email'             => 'narayanahotelkarimunjawa@gmail.com',
    'web_phone'             => '+62 812-2222-8590',
    'web_address'           => 'Karimunjawa, Jepara, Central Java, Indonesia 59455',
    
    // Operations
    'web_checkin_time'      => '14:00',
    'web_checkout_time'     => '12:00',
    
    // Room Descriptions (for website display, not in DB)
    'web_room_desc_king'    => 'Our most prestigious accommodation featuring a luxurious king-sized bed, premium ocean-view balcony, and elegant tropical décor. Experience the pinnacle of island luxury.',
    'web_room_desc_queen'   => 'Beautifully appointed rooms with a comfortable queen-sized bed, modern amenities, and a private balcony with garden or partial ocean views. Perfect for couples.',
    'web_room_desc_twin'    => 'Spacious rooms with two single beds, ideal for friends or family. Features modern furnishings, ample storage, and a cozy balcony with tropical garden views.',
    
    // Room Gallery Images
    'web_room_gallery_king'  => '', // JSON array of image paths
    'web_room_gallery_queen' => '',
    'web_room_gallery_twin'  => '',
    
    // Room Primary (cover) Image
    'web_room_primary_king'  => '',
    'web_room_primary_queen' => '',
    'web_room_primary_twin'  => '',
    
    // SEO
    'web_meta_title'        => 'Narayana Karimunjawa | Luxury Island Resort',
    'web_meta_description'  => 'Book your tropical paradise getaway at Narayana Karimunjawa. Premium beachfront resort with King, Queen & Twin rooms on Karimunjawa Island.',
    'web_meta_keywords'     => 'karimunjawa hotel, karimunjawa resort, narayana karimunjawa, island resort jepara, karimunjawa accommodation',
    
    // Appearance
    'web_primary_color'     => '#0c2340',
    'web_accent_color'      => '#c8a45e',
    
    // Booking Settings
    'web_max_advance_days'  => '365',
    'web_min_stay_nights'   => '1',
    'web_booking_notice'    => 'Payment is due upon arrival at the hotel. Free cancellation up to 24 hours before check-in.',
];

// Connect to hotel database for website settings
$webDb = null;
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
$webDbName = $isProduction ? 'adfb2574_narayana_hotel' : 'adf_narayana_hotel';
$webDbUser = $isProduction ? 'adfb2574_adfsystem' : 'root';
$webDbPass = $isProduction ? '@Nnoc2025' : '';

// Website public directory for auto-sync of uploaded images
if ($isProduction) {
    $websitePublicDir = '/home/adfb2574/public_html/narayanakarimunjawa.com';
    $websiteUrl = 'https://narayanakarimunjawa.com';
} else {
    $websitePublicDir = dirname(dirname(__FILE__)) . '/../narayanakarimunjawa/public';
    $websiteUrl = 'http://localhost:8081/narayanakarimunjawa/public/';
}
try {
    $webDb = new PDO(
        'mysql:host=localhost;dbname=' . $webDbName . ';charset=utf8mb4',
        $webDbUser, $webDbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Load current values from database
$currentValues = [];
$keys = array_keys($webSettings);
$placeholders = implode(',', array_fill(0, count($keys), '?'));
$stmt = $webDb->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
$stmt->execute($keys);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $currentValues[$row['setting_key']] = $row['setting_value'];
}

// Merge: use DB value if exists, otherwise default
foreach ($webSettings as $key => $default) {
    $webSettings[$key] = $currentValues[$key] ?? $default;
}

// Handle flash messages from redirect
$activeTab = $_GET['tab'] ?? 'general';

// Handle AJAX success redirect
if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $success = 'Gallery updated successfully!';
}
if (isset($_SESSION['web_settings_success'])) {
    $success = $_SESSION['web_settings_success'];
    unset($_SESSION['web_settings_success']);
}
if (isset($_SESSION['web_settings_error'])) {
    $error = $_SESSION['web_settings_error'];
    unset($_SESSION['web_settings_error']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectTab = 'general'; // default tab after save
    
    if ($action === 'save_general') {
        $redirectTab = 'general';
        $fields = ['web_enabled', 'web_site_name', 'web_tagline', 'web_description'];

        // Handle logo upload
        if (isset($_FILES['web_logo']) && $_FILES['web_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = dirname(dirname(__FILE__)) . '/uploads/logo/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fileInfo = pathinfo($_FILES['web_logo']['name']);
            $allowedExts = ['png', 'svg', 'jpg', 'jpeg', 'webp', 'gif'];
            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                $newFileName = 'logo-' . time() . '.' . $fileInfo['extension'];
                $uploadPath = $uploadDir . $newFileName;
                if (move_uploaded_file($_FILES['web_logo']['tmp_name'], $uploadPath)) {
                    $relativePath = 'uploads/logo/' . $newFileName;
                    $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES ('web_logo', ?, 'text', 'Website Logo') ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$relativePath, $relativePath]);
                    $websiteLogoDir = $websitePublicDir . '/uploads/logo/';
                    if (!is_dir($websiteLogoDir)) @mkdir($websiteLogoDir, 0755, true);
                    @copy($uploadPath, $websiteLogoDir . $newFileName);
                    $oldLogo = $webSettings['web_logo'] ?? '';
                    if ($oldLogo) {
                        $old1 = dirname(dirname(__FILE__)) . '/' . $oldLogo;
                        $old2 = $websitePublicDir . '/' . $oldLogo;
                        if (file_exists($old1)) @unlink($old1);
                        if (file_exists($old2)) @unlink($old2);
                    }
                    $webSettings['web_logo'] = $relativePath;
                }
            }
        }
        // Handle remove logo
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            $oldLogo = $webSettings['web_logo'] ?? '';
            if ($oldLogo) {
                $f1 = dirname(dirname(__FILE__)) . '/' . $oldLogo;
                $f2 = $websitePublicDir . '/' . $oldLogo;
                if (file_exists($f1)) @unlink($f1);
                if (file_exists($f2)) @unlink($f2);
            }
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('web_logo', '') ON DUPLICATE KEY UPDATE setting_value = ''");
            $stmt->execute();
            $webSettings['web_logo'] = '';
        }
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website: ' . str_replace('web_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        // Handle checkbox (unchecked = not sent)
        if (!isset($_POST['web_enabled'])) {
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('web_enabled', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
            $stmt->execute();
            $webSettings['web_enabled'] = '0';
        }
        // Handle favicon upload
        if (isset($_FILES['web_favicon']) && $_FILES['web_favicon']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = dirname(dirname(__FILE__)) . '/uploads/favicon/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $fileInfo = pathinfo($_FILES['web_favicon']['name']);
            $allowedExts = ['ico', 'png', 'svg', 'jpg', 'jpeg', 'webp'];

            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                $newFileName = 'favicon-' . time() . '.' . $fileInfo['extension'];
                $uploadPath = $uploadDir . $newFileName;

                if (move_uploaded_file($_FILES['web_favicon']['tmp_name'], $uploadPath)) {
                    $relativePath = 'uploads/favicon/' . $newFileName;
                    $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description)
                                VALUES ('web_favicon', ?, 'text', 'Website Favicon Icon')
                                ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$relativePath, $relativePath]);

                    // Auto-sync to website public dir
                    $websiteFaviconDir = $websitePublicDir . '/uploads/favicon/';
                    if (!is_dir($websiteFaviconDir)) @mkdir($websiteFaviconDir, 0755, true);
                    @copy($uploadPath, $websiteFaviconDir . $newFileName);

                    // Delete old favicon
                    $oldFav = $webSettings['web_favicon'] ?? '';
                    if ($oldFav) {
                        $old1 = dirname(dirname(__FILE__)) . '/' . $oldFav;
                        $old2 = $websitePublicDir . '/' . $oldFav;
                        if (file_exists($old1)) @unlink($old1);
                        if (file_exists($old2)) @unlink($old2);
                    }
                    $webSettings['web_favicon'] = $relativePath;
                }
            }
        }

        // Handle remove favicon
        if (isset($_POST['remove_favicon']) && $_POST['remove_favicon'] === '1') {
            $oldFav = $webSettings['web_favicon'] ?? '';
            if ($oldFav) {
                $f1 = dirname(dirname(__FILE__)) . '/' . $oldFav;
                $f2 = $websitePublicDir . '/' . $oldFav;
                if (file_exists($f1)) @unlink($f1);
                if (file_exists($f2)) @unlink($f2);
            }
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('web_favicon', '') ON DUPLICATE KEY UPDATE setting_value = ''");
            $stmt->execute();
            $webSettings['web_favicon'] = '';
        }

        $success = 'General settings saved successfully!';
    }
    
    elseif ($action === 'save_hero') {
        $redirectTab = 'hero';
        // DEBUG LOG - temporary
        $debugLog = [];
        $debugLog[] = "save_hero triggered at " . date('H:i:s');
        $debugLog[] = "POST data: " . json_encode($_POST);
        
        $fields = ['web_hero_accent', 'web_hero_title', 'web_hero_subtitle'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $debugLog[] = "Saving $key = '$val'";
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Hero: ' . str_replace('web_hero_', '', $key), $val]);
                $webSettings[$key] = $val;
                $debugLog[] = "Rows affected: " . $stmt->rowCount();
            } else {
                $debugLog[] = "KEY NOT FOUND IN POST: $key";
            }
        }
        
        // Verify save
        $verifyStmt = $webDb->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_%'");
        $verifyRows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
        $debugLog[] = "After save DB check: " . json_encode($verifyRows);
        
        // Write debug log
        file_put_contents(dirname(dirname(__FILE__)) . '/debug_hero_save.log', implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        
        $success = 'Hero section berhasil disimpan! ✅';
        
        // Handle hero background image upload
        if (isset($_FILES['web_hero_background']) && $_FILES['web_hero_background']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = dirname(dirname(__FILE__)) . '/uploads/hero/';
            
            // Create directory if not exists
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileInfo = pathinfo($_FILES['web_hero_background']['name']);
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                $newFileName = 'hero-bg-' . time() . '.' . $fileInfo['extension'];
                $uploadPath = $uploadDir . $newFileName;
                
                if (move_uploaded_file($_FILES['web_hero_background']['tmp_name'], $uploadPath)) {
                    // Save relative path to database
                    $relativePath = 'uploads/hero/' . $newFileName;
                    $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                                VALUES ('web_hero_background', ?, 'text', 'Website Hero Background Image') 
                                ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$relativePath, $relativePath]);
                    $webSettings['web_hero_background'] = $relativePath;
                    
                    // Auto-sync to narayanakarimunjawa website folder
                    $websiteUploadDir = $websitePublicDir . '/uploads/hero/';
                    if (!is_dir($websiteUploadDir)) {
                        mkdir($websiteUploadDir, 0755, true);
                    }
                    @copy($uploadPath, $websiteUploadDir . $newFileName);
                    
                    // Delete old image if exists (both locations)
                    $oldBg = $currentValues['web_hero_background'] ?? '';
                    if ($oldBg) {
                        $oldFile1 = dirname(dirname(__FILE__)) . '/' . $oldBg;
                        $oldFile2 = $websitePublicDir . '/' . $oldBg;
                        if (file_exists($oldFile1)) unlink($oldFile1);
                        if (file_exists($oldFile2)) @unlink($oldFile2);
                    }
                }
            }
        }
        
        // Handle remove background
        if (isset($_POST['remove_background']) && $_POST['remove_background'] === '1') {
            $oldBg = $webSettings['web_hero_background'] ?? '';
            if ($oldBg) {
                // Delete from both locations
                $file1 = dirname(dirname(__FILE__)) . '/' . $oldBg;
                $file2 = $websitePublicDir . '/' . $oldBg;
                if (file_exists($file1)) unlink($file1);
                if (file_exists($file2)) @unlink($file2);
            }
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) 
                        VALUES ('web_hero_background', '') ON DUPLICATE KEY UPDATE setting_value = ''");
            $stmt->execute();
            $webSettings['web_hero_background'] = '';
        }
        
        // success already set above with debug info
    }
    
    elseif ($action === 'save_contact') {
        $redirectTab = 'contact';
        $fields = ['web_whatsapp', 'web_instagram', 'web_email', 'web_phone', 'web_address', 'web_checkin_time', 'web_checkout_time'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website: ' . str_replace('web_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Contact & operations settings saved!';
    }
    
    elseif ($action === 'save_rooms') {
        $redirectTab = 'rooms';
        $fields = ['web_room_desc_king', 'web_room_desc_queen', 'web_room_desc_twin'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'textarea', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Room Description', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Room descriptions saved!';
    }
    
    elseif ($action === 'save_seo') {
        $redirectTab = 'seo';
        $fields = ['web_meta_title', 'web_meta_description', 'web_meta_keywords'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website SEO: ' . str_replace('web_meta_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'SEO settings saved!';
    }
    
    elseif ($action === 'save_appearance') {
        $redirectTab = 'appearance';
        $fields = ['web_primary_color', 'web_accent_color'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Color', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Appearance settings saved!';
    }
    
    elseif ($action === 'save_booking') {
        $redirectTab = 'booking';
        $fields = ['web_max_advance_days', 'web_min_stay_nights', 'web_booking_notice'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Booking', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Booking settings saved!';
    }
    
    elseif ($action === 'save_room_gallery') {
        $redirectTab = 'gallery';
        $roomType = $_POST['room_type'] ?? '';
        if (!in_array($roomType, ['king', 'queen', 'twin'])) {
            $error = 'Invalid room type!';
        } else {
            $galleryKey = 'web_room_gallery_' . $roomType;
            
            // Load existing gallery
            $existingGallery = json_decode($webSettings[$galleryKey] ?? '[]', true) ?: [];
            
            // Handle file uploads
            if (isset($_FILES['room_images']) && !empty($_FILES['room_images']['name'][0])) {
                $uploadDir = dirname(dirname(__FILE__)) . '/uploads/rooms/' . $roomType . '/';
                $websiteUploadDir = $websitePublicDir . '/uploads/rooms/' . $roomType . '/';
                
                // Create directories if not exist
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                if (!is_dir($websiteUploadDir)) {
                    mkdir($websiteUploadDir, 0755, true);
                }
                
                $uploaded = 0;
                foreach ($_FILES['room_images']['tmp_name'] as $key => $tmpName) {
                    if ($_FILES['room_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $fileInfo = pathinfo($_FILES['room_images']['name'][$key]);
                        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                        
                        if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                            $newFileName = $roomType . '-' . time() . '-' . $uploaded . '.' . $fileInfo['extension'];
                            $uploadPath = $uploadDir . $newFileName;
                            
                            if (move_uploaded_file($tmpName, $uploadPath)) {
                                // Auto-sync to narayanakarimunjawa
                                @copy($uploadPath, $websiteUploadDir . $newFileName);
                                
                                $relativePath = 'uploads/rooms/' . $roomType . '/' . $newFileName;
                                $existingGallery[] = $relativePath;
                                $uploaded++;
                            }
                        }
                    }
                }
            }
            
            // Handle image deletion
            if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                foreach ($_POST['delete_images'] as $imgPath) {
                    // Delete from both locations
                    $file1 = dirname(dirname(__FILE__)) . '/' . $imgPath;
                    $file2 = $websitePublicDir . '/' . $imgPath;
                    if (file_exists($file1)) unlink($file1);
                    if (file_exists($file2)) @unlink($file2);
                    
                    $existingGallery = array_diff($existingGallery, [$imgPath]);
                }
                $existingGallery = array_values($existingGallery); // Re-index
            }
            
            // Handle primary image selection
            $primaryKey = 'web_room_primary_' . $roomType;
            if (isset($_POST['primary_image']) && in_array($_POST['primary_image'], $existingGallery)) {
                $primaryVal = $_POST['primary_image'];
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$primaryKey, $primaryVal, 'Room Primary Image', $primaryVal]);
                $webSettings[$primaryKey] = $primaryVal;
            }
            // If primary was deleted, clear it
            $currentPrimary = $webSettings[$primaryKey] ?? '';
            if ($currentPrimary && !in_array($currentPrimary, $existingGallery)) {
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$primaryKey, '', 'Room Primary Image', '']);
                $webSettings[$primaryKey] = '';
            }
            
            // Save to database
            $galleryJson = json_encode($existingGallery);
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                        VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$galleryKey, $galleryJson, 'Website Room Gallery', $galleryJson]);
            $webSettings[$galleryKey] = $galleryJson;
            
            $success = ucfirst($roomType) . ' room gallery updated!';
        }
    }
    
    elseif ($action === 'save_destinations') {
        $redirectTab = 'destinations';
        
        // Load existing destinations
        $existingDest = json_decode($webSettings['web_destinations'] ?? '[]', true) ?: [];
        
        // Handle adding new destination
        if (!empty($_POST['dest_title'])) {
            $newDest = [
                'id' => uniqid('dest_'),
                'title' => trim($_POST['dest_title']),
                'subtitle' => trim($_POST['dest_subtitle'] ?? ''),
                'content' => trim($_POST['dest_content'] ?? ''),
                'image' => '',
                'order' => count($existingDest) + 1,
                'active' => true,
            ];
            
            // Handle image upload for new destination
            if (isset($_FILES['dest_image']) && $_FILES['dest_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = dirname(dirname(__FILE__)) . '/uploads/destinations/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileInfo = pathinfo($_FILES['dest_image']['name']);
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                    $newFileName = 'dest-' . time() . '.' . $fileInfo['extension'];
                    $uploadPath = $uploadDir . $newFileName;
                    if (move_uploaded_file($_FILES['dest_image']['tmp_name'], $uploadPath)) {
                        $newDest['image'] = 'uploads/destinations/' . $newFileName;
                        $websiteDestDir = $websitePublicDir . '/uploads/destinations/';
                        if (!is_dir($websiteDestDir)) @mkdir($websiteDestDir, 0755, true);
                        @copy($uploadPath, $websiteDestDir . $newFileName);
                    }
                }
            }
            
            $existingDest[] = $newDest;
            $success = 'Destination "' . $newDest['title'] . '" added!';
        }
        
        // Handle update existing destinations
        if (isset($_POST['dest_update_id']) && is_array($_POST['dest_update_id'])) {
            foreach ($_POST['dest_update_id'] as $idx => $destId) {
                foreach ($existingDest as &$d) {
                    if ($d['id'] === $destId) {
                        $d['title'] = trim($_POST['dest_update_title'][$idx] ?? $d['title']);
                        $d['subtitle'] = trim($_POST['dest_update_subtitle'][$idx] ?? $d['subtitle']);
                        $d['content'] = trim($_POST['dest_update_content'][$idx] ?? $d['content']);
                        $d['order'] = (int)($_POST['dest_update_order'][$idx] ?? $d['order']);
                        $d['active'] = isset($_POST['dest_update_active']) && in_array($destId, $_POST['dest_update_active']);
                        
                        // Handle image upload for update
                        $fileKey = 'dest_update_image_' . $destId;
                        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                            $uploadDir = dirname(dirname(__FILE__)) . '/uploads/destinations/';
                            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                            $fileInfo = pathinfo($_FILES[$fileKey]['name']);
                            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                                $newFileName = 'dest-' . time() . '-' . $idx . '.' . $fileInfo['extension'];
                                $uploadPath = $uploadDir . $newFileName;
                                if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadPath)) {
                                    // Delete old image
                                    if (!empty($d['image'])) {
                                        $old1 = dirname(dirname(__FILE__)) . '/' . $d['image'];
                                        $old2 = $websitePublicDir . '/' . $d['image'];
                                        if (file_exists($old1)) @unlink($old1);
                                        if (file_exists($old2)) @unlink($old2);
                                    }
                                    $d['image'] = 'uploads/destinations/' . $newFileName;
                                    $websiteDestDir = $websitePublicDir . '/uploads/destinations/';
                                    if (!is_dir($websiteDestDir)) @mkdir($websiteDestDir, 0755, true);
                                    @copy($uploadPath, $websiteDestDir . $newFileName);
                                }
                            }
                        }
                        break;
                    }
                }
            }
            unset($d);
            if (!$success) $success = 'Destinations updated!';
        }
        
        // Handle delete destinations
        if (isset($_POST['delete_dest']) && is_array($_POST['delete_dest'])) {
            foreach ($_POST['delete_dest'] as $destId) {
                foreach ($existingDest as $dk => $d) {
                    if ($d['id'] === $destId) {
                        if (!empty($d['image'])) {
                            $f1 = dirname(dirname(__FILE__)) . '/' . $d['image'];
                            $f2 = $websitePublicDir . '/' . $d['image'];
                            if (file_exists($f1)) @unlink($f1);
                            if (file_exists($f2)) @unlink($f2);
                        }
                        unset($existingDest[$dk]);
                        break;
                    }
                }
            }
            $existingDest = array_values($existingDest);
            $success = 'Destination(s) deleted!';
        }
        
        // Sort by order
        usort($existingDest, function($a, $b) { return ($a['order'] ?? 0) - ($b['order'] ?? 0); });
        
        // Save to database
        $destJson = json_encode($existingDest);
        $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                    VALUES ('web_destinations', ?, 'text', 'Website Destinations') 
                    ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$destJson, $destJson]);
        $webSettings['web_destinations'] = $destJson;
    }

    // POST-Redirect-GET: store message in session and redirect to preserve active tab
    if ($success || $error) {
        if ($success) $_SESSION['web_settings_success'] = $success;
        if ($error) $_SESSION['web_settings_error'] = $error;
        header('Location: web-settings.php?tab=' . urlencode($redirectTab));
        exit;
    }
}

// Get live stats from hotel database (using same $webDb connection)
try {
    $totalRooms = $webDb->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $availableRooms = $webDb->query("SELECT COUNT(*) FROM rooms WHERE status = 'available'")->fetchColumn();
    $totalBookings = $webDb->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online'")->fetchColumn();
    $todayBookings = $webDb->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $roomTypes = $webDb->query("SELECT rt.type_name, rt.base_price, COUNT(r.id) as room_count 
                                   FROM room_types rt LEFT JOIN rooms r ON r.room_type_id = rt.id 
                                   GROUP BY rt.id ORDER BY rt.base_price DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $totalRooms = $availableRooms = $totalBookings = $todayBookings = 0;
    $roomTypes = [];
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .web-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 28px;
    }
    .web-stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .web-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
    }
    .web-stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1a1a2e;
    }
    .web-stat-label {
        font-size: 0.8rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .settings-tabs {
        display: flex;
        gap: 4px;
        background: #f1f3f5;
        border-radius: 12px;
        padding: 4px;
        margin-bottom: 24px;
        overflow-x: auto;
        flex-wrap: nowrap;
    }
    .settings-tab {
        padding: 10px 18px;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #6c757d;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
        border: none;
        background: transparent;
    }
    .settings-tab:hover {
        color: #1a1a2e;
        background: rgba(255,255,255,0.6);
    }
    .settings-tab.active {
        background: white;
        color: var(--dev-primary, #6f42c1);
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    
    .settings-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        margin-bottom: 20px;
        overflow: hidden;
    }
    .settings-card-header {
        padding: 20px 24px;
        border-bottom: 1px solid #f1f3f5;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .settings-card-header .icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }
    .settings-card-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 1rem;
    }
    .settings-card-header small {
        color: #6c757d;
        display: block;
        margin-top: 2px;
    }
    .settings-card-body {
        padding: 24px;
    }
    
    .form-label {
        font-weight: 500;
        font-size: 0.9rem;
        margin-bottom: 4px;
    }
    .form-text {
        font-size: 0.8rem;
        color: #6c757d;
    }
    .form-control, .form-select {
        border-radius: 8px;
    }
    
    .toggle-switch {
        position: relative;
        width: 52px;
        height: 28px;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background: #dee2e6;
        border-radius: 28px;
        transition: 0.3s;
    }
    .toggle-slider::before {
        content: '';
        position: absolute;
        height: 22px;
        width: 22px;
        left: 3px;
        bottom: 3px;
        background: white;
        border-radius: 50%;
        transition: 0.3s;
    }
    .toggle-switch input:checked + .toggle-slider {
        background: #28a745;
    }
    .toggle-switch input:checked + .toggle-slider::before {
        transform: translateX(24px);
    }
    
    .website-status {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    .website-status.online {
        background: #d4edda;
        color: #155724;
    }
    .website-status.offline {
        background: #f8d7da;
        color: #721c24;
    }
    .website-status .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    .website-status.online .status-dot { background: #28a745; }
    .website-status.offline .status-dot { background: #dc3545; }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    
    .color-preview {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .color-swatch {
        width: 42px;
        height: 42px;
        border-radius: 8px;
        border: 2px solid #dee2e6;
        cursor: pointer;
    }
    
    .room-type-pills {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .room-type-pill {
        padding: 6px 14px;
        background: #f1f3f5;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    .room-type-pill .count {
        color: var(--dev-primary, #6f42c1);
        font-weight: 700;
    }
    
    .website-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        background: rgba(111, 66, 193, 0.1);
        color: var(--dev-primary, #6f42c1);
        border-radius: 8px;
        font-weight: 500;
        text-decoration: none;
        font-size: 0.85rem;
    }
    .website-link:hover {
        background: rgba(111, 66, 193, 0.2);
        color: var(--dev-primary, #6f42c1);
    }
    
    .preview-hero {
        background: linear-gradient(135deg, var(--preview-primary, #0c2340), #1a3a5c);
        color: white;
        padding: 40px;
        border-radius: 12px;
        text-align: center;
        margin-top: 16px;
        position: relative;
        background-size: cover;
        background-position: center;
        overflow: hidden;
    }
    .preview-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(12, 35, 64, 0.85), rgba(26, 58, 92, 0.85));
        z-index: 1;
    }
    .preview-hero > * {
        position: relative;
        z-index: 2;
    }
    .preview-hero .accent { color: var(--preview-accent, #c8a45e); font-style: italic; font-size: 0.9rem; }
    .preview-hero h3 { font-size: 1.4rem; margin: 8px 0; }
    .preview-hero p { opacity: 0.9; font-size: 0.85rem; }
    
    .current-bg-preview {
        border: 2px solid #e9ecef;
        border-radius: 8px;
        padding: 8px;
        background: #f8f9fa;
    }
</style>

<div class="container-fluid py-4">
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-globe me-2"></i>Web Settings</h4>
            <p class="text-muted mb-0">Configure the Narayana Karimunjawa booking website</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= $websiteUrl ?>" target="_blank" class="website-link">
                <i class="bi bi-box-arrow-up-right"></i> Visit Website
            </a>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>
    
    <!-- Alerts -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Website Status -->
    <div class="website-status <?= $webSettings['web_enabled'] === '1' ? 'online' : 'offline' ?>">
        <div class="status-dot"></div>
        <strong>Website is <?= $webSettings['web_enabled'] === '1' ? 'Online' : 'Offline' ?></strong>
        <span class="ms-auto">
            <?php if ($webSettings['web_enabled'] === '1'): ?>
                Accepting reservations
            <?php else: ?>
                Visitors will see a maintenance page
            <?php endif; ?>
        </span>
    </div>
    
    <!-- Live Stats from Hotel DB -->
    <div class="web-stats-grid">
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(111,66,193,0.12); color: #6f42c1;">
                <i class="bi bi-door-open"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $totalRooms ?></div>
                <div class="web-stat-label">Total Rooms</div>
            </div>
        </div>
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(40,167,69,0.12); color: #28a745;">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $availableRooms ?></div>
                <div class="web-stat-label">Available Now</div>
            </div>
        </div>
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(0,123,255,0.12); color: #007bff;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $totalBookings ?></div>
                <div class="web-stat-label">Online Bookings</div>
            </div>
        </div>
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(255,193,7,0.12); color: #ffc107;">
                <i class="bi bi-lightning"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $todayBookings ?></div>
                <div class="web-stat-label">Today's Web Bookings</div>
            </div>
        </div>
    </div>
    
    <!-- Room Types Info -->
    <?php if (!empty($roomTypes)): ?>
    <div class="room-type-pills">
        <?php foreach ($roomTypes as $rt): ?>
        <span class="room-type-pill">
            <?= htmlspecialchars($rt['type_name']) ?> — <span class="count"><?= $rt['room_count'] ?> rooms</span>
            — Rp <?= number_format($rt['base_price'], 0, ',', '.') ?>/night
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Settings Tabs -->
    <div class="settings-tabs">
        <button class="settings-tab <?= $activeTab === 'general' ? 'active' : '' ?>" data-tab="general"><i class="bi bi-gear me-1"></i>General</button>
        <button class="settings-tab <?= $activeTab === 'hero' ? 'active' : '' ?>" data-tab="hero"><i class="bi bi-image me-1"></i>Hero Section</button>
        <button class="settings-tab <?= $activeTab === 'contact' ? 'active' : '' ?>" data-tab="contact"><i class="bi bi-telephone me-1"></i>Contact & Operations</button>
        <button class="settings-tab <?= $activeTab === 'rooms' ? 'active' : '' ?>" data-tab="rooms"><i class="bi bi-door-open me-1"></i>Room Descriptions</button>
        <button class="settings-tab <?= $activeTab === 'gallery' ? 'active' : '' ?>" data-tab="gallery"><i class="bi bi-images me-1"></i>Room Gallery</button>
        <button class="settings-tab <?= $activeTab === 'seo' ? 'active' : '' ?>" data-tab="seo"><i class="bi bi-search me-1"></i>SEO</button>
        <button class="settings-tab <?= $activeTab === 'appearance' ? 'active' : '' ?>" data-tab="appearance"><i class="bi bi-palette me-1"></i>Appearance</button>
        <button class="settings-tab <?= $activeTab === 'booking' ? 'active' : '' ?>" data-tab="booking"><i class="bi bi-calendar-check me-1"></i>Booking</button>
        <button class="settings-tab <?= $activeTab === 'destinations' ? 'active' : '' ?>" data-tab="destinations"><i class="bi bi-geo-alt me-1"></i>Destinations</button>
    </div>
    
    <!-- ============== GENERAL TAB ============== -->
    <div class="tab-content <?= $activeTab === 'general' ? 'active' : '' ?>" id="tab-general">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(111,66,193,0.15); color: #6f42c1;">
                    <i class="bi bi-gear-fill"></i>
                </div>
                <div>
                    <h5>General Settings</h5>
                    <small>Basic website configuration and status</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_general">
                    
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Website Status</label>
                                <div class="form-text">Enable or disable the public booking website</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="web_enabled" value="1" <?= $webSettings['web_enabled'] === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="web_site_name" class="form-control" value="<?= htmlspecialchars($webSettings['web_site_name']) ?>" required>
                        <div class="form-text">Displayed in browser tab and footer</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tagline</label>
                        <input type="text" name="web_tagline" class="form-control" value="<?= htmlspecialchars($webSettings['web_tagline']) ?>">
                        <div class="form-text">Short phrase under the site name</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Site Description</label>
                        <textarea name="web_description" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_description']) ?></textarea>
                        <div class="form-text">Used in about sections and meta tags</div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-globe2 me-1"></i>Favicon (Browser Tab Icon)</label>
                        <?php if (!empty($webSettings['web_favicon'])): ?>
                        <div class="mb-2 p-3 rounded" style="background: #f8f9fa; display: flex; align-items: center; gap: 16px;">
                            <div style="width: 48px; height: 48px; border: 2px solid #dee2e6; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #fff;">
                                <img src="../<?= htmlspecialchars($webSettings['web_favicon']) ?>" style="max-width: 32px; max-height: 32px;" alt="Favicon">
                            </div>
                            <div>
                                <div style="font-size: 13px; color: #333; font-weight: 500;">Current Favicon</div>
                                <div style="font-size: 11px; color: #888;"><?= htmlspecialchars($webSettings['web_favicon']) ?></div>
                            </div>
                            <label style="margin-left: auto; font-size: 12px; color: #dc3545; cursor: pointer;">
                                <input type="checkbox" name="remove_favicon" value="1" style="margin-right: 4px;">Remove
                            </label>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="web_favicon" class="form-control" accept="image/x-icon,image/png,image/svg+xml,image/jpeg,image/webp">
                        <div class="form-text">Upload an icon for the browser tab. Recommended: 32×32px or 64×64px PNG/ICO file.</div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-image me-1"></i>Website Logo (Navbar)</label>
                        <?php if (!empty($webSettings['web_logo'])): ?>
                        <div class="mb-2 p-3 rounded" style="background: #f8f9fa; display: flex; align-items: center; gap: 16px;">
                            <div style="width: 80px; height: 48px; border: 2px solid #dee2e6; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #2d2926;">
                                <img src="../<?= htmlspecialchars($webSettings['web_logo']) ?>" style="max-width: 72px; max-height: 40px; object-fit: contain;" alt="Logo">
                            </div>
                            <div>
                                <div style="font-size: 13px; color: #333; font-weight: 500;">Current Logo</div>
                                <div style="font-size: 11px; color: #888;"><?= htmlspecialchars($webSettings['web_logo']) ?></div>
                            </div>
                            <label style="margin-left: auto; font-size: 12px; color: #dc3545; cursor: pointer;">
                                <input type="checkbox" name="remove_logo" value="1" style="margin-right: 4px;">Remove
                            </label>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="web_logo" class="form-control" accept="image/png,image/svg+xml,image/jpeg,image/webp,image/gif">
                        <div class="form-text">Upload a logo to display before the hotel name in the navbar. Recommended: transparent PNG, max 150×50px.</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save General Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== HERO TAB ============== -->
    <div class="tab-content <?= $activeTab === 'hero' ? 'active' : '' ?>" id="tab-hero">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(0,123,255,0.15); color: #007bff;">
                    <i class="bi bi-image-fill"></i>
                </div>
                <div>
                    <h5>Hero Section</h5>
                    <small>Customize the main banner on the homepage</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_hero">
                    
                    <div class="mb-3">
                        <label class="form-label">Accent Text</label>
                        <input type="text" name="web_hero_accent" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_accent']) ?>" id="heroAccent">
                        <div class="form-text">Small italic text above the title</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Hero Title</label>
                        <input type="text" name="web_hero_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_title']) ?>" id="heroTitle">
                        <div class="form-text">Main heading. Use &lt;br&gt; for line breaks.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Hero Subtitle</label>
                        <textarea name="web_hero_subtitle" class="form-control" rows="2" id="heroSubtitle"><?= htmlspecialchars($webSettings['web_hero_subtitle']) ?></textarea>
                        <div class="form-text">Description paragraph below the title</div>
                    </div>
                    
                    <hr>
                    
                    <!-- Hero Background Image -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-image me-1"></i>Hero Background Image</label>
                        
                        <?php if (!empty($webSettings['web_hero_background'])): ?>
                        <div class="current-bg-preview mb-3" style="position: relative;">
                            <img src="../<?= htmlspecialchars($webSettings['web_hero_background']) ?>" 
                                 alt="Current Hero Background" 
                                 style="width: 100%; max-height: 200px; object-fit: cover; border-radius: 8px;">
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-danger" onclick="removeBackground()">
                                    <i class="bi bi-trash me-1"></i>Remove Background
                                </button>
                                <input type="hidden" name="remove_background" id="removeBackgroundInput" value="0">
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <input type="file" name="web_hero_background" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
                        <div class="form-text">
                            Upload hero background image (JPG, PNG, WEBP). Recommended size: 1920x1080px. 
                            <?php if (!empty($webSettings['web_hero_background'])): ?>
                                Leave empty to keep current image.
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Live Preview -->
                    <div class="preview-hero" id="heroPreview" style="--preview-primary: <?= htmlspecialchars($webSettings['web_primary_color']) ?>; --preview-accent: <?= htmlspecialchars($webSettings['web_accent_color']) ?>; <?php if (!empty($webSettings['web_hero_background'])): ?>background-image: url('../<?= htmlspecialchars($webSettings['web_hero_background']) ?>');<?php endif; ?>">
                        <p class="accent" id="previewAccent"><i><?= htmlspecialchars($webSettings['web_hero_accent']) ?></i></p>
                        <h3 id="previewTitle"><?= $webSettings['web_hero_title'] ?></h3>
                        <p id="previewSubtitle"><?= htmlspecialchars($webSettings['web_hero_subtitle']) ?></p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 mt-3">
                        <i class="bi bi-check-lg me-1"></i>Save Hero Section
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== CONTACT TAB ============== -->
    <div class="tab-content <?= $activeTab === 'contact' ? 'active' : '' ?>" id="tab-contact">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(40,167,69,0.15); color: #28a745;">
                    <i class="bi bi-telephone-fill"></i>
                </div>
                <div>
                    <h5>Contact & Operations</h5>
                    <small>Contact information and hotel operation hours</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_contact">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">WhatsApp Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                                <input type="text" name="web_whatsapp" class="form-control" value="<?= htmlspecialchars($webSettings['web_whatsapp']) ?>" placeholder="628xxxx">
                            </div>
                            <div class="form-text">International format without +</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Instagram Handle</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-instagram"></i></span>
                                <input type="text" name="web_instagram" class="form-control" value="<?= htmlspecialchars($webSettings['web_instagram']) ?>" placeholder="username">
                            </div>
                            <div class="form-text">Without @</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="web_email" class="form-control" value="<?= htmlspecialchars($webSettings['web_email']) ?>">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="text" name="web_phone" class="form-control" value="<?= htmlspecialchars($webSettings['web_phone']) ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="web_address" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_address']) ?></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="text-muted mb-3"><i class="bi bi-clock me-1"></i>Operation Hours</h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check-in Time</label>
                            <input type="time" name="web_checkin_time" class="form-control" value="<?= htmlspecialchars($webSettings['web_checkin_time']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check-out Time</label>
                            <input type="time" name="web_checkout_time" class="form-control" value="<?= htmlspecialchars($webSettings['web_checkout_time']) ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Contact & Operations
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== ROOMS TAB ============== -->
    <div class="tab-content <?= $activeTab === 'rooms' ? 'active' : '' ?>" id="tab-rooms">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(255,193,7,0.15); color: #ffc107;">
                    <i class="bi bi-door-open-fill"></i>
                </div>
                <div>
                    <h5>Room Descriptions</h5>
                    <small>Website text for each room type (room data syncs from the hotel system automatically)</small>
                </div>
            </div>
            <div class="settings-card-body">
                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Note:</strong> Room names, prices, and availability are synced in real-time from your hotel management system (frontdesk). 
                    Only the descriptions below are managed here for the website display.
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_rooms">
                    
                    <div class="mb-4">
                        <label class="form-label"><span class="badge bg-primary me-2">👑</span>King Room Description</label>
                        <textarea name="web_room_desc_king" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_room_desc_king']) ?></textarea>
                        <div class="form-text">Shown on the rooms page for King-type rooms</div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label"><span class="badge bg-success me-2">🌙</span>Queen Room Description</label>
                        <textarea name="web_room_desc_queen" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_room_desc_queen']) ?></textarea>
                        <div class="form-text">Shown on the rooms page for Queen-type rooms</div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label"><span class="badge bg-info me-2">🛏️</span>Twin Room Description</label>
                        <textarea name="web_room_desc_twin" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_room_desc_twin']) ?></textarea>
                        <div class="form-text">Shown on the rooms page for Twin-type rooms</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Room Descriptions
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== ROOM GALLERY TAB ============== -->
    <div class="tab-content <?= $activeTab === 'gallery' ? 'active' : '' ?>" id="tab-gallery">
        <?php 
        $roomGalleries = [
            'king' => json_decode($webSettings['web_room_gallery_king'] ?? '[]', true) ?: [],
            'queen' => json_decode($webSettings['web_room_gallery_queen'] ?? '[]', true) ?: [],
            'twin' => json_decode($webSettings['web_room_gallery_twin'] ?? '[]', true) ?: []
        ];
        $roomPrimaries = [
            'king' => $webSettings['web_room_primary_king'] ?? '',
            'queen' => $webSettings['web_room_primary_queen'] ?? '',
            'twin' => $webSettings['web_room_primary_twin'] ?? ''
        ];
        $roomIcons = ['king' => '👑', 'queen' => '🌙', 'twin' => '🛏️'];
        $roomNames = ['king' => 'King Room', 'queen' => 'Queen Room', 'twin' => 'Twin Room'];
        foreach ($roomGalleries as $roomType => $gallery):
        ?>
        <div class="settings-card mb-4">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(111,66,193,0.15); color: #6f42c1;">
                    <span style="font-size: 1.4rem;"><?= $roomIcons[$roomType] ?></span>
                </div>
                <div>
                    <h5><?= $roomNames[$roomType] ?> Gallery</h5>
                    <small>Upload and manage photos for <?= $roomNames[$roomType] ?></small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_room_gallery">
                    <input type="hidden" name="room_type" value="<?= $roomType ?>">
                    
                    <?php 
                    $currentPrimary = $roomPrimaries[$roomType] ?? '';
                    if (!empty($gallery)): ?>
                    <p class="text-muted mb-2" style="font-size:0.85rem;"><i class="bi bi-star me-1"></i>Pilih <strong>Tampilan Utama</strong> (gambar yang ditampilkan pertama di website)</p>
                    <div class="row g-3 mb-4">
                        <?php foreach ($gallery as $imgPath): 
                            $isPrimary = ($imgPath === $currentPrimary);
                        ?>
                        <div class="col-md-3 col-6">
                            <div class="gallery-item" style="border: 2px solid <?= $isPrimary ? '#c8a45e' : 'transparent' ?>; border-radius: 10px; padding: 6px; position: relative;">
                                <?php if ($isPrimary): ?>
                                <span style="position:absolute;top:10px;right:10px;background:#c8a45e;color:#fff;font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600;z-index:1;">UTAMA</span>
                                <?php endif; ?>
                                <img src="../<?= htmlspecialchars($imgPath) ?>" alt="<?= $roomNames[$roomType] ?>" style="width: 100%; height: 150px; object-fit: cover; border-radius: 8px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 8px; font-size: 0.85rem;">
                                    <label class="text-primary" style="cursor:pointer;">
                                        <input type="radio" name="primary_image" value="<?= htmlspecialchars($imgPath) ?>" <?= $isPrimary ? 'checked' : '' ?>>
                                        <i class="bi bi-star-fill ms-1"></i> Utama
                                    </label>
                                    <label class="text-danger" style="cursor:pointer;">
                                        <input type="checkbox" name="delete_images[]" value="<?= htmlspecialchars($imgPath) ?>">
                                        <i class="bi bi-trash ms-1"></i> Hapus
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>Belum ada gambar untuk tipe kamar ini.
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-upload me-1"></i>Upload New Images</label>
                        <input type="file" name="room_images[]" class="form-control gallery-file-input" accept="image/jpeg,image/jpg,image/png,image/webp" multiple>
                        <div class="form-text">Pilih beberapa gambar sekaligus (JPG, PNG, WEBP). Recommended: 800x600px. Max 10MB per file.</div>
                        <div class="gallery-preview" style="display:none; margin-top:10px;"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 gallery-submit-btn">
                        <i class="bi bi-check-lg me-1"></i>Save <?= $roomNames[$roomType] ?> Gallery
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- ============== SEO TAB ============== -->
    <div class="tab-content <?= $activeTab === 'seo' ? 'active' : '' ?>" id="tab-seo">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(255,87,51,0.15); color: #ff5733;">
                    <i class="bi bi-search"></i>
                </div>
                <div>
                    <h5>SEO Settings</h5>
                    <small>Search engine optimization for better visibility</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_seo">
                    
                    <div class="mb-3">
                        <label class="form-label">Meta Title</label>
                        <input type="text" name="web_meta_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_meta_title']) ?>" maxlength="70">
                        <div class="form-text">
                            <span id="titleCharCount"><?= strlen($webSettings['web_meta_title']) ?></span>/70 characters — 
                            Recommended: 50-60 characters
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Meta Description</label>
                        <textarea name="web_meta_description" class="form-control" rows="3" maxlength="160"><?= htmlspecialchars($webSettings['web_meta_description']) ?></textarea>
                        <div class="form-text">
                            <span id="descCharCount"><?= strlen($webSettings['web_meta_description']) ?></span>/160 characters — 
                            Recommended: 120-155 characters
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Keywords</label>
                        <textarea name="web_meta_keywords" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_meta_keywords']) ?></textarea>
                        <div class="form-text">Comma-separated keywords. Less important for modern SEO but still useful.</div>
                    </div>
                    
                    <!-- Google Preview -->
                    <div class="card bg-light p-3 mb-3">
                        <small class="text-muted mb-1">Google Search Preview:</small>
                        <div style="font-family: Arial, sans-serif;">
                            <div style="color: #1a0dab; font-size: 18px;"><?= htmlspecialchars($webSettings['web_meta_title']) ?></div>
                            <div style="color: #006621; font-size: 14px;">narayanakarimunjawa.com</div>
                            <div style="color: #545454; font-size: 13px;"><?= htmlspecialchars($webSettings['web_meta_description']) ?></div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save SEO Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== APPEARANCE TAB ============== -->
    <div class="tab-content <?= $activeTab === 'appearance' ? 'active' : '' ?>" id="tab-appearance">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(232,62,140,0.15); color: #e83e8c;">
                    <i class="bi bi-palette-fill"></i>
                </div>
                <div>
                    <h5>Appearance</h5>
                    <small>Website color scheme and visual customization</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_appearance">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Primary Color (Navy)</label>
                            <div class="color-preview">
                                <input type="color" name="web_primary_color" class="color-swatch" value="<?= htmlspecialchars($webSettings['web_primary_color']) ?>" id="primaryColor">
                                <input type="text" class="form-control" value="<?= htmlspecialchars($webSettings['web_primary_color']) ?>" id="primaryColorText" style="max-width: 120px;">
                            </div>
                            <div class="form-text">Main background and text color</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Accent Color (Gold)</label>
                            <div class="color-preview">
                                <input type="color" name="web_accent_color" class="color-swatch" value="<?= htmlspecialchars($webSettings['web_accent_color']) ?>" id="accentColor">
                                <input type="text" class="form-control" value="<?= htmlspecialchars($webSettings['web_accent_color']) ?>" id="accentColorText" style="max-width: 120px;">
                            </div>
                            <div class="form-text">Buttons, highlights, and accents</div>
                        </div>
                    </div>
                    
                    <!-- Color Preview -->
                    <div class="card p-3 mb-3" id="colorPreviewCard">
                        <small class="text-muted mb-2">Preview:</small>
                        <div class="d-flex gap-3 align-items-center">
                            <div style="background: <?= htmlspecialchars($webSettings['web_primary_color']) ?>; color: white; padding: 12px 24px; border-radius: 8px; font-weight: 600;" id="previewPrimary">
                                Primary Button
                            </div>
                            <div style="background: <?= htmlspecialchars($webSettings['web_accent_color']) ?>; color: #1a1a2e; padding: 12px 24px; border-radius: 8px; font-weight: 600;" id="previewAccentBtn">
                                Accent Button
                            </div>
                            <div style="background: #faf8f4; padding: 12px 24px; border-radius: 8px; border: 2px solid <?= htmlspecialchars($webSettings['web_accent_color']) ?>; color: <?= htmlspecialchars($webSettings['web_primary_color']) ?>; font-weight: 600;" id="previewOutline">
                                Outline
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Appearance
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== BOOKING TAB ============== -->
    <div class="tab-content <?= $activeTab === 'booking' ? 'active' : '' ?>" id="tab-booking">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(23,162,184,0.15); color: #17a2b8;">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
                <div>
                    <h5>Booking Settings</h5>
                    <small>Configure online booking rules and policies</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_booking">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Advance Booking (Days)</label>
                            <input type="number" name="web_max_advance_days" class="form-control" value="<?= htmlspecialchars($webSettings['web_max_advance_days']) ?>" min="30" max="730">
                            <div class="form-text">How far in advance guests can book</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Minimum Stay (Nights)</label>
                            <input type="number" name="web_min_stay_nights" class="form-control" value="<?= htmlspecialchars($webSettings['web_min_stay_nights']) ?>" min="1" max="30">
                            <div class="form-text">Minimum nights per booking</div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Booking Notice / Policy</label>
                        <textarea name="web_booking_notice" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_booking_notice']) ?></textarea>
                        <div class="form-text">Displayed on the confirmation page for guests</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Booking Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== DESTINATIONS TAB ============== -->
    <?php $destinations = json_decode($webSettings['web_destinations'] ?? '[]', true) ?: []; ?>
    <div class="tab-content <?= $activeTab === 'destinations' ? 'active' : '' ?>" id="tab-destinations">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(32,201,151,0.15); color: #20c997;">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div>
                    <h5>Destinations & Blog</h5>
                    <small>Manage Karimunjawa destination guides shown on the website</small>
                </div>
            </div>
            <div class="settings-card-body">
                <!-- Add New Destination -->
                <div class="p-3 mb-4 rounded" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                    <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Add New Destination</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_destinations">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" name="dest_title" class="form-control" placeholder="e.g. Pantai Ujung Gelam" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subtitle</label>
                                <input type="text" name="dest_subtitle" class="form-control" placeholder="e.g. The Most Beautiful Sunset Beach">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Content / Description</label>
                            <textarea name="dest_content" class="form-control" rows="4" placeholder="Write a detailed description about this destination..."></textarea>
                            <div class="form-text">Support HTML tags for formatting. Write engaging content about the destination.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cover Image</label>
                            <input type="file" name="dest_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">Recommended: 800×500px landscape photo</div>
                        </div>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plus-lg me-1"></i>Add Destination
                        </button>
                    </form>
                </div>

                <?php if (empty($destinations)): ?>
                <div class="text-center p-4" style="color: #999;">
                    <i class="bi bi-geo-alt" style="font-size: 48px; opacity: 0.3;"></i>
                    <p class="mt-2">No destinations added yet. Add your first Karimunjawa destination guide above.</p>
                </div>
                <?php else: ?>
                <!-- Existing Destinations -->
                <h6 class="mb-3"><i class="bi bi-list-ul me-1"></i>Current Destinations (<?= count($destinations) ?>)</h6>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_destinations">
                    <?php foreach ($destinations as $di => $dest): ?>
                    <div class="p-3 mb-3 rounded" style="background: #f8f9fa; border: 1px solid #dee2e6;">
                        <div class="d-flex align-items-start gap-3">
                            <?php if (!empty($dest['image'])): ?>
                            <div style="width: 120px; min-width: 120px; height: 80px; border-radius: 8px; overflow: hidden;">
                                <img src="../<?= htmlspecialchars($dest['image']) ?>" style="width: 100%; height: 100%; object-fit: cover;" alt="">
                            </div>
                            <?php endif; ?>
                            <div style="flex: 1;">
                                <input type="hidden" name="dest_update_id[]" value="<?= htmlspecialchars($dest['id']) ?>">
                                <div class="row mb-2">
                                    <div class="col-md-5">
                                        <input type="text" name="dest_update_title[]" class="form-control form-control-sm" value="<?= htmlspecialchars($dest['title']) ?>" placeholder="Title">
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" name="dest_update_subtitle[]" class="form-control form-control-sm" value="<?= htmlspecialchars($dest['subtitle'] ?? '') ?>" placeholder="Subtitle">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" name="dest_update_order[]" class="form-control form-control-sm" value="<?= (int)($dest['order'] ?? $di + 1) ?>" min="1" title="Display order">
                                    </div>
                                    <div class="col-md-1 d-flex align-items-center">
                                        <label title="Active" style="cursor: pointer;">
                                            <input type="checkbox" name="dest_update_active[]" value="<?= htmlspecialchars($dest['id']) ?>" <?= ($dest['active'] ?? true) ? 'checked' : '' ?>>
                                            <i class="bi bi-eye ms-1"></i>
                                        </label>
                                    </div>
                                </div>
                                <textarea name="dest_update_content[]" class="form-control form-control-sm mb-2" rows="2" placeholder="Description"><?= htmlspecialchars($dest['content'] ?? '') ?></textarea>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="file" name="dest_update_image_<?= htmlspecialchars($dest['id']) ?>" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" style="max-width: 250px;">
                                    <label style="font-size: 12px; color: #dc3545; cursor: pointer; white-space: nowrap;">
                                        <input type="checkbox" name="delete_dest[]" value="<?= htmlspecialchars($dest['id']) ?>" style="margin-right: 3px;">
                                        <i class="bi bi-trash"></i> Delete
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save All Destinations
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
</div>

<script>
// Tab switching
document.querySelectorAll('.settings-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});

// Hero live preview
['heroAccent', 'heroTitle', 'heroSubtitle'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', function() {
            if (id === 'heroAccent') document.getElementById('previewAccent').innerHTML = '<i>' + this.value + '</i>';
            if (id === 'heroTitle') document.getElementById('previewTitle').innerHTML = this.value;
            if (id === 'heroSubtitle') document.getElementById('previewSubtitle').textContent = this.value;
        });
    }
});

// Color sync
function syncColors() {
    const primary = document.getElementById('primaryColor');
    const accent = document.getElementById('accentColor');
    const primaryText = document.getElementById('primaryColorText');
    const accentText = document.getElementById('accentColorText');
    
    if (primary && primaryText) {
        primary.addEventListener('input', () => {
            primaryText.value = primary.value;
            updateColorPreview();
        });
        primaryText.addEventListener('input', () => {
            primary.value = primaryText.value;
            updateColorPreview();
        });
    }
    if (accent && accentText) {
        accent.addEventListener('input', () => {
            accentText.value = accent.value;
            updateColorPreview();
        });
        accentText.addEventListener('input', () => {
            accent.value = accentText.value;
            updateColorPreview();
        });
    }
}

function updateColorPreview() {
    const p = document.getElementById('primaryColor')?.value || '#0c2340';
    const a = document.getElementById('accentColor')?.value || '#c8a45e';
    const previewPrimary = document.getElementById('previewPrimary');
    const previewAccent = document.getElementById('previewAccentBtn');
    const previewOutline = document.getElementById('previewOutline');
    if (previewPrimary) previewPrimary.style.background = p;
    if (previewAccent) previewAccent.style.background = a;
    if (previewOutline) {
        previewOutline.style.borderColor = a;
        previewOutline.style.color = p;
    }
    // Update hero preview too
    const heroPreview = document.getElementById('heroPreview');
    if (heroPreview) {
        heroPreview.style.setProperty('--preview-primary', p);
        heroPreview.style.setProperty('--preview-accent', a);
    }
}

syncColors();

// SEO character counters
document.querySelector('[name="web_meta_title"]')?.addEventListener('input', function() {
    document.getElementById('titleCharCount').textContent = this.value.length;
});
document.querySelector('[name="web_meta_description"]')?.addEventListener('input', function() {
    document.getElementById('descCharCount').textContent = this.value.length;
});

// Remove background image handler
function removeBackground() {
    if (confirm('Are you sure you want to remove the hero background image?')) {
        document.getElementById('removeBackgroundInput').value = '1';
        // Find the hero form (the one with action=save_hero)
        document.querySelector('input[name="action"][value="save_hero"]').closest('form').submit();
    }
}

// ============ GALLERY UPLOAD LOADING & PREVIEW ============

// Loading overlay with progress
const overlay = document.createElement('div');
overlay.id = 'uploadOverlay';
overlay.innerHTML = `
    <div style="background:rgba(0,0,0,0.8);position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px;padding:20px;">
        <div style="width:50px;height:50px;border:4px solid rgba(255,255,255,0.3);border-top:4px solid #c8a45e;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
        <div style="color:#fff;font-size:16px;font-weight:500;" id="uploadText">Uploading images...</div>
        <div style="width:280px;height:6px;background:rgba(255,255,255,0.2);border-radius:3px;overflow:hidden;">
            <div id="uploadProgress" style="height:100%;background:#c8a45e;width:0%;transition:width 0.3s;border-radius:3px;"></div>
        </div>
        <div style="color:rgba(255,255,255,0.6);font-size:13px;" id="uploadPercent">0%</div>
        <div style="color:rgba(255,255,255,0.4);font-size:12px;">Jangan tutup halaman ini</div>
    </div>
`;
overlay.style.display = 'none';
document.body.appendChild(overlay);

// Spinner + preview CSS
const spinStyle = document.createElement('style');
spinStyle.textContent = `
    @keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
    .gallery-preview{display:flex;flex-wrap:wrap;gap:8px;padding:10px;background:#f8f9fa;border-radius:8px;}
    .gallery-preview img{width:80px;height:60px;object-fit:cover;border-radius:6px;border:2px solid #e0e0e0;}
    .upload-result{padding:12px;border-radius:8px;margin-top:10px;font-size:14px;}
    .upload-result.success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
    .upload-result.error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
`;
document.head.appendChild(spinStyle);

// File preview on select
document.querySelectorAll('.gallery-file-input').forEach(input => {
    input.addEventListener('change', function() {
        const preview = this.closest('.mb-3').querySelector('.gallery-preview');
        preview.innerHTML = '';
        if (this.files.length > 0) {
            preview.style.display = 'flex';
            const label = document.createElement('div');
            label.style.cssText = 'width:100%;font-size:13px;color:#666;margin-bottom:4px;';
            label.innerHTML = '<i class="bi bi-images"></i> <strong>' + this.files.length + ' file</strong> dipilih:';
            preview.appendChild(label);
            
            let totalSize = 0;
            Array.from(this.files).forEach(file => {
                totalSize += file.size;
                if (file.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.title = file.name + ' (' + (file.size/1024/1024).toFixed(1) + 'MB)';
                    preview.appendChild(img);
                }
            });
            const sizeLabel = document.createElement('div');
            sizeLabel.style.cssText = 'width:100%;font-size:12px;color:#999;';
            sizeLabel.textContent = 'Total: ' + (totalSize/1024/1024).toFixed(1) + 'MB';
            preview.appendChild(sizeLabel);
        } else {
            preview.style.display = 'none';
        }
    });
});

// AJAX upload with progress
document.querySelectorAll('.gallery-submit-btn').forEach(btn => {
    btn.closest('form').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const form = this;
        const fileInput = form.querySelector('.gallery-file-input');
        const hasFiles = fileInput && fileInput.files.length > 0;
        const hasDelete = form.querySelectorAll('input[name="delete_images[]"]:checked').length > 0;
        const hasPrimary = form.querySelector('input[name="primary_image"]:checked');
        
        if (!hasFiles && !hasDelete && !hasPrimary) {
            alert('Pilih file untuk upload, atau pilih gambar untuk dihapus.');
            return;
        }
        
        // Show overlay
        overlay.style.display = 'block';
        const uploadText = document.getElementById('uploadText');
        const uploadProgress = document.getElementById('uploadProgress');
        const uploadPercent = document.getElementById('uploadPercent');
        
        if (hasFiles) {
            uploadText.textContent = 'Uploading ' + fileInput.files.length + ' image(s)...';
        } else {
            uploadText.textContent = 'Processing...';
        }
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading...';
        
        // Send via AJAX
        const formData = new FormData(form);
        const xhr = new XMLHttpRequest();
        
        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                uploadProgress.style.width = pct + '%';
                uploadPercent.textContent = pct + '%';
                if (pct >= 100) {
                    uploadText.textContent = 'Processing on server...';
                }
            }
        });
        
        xhr.addEventListener('load', function() {
            overlay.style.display = 'none';
            uploadProgress.style.width = '0%';
            uploadPercent.textContent = '0%';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Gallery';
            
            if (xhr.status === 200) {
                // Reload page to gallery tab
                window.location.href = window.location.pathname + '?tab=gallery&msg=success';
            } else {
                const result = document.createElement('div');
                result.className = 'upload-result error';
                result.innerHTML = '<i class="bi bi-x-circle me-1"></i>Upload gagal. Coba lagi.';
                form.appendChild(result);
                setTimeout(() => result.remove(), 5000);
            }
        });
        
        xhr.addEventListener('error', function() {
            overlay.style.display = 'none';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Gallery';
            
            const result = document.createElement('div');
            result.className = 'upload-result error';
            result.innerHTML = '<i class="bi bi-x-circle me-1"></i>Koneksi gagal. Periksa internet dan coba lagi.';
            form.appendChild(result);
            setTimeout(() => result.remove(), 5000);
        });
        
        xhr.addEventListener('timeout', function() {
            overlay.style.display = 'none';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Gallery';
            
            const result = document.createElement('div');
            result.className = 'upload-result error';
            result.innerHTML = '<i class="bi bi-x-circle me-1"></i>Timeout. Coba upload lebih sedikit gambar.';
            form.appendChild(result);
            setTimeout(() => result.remove(), 5000);
        });
        
        xhr.open('POST', window.location.pathname);
        xhr.timeout = 300000; // 5 menit timeout
        xhr.send(formData);
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
