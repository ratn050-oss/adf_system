-- ============================================
-- USER_PERMISSIONS TABLE
-- For: adfb2574_narayana_db
-- ============================================

SET FOREIGN_KEY_CHECKS=0;

-- DROP existing table if you want clean state
-- DROP TABLE IF EXISTS user_permissions;

CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_permission (user_id, permission),
    INDEX idx_user_id (user_id),
    INDEX idx_permission (permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clear existing data for admin user to avoid duplicates
DELETE FROM user_permissions WHERE user_id = 1;

SET FOREIGN_KEY_CHECKS=1;

-- ============================================
-- INITIAL DATA - ADMIN PERMISSIONS
-- ============================================
INSERT INTO user_permissions (user_id, permission) VALUES
-- Admin user (id=1) gets all permissions
(1, 'dashboard'),
(1, 'cashbook'),
(1, 'divisions'),
(1, 'frontdesk'),
(1, 'sales_invoice'),
(1, 'procurement'),
(1, 'users'),
(1, 'reports'),
(1, 'settings'),
(1, 'investor'),
(1, 'project');

-- ============================================
-- END OF USER_PERMISSIONS SETUP
-- ============================================
