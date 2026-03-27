-- ============================================
-- MULTI-BUILDING SUPPORT WITH ROTATION
-- Semi-Circular Floor Plan System
-- ============================================

-- Buildings Table
CREATE TABLE IF NOT EXISTS buildings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    building_code VARCHAR(20) NOT NULL UNIQUE,
    building_name VARCHAR(100) NOT NULL,
    rotation_angle INT NOT NULL DEFAULT 0 COMMENT 'Sudut rotasi dalam derajat (0, 90, 120, 180, 240, dll)',
    position_x INT DEFAULT 0 COMMENT 'Koordinat X di master map',
    position_y INT DEFAULT 0 COMMENT 'Koordinat Y di master map',
    floor_count INT NOT NULL DEFAULT 1,
    total_rooms INT NOT NULL DEFAULT 4,
    is_active TINYINT(1) DEFAULT 1,
    color_theme VARCHAR(7) DEFAULT '#6366f1',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample Buildings Data
INSERT INTO buildings (building_code, building_name, rotation_angle, position_x, position_y, color_theme) VALUES
('BLD-A', 'Building A (North)', 0, 0, 0, '#6366f1'),
('BLD-B', 'Building B (Southeast)', 120, 300, 200, '#8b5cf6'),
('BLD-C', 'Building C (Southwest)', 240, -300, 200, '#ec4899');

-- Update Rooms table to include building_id
ALTER TABLE rooms ADD COLUMN building_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE rooms ADD COLUMN arc_position ENUM('left_top', 'left_bottom', 'right_top', 'right_bottom') DEFAULT 'left_top' COMMENT 'Posisi di arc setengah lingkaran';
ALTER TABLE rooms ADD CONSTRAINT fk_room_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE;

-- Update existing rooms to Building A
UPDATE rooms SET building_id = 1 WHERE room_number LIKE '1%' OR room_number LIKE '2%' OR room_number LIKE '3%';

-- Add rooms for Building B
INSERT INTO rooms (building_id, room_number, room_type_id, floor_number, arc_position) VALUES
(2, 'B-101', 1, 1, 'left_top'),
(2, 'B-102', 1, 1, 'left_bottom'),
(2, 'B-201', 2, 1, 'right_top'),
(2, 'B-202', 3, 1, 'right_bottom');

-- Add rooms for Building C
INSERT INTO rooms (building_id, room_number, room_type_id, floor_number, arc_position) VALUES
(3, 'C-101', 2, 1, 'left_top'),
(3, 'C-102', 2, 1, 'left_bottom'),
(3, 'C-201', 3, 1, 'right_top'),
(3, 'C-202', 4, 1, 'right_bottom');
