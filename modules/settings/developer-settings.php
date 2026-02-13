<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user has permission to access settings
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Developer Settings';

// Get settings from database
$loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
$currentLoginBg = $loginBgSetting['setting_value'] ?? null;

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
        // Update config file
        $newConfigContent = preg_replace(
            "/define\('DEVELOPER_NAME',\s*'[^']*'\);/",
            "define('DEVELOPER_NAME', '" . addslashes($newName) . "');",
            $configContent
        );
        
        if (file_put_contents($configFile, $newConfigContent)) {
            setFlash('success', 'Nama developer berhasil diupdate!');
            header('Location: developer-settings.php');
            exit;
        } else {
            setFlash('error', 'Gagal update config file. Periksa permission folder.');
        }
    } else {
        setFlash('error', 'Nama developer tidak boleh kosong.');
    }
}

// Handle login background upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['login_background'])) {
    $file = $_FILES['login_background'];
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        if (!in_array($file['type'], $allowedTypes)) {
            setFlash('error', 'Tipe file tidak diizinkan. Gunakan JPG atau PNG.');
        } elseif ($file['size'] > $maxSize) {
            setFlash('error', 'Ukuran file terlalu besar. Maksimal 2MB.');
        } else {
            $uploadDir = BASE_PATH . '/uploads/backgrounds/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'login-bg.' . $extension;
            $uploadPath = $uploadDir . $filename;
            
            // Delete old background files
            foreach (glob($uploadDir . 'login-bg.*') as $oldFile) {
                unlink($oldFile);
            }
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Save to settings table
                $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('login_background', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$filename, $filename]);
                
                setFlash('success', 'Background login berhasil diupload!');
                header('Location: developer-settings.php');
                exit;
            } else {
                setFlash('error', 'Gagal upload file.');
            }
        }
    }
}

// Handle WhatsApp number update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whatsapp_number'])) {
    $waNumber = trim($_POST['whatsapp_number']);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('developer_whatsapp', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$waNumber, $waNumber]);
    setFlash('success', 'Nomor WhatsApp berhasil disimpan!');
    header('Location: developer-settings.php');
    exit;
}

// Handle footer text update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['footer_copyright'])) {
    $copyright = trim($_POST['footer_copyright']);
}

// Get businesses for reset data functionality
$businesses = [];
try {
    $businessResult = $db->fetchAll("SELECT business_id, business_name, business_type FROM businesses WHERE status = 'active' ORDER BY business_name");
    foreach ($businessResult as $business) {
        $businesses[$business['business_id']] = $business;
    }
} catch (Exception $e) {
    // If no businesses table, use default
    $businesses = [];
}

// Function to get business display name
function getBusinessDisplayName($businessId) {
    global $businesses;
    if (isset($businesses[$businessId])) {
        $b = $businesses[$businessId];
        return $b['business_name'] . ' (' . ucfirst($b['business_type']) . ')';
    }
    return 'Business #' . $businessId;
}

$selectedBusiness = $_POST['reset_business_id'] ?? (array_key_first($businesses) ?: '1');
$resetResult = null;

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
        
        setFlash('info', 'Reset data telah dijalankan. Lihat hasil detail di bawah.');
    } else {
        setFlash('error', 'Pilih bisnis dan minimal 1 jenis data untuk direset.');
    }
}

// Handle footer text update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['footer_copyright'])) {
    $copyright = trim($_POST['footer_copyright']);
    $version = trim($_POST['footer_version']);
    
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_copyright', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$copyright, $copyright]);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_version', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$version, $version]);
    
    setFlash('success', 'Teks footer berhasil diupdate!');
    header('Location: developer-settings.php');
    exit;
}

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['developer_logo'])) {
    $file = $_FILES['developer_logo'];
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/svg+xml'];
    $maxSize = 1 * 1024 * 1024; // 1MB
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        if (!in_array($file['type'], $allowedTypes)) {
            setFlash('error', 'Tipe file tidak diizinkan. Gunakan JPG, PNG, SVG, atau GIF.');
        } elseif ($file['size'] > $maxSize) {
            setFlash('error', 'Ukuran file terlalu besar. Maksimal 1MB.');
        } else {
            $uploadDir = BASE_PATH . '/assets/img/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Keep filename as developer-logo
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'developer-logo.' . $extension;
            $uploadPath = $uploadDir . $filename;
            
            // Delete old logo files
            foreach (glob($uploadDir . 'developer-logo.*') as $oldFile) {
                unlink($oldFile);
            }
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Update config file
                $newLogoPath = 'assets/img/' . $filename;
                $newConfigContent = preg_replace(
                    "/define\('DEVELOPER_LOGO',\s*'[^']*'\);/",
                    "define('DEVELOPER_LOGO', '" . $newLogoPath . "');",
                    $configContent
                );
                
                if (file_put_contents($configFile, $newConfigContent)) {
                    setFlash('success', 'Logo developer berhasil diupload!');
                    header('Location: developer-settings.php');
                    exit;
                } else {
                    setFlash('error', 'File uploaded tapi gagal update config.');
                }
            } else {
                setFlash('error', 'Gagal upload file.');
            }
        }
    }
}

