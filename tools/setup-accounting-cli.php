<?php
/**
 * Setup Accounting Upgrade - CLI Version (Multi-Database Safe)
 * Jalankan dari command line: php tools/setup-accounting-cli.php
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';

echo "\n";
echo "════════════════════════════════════════════════════════════\n";
echo "  🚀 SETUP ACCOUNTING UPGRADE - Multi Cash Account System\n";
echo "════════════════════════════════════════════════════════════\n\n";

try {
    // Connect to MASTER database
    $masterPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $masterPdo->beginTransaction();

    // Get all active businesses
    echo "📍 Fetching active businesses from master database...\n\n";
    $stmt = $masterPdo->prepare("SELECT id, business_code, business_name, database_name FROM businesses WHERE is_active = 1");
    $stmt->execute();
    $businesses = $stmt->fetchAll();

    if (empty($businesses)) {
        throw new Exception("Tidak ada business yang aktif di sistem!");
    }

    echo "✅ Ditemukan " . count($businesses) . " business aktif\n\n";

    $successCount = 0;

    foreach ($businesses as $biz) {
        echo "─────────────────────────────────────────────────────────\n";
        echo "📦 Processing: " . $biz['business_name'] . "\n";
        echo "   Code: " . $biz['business_code'] . " | Database: " . $biz['database_name'] . "\n";
        echo "─────────────────────────────────────────────────────────\n\n";

        try {
            // Connect to business database
            $bizDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $biz['database_name'] . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            // Step 1: Create cash_accounts table
            echo "   📋 Creating cash_accounts table...\n";
            $bizDb->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            echo "      ✅ Table cash_accounts created\n";

            // Step 2: Create cash_account_transactions table
            echo "   📋 Creating cash_account_transactions table...\n";
            $bizDb->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            echo "      ✅ Table cash_account_transactions created\n";

            // Step 3: Add column to cash_book
            echo "   📋 Adding cash_account_id column to cash_book...\n";
            try {
                $bizDb->exec("ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` int(11) DEFAULT NULL");
                echo "      ✅ Column added successfully\n";
            } catch (Exception $e) {
                echo "      ℹ️  Column already exists (skipped)\n";
            }

            // Step 4: Check and insert default cash accounts
            echo "   📋 Setting up default cash accounts...\n";
            $checkStmt = $bizDb->prepare("SELECT COUNT(*) as cnt FROM cash_accounts");
            $checkStmt->execute();
            $result = $checkStmt->fetch();

            if ($result['cnt'] == 0) {
                // Insert default accounts
                $bizDb->prepare("
                    INSERT INTO cash_accounts 
                    (business_id, account_name, account_type, is_default_account, description, is_active)
                    VALUES (?, ?, ?, 1, ?, 1)
                ")->execute([
                    $biz['id'],
                    'Kas Operasional',
                    'cash',
                    'Kas untuk pendapatan operasional'
                ]);

                $bizDb->prepare("
                    INSERT INTO cash_accounts 
                    (business_id, account_name, account_type, description, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ")->execute([
                    $biz['id'],
                    'Kas Modal Owner',
                    'owner_capital',
                    'Dana dari pemilik untuk kebutuhan operasional harian'
                ]);

                $bizDb->prepare("
                    INSERT INTO cash_accounts 
                    (business_id, account_name, account_type, description, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ")->execute([
                    $biz['id'],
                    'Bank',
                    'bank',
                    'Rekening bank utama bisnis'
                ]);

                echo "      ✅ 3 default cash accounts created\n";
            } else {
                echo "      ℹ️  Cash accounts already exist (skipped)\n";
            }

            $successCount++;
            echo "\n";

        } catch (PDOException $e) {
            echo "   ❌ ERROR: " . $e->getMessage() . "\n\n";
        }
    }

    $masterPdo->commit();

    echo "════════════════════════════════════════════════════════════\n";
    echo "  ✅ SETUP COMPLETED SUCCESSFULLY!\n";
    echo "════════════════════════════════════════════════════════════\n\n";

    echo "📊 Summary:\n";
    echo "   ✅ $successCount / " . count($businesses) . " businesses processed successfully\n";
    echo "   ✅ cash_accounts tables created\n";
    echo "   ✅ cash_account_transactions tables created\n";
    echo "   ✅ Default cash accounts initialized\n\n";

    echo "🎯 Default Cash Accounts per Business:\n";
    echo "   1. Kas Operasional (untuk pendapatan penjualan)\n";
    echo "   2. Kas Modal Owner (untuk dana dari pemilik)\n";
    echo "   3. Bank (untuk transaksi perbankan)\n\n";

    echo "📋 Next Steps:\n";
    echo "   1. Update form input transaksi untuk pilih akun kas\n";
    echo "   2. Buat kategori 'Setoran Modal Owner' di divisions\n";
    echo "   3. Buat laporan monitoring modal untuk Owner\n";
    echo "   4. Add widget di Owner Dashboard\n\n";

    exit(0);

} catch (PDOException $e) {
    echo "\n❌ DATABASE CONNECTION ERROR!\n";
    echo "════════════════════════════════════════════════════════════\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Database: " . DB_NAME . "\n";
    echo "Host: " . DB_HOST . "\n";
    echo "════════════════════════════════════════════════════════════\n\n";
    exit(1);
} catch (Exception $e) {
    echo "\n❌ ERROR!\n";
    echo "════════════════════════════════════════════════════════════\n";
    echo $e->getMessage() . "\n";
    echo "════════════════════════════════════════════════════════════\n\n";
    exit(1);
}
?>
