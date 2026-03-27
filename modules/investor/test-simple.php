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

$pageTitle = 'Test Simple';
$inlineStyles = '<style>.test { color: red; }</style>';

echo "Before include header<br>";
include '../../includes/header.php'; 
echo "After include header<br>";
?>

<main class="main-content">
    <div style="padding: 2rem;">
        <h1>Test Simple Page</h1>
        <p>If you can see this, header works!</p>
    </div>
</main>

<?php
$inlineScript = 'console.log("Test script works");';
include '../../includes/footer.php';
?>
