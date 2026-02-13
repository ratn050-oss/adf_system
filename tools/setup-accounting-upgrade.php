<?php
/**
 * Setup Accounting Upgrade - Implementasi Multi-Akun Kas
 * Membuat tabel cash_accounts untuk memisahkan sumber dana
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/includes/auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions.php';

// Only allow developer access
$auth = new Auth();
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'developer') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$success = '';
$error = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upgrade') {
        try {
            $db->getConnection()->beginTransaction();

            // Step 1: Create cash_accounts table
            $sql_create_table = "
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->getConnection()->exec($sql_create_table);

            // Step 2: Create cash_account_transactions table (untuk tracking setiap transaksi)
            $sql_transactions = "
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->getConnection()->exec($sql_transactions);

            // Step 3: Tambahkan kolom ke cash_book untuk tracking akun kas
            try {
                $db->getConnection()->exec("
                    ALTER TABLE `cash_book` 
                    ADD COLUMN `cash_account_id` int(11) DEFAULT NULL
                ");
            } catch (Exception $e) {
                // Kolom mungkin sudah ada, abaikan
            }

            // Step 4: Insert default cash accounts untuk setiap business
            $businesses = $db->fetchAll("SELECT id FROM businesses WHERE is_active = 1");
            
            foreach ($businesses as $biz) {
                // Cek apakah sudah ada cash accounts
                $existing = $db->fetchOne(
                    "SELECT COUNT(*) as cnt FROM cash_accounts WHERE business_id = ?",
                    [$biz['id']]
                );

                if ($existing['cnt'] == 0) {
                    // Insert akun default
                    $db->insert('cash_accounts', [
                        'business_id' => $biz['id'],
                        'account_name' => 'Kas Operasional',
                        'account_type' => 'cash',
                        'current_balance' => 0,
                        'is_default_account' => 1,
                        'description' => 'Kas untuk pendapatan operasional hotel',
                        'is_active' => 1
                    ]);

                    $db->insert('cash_accounts', [
                        'business_id' => $biz['id'],
                        'account_name' => 'Kas Modal Owner',
                        'account_type' => 'owner_capital',
                        'current_balance' => 0,
                        'is_default_account' => 0,
                        'description' => 'Dana dari pemilik untuk kebutuhan operasional harian',
                        'is_active' => 1
                    ]);

                    $db->insert('cash_accounts', [
                        'business_id' => $biz['id'],
                        'account_name' => 'Bank',
                        'account_type' => 'bank',
                        'current_balance' => 0,
                        'is_default_account' => 0,
                        'description' => 'Rekening bank utama bisnis',
                        'is_active' => 1
                    ]);
                }
            }

            $db->getConnection()->commit();
            $success = '✅ Upgrade akuntansi berhasil! Tabel cash_accounts dan cash_account_transactions sudah dibuat.';
            $step = 2;

        } catch (Exception $e) {
            $db->getConnection()->rollBack();
            $error = '❌ Error: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Accounting Upgrade - ADF System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 700px;
            width: 100%;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .content {
            padding: 40px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        
        .step-indicator {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            align-items: center;
        }
        
        .step {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #495057;
            font-weight: 600;
            font-size: 16px;
        }
        
        .step.active {
            background: #667eea;
            color: white;
        }
        
        .step.completed {
            background: #28a745;
            color: white;
        }
        
        .step-line {
            flex: 1;
            height: 2px;
            background: #e9ecef;
        }
        
        .step-line.active {
            background: #667eea;
        }
        
        .feature-list {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .feature-list h3 {
            font-size: 16px;
            margin-bottom: 15px;
            color: #333;
        }
        
        .feature-list ul {
            list-style: none;
            padding: 0;
        }
        
        .feature-list li {
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
            color: #555;
            font-size: 14px;
        }
        
        .feature-list li:before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
        }
        
        .table-preview {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 25px;
            font-size: 13px;
        }
        
        .table-preview th {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #333;
        }
        
        .table-preview td {
            border: 1px solid #dee2e6;
            padding: 10px;
            color: #666;
        }
        
        .buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .note {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 25px;
            font-size: 13px;
            color: #856404;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Setup Accounting Upgrade</h1>
            <p>Implementasi Multi-Akun Kas untuk Memisahkan Sumber Dana</p>
        </div>
        
        <div class="content">
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">1</div>
                <div class="step-line <?php echo $step > 1 ? 'active' : ''; ?>"></div>
                <div class="step <?php echo $step >= 2 ? 'active' : ''; ?>">2</div>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Sukses!</strong> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Error!</strong> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($step == 1): ?>
                <h2 style="margin-bottom: 20px; color: #333; font-size: 20px;">Langkah 1: Persiapan Database</h2>
                
                <div class="alert alert-info">
                    <strong>ℹ️ Informasi:</strong> Upgrade ini akan membuat dua tabel baru di database untuk mendukung pemisahan akun kas dan tracking arus kas. Tidak ada data yang akan dihapus.
                </div>
                
                <div class="feature-list">
                    <h3>📋 Tabel yang Akan Dibuat:</h3>
                    <ul>
                        <li><strong>cash_accounts</strong> - Mendaftar semua akun kas yang tersedia (Kas Operasional, Kas Modal Owner, Bank, dll)</li>
                        <li><strong>cash_account_transactions</strong> - Mencatat setiap transaksi per akun kas untuk tracking detail</li>
                        <li><strong>Kolom cash_account_id di cash_book</strong> - Link transaksi ke akun kas yang tepat</li>
                    </ul>
                </div>
                
                <div class="feature-list">
                    <h3>🎯 Akun Default yang Akan Dibuat:</h3>
                    <ul>
                        <li><strong>Kas Operasional</strong> - Untuk pendapatan hasil penjualan hotel</li>
                        <li><strong>Kas Modal Owner</strong> - Untuk dana pemilik yang ditransfer</li>
                        <li><strong>Bank</strong> - Untuk transaksi perbankan</li>
                    </ul>
                </div>
                
                <div class="note">
                    ⚠️ <strong>Perhatian:</strong> Proses ini tidak akan mengubah data existing. Setelah upgrade, Anda perlu melakukan "koreksi" untuk mentransfer saldo dari transaksi sebelumnya ke akun yang tepat.
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="upgrade">
                    <div class="buttons">
                        <a href="<?php echo BASE_URL; ?>/tools/developer-panel.php" class="btn btn-secondary">Batal</a>
                        <button type="submit" class="btn btn-primary">Jalankan Upgrade →</button>
                    </div>
                </form>
                
            <?php elseif ($step == 2): ?>
                <h2 style="margin-bottom: 20px; color: #333; font-size: 20px;">Langkah 2: Verifikasi Berhasil</h2>
                
                <div class="alert alert-success">
                    ✅ Database telah berhasil di-upgrade! Tabel baru sudah dibuat dan siap digunakan.
                </div>
                
                <div class="feature-list">
                    <h3>✨ Yang Sudah Dibuat:</h3>
                    <ul>
                        <li>Tabel <code>cash_accounts</code> dengan 3 akun default per bisnis</li>
                        <li>Tabel <code>cash_account_transactions</code> untuk tracking detail</li>
                        <li>Kolom <code>cash_account_id</code> di tabel <code>cash_book</code></li>
                    </ul>
                </div>
                
                <div class="feature-list">
                    <h3>🔤 Langkah Selanjutnya:</h3>
                    <ul>
                        <li>Update form cashbook untuk memilih akun kas saat memasukkan transaksi</li>
                        <li>Buat kategori khusus "Setoran Modal Owner" di divisions/categories</li>
                        <li>Buat laporan rekapitulasi modal untuk Owner</li>
                        <li>Add widget monitoring modal di Owner Dashboard</li>
                    </ul>
                </div>
                
                <div class="note">
                    ℹ️ Saat ini sistem sudah siap. Data transaksi yang sudah ada akan terus berfungsi normal. Untuk transaksi baru, Anda bisa memilih akun kas mana yang akan digunakan.
                </div>
                
                <div class="buttons">
                    <a href="<?php echo BASE_URL; ?>/tools/developer-panel.php" class="btn btn-secondary">Kembali ke Developer Panel</a>
                    <a href="<?php echo BASE_URL; ?>/modules/settings/index.php" class="btn btn-primary">Lanjut ke Pengaturan →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
