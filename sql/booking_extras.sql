-- ============================================
-- BOOKING EXTRAS (Extra Bed, Laundry, dll)
-- Tambahan item/service per booking
-- ============================================

CREATE TABLE IF NOT EXISTS booking_extras (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    INDEX idx_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
