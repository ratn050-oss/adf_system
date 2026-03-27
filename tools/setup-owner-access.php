<?php
/**
 * Quick Setup: Grant Owner Access to All Businesses
 * Jalankan ini untuk memberikan akses semua bisnis ke owner/admin
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/businesses.php';

echo "<h2>Setup Owner Business Access</h2>";

$db = Database::getInstance();

// Get all owner and admin users
$users = $db->fetchAll(
    "SELECT id, username, full_name, role, business_access FROM users WHERE role IN ('owner', 'admin')"
);

echo "<h3>Current Users:</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Username</th><th>Name</th><th>Role</th><th>Current Access</th><th>Action</th></tr>";

foreach ($users as $user) {
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['username']}</td>";
    echo "<td>{$user['full_name']}</td>";
    echo "<td>{$user['role']}</td>";
    echo "<td>" . ($user['business_access'] ?: 'NULL') . "</td>";
    echo "<td>";
    
    if (isset($_GET['grant']) && $_GET['grant'] == $user['id']) {
        // Grant access to all businesses
        $allBusinessIds = array_column($BUSINESSES, 'id');
        $businessAccessJson = json_encode($allBusinessIds);
        
        $db->query(
            "UPDATE users SET business_access = ? WHERE id = ?",
            [$businessAccessJson, $user['id']]
        );
        
        // Also update in all business databases
        foreach ($BUSINESSES as $business) {
            try {
                // Use database name directly in query
                $db->query(
                    "UPDATE {$business['database']}.users SET business_access = ? WHERE id = ?",
                    [$businessAccessJson, $user['id']]
                );
            } catch (Exception $e) {
                // Skip if database doesn't exist
            }
        }
        
        echo "<strong style='color:green'>✅ GRANTED ACCESS TO ALL BUSINESSES!</strong>";
        echo "<br><a href='setup-owner-access.php'>Refresh</a>";
    } else {
        echo "<a href='?grant={$user['id']}' style='background:#4CAF50;color:white;padding:5px 10px;text-decoration:none;border-radius:5px;'>Grant All Access</a>";
    }
    
    echo "</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h3>Available Businesses:</h3>";
echo "<ul>";
foreach ($BUSINESSES as $business) {
    echo "<li>ID {$business['id']}: {$business['name']} - {$business['database']}</li>";
}
echo "</ul>";

echo "<hr>";
echo "<p><strong>Quick Action:</strong></p>";
echo "<form method='GET'>";
echo "<p>Grant access to user ID: <input type='number' name='grant' required> <button type='submit'>Grant All Access</button></p>";
echo "</form>";

echo "<hr>";
echo "<p><a href='../modules/owner/dashboard.php'>→ Go to Owner Dashboard</a></p>";
echo "<p><a href='manage-user-access.php'>→ Manage User Access (Advanced)</a></p>";
