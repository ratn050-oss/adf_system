<?php
// Load helper functions
require_once __DIR__ . '/functions.php';

// Get favicon from settings
$faviconUrl = null;
try {
    if (class_exists('Database')) {
        $db = Database::getInstance();
        $faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
        $faviconFile = $faviconSetting['setting_value'] ?? null;
        if ($faviconFile && file_exists(BASE_PATH . '/uploads/icons/' . $faviconFile)) {
            $faviconUrl = BASE_URL . '/uploads/icons/' . $faviconFile;
        }
    }
} catch (Exception $e) {
    // Silent fail
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['user_language'] ?? 'id'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <!-- Favicon -->
    <?php if ($faviconUrl): ?>
    <link rel="icon" type="image/x-icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <?php endif; ?>
    
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Main CSS with Cache Busting -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    
    <!-- Icons (Feather Icons) -->
    <script src="https://unpkg.com/feather-icons"></script>
    
    <!-- Additional CSS -->
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL . '/' . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Inline Styles -->
    <?php if (isset($inlineStyles)): ?>
        <?php echo $inlineStyles; ?>
    <?php endif; ?>
    
    <!-- Business Theme CSS -->
    <style>
        <?php echo getBusinessThemeCSS(); ?>
    </style>
</head>
<?php
// Load user theme from database per business (reliable method)
$userTheme = 'dark';
$themeError = null;

if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $db = Database::getInstance();
        
        // Load theme for current business and user
        $themeResult = $db->fetchOne(
            "SELECT theme FROM user_preferences WHERE user_id = ? AND branch_id = ? LIMIT 1",
            [$_SESSION['user_id'], ACTIVE_BUSINESS_ID]
        );
        
        if ($themeResult && !empty($themeResult['theme'])) {
            $userTheme = $themeResult['theme'];
        } else {
            // Fallback: try to find any preference for this user
            $fallbackTheme = $db->fetchOne(
                "SELECT theme FROM user_preferences WHERE user_id = ? LIMIT 1",
                [$_SESSION['user_id']]
            );
            
            if ($fallbackTheme && !empty($fallbackTheme['theme'])) {
                $userTheme = $fallbackTheme['theme'];
            }
        }
        
    } catch (Exception $e) {
        $userTheme = 'dark';
        $themeError = $e->getMessage();
        // Don't die, just use default theme
    }
}
?>
<body data-theme="<?php echo htmlspecialchars($userTheme); ?>" data-business="<?php echo ACTIVE_BUSINESS_ID; ?>" data-business-type="<?php echo BUSINESS_TYPE; ?>">
<?php if ($themeError): ?>
    <!-- Theme Load Warning: <?php echo htmlspecialchars($themeError); ?> -->
