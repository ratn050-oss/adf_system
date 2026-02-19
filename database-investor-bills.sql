-- Simple Bills Recording for Investor Module
-- Manual recording of bills: land payments, utilities, etc.

CREATE TABLE IF NOT EXISTS `investor_bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_number` varchar(50) DEFAULT NULL COMMENT 'Optional reference number',
  `title` varchar(255) NOT NULL COMMENT 'Bill title/name',
  `description` text COMMENT 'Detailed description',
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Bill amount in IDR',
  `category` enum('land','property','utility','tax','service','legal','other') DEFAULT 'other' COMMENT 'Bill category',
  `due_date` date DEFAULT NULL COMMENT 'When payment is due',
  `payment_date` date DEFAULT NULL COMMENT 'When actually paid',
  `status` enum('unpaid','paid','overdue','cancelled') DEFAULT 'unpaid' COMMENT 'Payment status',
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'Cash/Transfer/etc',
  `paid_by` varchar(100) DEFAULT NULL COMMENT 'Who made the payment',
  `notes` text COMMENT 'Additional notes',
  `attachment` varchar(255) DEFAULT NULL COMMENT 'File path for invoice/receipt',
  `created_by` int(11) DEFAULT NULL COMMENT 'User ID who created',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Simple manual bills recording for investor projects';
