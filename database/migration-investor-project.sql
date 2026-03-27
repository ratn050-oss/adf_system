-- ============================================
-- INVESTOR & PROJECT MANAGEMENT SYSTEM
-- Database Migration for narayana_hotel
-- Created: 2026-01-25
-- ============================================

USE narayana_hotel;

-- ============================================
-- INVESTORS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS investors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investor_name VARCHAR(150) NOT NULL,
    investor_address TEXT NOT NULL,
    contact_phone VARCHAR(20),
    email VARCHAR(100),
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INVESTOR CAPITAL TRANSACTIONS
-- (Transaksi modal masuk dari investor, dalam USD dan IDR)
-- ============================================
CREATE TABLE IF NOT EXISTS investor_capital_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investor_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_time TIME NOT NULL,
    
    -- Nilai dalam USD
    amount_usd DECIMAL(15,2) NOT NULL,
    
    -- Nilai dalam IDR (hasil konversi otomatis)
    amount_idr DECIMAL(15,2) NOT NULL,
    exchange_rate DECIMAL(10,4) NOT NULL COMMENT 'Kurs USD ke IDR pada saat transaksi',
    
    -- Deskripsi
    description TEXT,
    reference_no VARCHAR(50),
    payment_method ENUM('bank_transfer', 'cash', 'check', 'other') DEFAULT 'bank_transfer',
    
    -- Status
    status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'confirmed',
    
    -- Metadata
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_investor (investor_id),
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INVESTOR BALANCE SUMMARY
-- (Saldo akumulatif setiap investor)
-- ============================================
CREATE TABLE IF NOT EXISTS investor_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investor_id INT NOT NULL UNIQUE,
    total_capital_usd DECIMAL(15,2) DEFAULT 0,
    total_capital_idr DECIMAL(15,2) DEFAULT 0,
    total_expenses_idr DECIMAL(15,2) DEFAULT 0,
    remaining_balance_idr DECIMAL(15,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_investor (investor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROJECTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(150) NOT NULL,
    project_code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    location VARCHAR(200),
    
    -- Finalisasi budget
    budget_idr DECIMAL(15,2),
    
    -- Status project
    status ENUM('planning', 'ongoing', 'on_hold', 'completed', 'cancelled') DEFAULT 'planning',
    
    -- Tanggal
    start_date DATE,
    end_date DATE,
    
    -- Metadata
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_code (project_code),
    INDEX idx_start_date (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROJECT EXPENSE CATEGORIES
-- ============================================
CREATE TABLE IF NOT EXISTS project_expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    category_code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROJECT EXPENSES LEDGER
-- (Buku kas untuk pengeluaran project dengan kategori khusus)
-- ============================================
CREATE TABLE IF NOT EXISTS project_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    expense_category_id INT NOT NULL,
    
    expense_date DATE NOT NULL,
    expense_time TIME NOT NULL,
    
    amount_idr DECIMAL(15,2) NOT NULL,
    description TEXT,
    reference_no VARCHAR(50),
    
    payment_method ENUM('cash', 'bank_transfer', 'check', 'other') DEFAULT 'cash',
    
    -- Status persetujuan
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'paid') DEFAULT 'draft',
    
    -- Approval
    approved_by INT,
    approved_at TIMESTAMP NULL,
    
    -- Metadata
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    FOREIGN KEY (expense_category_id) REFERENCES project_expense_categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_project (project_id),
    INDEX idx_category (expense_category_id),
    INDEX idx_expense_date (expense_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROJECT BALANCE SUMMARY
-- (Saldo pengeluaran per project)
-- ============================================
CREATE TABLE IF NOT EXISTS project_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL UNIQUE,
    total_expenses_idr DECIMAL(15,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EXCHANGE RATES TABLE
-- (Menyimpan historical kurs USD → IDR)
-- ============================================
CREATE TABLE IF NOT EXISTS exchange_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_of_rate DATE NOT NULL,
    time_of_rate TIME NOT NULL,
    usd_to_idr DECIMAL(10,4) NOT NULL COMMENT 'Rate: 1 USD = ? IDR',
    source ENUM('api_bank_indonesia', 'api_openexchange', 'manual_input') DEFAULT 'api_bank_indonesia',
    
    is_current TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_date (date_of_rate),
    INDEX idx_is_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERT DEFAULT EXPENSE CATEGORIES
-- ============================================
INSERT INTO project_expense_categories (category_name, category_code, description, sort_order) VALUES
('Pembelian Material', 'MAT', 'Pembelian bahan material untuk project', 1),
('Pembayaran Truk', 'TRUCK', 'Biaya pengangkutan dan truk', 2),
('Tiket Kapal', 'SHIP', 'Biaya tiket kapal untuk distribusi', 3),
('Gaji Tukang', 'LABOR', 'Gaji buruh dan tukang project', 4)
ON DUPLICATE KEY UPDATE category_name = category_name;

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================
CREATE INDEX idx_investor_capital_confirmed ON investor_capital_transactions(investor_id, status, transaction_date);
CREATE INDEX idx_project_expenses_summary ON project_expenses(project_id, status, expense_date);

-- ============================================
-- END MIGRATION
-- ============================================
COMMIT;