<?php endif; ?>
    <div class="main-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <?php
                // Get business logo
                $logoPath = getBusinessLogo();
                
                // Get company name from settings, fallback to BUSINESS_NAME
                $companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
                $displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
                    ? $companyNameSetting['setting_value'] 
                    : BUSINESS_NAME;
                ?>
                <div style="display: flex; align-items: center; gap: 0.875rem;">
                    <?php if ($logoPath): ?>
                        <div style="width: 48px; height: 48px; border-radius: var(--radius-md); background: #0f172a; padding: 4px; display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                            <img src="<?php echo $logoPath; ?>" alt="<?php echo htmlspecialchars($displayCompanyName); ?>" style="width: 100%; height: 100%; border-radius: 6px; object-fit: cover;">
                        </div>
                    <?php else: ?>
                        <div style="width: 48px; height: 48px; border-radius: var(--radius-md); background: linear-gradient(135deg, <?php echo BUSINESS_COLOR; ?>, <?php echo BUSINESS_COLOR; ?>dd); display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                            <span style="font-size: 1.5rem; font-weight: 800; color: white;"><?php echo BUSINESS_ICON; ?></span>
                        </div>
                    <?php endif; ?>
                    <div style="flex: 1;">
                        <h1 class="logo" style="margin: 0; font-size: 1rem;"><?php echo htmlspecialchars($displayCompanyName); ?></h1>
                        <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0; margin-top: 0.25rem;"><?php echo ucfirst(BUSINESS_TYPE); ?> System</p>
                    </div>
                </div>
                
                <!-- Business Switcher Dropdown (Only show if user has multiple business access) -->
                <?php
                require_once __DIR__ . '/business_access.php';
                $userBusinesses = getUserAvailableBusinesses();
                if (count($userBusinesses) > 1):
                ?>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                    <label style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; display: block;">Switch Business</label>
                    <select onchange="switchBusiness(this.value)" style="width: 100%; padding: 0.5rem; background: var(--bg-tertiary); border: 1px solid var(--bg-quaternary); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.875rem; cursor: pointer;">
                        <?php
                        foreach ($userBusinesses as $bizId => $bizConfig):
                            $selected = ($bizId === ACTIVE_BUSINESS_ID) ? 'selected' : '';
                        ?>
                            <option value="<?php echo htmlspecialchars($bizId); ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($bizConfig['theme']['icon'] . ' ' . $bizConfig['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Database Indicator -->
                <div style="margin-top: 0.75rem; padding: 0.625rem; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: var(--radius-md);">
                    <div style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem;">Database</div>
                    <div style="font-size: 0.75rem; color: var(--primary-color); font-weight: 600; word-break: break-all;">
                        <?php 
                        require_once __DIR__ . '/../config/database.php';
                        $currentDb = Database::getCurrentDatabase();
                        echo htmlspecialchars($currentDb);
                        ?>
                    </div>
                </div>
            </div>
            
            <nav style="flex: 1; overflow-y: auto; overflow-x: hidden;">
                <ul class="nav-menu">
                    <?php if ($auth->hasPermission('dashboard')): ?>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>/index.php" class="nav-link <?php echo activeMenu('index.php'); ?>">
                            <i data-feather="home" class="nav-icon"></i>
                            <span><?php echo __('dashboard.title'); ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasPermission('cashbook')): ?>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="nav-link <?php echo activeMenu('cashbook'); ?>">
                            <i data-feather="book" class="nav-icon"></i>
                            <span><?php echo __('cashbook.title'); ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasPermission('divisions')): ?>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>/modules/divisions/index.php" class="nav-link <?php echo activeMenu('divisions'); ?>">
                            <i data-feather="grid" class="nav-icon"></i>
                            <span><?php echo __('settings.divisions'); ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasPermission('frontdesk') && isModuleEnabled('frontdesk')): ?>
                    <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/frontdesk/') !== false) ? 'open' : ''; ?>">
                        <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('frontdesk'); ?>">
                            <i data-feather="home" class="nav-icon"></i>
                            <span>Front Desk</span>
                        </a>
                        <ul class="submenu">
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                    <i data-feather="layout" class="submenu-icon"></i>
                                    <span>Dashboard</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/reservasi.php" class="submenu-link <?php echo activeMenu('reservasi.php'); ?>">
                                    <i data-feather="calendar" class="submenu-icon"></i>
                                    <span>Reservasi</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/calendar.php" class="submenu-link <?php echo activeMenu('calendar.php'); ?>">
                                    <i data-feather="grid" class="submenu-icon"></i>
                                    <span>Calendar View</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/in-house.php" class="submenu-link <?php echo activeMenu('in-house.php'); ?>">
                                    <i data-feather="users" class="submenu-icon"></i>
                                    <span>Tamu In House</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/breakfast.php" 
                                   class="submenu-link <?php echo activeMenu('breakfast.php'); ?>"
                                   onclick="console.log('Breakfast link clicked!'); return true;">
                                    <i data-feather="coffee" class="submenu-icon"></i>
                                    <span>Breakfast Order</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/laporan.php" class="submenu-link <?php echo activeMenu('laporan.php'); ?>">
                                    <i data-feather="file-text" class="submenu-icon"></i>
                                    <span>Laporan</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/settings.php" class="submenu-link <?php echo activeMenu('settings.php'); ?>">
                                    <i data-feather="settings" class="submenu-icon"></i>
                                    <span>Pengaturan</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Sales Invoice Menu -->
                    <?php if ($auth->hasPermission('sales_invoice') && isModuleEnabled('sales')): ?>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>/modules/sales/" class="nav-link <?php echo activeMenu('sales'); ?>">
                            <i data-feather="file-text" class="nav-icon"></i>
                            <span>Sales Invoice</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Procurement Dropdown Menu -->
                    <?php if ($auth->hasPermission('procurement') && isModuleEnabled('procurement')): ?>
                    <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/procurement/') !== false) ? 'open' : ''; ?>">
                        <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('procurement'); ?>">
                            <i data-feather="shopping-cart" class="nav-icon"></i>
                            <span>PO & SHOOP</span>
                        </a>
                        <ul class="submenu">
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/procurement/purchase-orders.php" class="submenu-link <?php echo activeMenu('purchase-orders.php'); ?>">
                                    <i data-feather="file-plus" class="submenu-icon"></i>
                                    <span>Purchase Orders</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/procurement/purchases.php" class="submenu-link <?php echo activeMenu('purchases.php'); ?>">
                                    <i data-feather="package" class="submenu-icon"></i>
                                    <span>Purchase Invoices</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/procurement/suppliers.php" class="submenu-link <?php echo activeMenu('suppliers.php'); ?>">
                                    <i data-feather="users" class="submenu-icon"></i>
                                    <span>Suppliers</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Laporan Dropdown Menu -->
                    <?php if ($auth->hasPermission('reports') && isModuleEnabled('reports')): ?>
                    <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/reports/') !== false) ? 'open' : ''; ?>">
                        <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('reports'); ?>">
                            <i data-feather="bar-chart-2" class="nav-icon"></i>
                            <span><?php echo 'Reports'; ?></span>
                        </a>
                        <ul class="submenu">
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/reports/daily.php" class="submenu-link <?php echo activeMenu('daily.php'); ?>">
                                    <i data-feather="calendar" class="submenu-icon"></i>
                                    <span>Laporan Harian</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/reports/monthly.php" class="submenu-link <?php echo activeMenu('monthly.php'); ?>">
                                    <i data-feather="trending-up" class="submenu-icon"></i>
                                    <span>Laporan Bulanan</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/reports/yearly.php" class="submenu-link <?php echo activeMenu('yearly.php'); ?>">
                                    <i data-feather="activity" class="submenu-icon"></i>
                                    <span>Laporan Tahunan</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/reports/by-division.php" class="submenu-link <?php echo activeMenu('by-division.php'); ?>">
                                    <i data-feather="grid" class="submenu-icon"></i>
                                    <span>Laporan Per Divisi</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Invest & Projek Menu -->
                    <?php if ($auth->hasPermission('investor')): ?>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>/modules/investor/" class="nav-link <?php echo activeMenu('investor'); ?>">
                            <i data-feather="briefcase" class="nav-icon"></i>
                            <span>Invest & Projek</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasPermission('settings')): ?>
                    <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/settings/') !== false) ? 'open' : ''; ?>" style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                        <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('settings'); ?>">
                            <i data-feather="settings" class="nav-icon"></i>
                            <span><?php echo __('settings.title'); ?></span>
                        </a>
                        <ul class="submenu">
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/settings/" class="submenu-link <?php echo activeMenu('settings-index'); ?>">
                                    <i data-feather="home" class="submenu-icon"></i>
                                    <span>Beranda Settings</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/settings/change-password.php" class="submenu-link <?php echo activeMenu('change-password.php'); ?>">
                                    <i data-feather="lock" class="submenu-icon"></i>
                                    <span>Ganti Password</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/settings/company.php" class="submenu-link <?php echo activeMenu('company.php'); ?>">
                                    <i data-feather="briefcase" class="submenu-icon"></i>
                                    <span>Setup Perusahaan</span>
                                </a>
                            </li>
                            <li class="submenu-item">
                                <a href="<?php echo BASE_URL; ?>/modules/settings/display.php" class="submenu-link <?php echo activeMenu('display.php'); ?>">
                                    <i data-feather="eye" class="submenu-icon"></i>
                                    <span>Display & Theme</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item" style="margin-top: 2rem;">
                        <a href="<?php echo BASE_URL; ?>/logout.php" class="nav-link">
                            <i data-feather="log-out" class="nav-icon"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <!-- Sidebar Footer -->
            <?php
            // Get custom footer version from settings
            $footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
            $sidebarFooterVersion = $footerVersionSetting['setting_value'] ?? ('Version ' . APP_VERSION);
            ?>
            <div class="sidebar-footer" style="padding: 1rem; border-top: 1px solid var(--bg-tertiary); margin-top: auto;">
                <div style="display: flex; align-items: center; gap: 0.875rem; margin-bottom: 0.75rem;">
                    <?php
                    $devLogoPath = BASE_PATH . '/' . DEVELOPER_LOGO;
                    if (file_exists($devLogoPath)):
                    ?>
                        <div style="width: 52px; height: 52px; border-radius: 8px; background: #0f172a; padding: 4px; display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                            <img src="<?php echo BASE_URL . '/' . DEVELOPER_LOGO; ?>?v=<?php echo filemtime($devLogoPath); ?>" alt="Developer Logo" style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                    <?php else: ?>
                        <div style="width: 52px; height: 52px; border-radius: 8px; background: var(--primary-color); display: flex; align-items: center; justify-content: center; border: 2px solid #1e293b;">
                            <span style="font-size: 1.5rem; font-weight: 700; color: white;">&lt;/&gt;</span>
                        </div>
                    <?php endif; ?>
                    <div style="flex: 1;">
                        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary);"><?php echo DEVELOPER_NAME; ?></div>
                        <div style="font-size: 0.7rem; color: var(--text-muted);">Developer</div>
                    </div>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-muted); text-align: center; padding-top: 0.5rem; border-top: 1px solid var(--bg-tertiary);">
                    <?php echo htmlspecialchars($sidebarFooterVersion); ?> • <?php echo APP_YEAR; ?>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div>
                    <h1 class="page-title"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
                    <?php if (isset($pageSubtitle)): ?>
                        <p style="color: var(--text-muted); margin-top: 0.5rem;"><?php echo $pageSubtitle; ?></p>
                    <?php endif; ?>
                </div>
                
                <div style="display: flex; align-items: center; gap: 1.5rem;">
                    <!-- End Shift Button -->
                            <a id="endShiftButton" href="<?php echo BASE_URL; ?>/print-end-shift-report.php" target="_blank" rel="noopener"
                               style="padding: 0.5rem 1rem; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: all 0.3s; text-decoration: none;">
                                <i data-feather="power" style="width: 18px; height: 18px;"></i>
                                <span>End Shift</span>
                            </a>

                    <!-- Date & Time Display -->
                    <div style="text-align: right; padding-right: 1.5rem; border-right: 1px solid var(--bg-tertiary);">
                        <div style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary);" id="currentDate">
                            <?php echo date('d/m/Y'); ?>
                        </div>
                        <div style="font-size: 0.875rem; font-weight: 700; color: var(--primary-color); font-variant-numeric: tabular-nums;" id="currentTime">
                            <?php echo date('H:i:s'); ?>
                        </div>
                    </div>
                    
                    <!-- User Info -->
                    <div class="user-info">
                        <div style="text-align: right; margin-right: 1rem;">
                            <div style="font-weight: 600; color: var(--text-primary);">
                                <?php echo $_SESSION['full_name'] ?? 'User'; ?>
                            </div>
                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                <?php echo ucfirst($_SESSION['role'] ?? 'staff'); ?>
                            </div>
                        </div>
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <?php if ($success = getFlash('success')): ?>
                <div class="alert alert-success fade-in" style="background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="check-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error = getFlash('error')): ?>
                <div class="alert alert-danger fade-in" style="background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="alert-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- Page Content -->
            <div class="page-content">

<script>
// Business Switcher Function
function switchBusiness(businessId) {
    if (confirm('Switch to selected business? Current page will reload.')) {
        // Send AJAX request to switch business
        fetch('<?php echo BASE_URL; ?>/api/switch-business.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'business_id=' + encodeURIComponent(businessId)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload page to apply new business
                window.location.reload();
            } else {
                alert('Failed to switch business: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to switch business. Please try again.');
        });
    } else {
        // Reset select to current value
        document.querySelector('select[onchange*="switchBusiness"]').value = '<?php echo ACTIVE_BUSINESS_ID; ?>';
    }
}
</script>

