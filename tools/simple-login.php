<?php
/**
 * Simple Developer Login - No Dependencies
 */
session_start();

// Direct MySQL connection
$host = 'localhost';
$dbname = 'narayana';
$dbuser = 'root';
$dbpass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: simple-login.php');
    exit;
}

// Handle login
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username AND password = MD5(:password)");
    $stmt->execute(['username' => $username, 'password' => $password]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && in_array($user['role'], ['admin', 'superadmin', 'owner'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header('Location: developer-panel.php');
        exit;
    } else {
        $error = "Username atau password salah, atau Anda bukan admin!";
    }
}

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: developer-panel.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Developer Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            width: 400px;
        }
        h1 { margin-bottom: 30px; color: #333; }
        .error {
            background: #f44336;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }
        button:hover {
            opacity: 0.9;
        }
        .info {
            margin-top: 20px;
            padding: 15px;
            background: #e3f2fd;
            border-radius: 5px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>🔧 Developer Panel</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" value="admin" required autofocus>
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" value="admin" required>
            </div>
            
            <button type="submit">Login</button>
        </form>
        
        <div class="info">
            <strong>Default Login:</strong><br>
            Username: admin<br>
            Password: admin
        </div>
    </div>
</body>
</html>
