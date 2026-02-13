<?php
/**
 * Developer Panel - Developer Settings
 * Configure developer name, logo, login background, WhatsApp, footer text
 */

require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions.php';

$devAuth = new DevAuth();
$devAuth->requireLogin();

$db = Database::getInstance();
$pageTitle = 'Developer Settings';
$currentPage = 'developer-settings';

$error = '';
$success = '';

// Get settings from database
$loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
$currentLoginBg = $loginBgSetting['setting_value'] ?? null;

$loginLogoSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_logo'");
$currentLoginLogo = $loginLogoSetting['setting_value'] ?? null;

$faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
$currentFavicon = $faviconSetting['setting_value'] ?? null;

$waSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'developer_whatsapp'");
$currentWA = $waSetting['setting_value'] ?? '';

$footerCopyrightSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_copyright'");
$currentFooterCopyright = $footerCopyrightSetting['setting_value'] ?? '';

$footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
$currentFooterVersion = $footerVersionSetting['setting_value'] ?? '';

// Read current config
$configFile = BASE_PATH . '/config/config.php';
$configContent = file_get_contents($configFile);

// Extract current values
preg_match("/define\('DEVELOPER_NAME',\s*'([^']*)'\);/", $configContent, $nameMatch);
preg_match("/define\('DEVELOPER_LOGO',\s*'([^']*)'\);/", $configContent, $logoMatch);

$currentDevName = $nameMatch[1] ?? 'DevTeam Studio';
$currentDevLogo = $logoMatch[1] ?? 'assets/img/developer-logo.png';

// Get businesses for reset data functionality
$businesses = [];
try {
    $businessResult = $db->fetchAll("SELECT business_id, business_name, business_type FROM businesses WHERE status = 'active' ORDER BY business_name");
    foreach ($businessResult as $business) {
        $businesses[$business['business_id']] = $business;
    }
} catch (Exception $e) {
    // If no businesses table, use defaults
    $businesses = [
        '1' => ['business_id' => '1', 'business_name' => 'Default Business', 'business_type' => 'hotel'],
        '2' => ['business_id' => '2', 'business_name' => 'Demo Restaurant', 'business_type' => 'restaurant']
    ];
}

// Check if function exists, if not create a simple version
if (!function_exists('getBusinessDisplayName')) {
    function getBusinessDisplayName($businessId) {
        global $businesses;
        if (isset($businesses[$businessId])) {
            $b = $businesses[$businessId];
            return $b['business_name'] . ' (' . ucfirst($b['business_type']) . ')';
        }
        return 'Business #' . $businessId;
    }
}

$selectedBusiness = $_POST['reset_business_id'] ?? (array_key_first($businesses) ?: '1');
$resetResult = null;

// Handle form submission for name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['developer_name'])) {
    $newName = trim($_POST['developer_name']);
    
    if (!empty($newName)) {
        $newConfigContent = preg_replace(
            "/define\('DEVELOPER_NAME',\s*'[^']*'\);/",
            "define('DEVELOPER_NAME', '" . addslashes($newName) . "');",
            $configContent
        );
        
        if (file_put_contents($configFile, $newConfigContent)) {
            $success = 'Nama developer berhasil diupdate!';
            $currentDevName = $newName;
            $configContent = $newConfigContent;
        } else {
            $error = 'Gagal update config file. Periksa permission folder.';
        }
    } else {
        $error = 'Nama developer tidak boleh kosong.';
    }
}

