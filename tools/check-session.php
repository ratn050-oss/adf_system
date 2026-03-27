<?php
session_start();
header('Content-Type: text/plain');

echo "===========================================\n";
echo "SESSION INFORMATION\n";
echo "===========================================\n\n";

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    echo "✅ User is logged in\n\n";
    
    echo "User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "\n";
    echo "Username: " . ($_SESSION['username'] ?? 'Not set') . "\n";
    echo "Full Name: " . ($_SESSION['full_name'] ?? 'Not set') . "\n";
    echo "Role: " . ($_SESSION['role'] ?? 'Not set') . "\n";
    echo "Business Access: " . ($_SESSION['business_access'] ?? 'Not set') . "\n";
    
    if (isset($_SESSION['business_access'])) {
        $accessIds = json_decode($_SESSION['business_access'], true);
        echo "\nDecoded Business IDs: ";
        if (is_array($accessIds)) {
            echo implode(', ', $accessIds) . "\n";
            echo "Count: " . count($accessIds) . " businesses\n";
        } else {
            echo "ERROR - Not an array!\n";
        }
    }
    
} else {
    echo "❌ User is NOT logged in\n\n";
    echo "Please login first:\n";
    echo "http://localhost:8080/narayana/owner-login.php\n";
}

echo "\n===========================================\n";
echo "ALL SESSION DATA:\n";
echo "===========================================\n";
print_r($_SESSION);
