-- ============================================
-- ADD CHECK-IN/CHECK-OUT TRACKING COLUMNS
-- Menambahkan kolom untuk waktu aktual check-in dan check-out
-- ============================================

-- Add columns to bookings table
ALTER TABLE `bookings` 
ADD COLUMN `actual_checkin_time` DATETIME NULL DEFAULT NULL COMMENT 'Waktu aktual check-in' AFTER `check_out_date`,
ADD COLUMN `actual_checkout_time` DATETIME NULL DEFAULT NULL COMMENT 'Waktu aktual check-out' AFTER `actual_checkin_time`,
ADD COLUMN `checked_in_by` INT NULL DEFAULT NULL COMMENT 'User ID yang melakukan check-in' AFTER `actual_checkout_time`,
ADD COLUMN `checked_out_by` INT NULL DEFAULT NULL COMMENT 'User ID yang melakukan check-out' AFTER `checked_in_by`;

-- Add index for better query performance
ALTER TABLE `bookings` 
ADD INDEX `idx_status` (`status`),
ADD INDEX `idx_checkin_time` (`actual_checkin_time`),
ADD INDEX `idx_checkout_time` (`actual_checkout_time`);

-- Add column to rooms table for current guest tracking
ALTER TABLE `rooms`
ADD COLUMN `current_guest_id` INT NULL DEFAULT NULL COMMENT 'ID guest yang sedang menginap' AFTER `status`,
ADD FOREIGN KEY (`current_guest_id`) REFERENCES `guests`(`id`) ON DELETE SET NULL;

-- Create activity logs table if not exists
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update existing checked_in bookings (if any) to set actual_checkin_time
UPDATE `bookings` 
SET `actual_checkin_time` = `check_in_date`
WHERE `status` = 'checked_in' 
AND `actual_checkin_time` IS NULL;

SELECT 'Migration completed successfully!' as status;
