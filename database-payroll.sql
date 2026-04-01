-- ═══════════════════════════════════════════════════════════════
-- PAYROLL MODULE - Database Schema
-- Auto-created when payroll is first accessed for a business
-- ═══════════════════════════════════════════════════════════════

-- Employees Master
CREATE TABLE IF NOT EXISTS `payroll_employees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_code` VARCHAR(20) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `position` VARCHAR(100) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `join_date` DATE DEFAULT NULL,
    `base_salary` DECIMAL(15,2) DEFAULT 0.00,
    `bank_name` VARCHAR(100) DEFAULT NULL,
    `bank_account` VARCHAR(50) DEFAULT NULL,
    `finger_id` VARCHAR(20) DEFAULT NULL,
    `attendance_pin` VARCHAR(6) DEFAULT NULL,
    `monthly_target_hours` INT DEFAULT 200,
    `face_descriptor` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_employee_code` (`employee_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monthly Payroll Periods
CREATE TABLE IF NOT EXISTS `payroll_periods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `period_month` INT NOT NULL,
    `period_year` INT NOT NULL,
    `period_label` VARCHAR(50) DEFAULT NULL,
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `total_employees` INT DEFAULT 0,
    `total_gross` DECIMAL(15,2) DEFAULT 0.00,
    `total_deductions` DECIMAL(15,2) DEFAULT 0.00,
    `total_net` DECIMAL(15,2) DEFAULT 0.00,
    `status` ENUM('draft','submitted','approved','paid') DEFAULT 'draft',
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_period` (`period_month`, `period_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Salary Slips (per employee per period)
CREATE TABLE IF NOT EXISTS `payroll_slips` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `period_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `employee_name` VARCHAR(100) DEFAULT NULL,
    `position` VARCHAR(100) DEFAULT NULL,
    `work_hours` DECIMAL(10,2) NOT NULL DEFAULT 200.00,
    `actual_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `base_salary` DECIMAL(15,2) DEFAULT 0.00,
    `overtime_hours` DECIMAL(10,2) DEFAULT 0.00,
    `overtime_rate` DECIMAL(15,2) DEFAULT 0.00,
    `overtime_amount` DECIMAL(15,2) DEFAULT 0.00,
    `incentive` DECIMAL(15,2) DEFAULT 0.00,
    `allowance` DECIMAL(15,2) DEFAULT 0.00,
    `uang_makan` DECIMAL(15,2) DEFAULT 0.00,
    `bonus` DECIMAL(15,2) DEFAULT 0.00,
    `other_income` DECIMAL(15,2) DEFAULT 0.00,
    `deduction_loan` DECIMAL(15,2) DEFAULT 0.00,
    `deduction_absence` DECIMAL(15,2) DEFAULT 0.00,
    `deduction_tax` DECIMAL(15,2) DEFAULT 0.00,
    `deduction_bpjs` DECIMAL(15,2) DEFAULT 0.00,
    `deduction_other` DECIMAL(15,2) DEFAULT 0.00,
    `total_earnings` DECIMAL(15,2) DEFAULT 0.00,
    `total_deductions` DECIMAL(15,2) DEFAULT 0.00,
    `net_salary` DECIMAL(15,2) DEFAULT 0.00,
    `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_period` (`period_id`),
    INDEX `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weekly Payroll
CREATE TABLE IF NOT EXISTS `payroll_weekly` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `employee_name` VARCHAR(100) NOT NULL,
    `position` VARCHAR(100) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `period_month` INT NOT NULL,
    `period_year` INT NOT NULL,
    `week_1` DECIMAL(15,2) DEFAULT 0,
    `week_2` DECIMAL(15,2) DEFAULT 0,
    `week_3` DECIMAL(15,2) DEFAULT 0,
    `week_4` DECIMAL(15,2) DEFAULT 0,
    `total_salary` DECIMAL(15,2) DEFAULT 0,
    `notes` TEXT,
    `status` VARCHAR(20) DEFAULT 'draft',
    `cashbook_synced` TINYINT(1) DEFAULT 0,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_emp_period` (`employee_id`, `period_month`, `period_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance Records
CREATE TABLE IF NOT EXISTS `payroll_attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `attendance_date` DATE NOT NULL,
    `check_in_time` TIME DEFAULT NULL,
    `check_in_lat` DECIMAL(10,7) DEFAULT NULL,
    `check_in_lng` DECIMAL(10,7) DEFAULT NULL,
    `check_in_distance_m` INT DEFAULT NULL,
    `check_in_address` VARCHAR(255) DEFAULT NULL,
    `check_in_device` VARCHAR(200) DEFAULT NULL,
    `check_out_time` TIME DEFAULT NULL,
    `check_out_lat` DECIMAL(10,7) DEFAULT NULL,
    `check_out_lng` DECIMAL(10,7) DEFAULT NULL,
    `check_out_distance_m` INT DEFAULT NULL,
    `check_out_device` VARCHAR(200) DEFAULT NULL,
    `scan_3` TIME DEFAULT NULL,
    `scan_4` TIME DEFAULT NULL,
    `work_hours` DECIMAL(5,2) DEFAULT NULL,
    `shift_1_hours` DECIMAL(5,2) DEFAULT NULL,
    `shift_2_hours` DECIMAL(5,2) DEFAULT NULL,
    `status` ENUM('present','late','absent','leave','holiday','half_day') NOT NULL DEFAULT 'present',
    `is_outside_radius` TINYINT(1) DEFAULT 0,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_attendance` (`employee_id`, `attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance Config (singleton)
CREATE TABLE IF NOT EXISTS `payroll_attendance_config` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `office_lat` DECIMAL(10,7) NOT NULL DEFAULT -6.2000000,
    `office_lng` DECIMAL(10,7) NOT NULL DEFAULT 106.8166700,
    `allowed_radius_m` INT NOT NULL DEFAULT 200,
    `office_name` VARCHAR(100) DEFAULT 'Kantor',
    `checkin_start` TIME DEFAULT '07:00:00',
    `checkin_end` TIME DEFAULT '10:00:00',
    `checkout_start` TIME DEFAULT '16:00:00',
    `allow_outside` TINYINT(1) DEFAULT 0,
    `app_logo` VARCHAR(255) DEFAULT NULL,
    `fingerspot_cloud_id` VARCHAR(50) DEFAULT NULL,
    `fingerspot_enabled` TINYINT(1) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `payroll_attendance_config` (`id`) VALUES (1);

-- Attendance Locations (multi-location GPS)
CREATE TABLE IF NOT EXISTS `payroll_attendance_locations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `location_name` VARCHAR(100) NOT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `lat` DECIMAL(10,7) NOT NULL DEFAULT 0,
    `lng` DECIMAL(10,7) NOT NULL DEFAULT 0,
    `radius_m` INT NOT NULL DEFAULT 200,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Work Schedules (per employee, per day)
CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `day_of_week` TINYINT NOT NULL DEFAULT 0,
    `start_time` TIME NOT NULL DEFAULT '09:00:00',
    `end_time` TIME NOT NULL DEFAULT '17:00:00',
    `break_minutes` INT DEFAULT 60,
    `is_off` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_emp_day` (`employee_id`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staff Accounts (for staff self-service portal)
CREATE TABLE IF NOT EXISTS `staff_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `last_login` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_emp` (`employee_id`),
    UNIQUE KEY `uk_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leave Requests
CREATE TABLE IF NOT EXISTS `leave_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `leave_type` VARCHAR(50) NOT NULL DEFAULT 'cuti',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` TEXT,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `approved_by` VARCHAR(100) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `admin_notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_emp` (`employee_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fingerprint Log
CREATE TABLE IF NOT EXISTS `fingerprint_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cloud_id` VARCHAR(50) NOT NULL,
    `type` VARCHAR(32) DEFAULT 'attlog',
    `pin` VARCHAR(20) DEFAULT NULL,
    `scan_time` DATETIME DEFAULT NULL,
    `verify_method` VARCHAR(30) DEFAULT NULL,
    `status_scan` VARCHAR(30) DEFAULT NULL,
    `employee_id` INT DEFAULT NULL,
    `processed` TINYINT(1) DEFAULT 0,
    `process_result` VARCHAR(255) DEFAULT NULL,
    `raw_data` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cloud` (`cloud_id`),
    INDEX `idx_pin` (`pin`),
    INDEX `idx_scan` (`scan_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
