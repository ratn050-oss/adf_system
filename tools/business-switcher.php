<?php
/**
 * Web-based Business Switcher
 * Access: http://localhost:8080/narayana/tools/business-switcher.php
 */

// Load config and business helper
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/business_helper.php';

// Security: Only allow in development mode
if (!defined('ALLOW_BUSINESS_SWITCHER')) {
    define('ALLOW_BUSINESS_SWITCHER', true); // Set to false in production
}

if (!ALLOW_BUSINESS_SWITCHER) {
    die("Business switcher is disabled in production mode.");
}

// Handle switch request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['business_id'])) {
    $businessId = $_POST['business_id'];
    
    if (setActiveBusinessId($businessId)) {
        $message = "✓ Switched to: " . getBusinessDisplayName($businessId);
        $success = true;
    } else {
        $message = "✗ Business not found!";
        $success = false;
    }
}

// Get current active business
$activeBusiness = getActiveBusinessId();

// Get all businesses
$businesses = getAvailableBusinesses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Switcher - Narayana System</title>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
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
            padding: 40px;
            max-width: 900px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .header h1 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 1rem;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .current-business {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
        }
        
        .current-business h3 {
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .current-business .info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .current-business .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 0.9rem;
        }
        
        .business-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .business-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            padding: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .business-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        
        .business-card.active {
            border-color: #667eea;
            background: linear-gradient(135deg, #f5f7ff 0%, #e8ecff 100%);
        }
        
        .business-card.active::before {
            content: "✓ ACTIVE";
            position: absolute;
            top: 10px;
            right: 10px;
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .business-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            display: block;
        }
        
        .business-name {
            font-weight: bold;
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 5px;
        }
        
        .business-type {
            color: #666;
            font-size: 0.9rem;
            text-transform: capitalize;
            margin-bottom: 10px;
        }
        
        .business-modules {
            color: #999;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .switch-btn {
            width: 100%;
            margin-top: 15px;
            padding: 10px;
            border: none;
            border-radius: 8px;
            background: #667eea;
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .switch-btn:hover {
            background: #5568d3;
        }
        
        .switch-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            color: #666;
            font-size: 0.9rem;
        }
        
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Business Switcher</h1>
            <p>Switch between different business configurations</p>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-<?= $success ? 'success' : 'error' ?>">
                <i data-feather="<?= $success ? 'check-circle' : 'alert-circle' ?>" style="width: 20px; height: 20px;"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if (isset($businesses[$activeBusiness])): ?>
            <?php $current = $businesses[$activeBusiness]; ?>
            <div class="current-business">
                <h3>
                    <span><?= $current['theme']['icon'] ?></span>
                    Currently Active: <?= htmlspecialchars($current['business_name']) ?>
                </h3>
                <div class="info">
                    <div class="info-item">
                        <i data-feather="tag" style="width: 16px; height: 16px;"></i>
                        <span><?= ucfirst($current['business_type']) ?></span>
                    </div>
                    <div class="info-item">
                        <i data-feather="database" style="width: 16px; height: 16px;"></i>
                        <span><?= $current['database'] ?></span>
                    </div>
                    <div class="info-item">
                        <i data-feather="package" style="width: 16px; height: 16px;"></i>
                        <span><?= count($current['enabled_modules']) ?> modules</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <h3 style="margin-bottom: 20px; color: #333;">Available Businesses:</h3>
        
        <div class="business-grid">
            <?php foreach ($businesses as $businessId => $config): ?>
                <div class="business-card <?= $businessId === $activeBusiness ? 'active' : '' ?>">
                    <span class="business-icon"><?= $config['theme']['icon'] ?></span>
                    <div class="business-name"><?= htmlspecialchars($config['business_name']) ?></div>
                    <div class="business-type"><?= $config['business_type'] ?></div>
                    <div class="business-modules">
                        <i data-feather="box" style="width: 14px; height: 14px;"></i>
                        <span><?= count($config['enabled_modules']) ?> modules</span>
                    </div>
                    
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="business_id" value="<?= $businessId ?>">
                        <button type="submit" class="switch-btn" <?= $businessId === $activeBusiness ? 'disabled' : '' ?>>
                            <?= $businessId === $activeBusiness ? '✓ Current' : 'Switch to This' ?>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="footer">
            <p>After switching, refresh your browser or restart the server.</p>
            <p><a href="/">← Back to Dashboard</a></p>
        </div>
    </div>
    
    <script>
        feather.replace();
        
        // Auto reload after successful switch
        <?php if (isset($success) && $success): ?>
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        <?php endif; ?>
    </script>
</body>
</html>