include '../../includes/header.php';
?>



<style>
    .preview-box {
        background: var(--bg-secondary);
        border: 2px solid var(--bg-tertiary);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .dev-logo-preview {
        width: 80px;
        height: 80px;
        border-radius: var(--radius-md);
        object-fit: contain;
        background: var(--bg-primary);
        padding: 0.5rem;
    }
    
    .checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 0.75rem;
        margin: 1rem 0;
    }
    
    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: var(--text-primary);
        cursor: pointer;
        padding: 0.5rem;
        border-radius: var(--radius-md);
        transition: all 0.2s;
    }
    
    .checkbox-label:hover {
        background: var(--bg-tertiary);
    }
    
    .checkbox-label input[type="checkbox"] {
        margin: 0;
        width: 16px;
        height: 16px;
    }
</style>

<!-- RESET DATA (Developer) -->
<div class="card" style="margin-top: 2rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, #ef4444, #f59e42); display: flex; align-items: center; justify-content: center;">
            <i data-feather="trash-2" style="width: 18px; height: 18px; color: white;"></i>
        </div>
        <div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">Reset Data Bisnis</h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">Hapus data tertentu untuk bisnis terpilih</p>
        </div>
    </div>
    <form method="POST" onsubmit="return confirm('Yakin ingin reset data untuk bisnis ini? Data akan dihapus permanen!');">
        <div class="form-group">
            <label class="form-label">Pilih Bisnis</label>
            <select name="reset_business_id" class="form-control" required>
                <?php foreach ($businesses as $bid => $b): ?>
                    <option value="<?php echo htmlspecialchars($bid); ?>" <?php if ($selectedBusiness == $bid) echo 'selected'; ?>><?php echo getBusinessDisplayName($bid); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-top: 1rem;">
            <label class="form-label">Pilih Data yang Direset</label>
            <div class="checkbox-group">
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="accounting"> Data Accounting</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="bookings"> Data Booking/Reservasi</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="invoices"> Data Invoice</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="procurement"> Data PO & Procurement</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="inventory"> Data Inventory</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="employees"> Data Karyawan</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="users"> Data User (non-admin)</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="guests"> Data Tamu</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="menu"> Data Menu</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="orders"> Data Orders</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="reports"> Data Reports</label>
                <label class="checkbox-label"><input type="checkbox" name="reset_type[]" value="logs"> Data Logs</label>
            </div>
        </div>
        <div style="margin-top: 1rem;">
            <button type="submit" name="reset_data_submit" class="btn btn-danger" style="width: 100%; font-weight: 600;">Reset Data yang Dipilih</button>
        </div>
    </form>
    <?php if ($resetResult !== null): ?>
        <div style="margin-top: 1rem;">
            <h4>Hasil Reset:</h4>
            <ul style="font-size: 0.95em;">
                <?php foreach ($resetResult as $type => $res): 
                    $data = json_decode($res['response'], true);
                ?>
                    <li><b><?php echo htmlspecialchars($type); ?>:</b> <?php echo $res['http'] == 200 && !empty($data['success']) ? '✅ ' . htmlspecialchars($data['message']) : '❌ ' . ($data['message'] ?? 'Gagal'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- Page Header -->
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.375rem;">
            Developer Settings
        </h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">
            Konfigurasi nama dan logo developer yang tampil di sidebar footer
        </p>
    </div>
    <a href="index.php" class="btn btn-secondary">
        <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
        Kembali
    </a>
</div>

<!-- Warning Notice -->
<div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: var(--radius-lg); padding: 1rem; margin-bottom: 1.5rem; display: flex; align-items: start; gap: 0.75rem;">
    <i data-feather="alert-triangle" style="width: 20px; height: 20px; color: var(--warning); flex-shrink: 0; margin-top: 0.125rem;"></i>
    <div>
        <h4 style="font-size: 0.938rem; font-weight: 600; color: var(--text-primary); margin: 0 0 0.375rem 0;">
            Perhatian - File Konfigurasi
        </h4>
        <p style="font-size: 0.813rem; color: var(--text-secondary); margin: 0;">
            Perubahan ini akan mengupdate file <code style="background: rgba(0,0,0,0.2); padding: 0.125rem 0.375rem; border-radius: 4px;">config/config.php</code>. 
            Pastikan file memiliki permission yang cukup (writable). Perubahan bersifat permanen dan akan tampil untuk semua user.
        </p>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
    
    <!-- Change Developer Name -->
    <div class="card">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
            <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center;">
                <i data-feather="user" style="width: 18px; height: 18px; color: white;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Nama Developer
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    Tampil di footer sidebar
                </p>
            </div>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Nama Developer</label>
                <input type="text" name="developer_name" class="form-control" value="<?php echo htmlspecialchars($currentDevName); ?>" required maxlength="50">
                <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                    Maksimal 50 karakter. Contoh: "DevTeam Studio", "PT. Teknologi Indonesia"
                </small>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i data-feather="save" style="width: 16px; height: 16px;"></i>
                Simpan Nama Developer
            </button>
        </form>
        
        <!-- Current Value Display -->
        <div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-primary); border-radius: var(--radius-md); border-left: 3px solid var(--primary-color);">
            <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.375rem;">Nama Saat Ini:</div>
            <div style="font-size: 0.938rem; font-weight: 600; color: var(--text-primary);">
                <?php echo htmlspecialchars($currentDevName); ?>
            </div>
        </div>
    </div>
    
    <!-- Change Developer Logo -->
    <div class="card">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
            <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--accent-color), var(--danger)); display: flex; align-items: center; justify-content: center;">
                <i data-feather="image" style="width: 18px; height: 18px; color: white;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Logo Developer
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    Ukuran 100x100px (persegi)
                </p>
            </div>
        </div>
        
        <!-- Current Logo Preview -->
        <div style="margin-bottom: 1.25rem;">
            <div style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.625rem;">
                Logo Saat Ini:
            </div>
            <div class="preview-box">
                <?php
                $logoFullPath = BASE_PATH . '/' . $currentDevLogo;
                if (file_exists($logoFullPath)):
                ?>
                    <div style="width: 80px; height: 80px; border-radius: var(--radius-md); background: var(--bg-secondary); padding: 8px; display: flex; align-items: center; justify-content: center;">
                        <img src="<?php echo BASE_URL . '/' . $currentDevLogo; ?>?v=<?php echo filemtime($logoFullPath); ?>" alt="Developer Logo" style="width: 100%; height: 100%; object-fit: contain;">
                    </div>
                <?php else: ?>
                    <div style="width: 80px; height: 80px; border-radius: var(--radius-md); background: var(--primary-color); display: flex; align-items: center; justify-content: center;">
                        <span style="font-size: 2rem; font-weight: 700; color: white;">&lt;/&gt;</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Upload Logo Baru</label>
                <input type="file" name="developer_logo" class="form-control" accept="image/*" required>
                <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                    Format: JPG, PNG, SVG, GIF • Maksimal 1MB • Rekomendasi: 100x100px
                </small>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i data-feather="upload" style="width: 16px; height: 16px;"></i>
                Upload Logo Developer
            </button>
        </form>
        
        <div style="margin-top: 1rem; padding: 0.875rem; background: rgba(99, 102, 241, 0.1); border-radius: var(--radius-md);">
            <h4 style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary); margin: 0 0 0.5rem 0;">
                <i data-feather="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                Tips Logo Developer:
            </h4>
            <ul style="font-size: 0.75rem; color: var(--text-secondary); margin: 0; padding-left: 1.125rem;">
                <li>Gunakan background transparan (PNG/SVG)</li>
                <li>Format persegi (1:1 ratio)</li>
                <li>Simple & readable di ukuran kecil</li>
                <li>Logo akan otomatis dapat background abu-abu untuk terlihat di tema terang/gelap</li>
            </ul>
        </div>
    </div>
    
