-- ============================================
-- BILLS / TAGIHAN MODULE - Database Schema
-- Recurring monthly bills with due dates & auto-payment
-- ============================================

-- 1. Bill Templates (recurring bill definitions)
CREATE TABLE IF NOT EXISTS bill_templates (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    bill_name VARCHAR(150) NOT NULL,
    bill_category ENUM('electricity','tax','wifi','vehicle','po','receivable','other') NOT NULL DEFAULT 'other',
    vendor_name VARCHAR(150) DEFAULT NULL,
    vendor_contact VARCHAR(100) DEFAULT NULL,
    account_number VARCHAR(100) DEFAULT NULL COMMENT 'Meter/account/reference number',
    default_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_fixed_amount TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=fixed amount every month, 0=variable',
    recurrence ENUM('monthly','quarterly','yearly','one-time') NOT NULL DEFAULT 'monthly',
    due_day INT(2) NOT NULL DEFAULT 1 COMMENT 'Day of month when bill is due (1-28)',
    reminder_days INT(3) NOT NULL DEFAULT 3 COMMENT 'Notify X days before due date',
    division_id INT(11) DEFAULT NULL,
    category_id INT(11) DEFAULT NULL,
    payment_method ENUM('cash','transfer','qr','debit','other') DEFAULT 'transfer',
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (bill_category),
    INDEX idx_active (is_active),
    INDEX idx_due_day (due_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Bill Records (individual monthly instances)
CREATE TABLE IF NOT EXISTS bill_records (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    template_id INT(11) NOT NULL,
    bill_period VARCHAR(7) NOT NULL COMMENT 'YYYY-MM format',
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    due_date DATE NOT NULL,
    status ENUM('pending','paid','overdue','cancelled') NOT NULL DEFAULT 'pending',
    paid_date DATE DEFAULT NULL,
    paid_amount DECIMAL(15,2) DEFAULT NULL,
    payment_method ENUM('cash','transfer','qr','debit','other') DEFAULT NULL,
    cashbook_id INT(11) DEFAULT NULL COMMENT 'Reference to cash_book.id after payment',
    proof_file VARCHAR(255) DEFAULT NULL COMMENT 'Upload bukti bayar',
    notes TEXT DEFAULT NULL,
    paid_by INT(11) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_template (template_id),
    INDEX idx_period (bill_period),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_cashbook (cashbook_id),
    UNIQUE KEY unique_bill_period (template_id, bill_period),
    FOREIGN KEY (template_id) REFERENCES bill_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Default bill categories with sample data (optional seed)
-- INSERT INTO bill_templates (bill_name, bill_category, vendor_name, default_amount, is_fixed_amount, recurrence, due_day, reminder_days, division_id, category_id, payment_method, created_by)
-- VALUES
-- ('Listrik PLN', 'electricity', 'PLN', 0, 0, 'monthly', 20, 3, NULL, NULL, 'transfer', 1),
-- ('Pajak PBB', 'tax', 'Kantor Pajak', 0, 0, 'yearly', 15, 7, NULL, NULL, 'transfer', 1),
-- ('Internet/WiFi', 'wifi', 'IndiHome', 0, 1, 'monthly', 20, 3, NULL, NULL, 'transfer', 1),
-- ('Cicilan Motor', 'vehicle', 'FIF', 0, 1, 'monthly', 10, 5, NULL, NULL, 'transfer', 1);
