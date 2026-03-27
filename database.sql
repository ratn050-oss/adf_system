-- ============================================
-- NARAYANA HOTEL MANAGEMENT SYSTEM
-- Database Structure - Accounting Module
-- Created: 2026
-- ============================================

CREATE DATABASE IF NOT EXISTS narayana_hotel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE narayana_hotel;

-- ============================================
-- USERS TABLE
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'manager', 'accountant', 'staff') DEFAULT 'staff',
    phone VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- USER PREFERENCES TABLE
-- ============================================
CREATE TABLE user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    theme VARCHAR(50) DEFAULT 'dark',
    language VARCHAR(20) DEFAULT 'id',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DIVISIONS TABLE (Hotel, Resto, Motor, Trip, Laundry, etc)
-- ============================================
CREATE TABLE divisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_code VARCHAR(20) UNIQUE NOT NULL,
    division_name VARCHAR(100) NOT NULL,
    division_type ENUM('income', 'expense', 'both') DEFAULT 'both',
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (division_code),
    INDEX idx_type (division_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CATEGORIES TABLE (Detail kategori transaksi)
-- ============================================
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_id INT NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    category_type ENUM('income', 'expense') NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
    INDEX idx_division (division_id),
    INDEX idx_type (category_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CASH BOOK (Buku Kas Besar)
-- ============================================
CREATE TABLE cash_book (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date DATE NOT NULL,
    transaction_time TIME NOT NULL,
    division_id INT NOT NULL,
    category_id INT NOT NULL,
    transaction_type ENUM('income', 'expense') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description TEXT,
    reference_no VARCHAR(50),
    payment_method ENUM('cash', 'bank_transfer', 'card', 'other') DEFAULT 'cash',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (division_id) REFERENCES divisions(id),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_date (transaction_date),
    INDEX idx_division (division_id),
    INDEX idx_category (category_id),
    INDEX idx_type (transaction_type),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CASH BALANCE (Saldo Kas)
-- ============================================
CREATE TABLE cash_balance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    balance_date DATE UNIQUE NOT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0,
    total_income DECIMAL(15,2) DEFAULT 0,
    total_expense DECIMAL(15,2) DEFAULT 0,
    closing_balance DECIMAL(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (balance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SETTINGS TABLE
-- ============================================
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value LONGTEXT,
    setting_type VARCHAR(50) DEFAULT 'text',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INITIAL DATA - USERS
-- ============================================
INSERT INTO users (username, password, full_name, email, role, phone) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@narayana.com', 'admin', '081234567890'),
('manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Manager Hotel', 'manager@narayana.com', 'manager', '081234567891');
-- Default password: password

-- ============================================
-- INITIAL DATA - DIVISIONS (Income)
-- ============================================
INSERT INTO divisions (division_code, division_name, division_type, description) VALUES
('HOTEL', 'Hotel', 'income', 'Pendapatan dari kamar hotel'),
('RESTO', 'Restaurant', 'both', 'Pendapatan & pengeluaran restaurant'),
('MOTOR', 'Rental Motor', 'income', 'Pendapatan rental motor'),
('TRIP', 'Trip & Tour', 'income', 'Pendapatan paket wisata'),
('LAUNDRY', 'Laundry', 'income', 'Pendapatan laundry'),
('SPA', 'Spa & Massage', 'income', 'Pendapatan spa dan massage');

-- ============================================
-- INITIAL DATA - DIVISIONS (Expense)
-- ============================================
INSERT INTO divisions (division_code, division_name, division_type, description) VALUES
('HK', 'Housekeeping', 'expense', 'Pengeluaran housekeeping'),
('BAR', 'Bar', 'expense', 'Pengeluaran bar'),
('MAINT', 'Maintenance', 'expense', 'Pengeluaran maintenance & perbaikan'),
('OPS', 'Operational', 'expense', 'Pengeluaran operasional'),
('RENO', 'Renovation', 'expense', 'Pengeluaran renovasi');

-- ============================================
-- INITIAL DATA - CATEGORIES (Income)
-- ============================================
INSERT INTO categories (division_id, category_name, category_type, description) VALUES
-- Hotel
(1, 'Kamar Standard', 'income', 'Pendapatan kamar standard'),
(1, 'Kamar Deluxe', 'income', 'Pendapatan kamar deluxe'),
(1, 'Kamar Suite', 'income', 'Pendapatan kamar suite'),
(1, 'Extra Bed', 'income', 'Pendapatan extra bed'),
-- Restaurant
(2, 'Food', 'income', 'Pendapatan makanan'),
(2, 'Beverage', 'income', 'Pendapatan minuman'),
(2, 'Catering', 'income', 'Pendapatan catering'),
-- Motor
(3, 'Motor Matic', 'income', 'Rental motor matic'),
(3, 'Motor Sport', 'income', 'Rental motor sport'),
-- Trip
(4, 'City Tour', 'income', 'Paket city tour'),
(4, 'Adventure Tour', 'income', 'Paket adventure'),
-- Laundry
(5, 'Laundry Kiloan', 'income', 'Laundry per kg'),
(5, 'Dry Clean', 'income', 'Dry cleaning'),
-- Spa
(6, 'Massage', 'income', 'Massage'),
(6, 'Facial', 'income', 'Facial treatment');

-- ============================================
-- INITIAL DATA - CATEGORIES (Expense)
-- ============================================
INSERT INTO categories (division_id, category_name, category_type, description) VALUES
-- Restaurant Expense
(2, 'Bahan Baku', 'expense', 'Pembelian bahan baku makanan'),
(2, 'Gas & BBM Dapur', 'expense', 'Gas dan BBM dapur'),
-- Housekeeping
(7, 'Cleaning Supplies', 'expense', 'Supplies kebersihan'),
(7, 'Linen & Towel', 'expense', 'Linen dan handuk'),
(7, 'Toiletries', 'expense', 'Perlengkapan kamar mandi'),
-- Bar
(8, 'Minuman Beralkohol', 'expense', 'Pembelian minuman beralkohol'),
(8, 'Soft Drink', 'expense', 'Pembelian soft drink'),
-- Maintenance
(9, 'Spare Parts', 'expense', 'Suku cadang'),
(9, 'Tools & Equipment', 'expense', 'Peralatan maintenance'),
(9, 'AC Service', 'expense', 'Service AC'),
(9, 'Plumbing', 'expense', 'Perbaikan plumbing'),
-- Operational
(10, 'Gaji Karyawan', 'expense', 'Gaji staff'),
(10, 'Listrik', 'expense', 'Pembayaran listrik'),
(10, 'Air', 'expense', 'Pembayaran air'),
(10, 'Internet', 'expense', 'Pembayaran internet'),
(10, 'Telepon', 'expense', 'Pembayaran telepon'),
(10, 'Transport', 'expense', 'Biaya transport'),
(10, 'ATK', 'expense', 'Alat tulis kantor'),
-- Renovation
(11, 'Material Bangunan', 'expense', 'Material renovasi'),
(11, 'Upah Tukang', 'expense', 'Upah tukang');

-- ============================================
-- VIEWS FOR REPORTING
-- ============================================

-- View: Summary per Division
CREATE VIEW view_division_summary AS
SELECT 
    d.id,
    d.division_code,
    d.division_name,
    COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE 0 END), 0) as total_income,
    COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as total_expense,
    COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE 0 END), 0) - 
    COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as net_profit
FROM divisions d
LEFT JOIN cash_book cb ON d.id = cb.division_id
GROUP BY d.id, d.division_code, d.division_name;

-- View: Daily Summary
CREATE VIEW view_daily_summary AS
SELECT 
    cb.transaction_date,
    d.division_name,
    c.category_name,
    cb.transaction_type,
    SUM(cb.amount) as total_amount,
    COUNT(*) as transaction_count
FROM cash_book cb
JOIN divisions d ON cb.division_id = d.id
JOIN categories c ON cb.category_id = c.id
GROUP BY cb.transaction_date, d.division_name, c.category_name, cb.transaction_type
ORDER BY cb.transaction_date DESC;

-- ============================================
-- END OF DATABASE STRUCTURE
-- ============================================
