-- ============================================
-- CQC Solar Panel Projects Management
-- Database Schema untuk CQC
-- ============================================

-- ============================================
-- CQC PROJECTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS cqc_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(200) NOT NULL,
    project_code VARCHAR(50) UNIQUE NOT NULL,
    description LONGTEXT,
    location VARCHAR(300),
    client_name VARCHAR(150),
    client_phone VARCHAR(20),
    client_email VARCHAR(100),
    
    -- Solar Panel Specifications
    solar_capacity_kwp DECIMAL(8,2) COMMENT 'Kapasitas dalam KWp',
    panel_count INT,
    panel_type VARCHAR(100),
    inverter_type VARCHAR(100),
    
    -- Budget & Progress
    budget_idr DECIMAL(15,2),
    spent_idr DECIMAL(15,2) DEFAULT 0,
    
    -- Status project
    status ENUM('planning', 'procurement', 'installation', 'testing', 'completed', 'on_hold') DEFAULT 'planning',
    progress_percentage INT DEFAULT 0,
    
    -- Dates
    start_date DATE,
    end_date DATE,
    estimated_completion DATE,
    actual_completion DATE,
    
    -- Team Assignment
    project_manager_id INT,
    lead_installer_id INT,
    
    -- Metadata
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_code (project_code),
    INDEX idx_start_date (start_date),
    INDEX idx_progress (progress_percentage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CQC PROJECT EXPENSE CATEGORIES
-- ============================================
CREATE TABLE IF NOT EXISTS cqc_expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    category_code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CQC PROJECT EXPENSES LEDGER
-- ============================================
CREATE TABLE IF NOT EXISTS cqc_project_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    expense_category_id INT NOT NULL,
    
    expense_date DATE NOT NULL,
    expense_time TIME,
    
    amount_idr DECIMAL(15,2) NOT NULL,
    description TEXT,
    reference_no VARCHAR(50),
    receipt_file VARCHAR(255),
    
    payment_method ENUM('cash', 'bank_transfer', 'check', 'credit') DEFAULT 'cash',
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'paid') DEFAULT 'draft',
    
    approved_by INT,
    approved_at TIMESTAMP NULL,
    
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES cqc_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (expense_category_id) REFERENCES cqc_expense_categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_project (project_id),
    INDEX idx_category (expense_category_id),
    INDEX idx_date (expense_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CQC PROJECT BALANCE SUMMARY
-- ============================================
CREATE TABLE IF NOT EXISTS cqc_project_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL UNIQUE,
    total_expenses_idr DECIMAL(15,2) DEFAULT 0,
    remaining_budget_idr DECIMAL(15,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES cqc_projects(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERT DEFAULT EXPENSE CATEGORIES FOR CQC
-- ============================================
INSERT INTO cqc_expense_categories (category_name, category_code, description, icon, sort_order) VALUES
('Panel Surya', 'PANEL', 'Pembelian panel surya', '☀️', 1),
('Inverter & Controller', 'INVERTER', 'Peralatan inverter dan controller', '⚡', 2),
('Kabel & Konektor', 'KABEL', 'Material kabel dan konektor', '🔌', 3),
('Instalasi & Labor', 'LABOR', 'Biaya tenaga kerja instalasi', '👷', 4),
('Struktur & Mounting', 'STRUKTUR', 'Rangka dan sistem mounting', '🏗️', 5),
('Perizinan & Desain', 'IZIN', 'Biaya perizinan dan design', '📋', 6),
('Testing & Commissioning', 'TESTING', 'Biaya testing dan commissioning', '🔧', 7),
('Transportasi & Logistik', 'LOGISTIK', 'Biaya pengiriman dan logistik', '🚚', 8),
('Konsultasi & Training', 'KONSULTASI', 'Biaya konsultasi dan pelatihan', '📚', 9),
('Lainnya', 'OTHER', 'Biaya lainnya yang tidak termasuk kategori', '📌', 10)
ON DUPLICATE KEY UPDATE category_code = VALUES(category_code);