</div>

<!-- Login Background & WhatsApp Settings -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
    
    <!-- Login Background -->
    <div class="card">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
            <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, #10b981, #059669); display: flex; align-items: center; justify-content: center;">
                <i data-feather="image" style="width: 18px; height: 18px; color: white;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Background Login
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    Custom background halaman login
                </p>
            </div>
        </div>
        
        <?php if ($currentLoginBg && file_exists(BASE_PATH . '/uploads/backgrounds/' . $currentLoginBg)): ?>
            <div style="margin-bottom: 1rem; border-radius: var(--radius-md); overflow: hidden; height: 150px;">
                <img src="<?php echo BASE_URL . '/uploads/backgrounds/' . $currentLoginBg; ?>?v=<?php echo time(); ?>" alt="Login Background" style="width: 100%; height: 100%; object-fit: cover;">
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Upload Background</label>
                <input type="file" name="login_background" class="form-control" accept="image/*" required>
                <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                    Format: JPG, PNG • Maksimal 2MB • Rekomendasi: 1920x1080px
                </small>
            </div>
            
            <button type="submit" class="btn btn-success" style="width: 100%;">
                <i data-feather="upload" style="width: 16px; height: 16px;"></i>
                Upload Background Login
            </button>
        </form>
    </div>
    
    <!-- WhatsApp Developer -->
    <div class="card">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
            <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, #25D366, #128C7E); display: flex; align-items: center; justify-content: center;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.304-1.654a11.882 11.882 0 005.713 1.456h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    WhatsApp Developer
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    Notifikasi trial expired
                </p>
            </div>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Nomor WhatsApp</label>
                <input type="text" name="whatsapp_number" class="form-control" value="<?php echo htmlspecialchars($currentWA); ?>" placeholder="628123456789" required>
                <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                    Format: 628xxx (tanpa +, tanpa spasi). Contoh: 628123456789
                </small>
            </div>
            
            <button type="submit" class="btn btn-success" style="width: 100%;">
                <i data-feather="save" style="width: 16px; height: 16px;"></i>
                Simpan Nomor WhatsApp
            </button>
        </form>
        
        <div style="margin-top: 1rem; padding: 0.875rem; background: rgba(37, 211, 102, 0.1); border-radius: var(--radius-md);">
            <h4 style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary); margin: 0 0 0.5rem 0;">
                <i data-feather="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                Fungsi WhatsApp:
            </h4>
            <ul style="font-size: 0.75rem; color: var(--text-secondary); margin: 0; padding-left: 1.125rem;">
                <li>Notif otomatis saat user demo trial expired</li>
                <li>Dashboard akan tampil tombol WhatsApp untuk perpanjang</li>
                <li>User dapat langsung chat untuk upgrade PRO</li>
            </ul>
        </div>
    </div>
    
