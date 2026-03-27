-- Owner Monitoring System with Multi-Branch Support
-- Database schema untuk sistem monitoring owner dengan multi-cabang

-- Tabel untuk menyimpan daftar cabang/lokasi
CREATE TABLE IF NOT EXISTS branches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    branch_code VARCHAR(20) UNIQUE NOT NULL,
    branch_name VARCHAR(100) NOT NULL,
    address TEXT,
    city VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_code (branch_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alter users table untuk menambah role 'owner' jika belum ada
-- Note: Jalankan script ini secara manual atau lewat installer

-- Tambah role owner di enum role users table (manual alter)
-- ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'staff', 'owner') DEFAULT 'staff';

-- Tabel untuk mapping owner ke cabang yang bisa diakses
CREATE TABLE IF NOT EXISTS owner_branch_access (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    branch_id INT NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_branch (user_id, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alter existing tables untuk menambah branch_id
-- ALTER TABLE cash_book ADD COLUMN branch_id INT DEFAULT 1 AFTER id;
-- ALTER TABLE cash_book ADD FOREIGN KEY (branch_id) REFERENCES branches(id);

-- ALTER TABLE frontdesk_reservations ADD COLUMN branch_id INT DEFAULT 1 AFTER id;
-- ALTER TABLE frontdesk_reservations ADD FOREIGN KEY (branch_id) REFERENCES branches(id);

-- ALTER TABLE frontdesk_rooms ADD COLUMN branch_id INT DEFAULT 1 AFTER id;
-- ALTER TABLE frontdesk_rooms ADD FOREIGN KEY (branch_id) REFERENCES branches(id);

-- Insert default branch (cabang utama)
INSERT INTO branches (branch_code, branch_name, address, city, phone, email, is_active)
VALUES 
('HQ', 'Kantor Pusat', 'Alamat Kantor Pusat', 'Jakarta', '021-12345678', 'hq@narayana.com', 1),
('CBG001', 'Cabang 1 - Bandung', 'Jl. Merdeka No. 1', 'Bandung', '022-12345678', 'bandung@narayana.com', 1),
('CBG002', 'Cabang 2 - Surabaya', 'Jl. Pahlawan No. 2', 'Surabaya', '031-12345678', 'surabaya@narayana.com', 1);

-- View untuk ringkasan monitoring per cabang
CREATE OR REPLACE VIEW v_branch_monitoring AS
SELECT 
    b.id as branch_id,
    b.branch_code,
    b.branch_name,
    b.city,
    
    -- Today's stats
    COALESCE(SUM(CASE 
        WHEN cb.transaction_type = 'income' 
        AND DATE(cb.transaction_date) = CURDATE() 
        THEN cb.amount ELSE 0 
    END), 0) as today_income,
    
    COALESCE(SUM(CASE 
        WHEN cb.transaction_type = 'expense' 
        AND DATE(cb.transaction_date) = CURDATE() 
        THEN cb.amount ELSE 0 
    END), 0) as today_expense,
    
    -- This month's stats
    COALESCE(SUM(CASE 
        WHEN cb.transaction_type = 'income' 
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        THEN cb.amount ELSE 0 
    END), 0) as month_income,
    
    COALESCE(SUM(CASE 
        WHEN cb.transaction_type = 'expense' 
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        THEN cb.amount ELSE 0 
    END), 0) as month_expense,
    
    -- Transaction count today
    COUNT(CASE 
        WHEN DATE(cb.transaction_date) = CURDATE() 
        THEN cb.id ELSE NULL 
    END) as today_transactions,
    
    b.is_active
FROM branches b
LEFT JOIN cash_book cb ON b.id = cb.branch_id
GROUP BY b.id, b.branch_code, b.branch_name, b.city, b.is_active;

-- Permissions untuk owner
-- Owner hanya bisa melihat (read-only) dashboard, reports, dan monitoring
-- Owner tidak bisa menambah/edit/hapus transaksi
