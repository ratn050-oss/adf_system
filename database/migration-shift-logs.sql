-- Create Shift Logs Table for End Shift Feature
CREATE TABLE IF NOT EXISTS shift_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_action (action)
);

-- Add WhatsApp number column to business_settings if not exists
ALTER TABLE business_settings ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(20);

-- Add phone column to users table if not exists
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20);

-- Create PO Images table if not exists
CREATE TABLE IF NOT EXISTS po_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    INDEX idx_po_id (po_id)
);
