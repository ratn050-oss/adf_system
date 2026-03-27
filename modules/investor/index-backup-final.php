<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Temporary: skip permission check
// if (!$auth->hasPermission('investor')) {
//     header('HTTP/1.1 403 Forbidden');
//     echo "You do not have permission to access this module.";
//     exit;
// }

$pageTitle = 'Manajemen Investor';
$inlineStyles = '<style>
.test-container { 
    padding: 2rem; 
    background: var(--bg-secondary);
    border-radius: 12px;
    margin: 2rem;
}
.test-container h2 {
    color: var(--text-primary);
}
</style>';

include '../../includes/header.php'; 
?>

<main class="main-content">
    <div class="test-container">
        <h2>✅ Test Investor Module</h2>
        <p>Jika Anda bisa melihat pesan ini, berarti header.php bekerja dengan baik!</p>
        <ul>
            <li>Sidebar: ✅ Muncul di kiri</li>
            <li>Header Content: ✅ Loaded</li>
            <li>Theme: <span id="themeCheck"></span></li>
        </ul>
        <hr>
        <h3>Langkah Selanjutnya:</h3>
        <p>1. Install database tables dulu</p>
        <p>2. Kemudian restore konten lengkap</p>
        <a href="<?php echo BASE_URL; ?>/install-investor-project.php" 
           style="display:inline-block;padding:0.75rem 1.5rem;background:#667eea;color:white;text-decoration:none;border-radius:8px;margin-top:1rem;">
            🔧 Install Database Tables
        </a>
    </div>
</main>

<?php
$inlineScript = '
const theme = document.body.getAttribute("data-theme");
document.getElementById("themeCheck").textContent = theme + " ✅";
console.log("Investor module test loaded successfully");
';
include '../../includes/footer.php';
?>
