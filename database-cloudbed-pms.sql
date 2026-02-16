-- Database setup for Cloudbed PMS Integration
-- Schema untuk integrasi Property Management System dengan Cloudbed

-- Table for Cloudbed API usage logging
CREATE TABLE IF NOT EXISTS cloudbed_api_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL,
    method ENUM('GET', 'POST', 'PUT', 'DELETE') NOT NULL,
    http_code INT,
    request_data JSON,
    response_data JSON,
    execution_time DECIMAL(8,3) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_endpoint (endpoint),
    INDEX idx_created_at (created_at),
    INDEX idx_http_code (http_code)
);

-- Table for sync status tracking
CREATE TABLE IF NOT EXISTS cloudbed_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_type ENUM('reservations', 'guests', 'rates', 'availability') NOT NULL,
    sync_direction ENUM('from_cloudbed', 'to_cloudbed') NOT NULL,
    start_date DATE,
    end_date DATE,
    records_processed INT DEFAULT 0,
    records_successful INT DEFAULT 0,
    records_failed INT DEFAULT 0,
    error_details JSON,
    sync_duration DECIMAL(8,3),
    status ENUM('running', 'completed', 'failed', 'cancelled') DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_sync_type (sync_type),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
);

-- Table for room type mapping between ADF and Cloudbed
CREATE TABLE IF NOT EXISTS cloudbed_room_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    adf_room_type VARCHAR(100) NOT NULL,
    cloudbed_room_type_id INT NOT NULL,
    cloudbed_room_type_name VARCHAR(200),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mapping (adf_room_type, cloudbed_room_type_id)
);

-- Add Cloudbed-related columns to existing tables
ALTER TABLE reservasi 
ADD COLUMN IF NOT EXISTS cloudbed_reservation_id VARCHAR(100) UNIQUE,
ADD COLUMN IF NOT EXISTS sync_status ENUM('pending', 'synced', 'error') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS sync_error TEXT,
ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP NULL,
ADD COLUMN IF NOT EXISTS cloudbed_updated_at TIMESTAMP NULL,
ADD INDEX IF NOT EXISTS idx_cloudbed_reservation (cloudbed_reservation_id),
ADD INDEX IF NOT EXISTS idx_sync_status (sync_status);

ALTER TABLE guest
ADD COLUMN IF NOT EXISTS cloudbed_guest_id VARCHAR(100),
ADD COLUMN IF NOT EXISTS sync_status ENUM('pending', 'synced', 'error') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP NULL,
ADD INDEX IF NOT EXISTS idx_cloudbed_guest (cloudbed_guest_id);

-- Insert Cloudbed PMS integration settings
INSERT IGNORE INTO settings (setting_key, setting_value, setting_description) VALUES
('cloudbed_client_id', '', 'Cloudbed OAuth Client ID'),
('cloudbed_client_secret', '', 'Cloudbed OAuth Client Secret'),
('cloudbed_property_id', '', 'Cloudbed Property ID'),
('cloudbed_access_token', '', 'Cloudbed Access Token (auto-generated)'),
('cloudbed_refresh_token', '', 'Cloudbed Refresh Token'),
('cloudbed_active', '0', 'Enable/disable Cloudbed integration'),
('cloudbed_auto_sync', '0', 'Enable automatic synchronization'),
('cloudbed_sync_interval', '30', 'Sync interval in minutes'),
('cloudbed_api_version', 'v1.2', 'Cloudbed API version to use'),
('cloudbed_webhook_url', '', 'Webhook URL for real-time updates');

-- Table for storing webhook events from Cloudbed
CREATE TABLE IF NOT EXISTS cloudbed_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    property_id VARCHAR(50),
    reservation_id VARCHAR(100),
    guest_id VARCHAR(100),
    event_data JSON,
    processed BOOLEAN DEFAULT FALSE,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_processed (processed),
    INDEX idx_reservation_id (reservation_id)
);

-- Table for rate synchronization
CREATE TABLE IF NOT EXISTS cloudbed_rate_sync (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_type_id INT,
    cloudbed_room_type_id INT,
    sync_date DATE,
    adf_rate DECIMAL(12,2),
    cloudbed_rate DECIMAL(12,2),
    sync_direction ENUM('from_cloudbed', 'to_cloudbed', 'bidirectional'),
    sync_status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    sync_result JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sync_date (sync_date),
    INDEX idx_sync_status (sync_status),
    INDEX idx_room_type (room_type_id)
);