// Handle login background upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['login_background']) && $_FILES['login_background']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['login_background'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 2 * 1024 * 1024;
    
    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG atau PNG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 2MB.';
    } else {
        $uploadDir = BASE_PATH . '/uploads/backgrounds/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'login-bg.' . $extension;
        $uploadPath = $uploadDir . $filename;
        
        foreach (glob($uploadDir . 'login-bg.*') as $oldFile) {
            unlink($oldFile);
        }
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('login_background', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$filename, $filename]);
            $success = 'Background login berhasil diupload!';
            $currentLoginBg = $filename;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle login logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['login_logo']) && $_FILES['login_logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['login_logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/svg+xml'];
    $maxSize = 1 * 1024 * 1024;
    
    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, atau SVG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 1MB.';
    } else {
        $uploadDir = BASE_PATH . '/uploads/logos/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'login-logo.' . $extension;
        $uploadPath = $uploadDir . $filename;
        
        foreach (glob($uploadDir . 'login-logo.*') as $oldFile) {
            unlink($oldFile);
        }
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('login_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$filename, $filename]);
            $success = 'Logo login berhasil diupload!';
            $currentLoginLogo = $filename;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete login logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_login_logo'])) {
    $uploadDir = BASE_PATH . '/uploads/logos/';
    foreach (glob($uploadDir . 'login-logo.*') as $oldFile) {
        unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'login_logo'");
    $success = 'Logo login berhasil dihapus!';
    $currentLoginLogo = null;
}

// Handle favicon upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['site_favicon'];
    $allowedTypes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'];
    $maxSize = 500 * 1024; // 500KB
    
    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan ICO, PNG, atau SVG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 500KB.';
    } else {
        $uploadDir = BASE_PATH . '/uploads/icons/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'favicon.' . $extension;
        $uploadPath = $uploadDir . $filename;
        
        foreach (glob($uploadDir . 'favicon.*') as $oldFile) {
            unlink($oldFile);
        }
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('site_favicon', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$filename, $filename]);
            $success = 'Favicon berhasil diupload!';
            $currentFavicon = $filename;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete favicon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_favicon'])) {
    $uploadDir = BASE_PATH . '/uploads/icons/';
    foreach (glob($uploadDir . 'favicon.*') as $oldFile) {
        unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'site_favicon'");
    $success = 'Favicon berhasil dihapus!';
    $currentFavicon = null;
}

// Handle WhatsApp number update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whatsapp_number'])) {
    $waNumber = trim($_POST['whatsapp_number']);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('developer_whatsapp', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$waNumber, $waNumber]);
    $success = 'Nomor WhatsApp berhasil disimpan!';
    $currentWA = $waNumber;
}

// Handle footer text update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['footer_copyright'])) {
    $copyright = trim($_POST['footer_copyright']);
    $version = trim($_POST['footer_version']);
    
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_copyright', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$copyright, $copyright]);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_version', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$version, $version]);
    
    $success = 'Teks footer berhasil diupdate!';
    $currentFooterCopyright = $copyright;
    $currentFooterVersion = $version;
}

