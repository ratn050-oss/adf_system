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
                $error = 'User tidak terdaftar di sistem! Hubungi developer untuk setup akses.';
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
                        $error = 'Akses ditolak! Hanya Owner yang dapat mengakses Owner Dashboard.';
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
                    $error = 'Anda tidak memiliki akses ke bisnis manapun! Hubungi developer.';
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
                        setActiveBusinessId($forcedBusiness);
                        setFlash('success', 'Login berhasil!');
                        redirect(BASE_URL . '/index.php');
                    } else {
                        $error = 'Anda tidak punya akses ke bisnis tersebut!';
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
        $error = 'Username atau password salah!';
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
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    
    <style>
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
        
        .login-box {
            background: #1e293b;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 15px 20px -5px rgba(0, 0, 0, 0.15);
            border: 1px solid #334155;
            width: 100%;
            max-width: 300px;
            position: relative;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 0.75rem;
            position: relative;
        }
        
        .business-logo-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .business-logo-img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            margin-bottom: 0.5rem;
            border-radius: 6px;
        }
        
        .login-logo {
            font-size: 1.1rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.15rem;
            letter-spacing: -0.3px;
        }
        
        .login-subtitle {
            color: #94a3b8;
            font-size: 0.7rem;
            font-weight: 500;
            margin-top: 0.15rem;
        }
        
        .form-group {
            margin-bottom: 0.75rem;
        }
        
        .form-label {
            display: block;
            color: #e2e8f0;
            font-size: 0.75rem;
            font-weight: 500;
            margin-bottom: 0.35rem;
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
            padding: 0.5rem 0.65rem;
            background: #0f172a;
            border: 1px solid #475569;
            border-radius: 6px;
            color: #e2e8f0;
            font-size: 0.85rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid #ef4444;
            color: #fca5a5;
            padding: 0.6rem;
            border-radius: 6px;
            margin-bottom: 0.75rem;
            text-align: center;
            font-size: 0.75rem;
        }
        
        .database-status {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.8), rgba(15, 23, 42, 0.6));
            border: 1px solid #334155;
            padding: 0.6rem 0.75rem;
            border-radius: 6px;
            margin-top: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            font-size: 0.6rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 0.1rem;
        }
        
        .db-name {
            font-size: 0.75rem;
            color: #e2e8f0;
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }
        
        .demo-credentials {
            background: #334155;
            padding: 0.6rem 0.75rem;
            border-radius: 6px;
            margin-top: 1rem;
            font-size: 0.7rem;
            color: #cbd5e1;
        }
        
        .demo-credentials strong {
            color: #6366f1;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #334155;
            color: #64748b;
            font-size: 0.7rem;
        }
        
        .login-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        
        .login-buttons button {
            flex: 1;
            padding: 0.6rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }
        
        .btn-owner {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: white;
        }
        
        .btn-owner:hover {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
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
                
                <div class="login-buttons">
                    <button type="submit" name="login_type" value="owner" class="btn-owner">📊 Login Owner</button>
                    <button type="submit" name="login_type" value="normal" class="btn-primary">🏢 Login System</button>
                </div>
            </form>
            
            <div class="demo-credentials">
                <div style="text-align: center; margin-bottom: 0.5rem;"><strong>Demo Credentials:</strong></div>
                <div>👤 Username: <strong>admin</strong></div>
                <div>🔑 Password: <strong>admin</strong></div>
            </div>
            
            <div class="login-footer">
                &copy; <?php echo APP_YEAR; ?> <?php echo APP_NAME; ?>
            </div>
        </div>
    </div>
    
    <script>
    function togglePassword(inputId, iconElement) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            iconElement.textContent = '👁️‍🗨️'; // Eye with slash
        } else {
            input.type = 'password';
            iconElement.textContent = '👁️'; // Normal eye
        }
    }
    </script>
</body>
</html>