-- Insert default room type mappings (customize based on your property)
INSERT IGNORE INTO cloudbed_room_mapping (adf_room_type, cloudbed_room_type_id, cloudbed_room_type_name) VALUES
('Standard', 1, 'Standard Room'),
('Deluxe', 2, 'Deluxe Room'),
('Suite', 3, 'Suite'),
('Family', 4, 'Family Room'),
('Superior', 5, 'Superior Room'),
('Executive', 6, 'Executive Room');

-- Create view for sync dashboard
CREATE OR REPLACE VIEW cloudbed_sync_summary AS
SELECT 
    -- Reservation sync stats
    (SELECT COUNT(*) FROM reservasi WHERE cloudbed_reservation_id IS NOT NULL) as total_synced_reservations,
    (SELECT COUNT(*) FROM reservasi WHERE sync_status = 'pending') as pending_reservation_sync,
    (SELECT COUNT(*) FROM reservasi WHERE sync_status = 'error') as failed_reservation_sync,
    
    -- Guest sync stats
    (SELECT COUNT(*) FROM guest WHERE cloudbed_guest_id IS NOT NULL) as total_synced_guests,
    (SELECT COUNT(*) FROM guest WHERE sync_status = 'pending') as pending_guest_sync,
    
    -- Last sync info
    (SELECT MAX(completed_at) FROM cloudbed_sync_log WHERE status = 'completed') as last_successful_sync,
    (SELECT COUNT(*) FROM cloudbed_sync_log WHERE status = 'failed' AND DATE(started_at) = CURDATE()) as failed_syncs_today,
    
    -- API usage stats
    (SELECT COUNT(*) FROM cloudbed_api_log WHERE DATE(created_at) = CURDATE()) as api_calls_today,
    (SELECT COUNT(*) FROM cloudbed_api_log WHERE http_code >= 400 AND DATE(created_at) = CURDATE()) as api_errors_today;

-- Create stored procedure for cleanup old logs
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS CleanupCloudbedLogs()
BEGIN
    -- Keep only last 30 days of API logs
    DELETE FROM cloudbed_api_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Keep only last 90 days of sync logs
    DELETE FROM cloudbed_sync_log WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- Keep only last 7 days of webhook events
    DELETE FROM cloudbed_webhook_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND processed = TRUE;
END$$
DELIMITER ;

-- Create function to get sync health score
DELIMITER $$
CREATE FUNCTION IF NOT EXISTS GetCloudbedSyncHealth() RETURNS INT
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE health_score INT DEFAULT 100;
    DECLARE api_errors INT DEFAULT 0;
    DECLARE failed_syncs INT DEFAULT 0;
    DECLARE pending_syncs INT DEFAULT 0;
    
    -- Check API errors today
    SELECT COUNT(*) INTO api_errors 
    FROM cloudbed_api_log 
    WHERE http_code >= 400 AND DATE(created_at) = CURDATE();
    
    -- Check failed syncs in last 24 hours
    SELECT COUNT(*) INTO failed_syncs
    FROM cloudbed_sync_log 
    WHERE status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
    
    -- Check pending syncs
    SELECT COUNT(*) INTO pending_syncs
    FROM reservasi 
    WHERE sync_status = 'pending' OR sync_status = 'error';
    
    -- Calculate health score
    SET health_score = health_score - (api_errors * 10) - (failed_syncs * 15) - (pending_syncs * 5);
    
    -- Ensure score is between 0 and 100
    IF health_score < 0 THEN SET health_score = 0; END IF;
    IF health_score > 100 THEN SET health_score = 100; END IF;
    
    RETURN health_score;
END$$
DELIMITER ;

-- Create indexes for better performance
ALTER TABLE cloudbed_api_log ADD INDEX IF NOT EXISTS idx_date_endpoint (created_at, endpoint);
ALTER TABLE cloudbed_sync_log ADD INDEX IF NOT EXISTS idx_date_type (started_at, sync_type);
ALTER TABLE cloudbed_webhook_events ADD INDEX IF NOT EXISTS idx_date_processed (created_at, processed);

-- Insert initial sync job (can be used by cron)
INSERT IGNORE INTO cloudbed_sync_log (sync_type, sync_direction, status, records_processed) 
VALUES ('reservations', 'from_cloudbed', 'completed', 0);