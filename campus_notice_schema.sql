-- Database schema for the JOOUST Campus Notice System
-- Run this script in MySQL / MariaDB to create the database and tables.

CREATE DATABASE IF NOT EXISTS `campus-notice` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `campus-notice`;

-- Faculties table
CREATE TABLE IF NOT EXISTS `faculties` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `dean_name` VARCHAR(150) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_faculty_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed faculties with school and dean details
INSERT IGNORE INTO faculties (name, dean_name) VALUES
    ('School of agricultural and food sciences', 'Prof. Arnold O. Watako'),
    ('School of biological, physical, mathematics and actuarial sciences', 'Prof. Regina O. Nyunja'),
    ('School of business and economics', 'Dr. Michael Otieno Nyagol'),
    ('School of education, humanities and social sciences', 'Dr Jack Odongo Ajowi'),
    ('School of engineering and technology', 'Dr. Michael O. Oloko'),
    ('School of health sciences', 'Dr George Ayodo'),
    ('School of Informatics and innovative systems', 'Prof. Agolla'),
    ('School of spatial planning and natural resources management', 'Dr Lorna Grace Okotto');

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(200) NOT NULL,
    `student_id` VARCHAR(100) NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('super_admin','admin','student') NOT NULL DEFAULT 'student',
    `admin_type` VARCHAR(100) NULL,
    `faculty_id` INT UNSIGNED NULL,
    `year` TINYINT UNSIGNED NULL,
    `membership` VARCHAR(100) NULL,
    `profile_picture` VARCHAR(255) NULL,
    `reset_token` VARCHAR(255) NULL,
    `reset_expires` DATETIME NULL,
    `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_users_email` (`email`),
    UNIQUE KEY `ux_users_student_id` (`student_id`),
    KEY `idx_users_faculty` (`faculty_id`),
    CONSTRAINT `fk_users_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notices table
CREATE TABLE IF NOT EXISTS `notices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `content` LONGTEXT NOT NULL,
    `category` VARCHAR(80) NULL,
    `attachment` VARCHAR(255) NULL,
    `posted_by` INT UNSIGNED NOT NULL,
    `faculty_target` INT UNSIGNED NULL,
    `department_target` INT UNSIGNED NULL,
    `year_target` TINYINT UNSIGNED NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `expire_at` DATETIME NULL,
    `publish_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
    `latitude` DECIMAL(10,7) NULL,
    `longitude` DECIMAL(10,7) NULL,
    `location_name` VARCHAR(255) NULL,
    `location_address` VARCHAR(255) NULL,
    `radius_km` DECIMAL(7,3) NULL,
    `event_date` DATETIME NULL,
    `event_end_date` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notices_posted_by` (`posted_by`),
    KEY `idx_notices_faculty_target` (`faculty_target`),
    KEY `idx_notices_department_target` (`department_target`),
    KEY `idx_notices_year_target` (`year_target`),
    CONSTRAINT `fk_notices_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notices_faculty_target` FOREIGN KEY (`faculty_target`) REFERENCES `faculties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_notices_department_target` FOREIGN KEY (`department_target`) REFERENCES `faculties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications table
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `notice_id` INT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user_id` (`user_id`),
    KEY `idx_notifications_notice_id` (`notice_id`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bookmarks table
CREATE TABLE IF NOT EXISTS `bookmarks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `notice_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_bookmarks_user_notice` (`user_id`, `notice_id`),
    KEY `idx_bookmarks_user_id` (`user_id`),
    KEY `idx_bookmarks_notice_id` (`notice_id`),
    CONSTRAINT `fk_bookmarks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bookmarks_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notice views table
CREATE TABLE IF NOT EXISTS `notice_views` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notice_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_notice_views_notice_user` (`notice_id`, `user_id`),
    KEY `idx_notice_views_notice_id` (`notice_id`),
    KEY `idx_notice_views_user_id` (`user_id`),
    CONSTRAINT `fk_notice_views_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notice_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User locations table
CREATE TABLE IF NOT EXISTS `user_locations` (
    `user_id` INT UNSIGNED NOT NULL,
    `latitude` DECIMAL(10,7) NOT NULL,
    `longitude` DECIMAL(10,7) NOT NULL,
    `location_name` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_user_locations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nearby notifications table
CREATE TABLE IF NOT EXISTS `nearby_notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `notice_id` INT UNSIGNED NOT NULL,
    `distance_km` DECIMAL(7,3) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nearby_notifications_user` (`user_id`),
    KEY `idx_nearby_notifications_notice` (`notice_id`),
    CONSTRAINT `fk_nearby_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_nearby_notifications_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emergency alerts table
CREATE TABLE IF NOT EXISTS `emergency_alerts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `severity` VARCHAR(50) NOT NULL DEFAULT 'medium',
    `target_faculty` INT UNSIGNED NULL,
    `target_year` TINYINT UNSIGNED NULL,
    `expires_at` DATETIME NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_emergency_alerts_faculty` (`target_faculty`),
    KEY `idx_emergency_alerts_created_by` (`created_by`),
    CONSTRAINT `fk_emergency_alerts_faculty` FOREIGN KEY (`target_faculty`) REFERENCES `faculties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_emergency_alerts_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emergency alert receipts table
CREATE TABLE IF NOT EXISTS `emergency_alert_receipts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `alert_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_emergency_receipts_alert_id` (`alert_id`),
    KEY `idx_emergency_receipts_user_id` (`user_id`),
    CONSTRAINT `fk_emergency_receipts_alert` FOREIGN KEY (`alert_id`) REFERENCES `emergency_alerts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_emergency_receipts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User activity log table
CREATE TABLE IF NOT EXISTS `user_activity_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(255) NOT NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_activity_user` (`user_id`),
    CONSTRAINT `fk_user_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feature extension migrations
ALTER TABLE `notices`
    ADD COLUMN IF NOT EXISTS `priority` VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER `category`,
    ADD COLUMN IF NOT EXISTS `requires_acknowledgement` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_pinned`,
    ADD COLUMN IF NOT EXISTS `acknowledgement_due_at` DATETIME NULL AFTER `requires_acknowledgement`,
    ADD COLUMN IF NOT EXISTS `approval_status` VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER `status`,
    ADD COLUMN IF NOT EXISTS `review_notes` TEXT NULL AFTER `approval_status`,
    ADD COLUMN IF NOT EXISTS `reviewed_by` INT UNSIGNED NULL AFTER `review_notes`,
    ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME NULL AFTER `reviewed_by`,
    ADD COLUMN IF NOT EXISTS `delivery_channels` VARCHAR(100) NOT NULL DEFAULT 'in_app' AFTER `reviewed_at`,
    ADD COLUMN IF NOT EXISTS `template_id` INT UNSIGNED NULL AFTER `delivery_channels`,
    ADD COLUMN IF NOT EXISTS `recurrence_pattern` VARCHAR(100) NULL AFTER `template_id`;

CREATE TABLE IF NOT EXISTS `user_notification_preferences` (
    `user_id` INT UNSIGNED NOT NULL,
    `in_app_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `email_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `emergency_override` TINYINT(1) NOT NULL DEFAULT 1,
    `quiet_hours_start` TIME NULL,
    `quiet_hours_end` TIME NULL,
    `categories_csv` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_user_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notice_acknowledgements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notice_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `acknowledged_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_notice_acknowledgements_notice_user` (`notice_id`, `user_id`),
    KEY `idx_notice_acknowledgements_notice` (`notice_id`),
    KEY `idx_notice_acknowledgements_user` (`user_id`),
    CONSTRAINT `fk_notice_acknowledgements_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notice_acknowledgements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notice_questions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notice_id` INT UNSIGNED NOT NULL,
    `asked_by` INT UNSIGNED NOT NULL,
    `question` TEXT NOT NULL,
    `answer` TEXT NULL,
    `answered_by` INT UNSIGNED NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `answered_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_notice_questions_notice` (`notice_id`),
    KEY `idx_notice_questions_asked_by` (`asked_by`),
    CONSTRAINT `fk_notice_questions_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notice_questions_asked_by` FOREIGN KEY (`asked_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notice_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `content` LONGTEXT NOT NULL,
    `category` VARCHAR(80) NULL,
    `faculty_target` INT UNSIGNED NULL,
    `year_target` TINYINT UNSIGNED NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `default_priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
    `requires_acknowledgement` TINYINT(1) NOT NULL DEFAULT 0,
    `delivery_channels` VARCHAR(100) NOT NULL DEFAULT 'in_app',
    `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
    `recurrence_pattern` VARCHAR(100) NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notice_templates_created_by` (`created_by`),
    CONSTRAINT `fk_notice_templates_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notice_deliveries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notice_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(30) NOT NULL,
    `destination` VARCHAR(255) NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_notice_deliveries_notice_user_channel` (`notice_id`, `user_id`, `channel`),
    KEY `idx_notice_deliveries_notice` (`notice_id`),
    KEY `idx_notice_deliveries_user` (`user_id`),
    CONSTRAINT `fk_notice_deliveries_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notice_deliveries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional sample data with a valid PHP password hash for super admin
INSERT IGNORE INTO users (name, email, student_id, password, role, admin_type, faculty_id, year, is_approved, is_active)
VALUES ('Super Admin', 'superadmin@campus.edu', 'SUPER001', '$2y$10$wMfcPg7pfyZcCui1fQ9JV.Wmin/t6Mka9IsVTRvdkRrjuO84gkPGC', 'super_admin', NULL, NULL, NULL, 1, 1);
