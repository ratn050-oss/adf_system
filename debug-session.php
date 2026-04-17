<?php
/**
 * DEBUG: Check session and cookies
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== SESSION & COOKIE DEBUG ===\n\n";

// 1. Check if session started
echo "1. SESSION STATUS:\n";
echo "   Session status: " . session_status() . " (1=disabled, 2=none, 3=active)\n";
echo "   Session name: " . session_name() . "\n";
echo "   Session ID: " . session_id() . "\n\n";

// 2. Start/resume session
if (session_status() === PHP_SESSION_NONE) {
    session_name('NARAYANA_SESSION');
    session_start();
    echo "   Started new session\n";
} else {
    echo "   Session already active\n";
}

echo "   Session ID after start: " . session_id() . "\n";
echo "   Session data: " . json_encode($_SESSION) . "\n\n";

// 3. Check cookies
echo "2. COOKIES:\n";
echo "   $_COOKIE: " . json_encode($_COOKIE) . "\n\n";

// 4. Check request headers
echo "3. REQUEST HEADERS:\n";
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        echo "   $name: $value\n";
    }
} else {
    echo "   getallheaders() not available\n";
    echo "   HTTP_COOKIE: " . ($_SERVER['HTTP_COOKIE'] ?? 'NOT SET') . "\n";
}

echo "\n4. SERVER INFO:\n";
echo "   HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "   REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'NOT SET') . "\n";
echo "   SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'NOT SET') . "\n";

// 5. Try to read auth from different methods
echo "\n5. AUTH ATTEMPTS:\n";
echo "   Via SESSION: " . (isset($_SESSION['active_business_id']) ? $_SESSION['active_business_id'] : 'NOT FOUND') . "\n";
echo "   Via COOKIE: " . ($_COOKIE['NARAYANA_SESSION'] ?? 'NOT FOUND') . "\n";
echo "   Via GET: " . ($_GET['token'] ?? 'NOT FOUND') . "\n";

// 6. Check session file on disk
echo "\n6. SESSION FILE:\n";
$sessionPath = session_save_path();
$sessionId = session_id();
echo "   Session save path: " . $sessionPath . "\n";
echo "   Session ID: " . $sessionId . "\n";

if ($sessionId && $sessionPath) {
    $sessionFile = $sessionPath . '/sess_' . $sessionId;
    echo "   Expected file: " . $sessionFile . "\n";
    echo "   File exists: " . (file_exists($sessionFile) ? "YES" : "NO") . "\n";
    
    if (file_exists($sessionFile)) {
        $content = file_get_contents($sessionFile);
        echo "   File size: " . strlen($content) . " bytes\n";
        echo "   File content: " . substr($content, 0, 100) . "...\n";
    }
}

echo "\n=== END DEBUG ===\n";
?>
