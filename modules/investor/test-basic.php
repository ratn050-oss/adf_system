<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "Test 1: PHP Works<br>";

define('APP_ACCESS', true);
echo "Test 2: Define works<br>";

$base_path = dirname(dirname(dirname(__FILE__)));
echo "Test 3: Base path: " . $base_path . "<br>";

require_once $base_path . '/config/config.php';
echo "Test 4: Config loaded<br>";

require_once $base_path . '/config/database.php';
echo "Test 5: Database loaded<br>";

require_once $base_path . '/includes/auth.php';
echo "Test 6: Auth loaded<br>";

$auth = new Auth();
echo "Test 7: Auth created<br>";

$auth->requireLogin();
echo "Test 8: Login checked<br>";

echo "<hr>";
echo "<h1>All tests passed!</h1>";
echo "<p>File location: " . __FILE__ . "</p>";
echo "<p>Session user: " . ($_SESSION['user_id'] ?? 'not set') . "</p>";
?>
