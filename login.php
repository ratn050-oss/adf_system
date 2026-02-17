<?php
/**
 * ADF SYSTEM - Multi Business Management
 * Login Page
 */

define('APP_ACCESS', true);
require_once 'config/config.php';

// Check if database exists
try {
    $testConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    unset($testConn);
} catch (PDOException $e) {
    header('Location: setup-required.html');
    exit;
}

require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'config/database.php';

$auth = new Auth();
$db = Database::getInstance();

// Get custom login background from settings (with error handling)
$customBg = null;
$bgUrl = null;
$loginLogo = null;
$loginLogoUrl = null;
$faviconUrl = null;
try {
    $loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
    $customBg = $loginBgSetting['setting_value'] ?? null;
    $bgUrl = $customBg && file_exists(BASE_PATH . '/uploads/backgrounds/' . $customBg) 
        ? BASE_URL . '/uploads/backgrounds/' . $customBg 
        : null;
    
    $loginLogoSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_logo'");
    $loginLogo = $loginLogoSetting['setting_value'] ?? null;
    $loginLogoUrl = $loginLogo && file_exists(BASE_PATH . '/uploads/logos/' . $loginLogo) 
        ? BASE_URL . '/uploads/logos/' . $loginLogo 
        : null;
    
    $faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
    $faviconFile = $faviconSetting['setting_value'] ?? null;
    $faviconUrl = $faviconFile && file_exists(BASE_PATH . '/uploads/icons/' . $faviconFile) 
        ? BASE_URL . '/uploads/icons/' . $faviconFile 
        : null;
    
    // Get demo credentials from settings
    $demoUsernameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_username'");
    $demoUsername = $demoUsernameSetting['setting_value'] ?? 'admin';
    
    $demoPasswordSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_password'");
    $demoPassword = $demoPasswordSetting['setting_value'] ?? 'admin';
} catch (Exception $e) {
    // Settings table might not exist yet, continue without background
}

// If already logged in, redirect to dashboard
if ($auth->isLoggedIn()) {
    redirect(BASE_URL . '/index.php');
}

