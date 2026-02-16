-- MONTHLY CLOSING SYSTEM TABLES
-- Create tables for monthly archives and carry forward balances

-- Table untuk menyimpan arsip monthly closing
CREATE TABLE IF NOT EXISTS `monthly_archives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `archive_month` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `total_income` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_expense` decimal(15,2) NOT NULL DEFAULT 0.00,
  `monthly_profit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `transaction_count` int(11) NOT NULL DEFAULT 0,
  `final_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `minimum_operational` decimal(15,2) NOT NULL DEFAULT 0.00,
  `excess_transferred` decimal(15,2) NOT NULL DEFAULT 0.00,
  `closing_date` datetime NOT NULL,
  `closed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_business_month` (`business_id`, `archive_month`),
  KEY `idx_business_month` (`business_id`, `archive_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table untuk carry forward balance ke bulan berikutnya  
CREATE TABLE IF NOT EXISTS `monthly_carry_forward` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `month` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `carry_forward_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `petty_cash_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `owner_capital_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_business_month` (`business_id`, `month`),
  KEY `idx_business_month` (`business_id`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add new transaction type for monthly closing
ALTER TABLE `cash_account_transactions` 
MODIFY COLUMN `transaction_type` ENUM('income','expense','transfer','capital_injection','capital_return','monthly_closing') NOT NULL DEFAULT 'income';

-- Insert sample data for testing (optional)
-- INSERT INTO monthly_archives (business_id, archive_month, total_income, total_expense, monthly_profit, transaction_count, final_balance, minimum_operational, excess_transferred, closing_date, closed_by) 
-- VALUES (1, '2026-01', 5000000, 3000000, 2000000, 25, 2500000, 500000, 2000000, '2026-02-01 23:59:59', 1);