</div>

<!-- Footer Text Customization -->
<div class="card" style="margin-top: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, #8b5cf6, #6366f1); display: flex; align-items: center; justify-content: center;">
            <i data-feather="align-center" style="width: 18px; height: 18px; color: white;"></i>
        </div>
        <div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Teks Footer
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Edit copyright dan versi di footer halaman
            </p>
        </div>
    </div>
    
    <form method="POST" style="display: grid; gap: 1rem;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Copyright Text</label>
            <input type="text" name="footer_copyright" class="form-control" 
                   value="<?php echo htmlspecialchars($currentFooterCopyright ?: '© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.'); ?>" 
                   placeholder="© 2026 Narayana Hotel Management. All rights reserved."
                   maxlength="100">
            <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                Teks copyright yang tampil di footer. Kosongkan untuk default.
            </small>
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Version Text</label>
            <input type="text" name="footer_version" class="form-control" 
                   value="<?php echo htmlspecialchars($currentFooterVersion ?: 'Version ' . APP_VERSION); ?>" 
                   placeholder="Version 1.0.0"
                   maxlength="50">
            <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                Teks versi aplikasi. Kosongkan untuk default.
            </small>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width: fit-content;">
            <i data-feather="save" style="width: 16px; height: 16px;"></i>
            Simpan Footer Text
        </button>
    </form>
    
    <!-- Preview -->
    <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--bg-tertiary);">
        <div style="font-size: 0.813rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.75rem;">
            <i data-feather="eye" style="width: 14px; height: 14px; vertical-align: middle;"></i>
            Preview Footer:
        </div>
        <div style="background: var(--bg-secondary); border: 1px solid var(--bg-tertiary); border-radius: var(--radius-md); padding: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); margin: 0 0 0.5rem 0; font-size: 0.875rem;">
                <?php echo htmlspecialchars($currentFooterCopyright ?: '© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.'); ?>
            </p>
            <p style="color: var(--text-muted); margin: 0; font-size: 0.813rem;">
                <?php echo htmlspecialchars($currentFooterVersion ?: 'Version ' . APP_VERSION); ?>
            </p>
        </div>
    </div>
