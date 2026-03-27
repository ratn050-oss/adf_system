-- ============================================
-- Procurement Module Schema
-- Narayana Hotel Management System
-- ============================================

-- Suppliers Table
CREATE TABLE IF NOT EXISTS suppliers (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    supplier_code VARCHAR(50) NOT NULL UNIQUE,
    supplier_name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255),
    phone VARCHAR(50),
    email VARCHAR(255),
    address TEXT,
    tax_number VARCHAR(100),
    payment_terms ENUM('cash', 'net_7', 'net_14', 'net_30', 'net_45', 'net_60') DEFAULT 'net_30',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase Orders Header
CREATE TABLE IF NOT EXISTS purchase_orders_header (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    po_number VARCHAR(50) NOT NULL UNIQUE,
    supplier_id INT(11) NOT NULL,
    po_date DATE NOT NULL,
    expected_delivery_date DATE,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'partially_received', 'completed', 'cancelled') DEFAULT 'draft',
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    grand_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    notes TEXT,
    approved_by INT(11),
    approved_at TIMESTAMP NULL,
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_po_date (po_date),
    INDEX idx_status (status),
    INDEX idx_supplier (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase Orders Detail
CREATE TABLE IF NOT EXISTS purchase_orders_detail (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    po_header_id INT(11) NOT NULL,
    line_number INT(11) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_description TEXT,
    unit_of_measure VARCHAR(50) DEFAULT 'pcs',
    quantity DECIMAL(15,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL,
    division_id INT(11) NOT NULL COMMENT 'Cost Center',
    received_quantity DECIMAL(15,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_header_id) REFERENCES purchase_orders_header(id) ON DELETE CASCADE,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE RESTRICT,
    INDEX idx_po_header (po_header_id),
    INDEX idx_division (division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchases Header (Real Invoice/Receipt)
CREATE TABLE IF NOT EXISTS purchases_header (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    po_id INT(11) NULL COMMENT 'Links to PO if applicable',
    supplier_id INT(11) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE,
    received_date DATE NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    grand_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_status ENUM('unpaid', 'partial', 'paid') DEFAULT 'unpaid',
    paid_amount DECIMAL(15,2) DEFAULT 0,
    gl_posted TINYINT(1) DEFAULT 0 COMMENT 'Posted to General Ledger',
    gl_posted_at TIMESTAMP NULL,
    notes TEXT,
    attachment_path VARCHAR(255),
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders_header(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_invoice_date (invoice_date),
    INDEX idx_supplier (supplier_id),
    INDEX idx_po (po_id),
    INDEX idx_payment_status (payment_status),
    INDEX idx_gl_posted (gl_posted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchases Detail
CREATE TABLE IF NOT EXISTS purchases_detail (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    purchase_header_id INT(11) NOT NULL,
    po_detail_id INT(11) NULL COMMENT 'Links to PO detail if applicable',
    line_number INT(11) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_description TEXT,
    unit_of_measure VARCHAR(50) DEFAULT 'pcs',
    quantity DECIMAL(15,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL,
    division_id INT(11) NOT NULL COMMENT 'Cost Center',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_header_id) REFERENCES purchases_header(id) ON DELETE CASCADE,
    FOREIGN KEY (po_detail_id) REFERENCES purchase_orders_detail(id) ON DELETE SET NULL,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE RESTRICT,
    INDEX idx_purchase_header (purchase_header_id),
    INDEX idx_division (division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General Ledger
CREATE TABLE IF NOT EXISTS general_ledger (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    gl_date DATE NOT NULL,
    account_code VARCHAR(50) NOT NULL COMMENT 'COA Account Code',
    account_name VARCHAR(255) NOT NULL COMMENT 'Account Description',
    description TEXT,
    debit DECIMAL(15,2) DEFAULT 0,
    credit DECIMAL(15,2) DEFAULT 0,
    transaction_type ENUM('purchase', 'payment', 'journal', 'cashbook', 'adjustment') NOT NULL,
    transaction_ref_id INT(11) COMMENT 'Reference to source transaction',
    transaction_ref_number VARCHAR(50) COMMENT 'Invoice/Receipt/Voucher Number',
    division_id INT(11) COMMENT 'For cost center reporting',
    fiscal_year INT(4) NOT NULL,
    fiscal_period INT(2) NOT NULL COMMENT 'Month 1-12',
    posted_by INT(11) NOT NULL,
    posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reversed TINYINT(1) DEFAULT 0,
    reversed_by INT(11) NULL,
    reversed_at TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (posted_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (reversed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL,
    INDEX idx_gl_date (gl_date),
    INDEX idx_account_code (account_code),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_transaction_ref (transaction_ref_id, transaction_type),
    INDEX idx_fiscal_period (fiscal_year, fiscal_period),
    INDEX idx_division (division_id),
    INDEX idx_reversed (reversed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chart of Accounts (COA)
CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    account_code VARCHAR(50) NOT NULL UNIQUE,
    account_name VARCHAR(255) NOT NULL,
    account_type ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    account_category VARCHAR(100) COMMENT 'Current Asset, Fixed Asset, etc.',
    parent_account_code VARCHAR(50),
    is_header TINYINT(1) DEFAULT 0 COMMENT 'Header account cannot have transactions',
    normal_balance ENUM('debit', 'credit') NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_account_type (account_type),
    INDEX idx_parent (parent_account_code),
    INDEX idx_is_header (is_header),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment Transactions
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    payment_number VARCHAR(50) NOT NULL UNIQUE,
    purchase_header_id INT(11) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'transfer', 'check', 'giro') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    bank_name VARCHAR(255),
    bank_account VARCHAR(100),
    check_number VARCHAR(50),
    check_date DATE,
    notes TEXT,
    gl_posted TINYINT(1) DEFAULT 0,
    gl_posted_at TIMESTAMP NULL,
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_header_id) REFERENCES purchases_header(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_payment_date (payment_date),
    INDEX idx_purchase (purchase_header_id),
    INDEX idx_gl_posted (gl_posted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Sample Data
-- ============================================

-- Sample Suppliers
INSERT INTO suppliers (supplier_code, supplier_name, contact_person, phone, email, payment_terms, created_by) VALUES
('SUP001', 'PT Sumber Makmur', 'Budi Santoso', '081234567890', 'budi@sumbermakmur.com', 'net_30', 1),
('SUP002', 'CV Cahaya Jaya', 'Siti Aminah', '081234567891', 'siti@cahayajaya.com', 'net_14', 1),
('SUP003', 'Toko Serba Ada', 'Ahmad Yani', '081234567892', 'ahmad@tokoserbaada.com', 'cash', 1);

-- Sample Chart of Accounts
INSERT INTO chart_of_accounts (account_code, account_name, account_type, account_category, normal_balance, is_header) VALUES
-- Assets
('1000', 'ASSET', 'asset', 'Header', 'debit', 1),
('1100', 'Current Assets', 'asset', 'Current Asset', 'debit', 1),
('1101', 'Cash', 'asset', 'Current Asset', 'debit', 0),
('1102', 'Bank', 'asset', 'Current Asset', 'debit', 0),
('1103', 'Accounts Receivable', 'asset', 'Current Asset', 'debit', 0),
('1104', 'Inventory', 'asset', 'Current Asset', 'debit', 0),
('1105', 'Prepaid Expenses', 'asset', 'Current Asset', 'debit', 0),

-- Liabilities
('2000', 'LIABILITY', 'liability', 'Header', 'credit', 1),
('2100', 'Current Liabilities', 'liability', 'Current Liability', 'credit', 1),
('2101', 'Accounts Payable', 'liability', 'Current Liability', 'credit', 0),
('2102', 'Accrued Expenses', 'liability', 'Current Liability', 'credit', 0),
('2103', 'Tax Payable', 'liability', 'Current Liability', 'credit', 0),

-- Expenses
('5000', 'EXPENSE', 'expense', 'Header', 'debit', 1),
('5100', 'Operating Expenses', 'expense', 'Operating Expense', 'debit', 1),
('5101', 'Office Supplies', 'expense', 'Operating Expense', 'debit', 0),
('5102', 'Utilities', 'expense', 'Operating Expense', 'debit', 0),
('5103', 'Maintenance', 'expense', 'Operating Expense', 'debit', 0),
('5104', 'Food & Beverage Supplies', 'expense', 'Operating Expense', 'debit', 0),
('5105', 'Housekeeping Supplies', 'expense', 'Operating Expense', 'debit', 0);

-- ============================================
-- Triggers
-- ============================================

-- Auto-generate PO Number
DELIMITER $$
CREATE TRIGGER before_insert_po_header
BEFORE INSERT ON purchase_orders_header
FOR EACH ROW
BEGIN
    IF NEW.po_number IS NULL OR NEW.po_number = '' THEN
        SET NEW.po_number = CONCAT('PO', DATE_FORMAT(NOW(), '%Y%m'), LPAD((
            SELECT COALESCE(MAX(CAST(SUBSTRING(po_number, 9) AS UNSIGNED)), 0) + 1
            FROM purchase_orders_header
            WHERE po_number LIKE CONCAT('PO', DATE_FORMAT(NOW(), '%Y%m'), '%')
        ), 4, '0'));
    END IF;
END$$
DELIMITER ;

-- Auto-calculate PO subtotal
DELIMITER $$
CREATE TRIGGER before_insert_po_detail
BEFORE INSERT ON purchase_orders_detail
FOR EACH ROW
BEGIN
    SET NEW.subtotal = NEW.quantity * NEW.unit_price;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER before_update_po_detail
BEFORE UPDATE ON purchase_orders_detail
FOR EACH ROW
BEGIN
    SET NEW.subtotal = NEW.quantity * NEW.unit_price;
END$$
DELIMITER ;

-- Update PO header total after detail insert
DELIMITER $$
CREATE TRIGGER after_insert_po_detail
AFTER INSERT ON purchase_orders_detail
FOR EACH ROW
BEGIN
    UPDATE purchase_orders_header
    SET total_amount = (
        SELECT SUM(subtotal)
        FROM purchase_orders_detail
        WHERE po_header_id = NEW.po_header_id
    ),
    grand_total = total_amount - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)
    WHERE id = NEW.po_header_id;
END$$
DELIMITER ;

-- Update PO header total after detail update
DELIMITER $$
CREATE TRIGGER after_update_po_detail
AFTER UPDATE ON purchase_orders_detail
FOR EACH ROW
BEGIN
    UPDATE purchase_orders_header
    SET total_amount = (
        SELECT SUM(subtotal)
        FROM purchase_orders_detail
        WHERE po_header_id = NEW.po_header_id
    ),
    grand_total = total_amount - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)
    WHERE id = NEW.po_header_id;
END$$
DELIMITER ;

-- Update PO header total after detail delete
DELIMITER $$
CREATE TRIGGER after_delete_po_detail
AFTER DELETE ON purchase_orders_detail
FOR EACH ROW
BEGIN
    UPDATE purchase_orders_header
    SET total_amount = (
        SELECT COALESCE(SUM(subtotal), 0)
        FROM purchase_orders_detail
        WHERE po_header_id = OLD.po_header_id
    ),
    grand_total = total_amount - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)
    WHERE id = OLD.po_header_id;
END$$
DELIMITER ;

-- Auto-calculate Purchase subtotal
DELIMITER $$
CREATE TRIGGER before_insert_purchase_detail
BEFORE INSERT ON purchases_detail
FOR EACH ROW
BEGIN
    SET NEW.subtotal = NEW.quantity * NEW.unit_price;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER before_update_purchase_detail
BEFORE UPDATE ON purchases_detail
FOR EACH ROW
BEGIN
    SET NEW.subtotal = NEW.quantity * NEW.unit_price;
END$$
DELIMITER ;

-- Update Purchase header total after detail insert
DELIMITER $$
CREATE TRIGGER after_insert_purchase_detail
AFTER INSERT ON purchases_detail
FOR EACH ROW
BEGIN
    UPDATE purchases_header
    SET total_amount = (
        SELECT SUM(subtotal)
        FROM purchases_detail
        WHERE purchase_header_id = NEW.purchase_header_id
    ),
    grand_total = total_amount - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)
    WHERE id = NEW.purchase_header_id;
END$$
DELIMITER ;

-- Update Purchase header total after detail update
DELIMITER $$
CREATE TRIGGER after_update_purchase_detail
AFTER UPDATE ON purchases_detail
FOR EACH ROW
BEGIN
    UPDATE purchases_header
    SET total_amount = (
        SELECT SUM(subtotal)
        FROM purchases_detail
        WHERE purchase_header_id = NEW.purchase_header_id
    ),
    grand_total = total_amount - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)
    WHERE id = NEW.purchase_header_id;
END$$
DELIMITER ;

-- Update Purchase header total after detail delete
DELIMITER $$
CREATE TRIGGER after_delete_purchase_detail
AFTER DELETE ON purchases_detail
FOR EACH ROW
BEGIN
    UPDATE purchases_header
    SET total_amount = (
        SELECT COALESCE(SUM(subtotal), 0)
        FROM purchases_detail
        WHERE purchase_header_id = OLD.purchase_header_id
    ),
    grand_total = total_amount - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)
    WHERE id = OLD.purchase_header_id;
END$$
DELIMITER ;

-- ============================================
-- Views
-- ============================================

-- Outstanding Purchase Orders
CREATE OR REPLACE VIEW v_outstanding_po AS
SELECT 
    poh.id,
    poh.po_number,
    poh.po_date,
    poh.expected_delivery_date,
    s.supplier_name,
    poh.grand_total,
    poh.status,
    SUM(pod.received_quantity) as total_received,
    SUM(pod.quantity) as total_ordered,
    CASE 
        WHEN SUM(pod.received_quantity) = 0 THEN 'Not Received'
        WHEN SUM(pod.received_quantity) < SUM(pod.quantity) THEN 'Partially Received'
        WHEN SUM(pod.received_quantity) >= SUM(pod.quantity) THEN 'Fully Received'
    END as receiving_status
FROM purchase_orders_header poh
LEFT JOIN suppliers s ON poh.supplier_id = s.id
LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
WHERE poh.status IN ('approved', 'partially_received')
GROUP BY poh.id, poh.po_number, poh.po_date, poh.expected_delivery_date, s.supplier_name, poh.grand_total, poh.status;

-- Accounts Payable Aging
CREATE OR REPLACE VIEW v_ap_aging AS
SELECT 
    ph.id,
    ph.invoice_number,
    ph.invoice_date,
    ph.due_date,
    s.supplier_name,
    ph.grand_total,
    ph.paid_amount,
    (ph.grand_total - ph.paid_amount) as outstanding_amount,
    DATEDIFF(CURDATE(), ph.due_date) as days_overdue,
    CASE
        WHEN ph.payment_status = 'paid' THEN 'Paid'
        WHEN DATEDIFF(CURDATE(), ph.due_date) <= 0 THEN 'Current'
        WHEN DATEDIFF(CURDATE(), ph.due_date) BETWEEN 1 AND 30 THEN '1-30 Days'
        WHEN DATEDIFF(CURDATE(), ph.due_date) BETWEEN 31 AND 60 THEN '31-60 Days'
        WHEN DATEDIFF(CURDATE(), ph.due_date) BETWEEN 61 AND 90 THEN '61-90 Days'
        ELSE 'Over 90 Days'
    END as aging_category
FROM purchases_header ph
LEFT JOIN suppliers s ON ph.supplier_id = s.id
WHERE ph.payment_status IN ('unpaid', 'partial')
ORDER BY ph.due_date;

-- Purchase Summary by Division
CREATE OR REPLACE VIEW v_purchase_by_division AS
SELECT 
    d.id as division_id,
    d.division_name,
    d.division_code,
    DATE_FORMAT(ph.invoice_date, '%Y-%m') as month_year,
    COUNT(DISTINCT ph.id) as total_invoices,
    COUNT(pd.id) as total_items,
    SUM(pd.subtotal) as total_amount
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY d.id, d.division_name, d.division_code, DATE_FORMAT(ph.invoice_date, '%Y-%m')
ORDER BY month_year DESC, total_amount DESC;

-- General Ledger Balance
CREATE OR REPLACE VIEW v_gl_balance AS
SELECT 
    account_code,
    account_name,
    SUM(debit) as total_debit,
    SUM(credit) as total_credit,
    SUM(debit) - SUM(credit) as balance,
    fiscal_year,
    MAX(gl_date) as last_transaction_date
FROM general_ledger
WHERE reversed = 0
GROUP BY account_code, account_name, fiscal_year
ORDER BY account_code;
