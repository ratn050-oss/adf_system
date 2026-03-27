-- ========================================
-- BREAKFAST MENU SYSTEM
-- Menu untuk breakfast order management
-- ========================================

-- Table untuk menyimpan menu breakfast
CREATE TABLE IF NOT EXISTS breakfast_menus (
    id INT PRIMARY KEY AUTO_INCREMENT,
    menu_name VARCHAR(100) NOT NULL,
    description TEXT,
    category ENUM('western', 'indonesian', 'asian', 'drinks', 'beverages', 'extras') DEFAULT 'western',
    price DECIMAL(10,2) DEFAULT 0.00,
    is_free BOOLEAN DEFAULT TRUE COMMENT 'TRUE = Free breakfast, FALSE = Extra/Paid',
    is_available BOOLEAN DEFAULT TRUE,
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_available (is_available),
    INDEX idx_free (is_free)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Update breakfast_log table untuk include menu selection
ALTER TABLE breakfast_log 
ADD COLUMN IF NOT EXISTS menu_id INT NULL AFTER guest_id,
ADD COLUMN IF NOT EXISTS quantity INT DEFAULT 1 AFTER menu_id,
ADD FOREIGN KEY (menu_id) REFERENCES breakfast_menus(id) ON DELETE SET NULL;

-- Insert default breakfast menus
-- is_free: TRUE = Free breakfast included, FALSE = Extra/Paid breakfast
INSERT INTO breakfast_menus (menu_name, description, category, price, is_free, is_available) VALUES
-- Western (Free)
('American Breakfast', 'Eggs, bacon, sausage, toast, hash browns', 'western', 0.00, TRUE, TRUE),
('Continental Breakfast', 'Croissant, jam, butter, fruit', 'western', 0.00, TRUE, TRUE),
('Pancakes', 'Fluffy pancakes with maple syrup', 'western', 0.00, TRUE, TRUE),
('French Toast', 'Classic french toast with fruits', 'western', 0.00, TRUE, TRUE),

-- Indonesian (Free)
('Nasi Goreng', 'Indonesian fried rice with egg', 'indonesian', 0.00, TRUE, TRUE),
('Bubur Ayam', 'Chicken porridge with condiments', 'indonesian', 0.00, TRUE, TRUE),
('Nasi Uduk', 'Coconut rice with side dishes', 'indonesian', 0.00, TRUE, TRUE),
('Mie Goreng', 'Indonesian fried noodles', 'indonesian', 0.00, TRUE, TRUE),
('Lontong Sayur', 'Rice cake with vegetable curry', 'indonesian', 0.00, TRUE, TRUE),

-- Asian (Free)
('Dim Sum', 'Assorted steamed dumplings', 'asian', 0.00, TRUE, TRUE),
('Congee', 'Rice porridge with toppings', 'asian', 0.00, TRUE, TRUE),

-- Drinks (Free)
('Coffee', 'Hot coffee', 'drinks', 0.00, TRUE, TRUE),
('Tea', 'Hot tea', 'drinks', 0.00, TRUE, TRUE),
('Orange Juice', 'Fresh orange juice', 'drinks', 0.00, TRUE, TRUE),
('Milk', 'Fresh milk', 'drinks', 0.00, TRUE, TRUE),
('Mineral Water', 'Bottled water', 'drinks', 0.00, TRUE, TRUE),

-- Extra Breakfast (Paid/Berbayar)
('Extra Eggs Benedict', 'Premium eggs with hollandaise', 'extras', 50000.00, FALSE, TRUE),
('Extra Ramen Bowl', 'Japanese ramen', 'extras', 45000.00, FALSE, TRUE),
('Extra Toast Set', 'Additional toast with jam', 'extras', 15000.00, FALSE, TRUE),
('Extra Egg', 'Additional egg', 'extras', 10000.00, FALSE, TRUE),
('Fresh Fruit Platter', 'Seasonal fruits', 'extras', 35000.00, FALSE, TRUE),
('Avocado Toast', 'Premium avocado on toast', 'extras', 40000.00, FALSE, TRUE),
('Smoothie Bowl', 'Fruit smoothie bowl', 'extras', 45000.00, FALSE, TRUE);
