-- Sales Invoices Module Database Schema
-- Narayana Hotel Management System

-- Table for sales invoice headers
CREATE TABLE IF NOT EXISTS `sales_invoices_header` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `customer_address` text DEFAULT NULL,
  `division_id` int(11) NOT NULL,
  `payment_method` enum('cash','debit','transfer','qr','other') DEFAULT 'cash',
  `payment_status` enum('unpaid','paid','partial') DEFAULT 'unpaid',
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cash_book_id` int(11) DEFAULT NULL COMMENT 'Reference to cash_book entry',
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `division_id` (`division_id`),
  KEY `created_by` (`created_by`),
  KEY `invoice_date` (`invoice_date`),
  KEY `payment_status` (`payment_status`),
  KEY `cash_book_id` (`cash_book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for sales invoice detail items
CREATE TABLE IF NOT EXISTS `sales_invoices_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_header_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'rental_motor, laundry, tour, rental_mobil, etc',
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_header_id` (`invoice_header_id`),
  CONSTRAINT `sales_invoices_detail_ibfk_1` FOREIGN KEY (`invoice_header_id`) REFERENCES `sales_invoices_header` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add index for better performance
CREATE INDEX idx_invoice_customer ON sales_invoices_header(customer_name);
CREATE INDEX idx_item_category ON sales_invoices_detail(category);
