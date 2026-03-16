<?php
/**
 * Temporary debug file to diagnose PWA icon issues on hosting
 * DELETE THIS FILE after debugging is complete
 */
define('APP_ACCESS', true);
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$debug = [];

// Step 1: Check config loads
try {
    require_once __DIR__ . '/../../config/config.php';
    $debug['config'] = 'OK';
    $debug['DB_NAME'] = defined('DB_NAME') ? DB_NAME : 'NOT_DEFINED';
    $debug['BASE_URL'] = defined('BASE_URL') ? BASE_URL : 'NOT_DEFINED';
    $debug['MASTER_DB_NAME'] = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'NOT_DEFINED';
    $debug['ACTIVE_BUSINESS_ID'] = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'NOT_DEFINED';
} catch (Exception $e) {
    $debug['config_error'] = $e->getMessage();
}

// Step 2: Check database loads
try {
    require_once __DIR__ . '/../../config/database.php';
    $debug['database_class'] = 'OK';
} catch (Exception $e) {
    $debug['database_error'] = $e->getMessage();
}

// Step 3: Check getInstance - what DB does it connect to?
try {
    $defaultDb = Database::getInstance();
    $debug['default_db'] = Database::getCurrentDatabase();
} catch (Exception $e) {
    $debug['default_db_error'] = $e->getMessage();
}

// Step 4: Switch to master DB
try {
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : 'adf_system');
    $debug['master_db_target'] = $masterDbName;
    $mdb = Database::switchDatabase($masterDbName);
    $debug['master_db_connected'] = Database::getCurrentDatabase();
} catch (Exception $e) {
    $debug['master_db_error'] = $e->getMessage();
}

// Step 5: Check if settings table exists
try {
    $tables = $mdb->fetchAll("SHOW TABLES LIKE 'settings'");
    $debug['settings_table_exists'] = !empty($tables);
} catch (Exception $e) {
    $debug['settings_table_error'] = $e->getMessage();
}

// Step 6: Query pwa_app_icon and login_logo
try {
    $pwaIcon = $mdb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'pwa_app_icon'");
    $debug['pwa_app_icon'] = $pwaIcon['setting_value'] ?? 'NULL/EMPTY';
    
    $loginLogo = $mdb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_logo'");
    $debug['login_logo'] = $loginLogo['setting_value'] ?? 'NULL/EMPTY';
} catch (Exception $e) {
    $debug['settings_query_error'] = $e->getMessage();
}

// Step 7: Check file paths
$rootDir = dirname(dirname(__DIR__));
$debug['root_dir'] = $rootDir;
$debug['script_dir'] = __DIR__;

$iconKeys = [
    'pwa_app_icon' => 'uploads/icons/',
    'login_logo'   => 'uploads/logos/',
];

foreach ($iconKeys as $key => $prefix) {
    $val = ($key === 'pwa_app_icon') ? ($pwaIcon['setting_value'] ?? '') : ($loginLogo['setting_value'] ?? '');
    if ($val) {
        $fullPath = $rootDir . '/' . $prefix . $val;
        $debug['path_' . $key] = $fullPath;
        $debug['exists_' . $key] = file_exists($fullPath);
        
        // Also check if file exists without prefix (maybe stored with full relative path)
        $altPath = $rootDir . '/' . $val;
        $debug['alt_path_' . $key] = $altPath;
        $debug['alt_exists_' . $key] = file_exists($altPath);
    }
}

// Step 8: Check uploads directory
$debug['uploads_logos_dir_exists'] = is_dir($rootDir . '/uploads/logos');
$debug['uploads_icons_dir_exists'] = is_dir($rootDir . '/uploads/icons');

// List files in uploads/logos if exists
if (is_dir($rootDir . '/uploads/logos')) {
    $files = scandir($rootDir . '/uploads/logos');
    $debug['uploads_logos_files'] = array_values(array_diff($files, ['.', '..']));
}
if (is_dir($rootDir . '/uploads/icons')) {
    $files = scandir($rootDir . '/uploads/icons');
    $debug['uploads_icons_files'] = array_values(array_diff($files, ['.', '..']));
}

// Step 9: All settings keys
try {
    $allSettings = $mdb->fetchAll("SELECT setting_key, LEFT(setting_value, 100) as val_preview FROM settings ORDER BY setting_key");
    $debug['all_settings'] = $allSettings;
} catch (Exception $e) {
    $debug['all_settings_error'] = $e->getMessage();
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