</div>

<!-- Live Preview -->
<div class="card" style="margin-top: 1.5rem;">
    <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.625rem;">
        <i data-feather="eye" style="width: 20px; height: 20px; color: var(--primary-color);"></i>
        Preview di Sidebar Footer
    </h3>
    
    <div style="max-width: 210px; background: var(--bg-secondary); border: 1px solid var(--bg-tertiary); border-radius: var(--radius-lg); padding: 0.875rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.625rem;">
            <?php if (file_exists($logoFullPath)): ?>
                <div style="width: 48px; height: 48px; border-radius: 6px; background: var(--bg-secondary); padding: 4px; display: flex; align-items: center; justify-content: center;">
                    <img src="<?php echo BASE_URL . '/' . $currentDevLogo; ?>?v=<?php echo filemtime($logoFullPath); ?>" alt="Developer Logo" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
            <?php else: ?>
                <div style="width: 48px; height: 48px; border-radius: 6px; background: var(--primary-color); display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 1.25rem; font-weight: 700; color: white;">&lt;/&gt;</span>
                </div>
            <?php endif; ?>
            <div style="flex: 1;">
                <div style="font-size: 0.625rem; font-weight: 600; color: var(--text-primary);">
                    <?php echo htmlspecialchars($currentDevName); ?>
                </div>
                <div style="font-size: 0.563rem; color: var(--text-muted);">Developer</div>
            </div>
        </div>
        <div style="font-size: 0.625rem; color: var(--text-muted); text-align: center; padding-top: 0.5rem; border-top: 1px solid var(--bg-tertiary);">
            Version <?php echo APP_VERSION; ?> • <?php echo APP_YEAR; ?>
        </div>
    </div>
</div>

<!-- Technical Info -->
<div class="card" style="margin-top: 1.5rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(139, 92, 246, 0.05));">
    <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
        <i data-feather="code" style="width: 18px; height: 18px; vertical-align: middle;"></i>
        Informasi Teknis
    </h3>
    
    <div style="display: grid; gap: 0.75rem; font-size: 0.813rem;">
        <div style="display: grid; grid-template-columns: 180px 1fr; gap: 0.5rem;">
            <span style="color: var(--text-muted);">File Konfigurasi:</span>
            <code style="background: rgba(0,0,0,0.2); padding: 0.25rem 0.5rem; border-radius: 4px; color: var(--primary-light);">config/config.php</code>
        </div>
        
        <div style="display: grid; grid-template-columns: 180px 1fr; gap: 0.5rem;">
            <span style="color: var(--text-muted);">Konstanta Nama:</span>
            <code style="background: rgba(0,0,0,0.2); padding: 0.25rem 0.5rem; border-radius: 4px; color: var(--success);">DEVELOPER_NAME</code>
        </div>
        
        <div style="display: grid; grid-template-columns: 180px 1fr; gap: 0.5rem;">
            <span style="color: var(--text-muted);">Konstanta Logo:</span>
            <code style="background: rgba(0,0,0,0.2); padding: 0.25rem 0.5rem; border-radius: 4px; color: var(--success);">DEVELOPER_LOGO</code>
        </div>
        
        <div style="display: grid; grid-template-columns: 180px 1fr; gap: 0.5rem;">
            <span style="color: var(--text-muted);">Path Logo Saat Ini:</span>
            <code style="background: rgba(0,0,0,0.2); padding: 0.25rem 0.5rem; border-radius: 4px; color: var(--warning);"><?php echo htmlspecialchars($currentDevLogo); ?></code>
        </div>
        
        <div style="display: grid; grid-template-columns: 180px 1fr; gap: 0.5rem;">
            <span style="color: var(--text-muted);">File Exists:</span>
            <span style="color: <?php echo file_exists($logoFullPath) ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 600;">
                <?php echo file_exists($logoFullPath) ? '✓ Yes' : '✗ No (using fallback)'; ?>
            </span>
        </div>
    </div>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
