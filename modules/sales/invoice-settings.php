<?php
/**
 * CQC Invoice Settings
 * Setup company info, logo, and bank details for professional invoices
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$businessId = 7; // CQC business ID

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = ROOT_PATH . '/uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileInfo = pathinfo($_FILES['logo']['name']);
            $extension = strtolower($fileInfo['extension']);
            
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
                throw new Exception('Invalid file type. Only images allowed.');
            }
            
            $newFilename = 'cqc-logo-' . time() . '.' . $extension;
            $uploadPath = $uploadDir . $newFilename;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                // Save logo setting
                saveInvoiceSetting($db, $businessId, 'logo', 'logos/' . $newFilename);
            }
        }
        
        // Save other settings
        $fields = [
            'business_name', 'tagline', 'address', 'city', 'phone', 'email', 'npwp',
            'bank_name', 'bank_account', 'bank_holder'
        ];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                saveInvoiceSetting($db, $businessId, $field, trim($_POST[$field]));
            }
        }
        
        $message = 'Settings saved successfully!';
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Helper function to save settings
function saveInvoiceSetting($db, $businessId, $key, $value) {
    // Check if setting exists
    $existing = $db->fetchOne(
        "SELECT id FROM business_settings WHERE business_id = ? AND setting_key = ?",
        [$businessId, $key]
    );
    
    if ($existing) {
        $db->query(
            "UPDATE business_settings SET setting_value = ?, updated_at = NOW() WHERE business_id = ? AND setting_key = ?",
            [$value, $businessId, $key]
        );
    } else {
        $db->query(
            "INSERT INTO business_settings (business_id, setting_key, setting_value, created_at) VALUES (?, ?, ?, NOW())",
            [$businessId, $key, $value]
        );
    }
}

// Load current settings
$settings = [];
try {
    $rows = $db->fetchAll("SELECT setting_key, setting_value FROM business_settings WHERE business_id = ?", [$businessId]);
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table might not exist, create it
    $db->query("
        CREATE TABLE IF NOT EXISTS business_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            created_at DATETIME,
            updated_at DATETIME,
            UNIQUE KEY unique_setting (business_id, setting_key)
        )
    ");
}

// Default values
$defaults = [
    'business_name' => 'CQC Enjiniring',
    'tagline' => 'Solar Panel Installation Contractor',
    'address' => '',
    'city' => '',
    'phone' => '',
    'email' => '',
    'npwp' => '',
    'logo' => '',
    'bank_name' => '',
    'bank_account' => '',
    'bank_holder' => ''
];

foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Check if logo exists
$logoExists = false;
$logoPath = '';
if (!empty($settings['logo'])) {
    $fullPath = ROOT_PATH . '/uploads/' . $settings['logo'];
    if (file_exists($fullPath)) {
        $logoExists = true;
        $logoPath = BASE_URL . '/uploads/' . $settings['logo'];
    }
}

$pageTitle = "Invoice Settings";
include '../../includes/header.php';
?>

<style>
    :root {
        --navy: #0d1f3c;
        --navy-light: #1a3a5c;
        --gold: #f0b429;
        --gold-dark: #d4960d;
    }
    
    .settings-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .settings-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--navy);
    }
    
    .settings-header h1 {
        font-size: 24px;
        color: var(--navy);
        font-weight: 700;
    }
    
    .settings-header p {
        font-size: 13px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .btn-back {
        background: #f1f5f9;
        color: #475569;
        padding: 10px 20px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }
    
    .btn-back:hover {
        background: #e2e8f0;
    }
    
    .settings-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        overflow: hidden;
        margin-bottom: 25px;
    }
    
    .card-header {
        background: linear-gradient(135deg, var(--navy), var(--navy-light));
        color: #fff;
        padding: 16px 24px;
        font-size: 14px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .card-body {
        padding: 24px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    
    .form-group {
        margin-bottom: 0;
    }
    
    .form-group.full-width {
        grid-column: span 2;
    }
    
    .form-group label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #475569;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 12px 14px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
    }
    
    .form-group input:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(240,180,41,0.15);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .form-group .hint {
        font-size: 11px;
        color: #94a3b8;
        margin-top: 4px;
    }
    
    /* Logo Upload */
    .logo-section {
        display: flex;
        gap: 25px;
        align-items: flex-start;
    }
    
    .logo-preview {
        width: 120px;
        height: 120px;
        border: 2px dashed #e2e8f0;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        overflow: hidden;
        flex-shrink: 0;
    }
    
    .logo-preview img {
        max-width: 100px;
        max-height: 100px;
        object-fit: contain;
    }
    
    .logo-preview .no-logo {
        text-align: center;
        color: #94a3b8;
        font-size: 11px;
    }
    
    .logo-upload-area {
        flex: 1;
    }
    
    .upload-box {
        border: 2px dashed #cbd5e1;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .upload-box:hover {
        border-color: var(--gold);
        background: rgba(240,180,41,0.05);
    }
    
    .upload-box input[type="file"] {
        display: none;
    }
    
    .upload-box .icon {
        font-size: 28px;
        margin-bottom: 8px;
    }
    
    .upload-box .text {
        font-size: 13px;
        color: #64748b;
    }
    
    .upload-box .text strong {
        color: var(--gold-dark);
    }
    
    /* Messages */
    .alert {
        padding: 14px 18px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 13px;
        font-weight: 500;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    /* Submit Button */
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }
    
    .btn-save {
        background: linear-gradient(135deg, var(--gold), var(--gold-dark));
        color: var(--navy);
        padding: 14px 30px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }
    
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(240,180,41,0.35);
    }
    
    /* Preview Card */
    .preview-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 20px;
        margin-top: 20px;
    }
    
    .preview-card h4 {
        font-size: 12px;
        color: #64748b;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .preview-invoice-header {
        display: flex;
        gap: 16px;
        align-items: center;
    }
    
    .preview-logo {
        width: 60px;
        height: 60px;
        background: #e2e8f0;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }
    
    .preview-logo img {
        max-width: 50px;
        max-height: 50px;
    }
    
    .preview-company h5 {
        font-size: 16px;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 2px;
    }
    
    .preview-company p {
        font-size: 11px;
        color: #64748b;
        margin-bottom: 1px;
    }
</style>

<div class="settings-container">
    <!-- Header -->
    <div class="settings-header">
        <div>
            <h1>⚙️ Invoice Settings</h1>
            <p>Configure company information for professional invoices</p>
        </div>
        <a href="index-cqc.php" class="btn-back">← Back to Invoices</a>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data">
        <!-- Company Logo -->
        <div class="settings-card">
            <div class="card-header">🏢 Company Logo</div>
            <div class="card-body">
                <div class="logo-section">
                    <div class="logo-preview">
                        <?php if ($logoExists): ?>
                            <img src="<?php echo $logoPath; ?>" alt="Company Logo" id="logoPreviewImg">
                        <?php else: ?>
                            <div class="no-logo" id="noLogoText">
                                <div>📷</div>
                                No Logo
                            </div>
                            <img src="" alt="" id="logoPreviewImg" style="display: none;">
                        <?php endif; ?>
                    </div>
                    <div class="logo-upload-area">
                        <label class="upload-box" for="logoInput">
                            <div class="icon">📤</div>
                            <div class="text">
                                <strong>Click to upload</strong> or drag and drop<br>
                                PNG, JPG, SVG (max 2MB)
                            </div>
                            <input type="file" name="logo" id="logoInput" accept="image/*">
                        </label>
                        <p class="hint" style="margin-top: 8px;">Recommended: Square logo, minimum 200x200 pixels</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Company Information -->
        <div class="settings-card">
            <div class="card-header">📋 Company Information</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Company Name *</label>
                        <input type="text" name="business_name" value="<?php echo htmlspecialchars($settings['business_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tagline / Slogan</label>
                        <input type="text" name="tagline" value="<?php echo htmlspecialchars($settings['tagline']); ?>" placeholder="e.g. Solar Panel Installation Contractor">
                    </div>
                    <div class="form-group full-width">
                        <label>Address *</label>
                        <textarea name="address" placeholder="Full street address"><?php echo htmlspecialchars($settings['address']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($settings['city']); ?>" placeholder="e.g. Jakarta">
                    </div>
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($settings['phone']); ?>" placeholder="+62 21 1234567">
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($settings['email']); ?>" placeholder="info@company.com">
                    </div>
                    <div class="form-group">
                        <label>NPWP (Tax ID)</label>
                        <input type="text" name="npwp" value="<?php echo htmlspecialchars($settings['npwp']); ?>" placeholder="00.000.000.0-000.000">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bank Information -->
        <div class="settings-card">
            <div class="card-header">🏦 Bank Information (for Payment)</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" value="<?php echo htmlspecialchars($settings['bank_name']); ?>" placeholder="e.g. Bank Mandiri">
                    </div>
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="bank_account" value="<?php echo htmlspecialchars($settings['bank_account']); ?>" placeholder="1234567890">
                    </div>
                    <div class="form-group full-width">
                        <label>Account Holder Name</label>
                        <input type="text" name="bank_holder" value="<?php echo htmlspecialchars($settings['bank_holder']); ?>" placeholder="PT Company Name">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Preview -->
        <div class="preview-card">
            <h4>Invoice Header Preview</h4>
            <div class="preview-invoice-header">
                <div class="preview-logo">
                    <?php if ($logoExists): ?>
                        <img src="<?php echo $logoPath; ?>" alt="Logo">
                    <?php else: ?>
                        <span style="color: #94a3b8; font-size: 20px;">📷</span>
                    <?php endif; ?>
                </div>
                <div class="preview-company">
                    <h5 id="previewName"><?php echo htmlspecialchars($settings['business_name']); ?></h5>
                    <p id="previewTagline"><?php echo htmlspecialchars($settings['tagline']); ?></p>
                    <p id="previewAddress"><?php echo htmlspecialchars($settings['address'] . ($settings['city'] ? ', ' . $settings['city'] : '')); ?></p>
                    <p id="previewContact">📞 <?php echo htmlspecialchars($settings['phone']); ?> | ✉️ <?php echo htmlspecialchars($settings['email']); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Submit -->
        <div class="form-actions">
            <button type="submit" class="btn-save">
                💾 Save Settings
            </button>
        </div>
    </form>
</div>

<script>
// Logo preview
document.getElementById('logoInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById('logoPreviewImg');
            const noLogo = document.getElementById('noLogoText');
            img.src = e.target.result;
            img.style.display = 'block';
            if (noLogo) noLogo.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }
});

// Live preview updates
document.querySelector('input[name="business_name"]').addEventListener('input', function() {
    document.getElementById('previewName').textContent = this.value || 'Company Name';
});

document.querySelector('input[name="tagline"]').addEventListener('input', function() {
    document.getElementById('previewTagline').textContent = this.value;
});

document.querySelector('textarea[name="address"]').addEventListener('input', updateAddress);
document.querySelector('input[name="city"]').addEventListener('input', updateAddress);

function updateAddress() {
    const address = document.querySelector('textarea[name="address"]').value;
    const city = document.querySelector('input[name="city"]').value;
    document.getElementById('previewAddress').textContent = address + (city ? ', ' + city : '');
}

document.querySelector('input[name="phone"]').addEventListener('input', updateContact);
document.querySelector('input[name="email"]').addEventListener('input', updateContact);

function updateContact() {
    const phone = document.querySelector('input[name="phone"]').value || '-';
    const email = document.querySelector('input[name="email"]').value || '-';
    document.getElementById('previewContact').textContent = '📞 ' + phone + ' | ✉️ ' + email;
}
</script>

<?php include '../../includes/footer.php'; ?>
