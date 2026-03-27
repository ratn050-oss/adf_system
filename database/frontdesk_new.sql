-- ============================================
-- HOTEL FRONTDESK SYSTEM - Modern Design
-- Room Grid + Booking Calendar (CloudBed Style)
-- ============================================

-- Room Types (Tipe Kamar)
CREATE TABLE IF NOT EXISTS room_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type_name VARCHAR(100) NOT NULL,
    description TEXT,
    base_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    max_occupancy INT NOT NULL DEFAULT 2,
    amenities TEXT,
    color_code VARCHAR(7) DEFAULT '#6366f1',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rooms (Kamar)
CREATE TABLE IF NOT EXISTS rooms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    room_number VARCHAR(20) NOT NULL UNIQUE,
    room_type_id INT NOT NULL,
    floor_number INT NOT NULL DEFAULT 1,
    status ENUM('available', 'occupied', 'cleaning', 'maintenance', 'blocked') DEFAULT 'available',
    notes TEXT,
    position_x INT DEFAULT 0,
    position_y INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_floor (floor_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guests (Tamu)
CREATE TABLE IF NOT EXISTS guests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    guest_name VARCHAR(200) NOT NULL,
    id_card_type ENUM('ktp', 'passport', 'sim') DEFAULT 'ktp',
    id_card_number VARCHAR(50) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    nationality VARCHAR(50) DEFAULT 'Indonesia',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_id_card (id_card_number),
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bookings (Reservasi/Booking)
CREATE TABLE IF NOT EXISTS bookings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_code VARCHAR(20) NOT NULL UNIQUE,
    guest_id INT NOT NULL,
    room_id INT NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    actual_check_in DATETIME,
    actual_check_out DATETIME,
    adults INT NOT NULL DEFAULT 1,
    children INT NOT NULL DEFAULT 0,
    room_price DECIMAL(12,2) NOT NULL,
    total_nights INT NOT NULL,
    total_price DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) DEFAULT 0,
    final_price DECIMAL(12,2) NOT NULL,
    status ENUM('pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled') DEFAULT 'pending',
    payment_status ENUM('unpaid', 'partial', 'paid') DEFAULT 'unpaid',
    paid_amount DECIMAL(12,2) DEFAULT 0,
    booking_source ENUM('walk_in', 'phone', 'online', 'ota') DEFAULT 'walk_in',
    special_request TEXT,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE RESTRICT,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_dates (check_in_date, check_out_date),
    INDEX idx_status (status),
    INDEX idx_booking_code (booking_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payments (Pembayaran)
CREATE TABLE IF NOT EXISTS booking_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'transfer', 'qris', 'ota') DEFAULT 'cash',
    reference_number VARCHAR(100),
    notes TEXT,
    processed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_booking (booking_id),
    INDEX idx_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample Data - Room Types
INSERT INTO room_types (type_name, description, base_price, max_occupancy, color_code) VALUES
('Standard', 'Kamar standard dengan fasilitas lengkap', 350000, 2, '#6366f1'),
('Deluxe', 'Kamar deluxe dengan view bagus', 500000, 2, '#8b5cf6'),
('Suite', 'Kamar suite dengan ruang tamu terpisah', 750000, 4, '#ec4899'),
('Family', 'Kamar keluarga dengan 2 bed', 650000, 4, '#f59e0b');

-- Sample Data - Rooms
INSERT INTO rooms (room_number, room_type_id, floor_number, position_x, position_y) VALUES
('101', 1, 1, 0, 0), ('102', 1, 1, 1, 0), ('103', 1, 1, 2, 0), ('104', 2, 1, 3, 0),
('105', 2, 1, 4, 0), ('106', 1, 1, 5, 0), ('107', 1, 1, 0, 1), ('108', 3, 1, 1, 1),
('201', 1, 2, 0, 0), ('202', 1, 2, 1, 0), ('203', 2, 2, 2, 0), ('204', 2, 2, 3, 0),
('205', 3, 2, 4, 0), ('206', 1, 2, 5, 0), ('207', 1, 2, 0, 1), ('208', 4, 2, 1, 1),
('301', 2, 3, 0, 0), ('302', 2, 3, 1, 0), ('303', 3, 3, 2, 0), ('304', 4, 3, 3, 0);
