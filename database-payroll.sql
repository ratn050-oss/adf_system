-- Payroll Module Database Schema (Business DB)
-- This file should be executed on the business database (adf_benscafe, adf_narayana_hotel, etc.)

-- 1. Employees Table
CREATE TABLE IF NOT EXISTS `payroll_employees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_code` VARCHAR(20) NOT NULL COMMENT 'Format: EMP-XXX',
  `full_name` VARCHAR(100) NOT NULL,
  `position` VARCHAR(100) NOT NULL COMMENT 'e.g. Manager, Chef, Waiter',
  `department` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `join_date` DATE NOT NULL,
  `base_salary` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `bank_name` VARCHAR(50) DEFAULT NULL,
  `bank_account` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_employee_code` (`employee_code`),
  INDEX `idx_active` (`is_active`),
  INDEX `idx_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. Payroll Periods Table
CREATE TABLE IF NOT EXISTS `payroll_periods` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `period_month` INT NOT NULL COMMENT '1-12',
  `period_year` INT NOT NULL COMMENT 'e.g. 2026',
  `period_label` VARCHAR(50) NOT NULL COMMENT 'e.g. Februari 2026',
  `status` ENUM('draft','submitted','approved','paid') NOT NULL DEFAULT 'draft',
  `total_gross` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_deductions` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_net` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_employees` INT NOT NULL DEFAULT 0,
  `submitted_at` DATETIME DEFAULT NULL,
  `submitted_by` INT DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `approved_by` INT DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_period` (`period_month`, `period_year`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. Payroll Slips Table (One per employee per period)
CREATE TABLE IF NOT EXISTS `payroll_slips` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `period_id` INT NOT NULL,
  `employee_id` INT NOT NULL,
  `employee_name` VARCHAR(100) NOT NULL COMMENT 'Snapshot of name',
  `position` VARCHAR(100) NOT NULL COMMENT 'Snapshot of position',
  
  -- Earnings
  `base_salary` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  
  -- Work Hours Logic: Target = 200 hours/month
  -- If work_hours >= 200: actual_base = base_salary (full salary)
  -- If work_hours < 200: actual_base = (base_salary/200) * work_hours
  `work_hours` DECIMAL(10,2) NOT NULL DEFAULT 200.00 COMMENT 'Monthly work hours (target: 200)',
  `actual_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Calculated base after work hours',
  
  -- Overtime Logic: (base_salary / 200) * overtime_hours
  `overtime_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Custom logic: hours input',
  `overtime_rate` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Rate per hour = Base / 200',
  `overtime_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Final amount',
  
  `incentive` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `allowance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `bonus` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `other_income` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  
  -- Deductions
  `deduction_loan` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `deduction_absence` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `deduction_tax` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `deduction_bpjs` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `deduction_other` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  
  -- Totals
  `total_earnings` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_deductions` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_salary` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  UNIQUE KEY `unique_slip` (`period_id`, `employee_id`),
  FOREIGN KEY (`period_id`) REFERENCES `payroll_periods`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`employee_id`) REFERENCES `payroll_employees`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4. Payroll Slip Details (Optional custom rows)
CREATE TABLE IF NOT EXISTS `payroll_slip_details` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `slip_id` INT NOT NULL,
  `component_type` ENUM('earning','deduction') NOT NULL,
  `component_name` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`slip_id`) REFERENCES `payroll_slips`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