// Handle login form submission
if (isPost()) {
    $username = sanitize(getPost('username'));
    $password = getPost('password');
    $loginType = getPost('login_type') ?? 'normal'; // owner or normal
    
    // Check if business specified via URL parameter
    $forcedBusiness = isset($_GET['biz']) ? sanitize($_GET['biz']) : null;
    
    if ($auth->login($username, $password)) {
        $currentUser = $auth->getCurrentUser();
        
        // Auto-detect user's accessible businesses
        require_once 'includes/business_helper.php';
        
        try {
            // Connect to master database (DB_NAME is correct for current environment)
            $masterDbName = DB_NAME;
            $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get user ID and role from master
            $userStmt = $masterPdo->prepare("SELECT u.id, u.role_id, r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
            $userStmt->execute([$username]);
            $masterUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$masterUser) {
                $error = 'Pengguna tidak terdaftar di sistem! Hubungi pengembang untuk mengatur akses.';
                $auth->logout();
            } else {
                $masterId = $masterUser['id'];
                $roleCode = $masterUser['role_code'];
                
                // Check if owner login requested
                if ($loginType === 'owner') {
                    // Only owner, admin, developer can access owner dashboard
                    if (in_array($roleCode, ['owner', 'admin', 'developer'])) {
                        setFlash('success', 'Login Owner berhasil!');
                        redirect(BASE_URL . '/modules/owner/dashboard.php');
                    } else {
                        $error = 'Akses ditolak! Hanya Pemilik yang dapat mengakses Dasbor Pemilik.';
                        $auth->logout();
                    }
                }
                
                // Developer role has full access to all businesses
                if ($roleCode === 'developer') {
                    // If specific business requested, use it
                    if ($forcedBusiness) {
                        setActiveBusinessId($forcedBusiness);
                    } else {
                        // Default to first business
                        setActiveBusinessId('narayana-hotel');
                    }
                    setFlash('success', 'Login berhasil! Developer mode aktif.');
                    redirect(BASE_URL . '/index.php');
                }
                
                // Get businesses user has access to
                $bizStmt = $masterPdo->prepare("
                    SELECT DISTINCT b.id, b.business_code, b.business_name
                    FROM businesses b
                    JOIN user_menu_permissions p ON b.id = p.business_id
                    WHERE p.user_id = ?
                    ORDER BY b.business_name
                ");
                $bizStmt->execute([$masterId]);
                $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($userBusinesses)) {
                    $error = 'Anda tidak memiliki akses ke bisnis manapun! Hubungi pengembang.';
                    $auth->logout();
                } elseif ($forcedBusiness) {
                    // Direct link with business parameter - validate access
                    // Map business_id to business_code
                    $idToCodeMap = [
                        'bens-cafe' => 'BENSCAFE',
                        'narayana-hotel' => 'NARAYANAHOTEL'
                    ];
                    
                    $forcedBizCode = isset($idToCodeMap[$forcedBusiness]) ? $idToCodeMap[$forcedBusiness] : strtoupper(str_replace('-', '', $forcedBusiness));
                    $hasAccess = false;
                    
                    foreach ($userBusinesses as $biz) {
                        if ($biz['business_code'] === $forcedBizCode) {
                            $hasAccess = true;
                            break;
                        }
                    }
                    
                    if ($hasAccess) {
                        // Find the numeric business ID from the matched business
                        foreach ($userBusinesses as $biz) {
                            if ($biz['business_code'] === $forcedBizCode) {
                                $_SESSION['business_id'] = (int)$biz['id']; // Set numeric business_id
                                break;
                            }
                        }
                        setActiveBusinessId($forcedBusiness);
                        setFlash('success', 'Login berhasil!');
                        redirect(BASE_URL . '/index.php');
                    } else {
                        $error = 'Anda tidak memiliki akses ke bisnis tersebut!';
                        $auth->logout();
                    }
                } else {
                    // One or multiple businesses - auto login to first business
                    $bizCode = $userBusinesses[0]['business_code'];
                    
                    // Map business_code to business_id
                    $codeToIdMap = [
                        'BENSCAFE' => 'bens-cafe',
                        'NARAYANAHOTEL' => 'narayana-hotel'
                    ];
                    
                    $businessId = isset($codeToIdMap[$bizCode]) ? $codeToIdMap[$bizCode] : strtolower($bizCode);
                    $_SESSION['business_id'] = (int)$userBusinesses[0]['id']; // Set numeric business_id
                    setActiveBusinessId($businessId);
                    
                    if (count($userBusinesses) === 1) {
                        setFlash('success', 'Login berhasil! Selamat datang ke ' . $userBusinesses[0]['business_name']);
                    } else {
                        setFlash('success', 'Login berhasil! Anda bisa switch bisnis melalui dropdown di sidebar.');
                    }
                    
                    redirect(BASE_URL . '/index.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Login business check error: ' . $e->getMessage());
            $error = 'Terjadi kesalahan sistem. Silakan coba lagi.';
            $auth->logout();
        }
    } else {
        $error = 'Nama pengguna atau kata sandi tidak tepat!';
    }
}

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Get business-specific information for display
$displayInfo = [
    'icon' => '🏢',
    'name' => 'ADF System',
    'subtitle' => 'Business Management System',
    'db_name' => 'Multi-Business Platform'
];

if (isset($_GET['biz'])) {
    $bizParam = strtolower(sanitize($_GET['biz']));
    
    // Map business codes to display info
    $businessMap = [
        'narayana-hotel' => [
            'icon' => '🏨',
            'name' => 'Narayana Hotel',
            'subtitle' => 'Karimunjawa',
            'db_name' => 'adf_narayana_hotel'
        ],
        'bens-cafe' => [
            'icon' => '☕',
            'name' => 'Ben\'s Cafe',
            'subtitle' => 'Karimunjawa',
            'db_name' => 'adf_benscafe'
        ]
    ];
    
    if (isset($businessMap[$bizParam])) {
        $displayInfo = $businessMap[$bizParam];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Login - <?php echo APP_NAME; ?></title>
    
    <!-- Favicon -->
    <?php if ($faviconUrl): ?>
    <link rel="icon" type="image/x-icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <?php endif; ?>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            position: relative;
            <?php if ($bgUrl): ?>
            background-image: linear-gradient(135deg, rgba(30,41,59,0.85), rgba(15,23,42,0.9)), url('<?php echo $bgUrl; ?>?v=<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-color: #0f172a;
            <?php else: ?>
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            <?php endif; ?>
        }
        
        /* Floating particles effect */
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(99, 102, 241, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(16, 185, 129, 0.06) 0%, transparent 50%);
            pointer-events: none;
        }
        
        .login-box {
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4),
                        0 0 0 1px rgba(255, 255, 255, 0.05),
                        inset 0 1px 0 rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(71, 85, 105, 0.5);
            width: 100%;
            max-width: 320px;
            position: relative;
            z-index: 1;
            animation: slideUp 0.4s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Glow effect on hover */
        .login-box::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 17px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.3), rgba(16, 185, 129, 0.3));
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .login-box:hover::before {
            opacity: 0.5;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 1rem;
            position: relative;
        }
        
        .business-logo-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            display: block;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.3));
        }
        
        .business-logo-img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            margin-bottom: 0.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
            border: 2px solid rgba(255,255,255,0.1);
        }
        
        .login-logo {
            font-size: 1.15rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.2rem;
            letter-spacing: -0.3px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .login-subtitle {
            color: #94a3b8;
            font-size: 0.72rem;
            font-weight: 500;
            margin-top: 0.15rem;
        }
        
        .form-group {
            margin-bottom: 0.85rem;
        }
        
        .form-label {
            display: block;
            color: #e2e8f0;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 1rem;
            user-select: none;
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: #cbd5e1;
        }
        
        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(71, 85, 105, 0.6);
            border-radius: 8px;
            color: #e2e8f0;
            font-size: 0.85rem;
            transition: all 0.25s ease;
        }
        
        .form-control::placeholder {
            color: #64748b;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15),
                        0 0 20px rgba(99, 102, 241, 0.1);
            background: rgba(15, 23, 42, 1);
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #fca5a5;
            padding: 0.65rem 0.75rem;
            border-radius: 8px;
            margin-bottom: 0.85rem;
            text-align: center;
            font-size: 0.75rem;
            backdrop-filter: blur(4px);
        }
        
        .database-status {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.7));
            border: 1px solid rgba(51, 65, 85, 0.6);
            padding: 0.65rem 0.85rem;
            border-radius: 10px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            backdrop-filter: blur(4px);
        }
        
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 10px rgba(16, 185, 129, 1), inset 0 0 3px rgba(255, 255, 255, 0.3);
            animation: blink 1.2s ease-in-out infinite;
            flex-shrink: 0;
        }
        
        @keyframes blink {
            0% {
                background: #10b981;
                box-shadow: 0 0 10px rgba(16, 185, 129, 1), inset 0 0 3px rgba(255, 255, 255, 0.3);
            }
            50% {
                background: #059669;
                box-shadow: 0 0 15px rgba(16, 185, 129, 0.8), inset 0 0 5px rgba(255, 255, 255, 0.2);
            }
            100% {
                background: #10b981;
                box-shadow: 0 0 10px rgba(16, 185, 129, 1), inset 0 0 3px rgba(255, 255, 255, 0.3);
            }
        }
        
        .db-info {
            flex: 1;
        }
        
        .db-label {
            font-size: 0.55rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.15rem;
            font-weight: 600;
        }
        
        .db-name {
            font-size: 0.78rem;
            color: #10b981;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            text-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
        }
        
        .remember-me-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .remember-me-wrapper input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #818cf8;
        }
        
        .remember-me-wrapper label {
            color: #cbd5e1;
            font-size: 0.75rem;
            cursor: pointer;
            margin-bottom: 0;
            user-select: none;
        }
        
        .demo-credentials {
            background: rgba(51, 65, 85, 0.6);
            border: 1px solid rgba(71, 85, 105, 0.4);
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            margin-top: 1rem;
            font-size: 0.72rem;
            color: #cbd5e1;
            backdrop-filter: blur(4px);
        }
        
        .demo-credentials strong {
            color: #818cf8;
        }
        
        .demo-credentials-clickable {
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .demo-credentials-clickable:hover {
            background: rgba(71, 85, 105, 0.6);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(129, 140, 248, 0.2);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(51, 65, 85, 0.5);
            color: #64748b;
            font-size: 0.7rem;
        }
        
        .login-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .login-buttons button {
            flex: 1;
            padding: 0.65rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .login-buttons button::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(rgba(255,255,255,0.2), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .login-buttons button:hover::after {
            opacity: 1;
        }
        
        .btn-owner {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: white;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.2);
        }
        
        .btn-owner:hover {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }
        
        /* Responsive */
        @media (max-width: 360px) {
            .login-box {
                padding: 1.25rem;
                max-width: 95%;
            }
            .login-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <?php if ($loginLogoUrl): ?>
                <img src="<?php echo $loginLogoUrl; ?>?v=<?php echo time(); ?>" alt="Logo" class="business-logo-img">
                <?php else: ?>
                <span class="business-logo-icon"><?php echo $displayInfo['icon']; ?></span>
                <?php endif; ?>
                <h1 class="login-logo"><?php echo $displayInfo['name']; ?></h1>
                <p class="login-subtitle"><?php echo $displayInfo['subtitle']; ?></p>
                <?php if (isset($_GET['biz'])): ?>
                    <p class="login-subtitle">Hotel System</p>
                <?php endif; ?>
            </div>
            
            <div class="database-status">
                <div class="status-indicator"></div>
                <div class="db-info">
                    <div class="db-label">DATABASE</div>
                    <div class="db-name"><?php echo $displayInfo['db_name']; ?></div>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert-danger">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Masukkan username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Masukkan password" required style="padding-right: 45px;">
                        <span class="password-toggle" onclick="togglePassword('loginPassword', this)">👁️</span>
                    </div>
                </div>
                
                <div class="remember-me-wrapper">
                    <input type="checkbox" name="remember_me" id="rememberMe">
                    <label for="rememberMe">💾 Simpan Password</label>
                </div>
                
                <div class="login-buttons">
                    <button type="submit" name="login_type" value="owner" class="btn-owner">📊 Login Owner</button>
                    <button type="submit" name="login_type" value="normal" class="btn-primary">🏢 Login System</button>
                </div>
            </form>
            
            <div class="demo-credentials demo-credentials-clickable" onclick="fillDemoCredentials()" title="Klik untuk isi otomatis">
                <div style="text-align: center; margin-bottom: 0.5rem;"><strong>🎯 Demo Credentials (Click to Fill)</strong></div>
                <div id="demoUsername">👤 Username: <strong><?php echo htmlspecialchars($demoUsername); ?></strong></div>
                <div id="demoPassword">🔑 Password: <strong><?php echo htmlspecialchars($demoPassword); ?></strong></div>
            </div>
            
            <div class="login-footer">
                &copy; <?php echo APP_YEAR; ?> <?php echo APP_NAME; ?>
            </div>
        </div>
    </div>
    
    <script>
    // Toggle password visibility
    function togglePassword(inputId, iconElement) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            iconElement.textContent = '👁️‍🗨️';
        } else {
            input.type = 'password';
            iconElement.textContent = '👁️';
        }
    }
    
    // Fill demo credentials
    function fillDemoCredentials() {
        const demoUsername = document.getElementById('demoUsername').querySelector('strong').textContent.trim();
        const demoPassword = document.getElementById('demoPassword').querySelector('strong').textContent.trim();
        
        document.querySelector('input[name="username"]').value = demoUsername;
        document.querySelector('input[name="password"]').value = demoPassword;
        
        // Focus on submit button
        document.querySelector('.btn-primary').focus();
    }
    
    // Save credentials to localStorage
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        const usernameInput = document.querySelector('input[name="username"]');
        const passwordInput = document.querySelector('input[name="password"]');
        const rememberCheckbox = document.getElementById('rememberMe');
        
        // Load saved credentials
        const savedUsername = localStorage.getItem('saved_username');
        const savedPassword = localStorage.getItem('saved_password');
        const rememberMe = localStorage.getItem('remember_me') === 'true';
        
        if (rememberMe && savedUsername && savedPassword) {
            usernameInput.value = savedUsername;
            passwordInput.value = savedPassword;
            rememberCheckbox.checked = true;
        }
        
        // Save on form submit
        form.addEventListener('submit', function() {
            if (rememberCheckbox.checked) {
                localStorage.setItem('saved_username', usernameInput.value);
                localStorage.setItem('saved_password', passwordInput.value);
                localStorage.setItem('remember_me', 'true');
            } else {
                localStorage.removeItem('saved_username');
                localStorage.removeItem('saved_password');
                localStorage.removeItem('remember_me');
            }
        });
    });
    </script>
</body>
</html>
