-- ========================================
-- MONTHLY BILLS SYSTEM - DATABASE SETUP
-- ========================================

-- TABLE: monthly_bills
-- Purpose: Track recurring/one-time monthly expenses
CREATE TABLE IF NOT EXISTS `monthly_bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `bill_code` varchar(20) NOT NULL UNIQUE,
  `division_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `bill_name` varchar(100) NOT NULL COMMENT 'Listrik, Air, Gaji, Sewa, dll',
  `bill_month` date NOT NULL COMMENT 'Bulan tagihan (format YYYY-04-01)',
  `amount` decimal(12,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','partial','paid','cancelled') DEFAULT 'pending',
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'cash, transfer, other',
  `cash_account_id_source` int(11) DEFAULT NULL COMMENT 'FK ke cash_accounts (source rekening)',
  `notes` text,
  `is_recurring` tinyint(1) DEFAULT 0 COMMENT '1=setiap bulan, 0=one-time',
  `created_by` int(11),
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_bill_month` (`bill_month`),
  KEY `idx_status` (`status`),
  KEY `idx_division_id` (`division_id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Monthly bills and recurring expenses';

-- TABLE: bill_payments
-- Purpose: Track individual payment entries for bills
CREATE TABLE IF NOT EXISTS `bill_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `bill_id` int(11) NOT NULL,
  `payment_date` datetime NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'cash, transfer, card, other',
  `cash_account_id` int(11) DEFAULT NULL COMMENT 'Dari rekening mana (FK ke cash_accounts)',
  `reference_number` varchar(50) DEFAULT NULL COMMENT 'Nomor bukti transfer/kartu',
  `synced_to_cashbook` tinyint(1) DEFAULT 0 COMMENT 'Sudah masuk buku kas?',
  `cashbook_id` int(11) DEFAULT NULL COMMENT 'FK ke cash_book.id',
  `notes` text,
  `created_by` int(11),
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bill_id`) REFERENCES `monthly_bills`(`id`) ON DELETE CASCADE,
  KEY `idx_bill_id` (`bill_id`),
  KEY `idx_synced_to_cashbook` (`synced_to_cashbook`),
  KEY `idx_payment_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment entries for monthly bills';

-- ========================================
-- DATA: Sample bill_code generator function
-- ========================================

-- Sample recurring bills untuk test
INSERT IGNORE INTO `monthly_bills` 
(`bill_code`, `division_id`, `category_id`, `bill_name`, `bill_month`, `amount`, `due_date`, `status`, `is_recurring`, `created_by`)
VALUES
('BL-202604-001', 1, 1, 'Listrik', '2026-04-01', 5000000, '2026-04-05', 'pending', 1, 1),
('BL-202604-002', 1, 1, 'Air Bersih', '2026-04-01', 2000000, '2026-04-05', 'pending', 1, 1),
('BL-202604-003', 1, 1, 'Gaji Staff', '2026-04-01', 50000000, '2026-04-25', 'pending', 1, 1),
('BL-202604-004', 1, 1, 'Sewa Gedung', '2026-04-01', 25000000, '2026-04-01', 'pending', 1, 1);

-- ========================================
-- INDEX: Optimize queries
-- ========================================
CREATE INDEX idx_monthly_bills_month_status ON `monthly_bills` (`bill_month`, `status`);
CREATE INDEX idx_bill_payments_bill_date ON `bill_payments` (`bill_id`, `payment_date`);
