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
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    .settings-card-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .settings-card-header .icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }
    .settings-card-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 1rem;
    }
    .settings-card-header small {
        color: #888;
        font-weight: 400;
    }
    .settings-card-body {
        padding: 1.25rem;
    }
    .preview-box {
        background: #f8f9fa;
        border: 2px dashed #dee2e6;
        border-radius: 10px;
        padding: 1.25rem;
        text-align: center;
    }
    .preview-box img {
        max-width: 120px;
        max-height: 120px;
        border-radius: 8px;
    }
    .current-value {
        background: #f8f9fa;
        border-left: 3px solid var(--dev-primary);
        padding: 0.75rem 1rem;
        border-radius: 0 6px 6px 0;
        margin-top: 1rem;
    }
    .current-value small {
        color: #888;
        font-size: 0.75rem;
    }
    .current-value strong {
        display: block;
        color: #333;
        margin-top: 0.25rem;
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
    
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