// Handle reset data submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_data_submit'])) {
    $businessId = $_POST['reset_business_id'] ?? '';
    $resetTypes = $_POST['reset_type'] ?? [];
    
    if (!empty($businessId) && !empty($resetTypes)) {
        $resetResult = [];
        
        foreach ($resetTypes as $type) {
            // Call reset API for each type
            $postData = json_encode([
                'business_id' => $businessId,
                'reset_type' => $type
            ]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => $postData
                ]
            ]);
            
            $response = @file_get_contents(BASE_URL . '/api/reset-business-data.php', false, $context);
            $httpCode = 200; // Default success
            
            $resetResult[$type] = [
                'http' => $httpCode,
                'response' => $response ?: json_encode(['success' => false, 'message' => 'No response from API'])
            ];
        }
        
        $success = 'Reset data telah dijalankan untuk ' . count($resetTypes) . ' jenis data pada ' . getBusinessDisplayName($businessId) . '. Lihat hasil detail di bawah.';
    } else {
        $error = 'Pilih bisnis dan minimal 1 jenis data untuk direset.';
    }
}

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['developer_logo']) && $_FILES['developer_logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['developer_logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/svg+xml'];
    $maxSize = 1 * 1024 * 1024;
    
    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, SVG, atau GIF.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 1MB.';
    } else {
        $uploadDir = BASE_PATH . '/assets/img/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'developer-logo.' . $extension;
        $uploadPath = $uploadDir . $filename;
        
        foreach (glob($uploadDir . 'developer-logo.*') as $oldFile) {
            unlink($oldFile);
        }
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $newLogoPath = 'assets/img/' . $filename;
            $newConfigContent = preg_replace(
                "/define\('DEVELOPER_LOGO',\s*'[^']*'\);/",
                "define('DEVELOPER_LOGO', '" . $newLogoPath . "');",
                $configContent
            );
            
            if (file_put_contents($configFile, $newConfigContent)) {
                $success = 'Logo developer berhasil diupload!';
                $currentDevLogo = $newLogoPath;
            } else {
                $error = 'File uploaded tapi gagal update config.';
            }
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .settings-card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        margin-bottom: 0.8rem;
        overflow: hidden;
    }
    .settings-card-header {
        padding: 0.6rem 0.9rem;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .settings-card-header .icon {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
    }
    .settings-card-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 0.85rem;
    }
    .settings-card-header small {
        color: #888;
        font-weight: 400;
        font-size: 0.7rem;
    }
    .settings-card-body {
        padding: 0.9rem;
    }
    .preview-box {
        background: #f8f9fa;
        border: 1px dashed #dee2e6;
        border-radius: 6px;
        padding: 0.8rem;
        text-align: center;
    }
    .preview-box img {
        max-width: 90px;
        max-height: 90px;
        border-radius: 6px;
    }
    .current-value {
        background: #f8f9fa;
        border-left: 2px solid var(--dev-primary);
        padding: 0.5rem 0.7rem;
        border-radius: 0 4px 4px 0;
        margin-top: 0.6rem;
    }
    .current-value small {
        color: #888;
        font-size: 0.65rem;
    }
    .current-value strong {
        display: block;
        color: #333;
        margin-top: 0.2rem;
        font-size: 0.8rem;
    }
    
    /* Compact form elements */
    .form-control {
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
        border-radius: 4px;
    }
    
    .btn {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
        border-radius: 4px;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
    }
    
    /* Compact spacing */
    .mb-3 {
        margin-bottom: 0.6rem !important;
    }
    
    .mb-4 {
        margin-bottom: 0.8rem !important;
    }
    
    /* Smaller alert boxes */
    .alert {
        padding: 0.6rem 0.8rem;
        font-size: 0.8rem;
    }
    
    /* Smaller headings in developer panel */
    h4 {
        font-size: 1.1rem;
    }
    
    h5 {
        font-size: 0.9rem;
    }
    
    /* Compact text */
    .text-muted {
        font-size: 0.7rem !important;
    }
    
    /* Compact checkboxes and form checks */
    .form-check {
        margin-bottom: 0.4rem;
    }
    
    .form-check-label {
        font-size: 0.8rem;
        padding-left: 0.3rem;
    }
    
    .form-check-input {
        margin-top: 0.2rem;
    }
    
    /* Reset data specific styling */
    .reset-data-grid {
        gap: 0.4rem;
    }
    
    .reset-data-grid .col-md-6 {
        padding: 0.2rem;
    }
    
    /* Ultra compact container */
    .container-fluid {
        padding: 1rem 0.8rem !important;
        max-width: 95% !important;
    }
    
    /* Compact rows and columns */
    .row {
        margin: 0 -0.3rem;
    }
    
    .col-lg-6 {
        padding: 0 0.3rem;
    }
    
    /* Smaller page header */
    .d-flex.justify-content-between {
        margin-bottom: 0.8rem !important;
    }
    
    /* More compact layout */
    .py-4 {
        padding-top: 0.8rem !important;
        padding-bottom: 0.8rem !important;
    }
    
    /* Tighter card spacing */
    .settings-card + .settings-card {
        margin-top: 0.5rem;
    }
    
    /* Compact grid system */
    @media (min-width: 992px) {
        .col-lg-6:first-child {
            margin-bottom: 0.5rem;
        }
    }
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-sliders me-2"></i>Developer Settings</h4>
            <p class="text-muted mb-0" style="font-size: 0.875rem;">Konfigurasi developer name, logo, background login, WhatsApp, dan footer</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Warning Notice -->
    <div class="alert alert-warning mb-4" style="border-left: 4px solid #f59e0b;">
        <div class="d-flex align-items-start">
            <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
            <div>
                <strong>Perhatian:</strong> Beberapa perubahan akan mengupdate file <code>config/config.php</code>. 
                Pastikan file memiliki permission writable pada hosting.
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Column 1: Developer Name & Logo -->
        <div class="col-lg-6">
            
            <!-- Developer Name -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background: rgba(111,66,193,0.15); color: var(--dev-primary);">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div>
                        <h5>Nama Developer</h5>
                        <small>Tampil di footer sidebar</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Nama Developer</label>
                            <input type="text" name="developer_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($currentDevName); ?>" 
                                   required maxlength="50" placeholder="DevTeam Studio">
                            <div class="form-text">Maksimal 50 karakter</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check-lg me-1"></i>Simpan Nama
                        </button>
                    </form>
                    <div class="current-value">
                        <small>Nama saat ini:</small>
                        <strong><?php echo htmlspecialchars($currentDevName); ?></strong>
                    </div>
                </div>
            </div>
            
            <!-- Developer Logo -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background: rgba(239,68,68,0.15); color: var(--dev-danger);">
                        <i class="bi bi-image"></i>
                    </div>
                    <div>
                        <h5>Logo Developer</h5>
                        <small>Ukuran rekomendasi 100x100px</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <div class="preview-box mb-3">
                        <?php
                        $logoFullPath = BASE_PATH . '/' . $currentDevLogo;
                        if (file_exists($logoFullPath)):
                        ?>
                            <img src="<?php echo BASE_URL . '/' . $currentDevLogo; ?>?v=<?php echo filemtime($logoFullPath); ?>" alt="Developer Logo">
                        <?php else: ?>
                            <div style="width:80px;height:80px;background:var(--dev-primary);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:white;font-size:1.5rem;font-weight:700;">&lt;/&gt;</div>
                        <?php endif; ?>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Upload Logo Baru</label>
                            <input type="file" name="developer_logo" class="form-control" accept="image/*" required>
                            <div class="form-text">Format: JPG, PNG, SVG, GIF • Maksimal 1MB</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-upload me-1"></i>Upload Logo
                        </button>
                    </form>
                </div>
            </div>
            
        </div>
        
        <!-- Column 2: Login Background & WhatsApp -->
        <div class="col-lg-6">
            
            <!-- Login Background -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background: rgba(16,185,129,0.15); color: var(--dev-success);">
                        <i class="bi bi-card-image"></i>
                    </div>
                    <div>
                        <h5>Background Login</h5>
                        <small>Custom background halaman login</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <?php if ($currentLoginBg && file_exists(BASE_PATH . '/uploads/backgrounds/' . $currentLoginBg)): ?>
                    <div class="preview-box mb-3" style="padding:0;overflow:hidden;border:0;">
                        <img src="<?php echo BASE_URL . '/uploads/backgrounds/' . $currentLoginBg; ?>?v=<?php echo time(); ?>" 
                             alt="Login Background" style="width:100%;height:150px;object-fit:cover;border-radius:10px;">
                    </div>
                    <?php else: ?>
                    <div class="preview-box mb-3">
                        <i class="bi bi-image text-muted" style="font-size:2rem;"></i>
                        <div class="text-muted mt-2" style="font-size:0.85rem;">Belum ada background</div>
                    </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Upload Background</label>
                            <input type="file" name="login_background" class="form-control" accept="image/*" required>
                            <div class="form-text">Format: JPG, PNG • Maksimal 2MB • Rekomendasi: 1920x1080px</div>
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-upload me-1"></i>Upload Background
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Login Logo -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background: rgba(59,130,246,0.15); color: #3b82f6;">
                        <i class="bi bi-building"></i>
                    </div>
                    <div>
                        <h5>Logo Login Page</h5>
                        <small>Logo tampil di halaman login (mengganti emoji)</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <?php if ($currentLoginLogo && file_exists(BASE_PATH . '/uploads/logos/' . $currentLoginLogo)): ?>
                    <div class="preview-box mb-3">
                        <img src="<?php echo BASE_URL . '/uploads/logos/' . $currentLoginLogo; ?>?v=<?php echo time(); ?>" 
                             alt="Login Logo" style="max-width:100px;max-height:100px;border-radius:8px;">
                        <div class="mt-2">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="delete_login_logo" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash me-1"></i>Hapus Logo
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="preview-box mb-3">
                        <div style="width:80px;height:80px;background:#e5e7eb;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:2.5rem;">🏢</div>
                        <div class="text-muted mt-2" style="font-size:0.85rem;">Default: Emoji icon</div>
                    </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Upload Logo</label>
                            <input type="file" name="login_logo" class="form-control" accept="image/*" required>
                            <div class="form-text">Format: JPG, PNG, SVG • Maksimal 1MB • Rekomendasi: 100x100px</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-upload me-1"></i>Upload Logo
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Favicon Browser -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background: rgba(245,158,11,0.15); color: #f59e0b;">
                        <i class="bi bi-window"></i>
                    </div>
                    <div>
                        <h5>Favicon Browser</h5>
                        <small>Icon di tab browser (favicon)</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <?php if ($currentFavicon && file_exists(BASE_PATH . '/uploads/icons/' . $currentFavicon)): ?>
                    <div class="preview-box mb-3">
                        <img src="<?php echo BASE_URL . '/uploads/icons/' . $currentFavicon; ?>?v=<?php echo time(); ?>" 
                             alt="Favicon" style="width:48px;height:48px;border-radius:4px;">
                        <div class="mt-2" style="font-size:0.75rem;color:#888;">
                            Preview di tab: 
                            <span style="display:inline-flex;align-items:center;background:#f1f5f9;padding:4px 10px;border-radius:6px;margin-left:5px;">
                                <img src="<?php echo BASE_URL . '/uploads/icons/' . $currentFavicon; ?>?v=<?php echo time(); ?>" style="width:16px;height:16px;margin-right:6px;">
                                <span style="font-size:0.7rem;color:#333;">ADF System</span>
                            </span>
                        </div>
                        <div class="mt-2">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="delete_favicon" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash me-1"></i>Hapus Favicon
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="preview-box mb-3">
                        <div style="width:48px;height:48px;background:#e5e7eb;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                            <i class="bi bi-globe text-muted" style="font-size:1.5rem;"></i>
                        </div>
                        <div class="text-muted mt-2" style="font-size:0.85rem;">Belum ada favicon custom</div>
                    </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Upload Favicon</label>
                            <input type="file" name="site_favicon" class="form-control" accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml" required>
                            <div class="form-text">Format: ICO, PNG, SVG • Maksimal 500KB • Rekomendasi: 32x32px atau 64x64px</div>
                        </div>
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-upload me-1"></i>Upload Favicon
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- WhatsApp Developer -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background: rgba(37,211,102,0.15); color: #25D366;">
                        <i class="bi bi-whatsapp"></i>
                    </div>
                    <div>
                        <h5>WhatsApp Developer</h5>
                        <small>Notifikasi trial expired</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Nomor WhatsApp</label>
                            <input type="text" name="whatsapp_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($currentWA); ?>" 
                                   placeholder="628123456789" required>
                            <div class="form-text">Format: 628xxx (tanpa +, tanpa spasi)</div>
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-lg me-1"></i>Simpan WhatsApp
                        </button>
                    </form>
                    <div class="current-value">
                        <small>Nomor saat ini:</small>
                        <strong><?php echo $currentWA ? htmlspecialchars($currentWA) : '-'; ?></strong>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- Footer Text - Full Width -->
    <div class="settings-card">
        <div class="settings-card-header">
            <div class="icon" style="background: rgba(139,92,246,0.15); color: #8b5cf6;">
                <i class="bi bi-card-text"></i>
            </div>
            <div>
                <h5>Teks Footer</h5>
                <small>Edit copyright dan versi di footer halaman sistem</small>
            </div>
        </div>
        <div class="settings-card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Copyright Text</label>
                        <input type="text" name="footer_copyright" class="form-control" 
                               value="<?php echo htmlspecialchars($currentFooterCopyright ?: '© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.'); ?>" 
                               placeholder="© 2026 ADF System. All rights reserved." maxlength="100">
                        <div class="form-text">Teks copyright di footer. Kosongkan untuk default.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Version Text</label>
                        <input type="text" name="footer_version" class="form-control" 
                               value="<?php echo htmlspecialchars($currentFooterVersion ?: 'Version ' . APP_VERSION); ?>" 
                               placeholder="Version 1.0.0" maxlength="50">
                        <div class="form-text">Teks versi aplikasi. Kosongkan untuk default.</div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Simpan Footer Text
                </button>
            </form>
            
            <!-- Preview -->
            <div class="mt-4 pt-3 border-top">
                <small class="text-muted d-block mb-2"><i class="bi bi-eye me-1"></i>Preview Footer:</small>
                <div class="text-center p-3 rounded" style="background:#f8f9fa;">
                    <div class="text-muted" style="font-size:0.875rem;">
                        <?php echo htmlspecialchars($currentFooterCopyright ?: '© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.'); ?>
                    </div>
                    <div class="text-muted" style="font-size:0.8rem;">
                        <?php echo htmlspecialchars($currentFooterVersion ?: 'Version ' . APP_VERSION); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Technical Info -->
    <div class="settings-card" style="background: linear-gradient(135deg, rgba(111,66,193,0.05), rgba(139,92,246,0.05));">
        <div class="settings-card-header">
            <div class="icon" style="background: rgba(111,66,193,0.15); color: var(--dev-primary);">
                <i class="bi bi-code-slash"></i>
            </div>
            <div>
                <h5>Informasi Teknis</h5>
                <small>Path dan konstanta yang digunakan</small>
            </div>
        </div>
        <div class="settings-card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">File Konfigurasi</small>
                    <code class="text-primary">config/config.php</code>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Konstanta Nama</small>
                    <code class="text-success">DEVELOPER_NAME</code>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Konstanta Logo</small>
                    <code class="text-success">DEVELOPER_LOGO</code>
                </div>
                <div class="col-md-6">
                    <small class="text-muted d-block">Path Logo Saat Ini</small>
                    <code class="text-warning"><?php echo htmlspecialchars($currentDevLogo); ?></code>
                </div>
                <div class="col-md-6">
                    <small class="text-muted d-block">File Exists</small>
                    <?php if (file_exists($logoFullPath)): ?>
                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>Yes</span>
                    <?php else: ?>
                        <span class="text-danger"><i class="bi bi-x-circle me-1"></i>No (using fallback)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reset Data Business Section --> 
    <div class="settings-card" style="background: linear-gradient(135deg, rgba(220,38,38,0.05), rgba(239,68,68,0.05)); border: 2px solid rgba(220,38,38,0.1);">
        <div class="settings-card-header">
            <div class="icon" style="background: rgba(220,38,38,0.15); color: #dc2626;">
                <i class="bi bi-trash"></i>
            </div>
            <div>
                <h5>🗑️ Reset Data Business</h5>
                <small>Hapus data tertentu untuk bisnis terpilih</small>
            </div>
        </div>
        <div class="settings-card-body">
            <!-- Warning Notice -->
            <div class="alert alert-danger" style="border-left: 4px solid #dc2626;">
                <div class="d-flex align-items-start">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-3" style="font-size: 1.2rem; margin-top: 0.1rem;"></i>
                    <div>
                        <h6 class="mb-1">⚠️ PERINGATAN - Reset Data Permanen</h6>
                        <p class="mb-0" style="font-size: 0.875rem;">
                            Data yang dihapus <strong>tidak dapat dikembalikan!</strong> Pastikan sudah backup database sebelum melakukan reset.
                        </p>
                    </div>
                </div>
            </div>
            
            <form method="POST" onsubmit="return confirmReset();" style="margin-top: 1.5rem;">
                <div class="row g-3">
                    <!-- Business Selection -->
                    <div class="col-md-6">
                        <label class="form-label">Pilih Bisnis</label>
                        <select name="reset_business_id" class="form-select" required>
                            <?php if (!empty($businesses)): ?>
                                <?php foreach ($businesses as $bid => $b): ?>
                                    <option value="<?php echo htmlspecialchars($bid); ?>" <?php if ($selectedBusiness == $bid) echo 'selected'; ?>>
                                        <?php echo getBusinessDisplayName($bid); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="1">Default Business (Hotel)</option>
                                <option value="2">Demo Restaurant</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <!-- Reset Button Column -->
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" name="reset_data_submit" class="btn btn-danger w-100" style="font-weight: 600;">
                            <i class="bi bi-trash me-1"></i>
                            Reset Data yang Dipilih
                        </button>
                    </div>
                </div>
                
                <!-- Data Type Selection -->  
                <div style="margin-top: 1.5rem;">
                    <label class="form-label">Pilih Data yang Direset</label>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="accounting" id="reset_accounting">
                                <label class="form-check-label" for="reset_accounting">
                                    💰 Data Accounting
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="bookings" id="reset_bookings">
                                <label class="form-check-label" for="reset_bookings">
                                    📅 Data Booking/Reservasi
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="invoices" id="reset_invoices">
                                <label class="form-check-label" for="reset_invoices">
                                    🧾 Data Invoice
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="procurement" id="reset_procurement">
                                <label class="form-check-label" for="reset_procurement">
                                    📋 Data PO & Procurement
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="inventory" id="reset_inventory">
                                <label class="form-check-label" for="reset_inventory">
                                    📦 Data Inventory
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="employees" id="reset_employees">
                                <label class="form-check-label" for="reset_employees">
                                    👥 Data Karyawan
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="users" id="reset_users">
                                <label class="form-check-label" for="reset_users">
                                    🔐 Data User (non-admin)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="guests" id="reset_guests">
                                <label class="form-check-label" for="reset_guests">
                                    🏨 Data Tamu
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="menu" id="reset_menu">
                                <label class="form-check-label" for="reset_menu">
                                    🍽️ Data Menu
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="orders" id="reset_orders">
                                <label class="form-check-label" for="reset_orders">
                                    🛒 Data Orders
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="reports" id="reset_reports">
                                <label class="form-check-label" for="reset_reports">
                                    📊 Data Reports
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reset_type[]" value="logs" id="reset_logs">
                                <label class="form-check-label" for="reset_logs">
                                    📝 Data Logs
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Reset Results Display -->
            <?php if ($resetResult !== null): ?>
                <div class="mt-4 p-3" style="background: rgba(59,130,246,0.05); border-radius: 0.5rem; border-left: 3px solid #3b82f6;">
                    <h6 class="text-primary mb-3">📊 Hasil Reset Data:</h6>
                    <div class="row">
                        <?php foreach ($resetResult as $type => $res): 
                            $data = json_decode($res['response'], true);
                            $isSuccess = $res['http'] == 200 && !empty($data['success']);
                            $message = $data['message'] ?? 'No response';
                        ?>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <span class="badge <?php echo $isSuccess ? 'bg-success' : 'bg-danger'; ?> me-2">
                                        <?php echo $isSuccess ? '✅' : '❌'; ?>
                                    </span>
                                    <small>
                                        <strong><?php echo htmlspecialchars($type); ?>:</strong> 
                                        <?php echo htmlspecialchars($message); ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
    
<script>
function confirmReset() {
    const checkboxes = document.querySelectorAll('input[name="reset_type[]"]:checked');
    const businessSelect = document.querySelector('select[name="reset_business_id"]');
    
    if (checkboxes.length === 0) {
        alert('⚠️ Pilih minimal 1 jenis data yang ingin direset!');
        return false;
    }
    
    const businessName = businessSelect.options[businessSelect.selectedIndex].text;
    const dataTypes = Array.from(checkboxes).map(cb => cb.nextElementSibling.textContent.trim()).join('\\n• ');
    
    const confirmMessage = 
        `⚠️ KONFIRMASI RESET DATA\\n\\n` +
        `Bisnis: ${businessName}\\n\\n` +
        `Data yang akan dihapus:\\n• ${dataTypes}\\n\\n` +
        `Data akan dihapus PERMANEN dan TIDAK DAPAT dikembalikan!\\n\\n` +
        `Apakah Anda yakin ingin melanjutkan?`;
        
    return confirm(confirmMessage);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
