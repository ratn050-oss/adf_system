-- ============================================================================
-- KASBOOK SETUP - Create cash_accounts & cash_account_transactions tables
-- For: Narayana Hotel & Ben's Cafe - ADF System
-- ============================================================================

-- TABLE 1: cash_accounts
-- Menyimpan akun kas dengan saldo current
CREATE TABLE IF NOT EXISTS `cash_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `account_name` VARCHAR(100) NOT NULL,
    `account_type` ENUM('owner_capital', 'petty_cash', 'cash') NOT NULL,
    `business_id` INT NOT NULL,
    `current_balance` DECIMAL(15, 2) DEFAULT 0,
    `opening_balance` DECIMAL(15, 2) DEFAULT 0,
    `description` TEXT,
    `is_active` BOOLEAN DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `unique_account_per_business` (`account_type`, `business_id`),
    KEY `idx_business_id` (`business_id`),
    KEY `idx_account_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLE 2: cash_account_transactions  
-- Menyimpan semua transaksi kas (debit/credit)
CREATE TABLE IF NOT EXISTS `cash_account_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cash_account_id` INT NOT NULL,
    `transaction_type` ENUM('debit', 'credit') NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `description` TEXT NOT NULL,
    `reference_number` VARCHAR(50),
    `transaction_date` DATE NOT NULL,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts`(`id`) ON DELETE RESTRICT,
    KEY `idx_cash_account_id` (`cash_account_id`),
    KEY `idx_transaction_date` (`transaction_date`),
    KEY `idx_transaction_type` (`transaction_type`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INSERT DEFAULT ACCOUNTS untuk Narayana Hotel (business_id = 1)
-- ============================================================================

INSERT INTO `cash_accounts` 
(`account_name`, `account_type`, `business_id`, `current_balance`, `opening_balance`, `description`, `is_active`)
VALUES
('Modal Owner - Narayana', 'owner_capital', 1, 0, 0, 'Setoran dari pemilik untuk operasional', 1),
('Petty Cash - Narayana', 'petty_cash', 1, 0, 0, 'Kas operasional harian (dari owner + revenue)', 1),
('Revenue Cash - Narayana', 'cash', 1, 0, 0, 'Penerimaan revenue hotel (untuk tracking)', 1)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ============================================================================
-- INSERT DEFAULT ACCOUNTS untuk Ben's Cafe (business_id = 2)
-- ============================================================================

INSERT INTO `cash_accounts` 
(`account_name`, `account_type`, `business_id`, `current_balance`, `opening_balance`, `description`, `is_active`)
VALUES
('Modal Owner - Bens', 'owner_capital', 2, 0, 0, 'Setoran dari pemilik untuk operasional', 1),
('Petty Cash - Bens', 'petty_cash', 2, 0, 0, 'Kas operasional harian (dari owner + revenue)', 1),
('Revenue Cash - Bens', 'cash', 2, 0, 0, 'Penerimaan revenue kafe (untuk tracking)', 1)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ============================================================================
-- SAMPLE DATA (uncomment if needed untuk testing)
-- ============================================================================

-- Insert sample transactions untuk hari ini (untuk testing kasbook-daily-simple.php)
/*
INSERT INTO `cash_account_transactions` 
(`cash_account_id`, `transaction_type`, `amount`, `description`, `transaction_date`, `created_by`)
SELECT 
    id, 'debit', 500000, '[OWNER] Setoran modal pagi hari', CURDATE(), 1
FROM `cash_accounts`
WHERE business_id = 1 AND account_type = 'petty_cash';

INSERT INTO `cash_account_transactions` 
(`cash_account_id`, `transaction_type`, `amount`, `description`, `transaction_date`, `created_by`)
SELECT
    id, 'debit', 1500000, '[REVENUE] Revenue kamar night audit', CURDATE(), 1
FROM `cash_accounts`
WHERE business_id = 1 AND account_type = 'petty_cash';

INSERT INTO `cash_account_transactions` 
(`cash_account_id`, `transaction_type`, `amount`, `description`, `transaction_date`, `created_by`)
SELECT
    id, 'credit', 200000, 'Belanja supplies dapur', CURDATE(), 1
FROM `cash_accounts`
WHERE business_id = 1 AND account_type = 'petty_cash';
*/

-- ============================================================================
-- VERIFY & TEST
-- ============================================================================

-- Check that tables were created:
-- SELECT * FROM cash_accounts LIMIT 5;
-- SELECT * FROM cash_account_transactions LIMIT 5;

-- Test Kas Masuk Owner (today):
-- SELECT SUM(amount) FROM cash_account_transactions 
-- WHERE cash_account_id = 2 AND transaction_date = CURDATE() 
-- AND transaction_type = 'debit' AND description LIKE '%OWNER%';

-- Test Kas Masuk Revenue (today):
-- SELECT SUM(amount) FROM cash_account_transactions 
-- WHERE cash_account_id = 2 AND transaction_date = CURDATE() 
-- AND transaction_type = 'debit' AND description LIKE '%REVENUE%';

-- Test Kas Keluar (today):
-- SELECT SUM(amount) FROM cash_account_transactions 
-- WHERE cash_account_id = 2 AND transaction_date = CURDATE() 
-- AND transaction_type = 'credit';
