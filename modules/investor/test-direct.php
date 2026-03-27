<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "Step 1: PHP works<br>";

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));
echo "Step 2: Base path: $base_path<br>";

require_once $base_path . '/config/config.php';
echo "Step 3: Config loaded<br>";

require_once $base_path . '/config/database.php';
echo "Step 4: Database loaded<br>";

require_once $base_path . '/includes/auth.php';
echo "Step 5: Auth loaded<br>";

$auth = new Auth();
$auth->requireLogin();
echo "Step 6: Login checked, user: " . $_SESSION['user_id'] . "<br>";

if (!$auth->hasPermission('investor')) {
    echo "Step 7: FAILED - No investor permission!<br>";
    exit;
}
echo "Step 7: Permission OK<br>";

$pageTitle = 'Test';
echo "Step 8: About to include header<br>";
ob_start();
include '../../includes/header.php';
$header = ob_get_clean();
echo "Step 9: Header included, length: " . strlen($header) . "<br>";
echo "Step 10: Header content:<br>";
echo "<pre>" . htmlspecialchars(substr($header, 0, 500)) . "</pre>";
?>
