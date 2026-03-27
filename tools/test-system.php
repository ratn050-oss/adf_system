<?php
/**
 * TEST SYSTEM - Cek semua setup sudah benar
 */
define('APP_ACCESS', true);
require_once '../config/config.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test System Setup</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            line-height: 1.6;
        }
        .test-section {
            background: #252526;
            border: 1px solid #3e3e42;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .test-section h2 {
            color: #4ec9b0;
            margin-top: 0;
        }
        .success {
            color: #4ec9b0;
        }
        .error {
            color: #f48771;
        }
        .warning {
            color: #dcdcaa;
        }
        .info {
            color: #569cd6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        table th,
        table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #3e3e42;
        }
        table th {
            background: #2d2d30;
            color: #4ec9b0;
        }
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #0e639c;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
        .back-btn:hover {
            background: #1177bb;
        }
    </style>
</head>
<body>
    <h1>🔍 Test System Setup</h1>
    <p>Mengecek semua komponen sistem...</p>

    <?php
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        echo '<div class="test-section">';
        echo '<h2>✅ 1. Database Connection</h2>';
        echo '<p class="success">✓ Connected to database: ' . DB_NAME . '</p>';
        echo '</div>';

        // Check tables
        echo '<div class="test-section">';
        echo '<h2>📋 2. Database Tables</h2>';
        
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $requiredTables = ['users', 'businesses', 'cash_book', 'user_preferences'];
        
        foreach ($requiredTables as $table) {
            if (in_array($table, $tables)) {
                echo '<p class="success">✓ Table ' . $table . ' exists</p>';
            } else {
                echo '<p class="error">✗ Table ' . $table . ' NOT FOUND</p>';
            }
        }
        echo '</div>';

        // Check businesses
        echo '<div class="test-section">';
        echo '<h2>🏢 3. Registered Businesses</h2>';
        
        $stmt = $pdo->query("SELECT * FROM businesses ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($businesses) > 0) {
            echo '<table>';
            echo '<tr><th>ID</th><th>Business Name</th><th>Address</th><th>Phone</th></tr>';
            foreach ($businesses as $biz) {
                echo '<tr>';
                echo '<td>' . $biz['id'] . '</td>';
                echo '<td>' . htmlspecialchars($biz['business_name']) . '</td>';
                echo '<td>' . htmlspecialchars($biz['address'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($biz['phone'] ?? '-') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<p class="success">✓ Total: ' . count($businesses) . ' businesses</p>';
        } else {
            echo '<p class="error">✗ No businesses found!</p>';
        }
        echo '</div>';

        // Check users
        echo '<div class="test-section">';
        echo '<h2>👥 4. System Users</h2>';
        
        $stmt = $pdo->query("SELECT id, username, full_name, role, business_access FROM users ORDER BY id");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($users) > 0) {
            echo '<table>';
            echo '<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Business Access</th></tr>';
            foreach ($users as $user) {
                echo '<tr>';
                echo '<td>' . $user['id'] . '</td>';
                echo '<td><strong>' . htmlspecialchars($user['username']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($user['full_name']) . '</td>';
                echo '<td><span class="info">' . htmlspecialchars($user['role']) . '</span></td>';
                echo '<td>' . htmlspecialchars($user['business_access'] ?? 'NULL') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<p class="success">✓ Total: ' . count($users) . ' users</p>';
        } else {
            echo '<p class="error">✗ No users found!</p>';
        }
        echo '</div>';

        // Check login credentials
        echo '<div class="test-section">';
        echo '<h2>🔑 5. Login Credentials (untuk testing)</h2>';
        echo '<table>';
        echo '<tr><th>Username</th><th>Password</th><th>Role</th><th>Purpose</th></tr>';
        echo '<tr><td>admin</td><td>admin</td><td>admin</td><td>Full access, semua bisnis</td></tr>';
        echo '<tr><td>staff1</td><td>staff123</td><td>staff</td><td>Akses terbatas, bisnis 1,2</td></tr>';
        echo '<tr><td>rob</td><td>owner123</td><td>owner</td><td>Owner Bens Cafe</td></tr>';
        echo '<tr><td>devadmin</td><td>dev123</td><td>admin</td><td>Developer access</td></tr>';
        echo '</table>';
        echo '</div>';

        // Check files
        echo '<div class="test-section">';
        echo '<h2>📁 6. Important Files</h2>';
        
        $files = [
            'login.php' => 'System Login',
            'owner-login.php' => 'Owner Login',
            'home.php' => 'Main Homepage',
            'tools/developer-panel.php' => 'Developer Panel',
            'includes/auth.php' => 'Authentication Class',
            'config/config.php' => 'Configuration'
        ];
        
        foreach ($files as $file => $desc) {
            $path = __DIR__ . '/../' . $file;
            if (file_exists($path)) {
                echo '<p class="success">✓ ' . $desc . ' (' . $file . ')</p>';
            } else {
                echo '<p class="error">✗ ' . $desc . ' (' . $file . ') NOT FOUND</p>';
            }
        }
        echo '</div>';

        // Recommendations
        echo '<div class="test-section">';
        echo '<h2>🎯 7. Next Steps</h2>';
        echo '<p class="info">Sistem sudah siap! Silakan coba:</p>';
        echo '<ol>';
        echo '<li><a href="../home.php" style="color: #569cd6;">Buka Homepage</a> - Pilih portal yang ingin diakses</li>';
        echo '<li><a href="../login.php" style="color: #569cd6;">System Login</a> - Login untuk staff/admin (gunakan admin/admin)</li>';
        echo '<li><a href="../owner-login.php" style="color: #569cd6;">Owner Portal</a> - Login untuk owner (gunakan rob/owner123)</li>';
        echo '<li><a href="developer-panel.php" style="color: #569cd6;">Developer Panel</a> - Setup dan maintenance</li>';
        echo '</ol>';
        echo '</div>';

    } catch (Exception $e) {
        echo '<div class="test-section">';
        echo '<h2 class="error">❌ Error</h2>';
        echo '<p class="error">Exception: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '</div>';
    }
    ?>

    <a href="../home.php" class="back-btn">← Kembali ke Home</a>
</body>
</html>
