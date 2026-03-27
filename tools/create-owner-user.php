<?php
/**
 * Create Owner/Admin User Tool
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/businesses.php';

echo "<style>body{font-family:Arial;padding:20px;} input,select{padding:8px;margin:5px;} button{background:#4CAF50;color:white;padding:10px 20px;border:none;cursor:pointer;} .success{color:green;} .error{color:red;}</style>";

echo "<h2>🔐 Create Owner/Admin User</h2>";

$db = Database::getInstance();
$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $fullName = trim($_POST['full_name']);
    $role = $_POST['role'];
    $grantAllAccess = isset($_POST['grant_all_access']);
    
    if (empty($username) || empty($password) || empty($fullName)) {
        $message = "<p class='error'>❌ All fields are required!</p>";
    } else {
        try {
            // Check if username exists
            $existing = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
            
            if ($existing) {
                $message = "<p class='error'>❌ Username already exists!</p>";
            } else {
                // Prepare business_access
                $businessAccess = null;
                if ($grantAllAccess) {
                    $allBusinessIds = array_column($BUSINESSES, 'id');
                    $businessAccess = json_encode($allBusinessIds);
                }
                
                // Create user in main database
                $db->query(
                    "INSERT INTO users (username, password, full_name, role, business_access, created_at) 
                     VALUES (?, MD5(?), ?, ?, ?, NOW())",
                    [$username, $password, $fullName, $role, $businessAccess]
                );
                
                $userId = $db->lastInsertId();
                
                // Also create in all business databases
                foreach ($BUSINESSES as $business) {
                    try {
                        $db->query(
                            "INSERT INTO {$business['database']}.users (id, username, password, full_name, role, business_access, created_at) 
                             VALUES (?, ?, MD5(?), ?, ?, ?, NOW())",
                            [$userId, $username, $password, $fullName, $role, $businessAccess]
                        );
                    } catch (Exception $e) {
                        // Skip if database doesn't exist
                    }
                }
                
                $message = "<p class='success'>✅ User created successfully!</p>";
                $message .= "<p><strong>User ID:</strong> {$userId}</p>";
                $message .= "<p><strong>Username:</strong> {$username}</p>";
                $message .= "<p><strong>Role:</strong> {$role}</p>";
                if ($grantAllAccess) {
                    $message .= "<p><strong>Access:</strong> All " . count($BUSINESSES) . " businesses</p>";
                }
                $message .= "<hr><p><a href='../modules/owner/dashboard.php'>→ Go to Dashboard</a></p>";
                $message .= "<p><a href='setup-owner-access.php'>→ Manage User Access</a></p>";
            }
        } catch (Exception $e) {
            $message = "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
        }
    }
}

echo $message;
?>

<h3>Create New User</h3>
<form method="POST">
    <table>
        <tr>
            <td><label>Username:</label></td>
            <td><input type="text" name="username" required placeholder="owner1"></td>
        </tr>
        <tr>
            <td><label>Password:</label></td>
            <td><input type="password" name="password" required placeholder="password123"></td>
        </tr>
        <tr>
            <td><label>Full Name:</label></td>
            <td><input type="text" name="full_name" required placeholder="Owner Name"></td>
        </tr>
        <tr>
            <td><label>Role:</label></td>
            <td>
                <select name="role" required>
                    <option value="owner">Owner</option>
                    <option value="admin">Admin</option>
                    <option value="staff">Staff</option>
                </select>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <label>
                    <input type="checkbox" name="grant_all_access" checked>
                    Grant access to all <?= count($BUSINESSES) ?> businesses
                </label>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <button type="submit">Create User</button>
            </td>
        </tr>
    </table>
</form>

<hr>
<h3>Existing Owner/Admin Users:</h3>
<?php
$users = $db->fetchAll(
    "SELECT id, username, full_name, role, business_access FROM users WHERE role IN ('owner', 'admin') ORDER BY id"
);

echo "<table border='1' cellpadding='8' cellspacing='0'>";
echo "<tr><th>ID</th><th>Username</th><th>Name</th><th>Role</th><th>Business Access</th></tr>";
foreach ($users as $user) {
    $accessCount = $user['business_access'] ? count(json_decode($user['business_access'])) : 0;
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['username']}</td>";
    echo "<td>{$user['full_name']}</td>";
    echo "<td><strong>{$user['role']}</strong></td>";
    echo "<td>" . ($accessCount > 0 ? "{$accessCount} businesses" : "No access") . "</td>";
    echo "</tr>";
}
echo "</table>";
?>

<hr>
<h3>Available Businesses:</h3>
<ul>
<?php foreach ($BUSINESSES as $business): ?>
    <li><strong><?= $business['name'] ?></strong> (ID: <?= $business['id'] ?>, DB: <?= $business['database'] ?>)</li>
<?php endforeach; ?>
</ul>

<hr>
<p><a href="setup-owner-access.php">→ Setup Existing User Access</a></p>
<p><a href="../">→ Back to Main App</a></p>
