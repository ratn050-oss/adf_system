<?php
/**
 * Quick Session Fixer
 * Sets the required session variables for dashboard access
 */

session_start();

// Set required session variables
$_SESSION['logged_in'] = true;
$_SESSION['role'] = 'developer';
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'developer';

// If business_id already exists, keep it
if (empty($_SESSION['business_id'])) {
    $_SESSION['business_id'] = 2; // Default to Ben's Cafe
}
if (empty($_SESSION['active_business_id'])) {
    $_SESSION['active_business_id'] = 'bens-cafe';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Fixed</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: bounce 1s ease-in-out infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 28px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .session-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
        }
        
        .session-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .session-item:last-child {
            border-bottom: none;
        }
        
        .session-key {
            font-weight: 600;
            color: #495057;
        }
        
        .session-value {
            color: #28a745;
            font-weight: 500;
        }
        
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 600;
            margin: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .button-secondary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .success-check {
            color: #28a745;
            font-size: 18px;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">✅</div>
        <h1>Session Fixed!</h1>
        <p class="subtitle">
            Session variables telah di-set dengan benar.<br>
            Sekarang Anda dapat mengakses dashboard tanpa masalah auth.
        </p>
        
        <div class="session-info">
            <h3 style="margin-bottom: 15px; color: #333; font-size: 16px;">
                <span class="success-check">✓</span> Session Active
            </h3>
            <div class="session-item">
                <span class="session-key">logged_in:</span>
                <span class="session-value"><?php echo $_SESSION['logged_in'] ? 'true' : 'false'; ?></span>
            </div>
            <div class="session-item">
                <span class="session-key">role:</span>
                <span class="session-value"><?php echo htmlspecialchars($_SESSION['role']); ?></span>
            </div>
            <div class="session-item">
                <span class="session-key">user_id:</span>
                <span class="session-value"><?php echo htmlspecialchars($_SESSION['user_id']); ?></span>
            </div>
            <div class="session-item">
                <span class="session-key">username:</span>
                <span class="session-value"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            </div>
            <div class="session-item">
                <span class="session-key">business_id:</span>
                <span class="session-value"><?php echo htmlspecialchars($_SESSION['business_id']); ?></span>
            </div>
            <div class="session-item">
                <span class="session-key">active_business_id:</span>
                <span class="session-value"><?php echo htmlspecialchars($_SESSION['active_business_id']); ?></span>
            </div>
        </div>
        
        <div style="margin-top: 30px;">
            <a href="modules/owner/dashboard-2028.php" class="button">
                📊 Buka Dashboard
            </a>
            <br>
            <a href="debug-dashboard-complete.php" class="button button-secondary">
                🔍 Cek Debug Info
            </a>
        </div>
        
        <p style="margin-top: 30px; color: #999; font-size: 14px;">
            Session ID: <?php echo session_id(); ?>
        </p>
    </div>
    
    <script>
        // Animate container on load
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                container.style.transition = 'all 0.5s ease';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
        });
    </script>
</body>
</html>
