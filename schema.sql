-- To The Future - Database Schema
-- MySQL 8.0+



-- 1. Users
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `phone` VARCHAR(20) DEFAULT NULL,
    `date_of_birth` DATE NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('super_admin','reviewer','user') NOT NULL DEFAULT 'user',
    `status` ENUM('pending','approved','rejected','inactive') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Settings (single-row config)
CREATE TABLE `settings` (
    `id` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
    `interest_rate` DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    `late_fee_rate` DECIMAL(5,2) NOT NULL DEFAULT 2.00,
    `term_days` INT UNSIGNED NOT NULL DEFAULT 30,
    `share_price` DECIMAL(12,2) NOT NULL DEFAULT 1000.00,
    `min_shares_to_borrow` INT UNSIGNED NOT NULL DEFAULT 5,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`id`) VALUES (1) ON DUPLICATE KEY UPDATE `id`=`id`;

-- 3. Verified Savings (ledger snapshot)
CREATE TABLE `verified_savings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `proposed_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `locked_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `verified_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_user_verified` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Savings transactions
CREATE TABLE `savings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `type` ENUM('deposit','withdrawal') NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `reference` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('pending','reviewer_approved','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewer_notes` TEXT DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_savings_user` (`user_id`),
    INDEX `idx_savings_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Loans
CREATE TABLE `loans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `principal_outstanding` DECIMAL(14,2) NOT NULL,
    `interest_flat` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `interest_paid` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `late_fees` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending','reviewer_approved','approved_disbursed','rejected','closed') NOT NULL DEFAULT 'pending',
    `reviewer_notes` TEXT DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `disbursed_at` TIMESTAMP NULL DEFAULT NULL,
    `due_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_loans_user` (`user_id`),
    INDEX `idx_loans_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Repayments
CREATE TABLE `repayments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT UNSIGNED NULL DEFAULT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `status` ENUM('pending','reviewer_approved','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewer_notes` TEXT DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_repayments_loan` (`loan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Shares
CREATE TABLE `shares` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `share_count` INT UNSIGNED NOT NULL,
    `price_per_share` DECIMAL(12,2) NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL,
    `status` ENUM('pending','reviewer_approved','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewer_notes` TEXT DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_shares_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Notifications
CREATE TABLE `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_notifications_user` (`user_id`),
    INDEX `idx_notifications_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Suggestions
CREATE TABLE `suggestions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `status` ENUM('pending','reviewer_approved','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewer_notes` TEXT DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_suggestions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Guarantors (loan guarantee system)
CREATE TABLE IF NOT EXISTS `guarantors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT UNSIGNED NOT NULL,
    `guarantor_id` INT UNSIGNED NOT NULL,
    `borrower_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pending','accepted','released','declined') NOT NULL DEFAULT 'pending',
    `locked_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`guarantor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`borrower_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_guarantor_loan` (`loan_id`),
    INDEX `idx_guarantor_user` (`guarantor_id`),
    INDEX `idx_guarantor_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Proof Uploads (receipts for payments)
CREATE TABLE IF NOT EXISTS `proof_uploads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `related_type` VARCHAR(50) NOT NULL COMMENT 'savings, repayment',
    `related_id` INT UNSIGNED DEFAULT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_proof_user` (`user_id`),
    INDEX `idx_proof_related` (`related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Audit Logs
CREATE TABLE `audit_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_audit_actor` (`actor_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Migration: Add missing columns for proof upload + full audit trail ──────
ALTER TABLE `savings`
    ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `amount`,
    ADD COLUMN `proof_file` VARCHAR(255) DEFAULT NULL AFTER `reference_number`,
    ADD COLUMN `admin_id` INT UNSIGNED DEFAULT NULL AFTER `reviewed_by`,
    ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `admin_id`,
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
    ADD INDEX `idx_savings_ref` (`reference_number`);

ALTER TABLE `loans`
    ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `amount`,
    ADD COLUMN `proof_file` VARCHAR(255) DEFAULT NULL AFTER `reference_number`,
    ADD COLUMN `admin_id` INT UNSIGNED DEFAULT NULL AFTER `reviewed_by`,
    ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `admin_id`,
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
    ADD INDEX `idx_loans_ref` (`reference_number`);

ALTER TABLE `repayments`
    ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `amount`,
    ADD COLUMN `proof_file` VARCHAR(255) DEFAULT NULL AFTER `reference_number`,
    ADD COLUMN `admin_id` INT UNSIGNED DEFAULT NULL AFTER `reviewed_by`,
    ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `admin_id`,
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
    ADD INDEX `idx_repayments_ref` (`reference_number`);

ALTER TABLE `shares`
    ADD COLUMN `admin_id` INT UNSIGNED DEFAULT NULL AFTER `reviewed_by`,
    ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `admin_id`,
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `suggestions`
    ADD COLUMN `admin_id` INT UNSIGNED DEFAULT NULL AFTER `reviewed_by`,
    ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `admin_id`,
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
