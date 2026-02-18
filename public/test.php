<?php
/**
 * PUBLIC WEBSITE - Test & Verification Page
 * Quick check before full testing
 */

define('PUBLIC_ACCESS', true);
require_once './includes/config.php';
require_once './includes/database.php';

$tests = [
    'config' => false,
    'database' => false,
    'tables' => false,
    'sample_data' => false
];

$messages = [];

try {
    // Test 1: Config
    $tests['config'] = defined('BUSINESS_ID') && defined('DB_NAME');
    if ($tests['config']) {
        $messages['config'] = "✅ Config loaded: " . BUSINESS_ID . " (DB: " . DB_NAME . ")";
    } else {
        $messages['config'] = "❌ Config not properly loaded";
    }
    
    // Test 2: Database Connection
    $db = PublicDatabase::getInstance();
    $conn = $db->getConnection();
    $tests['database'] = $conn !== null;
    if ($tests['database']) {
        $messages['database'] = "✅ Database connected successfully";
    } else {
        $messages['database'] = "❌ Database connection failed";
    }
    
    // Test 3: Required Tables
    if ($tests['database']) {
        $tables = ['room_types', 'rooms', 'guests', 'bookings', 'booking_payments'];
        $allTablesExist = true;
        foreach ($tables as $table) {
            $result = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [DB_NAME, $table]);
            if (!$result) {
                $allTablesExist = false;
                break;
            }
        }
        $tests['tables'] = $allTablesExist;
        if ($allTablesExist) {
            $messages['tables'] = "✅ All required tables exist: " . implode(', ', $tables);
        } else {
            $messages['tables'] = "❌ Missing one or more required tables";
        }
    }
    
    // Test 4: Sample Data
    if ($tests['tables']) {
        $roomTypes = $db->fetchOne("SELECT COUNT(*) as count FROM room_types");
        $rooms = $db->fetchOne("SELECT COUNT(*) as count FROM rooms");
        
        if ($roomTypes['count'] > 0 && $rooms['count'] > 0) {
            $tests['sample_data'] = true;
            $messages['sample_data'] = "✅ Sample data found: " . $roomTypes['count'] . " room types, " . $rooms['count'] . " rooms";
        } else {
            $tests['sample_data'] = false;
            $messages['sample_data'] = "⚠️ No sample data. Need to create room types and rooms first.";
        }
    }
    
} catch (Exception $e) {
    $messages['error'] = "❌ Error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Test - Narayana Hotel</title>
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
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .container {
            background: white;
            border-radius: 0.75rem;
            padding: 2rem;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        h1 {
            color: #1e293b;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .test-item {
            margin-bottom: 1.5rem;
            padding: 1.25rem;
            background: #f8fafc;
            border-radius: 0.5rem;
            border-left: 4px solid #cbd5e1;
        }
        
        .test-item.success {
            background: #ecfdf5;
            border-left-color: #10b981;
        }
        
        .test-item.error {
            background: #fee2e2;
            border-left-color: #ef4444;
        }
        
        .test-item.warning {
            background: #fffbeb;
            border-left-color: #f59e0b;
        }
        
        .test-label {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .test-message {
            color: #64748b;
            font-size: 0.95rem;
        }
        
        .test-item.success .test-message {
            color: #065f46;
        }
        
        .test-item.error .test-message {
            color: #991b1b;
        }
        
        .test-item.warning .test-message {
            color: #92400e;
        }
        
        .action-buttons {
            margin-top: 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }
        
        .btn-secondary:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }
        
        .info-box {
            background: #f0f4ff;
            border: 1px solid #c7d2fe;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 2rem;
            color: #3730a3;
            font-size: 0.9rem;
        }
        
        code {
            background: #1e293b;
            color: #e2e8f0;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Website Verification Test</h1>
        
        <!-- Config Test -->
        <div class="test-item <?php echo $tests['config'] ? 'success' : 'error'; ?>">
            <div class="test-label">1. Configuration</div>
            <div class="test-message"><?php echo $messages['config'] ?? 'Unknown'; ?></div>
        </div>
        
        <!-- Database Test -->
        <div class="test-item <?php echo $tests['database'] ? 'success' : 'error'; ?>">
            <div class="test-label">2. Database Connection</div>
            <div class="test-message"><?php echo $messages['database'] ?? 'Unknown'; ?></div>
        </div>
        
        <!-- Tables Test -->
        <div class="test-item <?php echo $tests['tables'] ? 'success' : 'error'; ?>">
            <div class="test-label">3. Database Tables</div>
            <div class="test-message"><?php echo $messages['tables'] ?? 'Unknown'; ?></div>
        </div>
        
        <!-- Sample Data Test -->
        <div class="test-item <?php echo $tests['sample_data'] ? ($tests['sample_data'] ? 'success' : 'warning') : 'warning'; ?>">
            <div class="test-label">4. Sample Data</div>
            <div class="test-message"><?php echo $messages['sample_data'] ?? 'Unknown'; ?></div>
        </div>
        
        <!-- Overall Status -->
        <?php
        $allPass = $tests['config'] && $tests['database'] && $tests['tables'] && $tests['sample_data'];
        $readyToTest = $tests['config'] && $tests['database'] && $tests['tables'];
        ?>
        
        <div class="info-box">
            <?php if ($allPass): ?>
                ✅ <strong>Semua test passed!</strong> Website siap untuk testing.
            <?php elseif ($readyToTest): ?>
                ⚠️ <strong>Persiapan belum lengkap.</strong> Anda perlu menambahkan sample data (room types & rooms).
            <?php else: ?>
                ❌ <strong>Ada masalah.</strong> Periksa konfigurasi dan database connection.
            <?php endif; ?>
        </div>
        
        <div class="action-buttons">
            <?php if ($readyToTest): ?>
                <a href="../index.php" class="btn btn-primary">Buka Homepage</a>
                <a href="../booking.php" class="btn btn-primary">Buka Booking</a>
            <?php endif; ?>
            <a href="javascript:location.reload()" class="btn btn-secondary">Refresh Test</a>
        </div>
    </div>
</body>
</html>
