-- Database setup for AI and Cloudbed integrations

-- Table for storing review analysis data
CREATE TABLE IF NOT EXISTS review_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_text TEXT NOT NULL,
    rating INT NOT NULL,
    platform VARCHAR(50),
    sentiment ENUM('positive', 'negative', 'neutral'),
    analysis_data JSON,
    suggested_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for guest sync data from Cloudbed
CREATE TABLE IF NOT EXISTS guest_sync (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cloudbed_guest_id VARCHAR(100) UNIQUE,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    email VARCHAR(255),
    phone VARCHAR(50),
    address TEXT,
    city VARCHAR(100),
    country VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Table for daily AI-generated reports
CREATE TABLE IF NOT EXISTS daily_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_date DATE UNIQUE,
    occupancy_rate DECIMAL(5,2),
    total_revenue DECIMAL(12,2),
    guest_count INT,
    ai_summary TEXT,
    report_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add cloudbed_reservation_id to existing reservasi table
ALTER TABLE reservasi 
ADD COLUMN IF NOT EXISTS cloudbed_reservation_id VARCHAR(100),
ADD INDEX idx_cloudbed_reservation (cloudbed_reservation_id);

-- Add preferensi (preferences) to guest table if not exists
ALTER TABLE guest 
ADD COLUMN IF NOT EXISTS preferensi TEXT COMMENT 'Guest preferences comma separated',
ADD COLUMN IF NOT EXISTS usia INT COMMENT 'Guest age',
ADD COLUMN IF NOT EXISTS negara VARCHAR(100) COMMENT 'Guest country';

-- Insert default API integration settings
INSERT IGNORE INTO settings (setting_key, setting_value, setting_description) VALUES
('openai_api_key', '', 'OpenAI API Key for AI features'),
('openai_model', 'gpt-3.5-turbo', 'OpenAI Model to use (gpt-3.5-turbo or gpt-4)'),
('openai_active', '0', 'Enable/disable OpenAI integration'),
('cloudbed_client_id', '', 'Cloudbed OAuth Client ID'),
('cloudbed_client_secret', '', 'Cloudbed OAuth Client Secret'),
('cloudbed_property_id', '', 'Cloudbed Property ID'),
('cloudbed_access_token', '', 'Cloudbed Access Token (auto-generated)'),
('cloudbed_active', '0', 'Enable/disable Cloudbed integration'),
('ai_auto_responses', '0', 'Enable automated AI responses for guest inquiries'),
('ai_daily_reports', '0', 'Enable daily AI report generation'),
('cloudbed_auto_sync', '0', 'Enable automatic Cloudbed synchronization');

-- Table for tracking API usage and costs
CREATE TABLE IF NOT EXISTS api_usage_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_provider ENUM('openai', 'cloudbed') NOT NULL,
    endpoint VARCHAR(255),
    tokens_used INT DEFAULT 0,
    estimated_cost DECIMAL(10,4) DEFAULT 0,
    request_data JSON,
    response_status INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for storing AI-generated content cache
CREATE TABLE IF NOT EXISTS ai_content_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) UNIQUE,
    content TEXT,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cache_key (cache_key),
    INDEX idx_expires (expires_at)
);

-- Table for guest communication history
CREATE TABLE IF NOT EXISTS guest_communications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guest_id INT,
    reservation_id INT,
    message_type ENUM('inquiry', 'response', 'welcome', 'followup'),
    guest_message TEXT,
    ai_response TEXT,
    human_edited BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guest_id) REFERENCES guest(guest_id) ON DELETE CASCADE,
    FOREIGN KEY (reservation_id) REFERENCES reservasi(reservasi_id) ON DELETE CASCADE
);

-- Table for rate optimization recommendations
CREATE TABLE IF NOT EXISTS rate_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_type VARCHAR(100),
    target_date DATE,
    current_rate DECIMAL(10,2),
    recommended_rate DECIMAL(10,2),
    reasoning TEXT,
    confidence_score DECIMAL(3,2),
    implemented BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for better performance
ALTER TABLE review_analysis ADD INDEX idx_sentiment (sentiment);
ALTER TABLE review_analysis ADD INDEX idx_created (created_at);
ALTER TABLE daily_reports ADD INDEX idx_report_date (report_date);
ALTER TABLE guest_sync ADD INDEX idx_email (email);
ALTER TABLE api_usage_log ADD INDEX idx_service (service_provider);
ALTER TABLE api_usage_log ADD INDEX idx_created (created_at);

-- Create view for dashboard summary
CREATE OR REPLACE VIEW ai_dashboard_summary AS
SELECT 
    (SELECT COUNT(*) FROM review_analysis WHERE created_at >= CURDATE()) as reviews_analyzed_today,
    (SELECT COUNT(*) FROM daily_reports WHERE report_date >= CURDATE() - INTERVAL 7 DAY) as reports_generated_week,
    (SELECT SUM(tokens_used) FROM api_usage_log WHERE service_provider = 'openai' AND created_at >= CURDATE()) as openai_tokens_today,
    (SELECT COUNT(*) FROM guest_sync WHERE updated_at >= CURDATE() - INTERVAL 1 DAY) as guests_synced_yesterday,
    (SELECT COUNT(*) FROM rate_recommendations WHERE created_at >= CURDATE() - INTERVAL 7 DAY) as rate_recommendations_week;