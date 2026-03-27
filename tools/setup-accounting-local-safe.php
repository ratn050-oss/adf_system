<?php
/**
 * Local Development - Accounting System Setup (SAFE)
 * This script only runs on localhost
 * Production databases are NOT touched
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

// Safety: Only access from localhost
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8081']);
if (!$isLocalhost) {
    die('❌ ERROR: This setup script can only be accessed from localhost!');
}

$step = $_GET['step'] ?? 1;
$message = '';
$error = '';

try {
    $db = Database::getInstance();
    
    // Step 1: Display information
    if ($step == 1) {
        // Just show intro
    }
    
    // Step 2: Create master database tables
    elseif ($step == 2) {
        // Check if tables already exist
        $masterDb = $db->getConnection();
        
        // Create cash_accounts table in MASTER database
        $sql1 = "
        CREATE TABLE IF NOT EXISTS `cash_accounts` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `business_id` int(11) NOT NULL,
          `account_name` varchar(100) NOT NULL,
          `account_type` enum('cash','bank','e-wallet','owner_capital','credit_card') NOT NULL DEFAULT 'cash',
          `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
          `is_default_account` tinyint(1) NOT NULL DEFAULT 0,
          `description` text,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `business_id` (`business_id`),
          KEY `account_type` (`account_type`),
          KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $masterDb->exec($sql1);
        
        $sql2 = "
        CREATE TABLE IF NOT EXISTS `cash_account_transactions` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `cash_account_id` int(11) NOT NULL,
          `transaction_id` int(11),
          `transaction_date` date NOT NULL,
          `description` varchar(255) NOT NULL,
          `debit` decimal(15,2) DEFAULT 0.00,
          `credit` decimal(15,2) DEFAULT 0.00,
          `transaction_type` enum('income','expense','transfer','opening_balance','capital_injection') NOT NULL,
          `reference_number` varchar(50),
          `created_by` int(11),
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `cash_account_id` (`cash_account_id`),
          KEY `transaction_date` (`transaction_date`),
          KEY `transaction_type` (`transaction_type`),
          FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $masterDb->exec($sql2);
        $message = "✅ Master database tables created successfully!";
    }
    
    // Step 3: Add columns to business databases
    elseif ($step == 3) {
        $businesses = $db->fetchAll("SELECT id, database_name FROM businesses WHERE is_active = 1");
        
        $results = [];
        foreach ($businesses as $biz) {
            try {
                $bizDb = new PDO(
                    "mysql:host={$_SERVER['DB_HOST']};dbname={$biz['database_name']};charset=utf8mb4",
                    $_SERVER['DB_USER'],
                    $_SERVER['DB_PASS'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                // Add cash_account_id column ke cash_book
                try {
                    $bizDb->exec("ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` int(11) DEFAULT NULL");
                    $results[] = "✅ {$biz['database_name']}: Column added";
                } catch (Exception $e) {
                    $results[] = "ℹ️  {$biz['database_name']}: Column exists (skip)";
                }
            } catch (Exception $e) {
                $results[] = "❌ {$biz['database_name']}: " . $e->getMessage();
            }
        }
        $message = implode("\n", $results);
    }
    
    // Step 4: Create default accounts
    elseif ($step == 4) {
        $businesses = $db->fetchAll("SELECT id, database_name, business_name FROM businesses WHERE is_active = 1");
        
        $results = [];
        foreach ($businesses as $biz) {
            try {
                // Check if already exists
                $existing = $db->fetchOne("SELECT COUNT(*) as cnt FROM cash_accounts WHERE business_id = ?", [$biz['id']]);
                
                if ($existing['cnt'] == 0) {
                    // Create 3 default accounts
                    $db->insert('cash_accounts', [
                        'business_id' => $biz['id'],
                        'account_name' => 'Kas Operasional',
                        'account_type' => 'cash',
                        'is_default_account' => 1,
                        'description' => 'Kas untuk pendapatan operasional',
                        'is_active' => 1
                    ]);
                    
                    $db->insert('cash_accounts', [
                        'business_id' => $biz['id'],
                        'account_name' => 'Kas Modal Owner',
                        'account_type' => 'owner_capital',
                        'description' => 'Dana dari pemilik untuk kebutuhan operasional harian',
                        'is_active' => 1
                    ]);
                    
                    $db->insert('cash_accounts', [
                        'business_id' => $biz['id'],
                        'account_name' => 'Bank',
                        'account_type' => 'bank',
                        'description' => 'Rekening bank utama bisnis',
                        'is_active' => 1
                    ]);
                    
                    $results[] = "✅ {$biz['business_name']}: 3 default accounts created";
                } else {
                    $results[] = "ℹ️  {$biz['business_name']}: Accounts exist (skip)";
                }
            } catch (Exception $e) {
                $results[] = "❌ {$biz['business_name']}: " . $e->getMessage();
            }
        }
        $message = implode("\n", $results);
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Accounting System - Local Development</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
            padding: 2rem;
        }
        
        h1 {
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1.75rem;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        
        .steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .step-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-weight: 700;
            color: white;
            margin: 0 auto;
            background: #ddd;
            cursor: pointer;
        }
        
        .step-badge.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .step-badge.completed {
            background: #10b981;
        }
        
        .step-label {
            text-align: center;
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.5rem;
        }
        
        .card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #047857;
            border: 1px solid #6ee7b7;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
        }
        
        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }
        
        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: space-between;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #333;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .warning {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .warning strong {
            display: block;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Setup Accounting System</h1>
        <p class="subtitle">Multi-Cash Account System - Local Development Only</p>
        
        <div class="warning">
            <strong>⚠️ LOCAL DEVELOPMENT ENVIRONMENT</strong>
            This setup script only works on localhost and CANNOT affect production databases.
        </div>
        
        <!-- Progress Steps -->
        <div class="steps">
            <?php for ($i = 1; $i <= 4; $i++): ?>
                <div>
                    <button class="step-badge <?php echo $i == $step ? 'active' : ($i < $step ? 'completed' : ''); ?>" 
                            onclick="window.location.href='?step=<?php echo $i; ?>'">
                        <?php echo $i < $step ? '✓' : $i; ?>
                    </button>
                    <div class="step-label">
                        <?php
                        $labels = ['Intro', 'Master DB', 'Add Columns', 'Create Accounts'];
                        echo $labels[$i - 1];
                        ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        
        <div class="card">
            <?php if ($step == 1): ?>
                <!-- Step 1: Introduction -->
                <h2 style="margin-bottom: 1rem; color: #333;">Step 1: Introduction</h2>
                
                <div class="alert alert-info">
📋 Sistem Akuntansi Multi-Akun Kas

Yang akan dibuat:
✓ Tabel cash_accounts (master database)
✓ Tabel cash_account_transactions (master database)
✓ Kolom cash_account_id di cash_book (setiap business database)
✓ 3 akun default per business:
  - Kas Operasional
  - Kas Modal Owner
  - Bank

Akun Kas Modal Owner = untuk tracking dana dari owner
Akun Kas Operasional = untuk income dari operasional
Akun Bank = untuk transaksi bank
                </div>
                
                <div class="alert alert-success">
✅ Benefit:
- Pisahkan modal owner dari pendapatan operasional
- Track pengeluaran berdasarkan sumber kas
- Buat laporan modal bulanan untuk owner
- Kasbon/advance dapat di-track per akun
                </div>
                
            <?php elseif ($step == 2): ?>
                <!-- Step 2: Create Master Tables -->
                <h2 style="margin-bottom: 1rem; color: #333;">Step 2: Create Master Database Tables</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">❌ Error: <?php echo htmlspecialchars($error); ?></div>
                <?php elseif ($message): ?>
                    <div class="alert alert-success"><?php echo nl2br(htmlspecialchars($message)); ?></div>
                    <div class="alert alert-success">
✅ Tabel berhasil dibuat!

Tabel yang dibuat:
- cash_accounts: Menyimpan master akun kas
- cash_account_transactions: Menyimpan history transaksi per akun

Siap lanjut ke step 3 untuk update existing business databases.
                    </div>
                <?php endif; ?>
                
            <?php elseif ($step == 3): ?>
                <!-- Step 3: Add Columns -->
                <h2 style="margin-bottom: 1rem; color: #333;">Step 3: Add Columns to Business Databases</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">❌ Error: <?php echo htmlspecialchars($error); ?></div>
                <?php elseif ($message): ?>
                    <div class="alert alert-info"><?php echo nl2br(htmlspecialchars($message)); ?></div>
                <?php endif; ?>
                
            <?php elseif ($step == 4): ?>
                <!-- Step 4: Create Default Accounts -->
                <h2 style="margin-bottom: 1rem; color: #333;">Step 4: Create Default Cash Accounts</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">❌ Error: <?php echo htmlspecialchars($error); ?></div>
                <?php elseif ($message): ?>
                    <div class="alert alert-success"><?php echo nl2br(htmlspecialchars($message)); ?></div>
                    <div class="alert alert-success">
✅ Setup Complete!

Akun-akun default sudah dibuat untuk semua business.

Next Steps:
1. Update form cashbook untuk select cash account
2. Buat dashboard owner untuk monitoring
3. Test workflow lengkap
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Navigation Buttons -->
        <div class="btn-group">
            <?php if ($step > 1): ?>
                <a href="?step=<?php echo $step - 1; ?>" class="btn btn-secondary">← Back</a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
            
            <?php if ($step < 4): ?>
                <a href="?step=<?php echo $step + 1; ?>" class="btn btn-primary">Next →</a>
            <?php else: ?>
                <a href="../../index.php" class="btn btn-primary">✓ Done - Back to Dashboard</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
