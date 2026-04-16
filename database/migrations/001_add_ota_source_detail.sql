-- ============================================
-- Migration: Add ota_source_detail column
-- Purpose: Store specific OTA platform names (agoda, booking, traveloka, etc)
-- When booking_source = 'ota', this stores the actual platform
-- ============================================

ALTER TABLE bookings 
ADD COLUMN IF NOT EXISTS ota_source_detail VARCHAR(50) DEFAULT NULL 
COMMENT 'OTA platform name (agoda, booking, traveloka, airbnb, expedia, pegipegi, etc)' 
AFTER booking_source;

-- Verify the column was added
-- SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='bookings' AND COLUMN_NAME='ota_source_detail';
