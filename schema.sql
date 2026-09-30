-- NumVault SMS OTP Marketplace Schema
-- Production MariaDB / MySQL Schema

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key_name` VARCHAR(100) UNIQUE NOT NULL,
  `value_text` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `email` VARCHAR(120) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('user', 'admin') DEFAULT 'user',
  `balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'blocked') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_role (role),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `countries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `prefix` VARCHAR(10) NOT NULL,
  `is_enabled` TINYINT(1) DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `icon` VARCHAR(50) DEFAULT 'message-square',
  `is_enabled` TINYINT(1) DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) DEFAULT '',
  `is_enabled` TINYINT(1) DEFAULT 1,
  `priority` INT DEFAULT 1,
  `balance` DECIMAL(10, 2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `last_check` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `servers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `service_id` INT NOT NULL,
  `country_id` INT NOT NULL,
  `provider_id` INT NOT NULL,
  `server_name` VARCHAR(100) NOT NULL,
  `provider_service_code` VARCHAR(50) NOT NULL,
  `provider_country_code` VARCHAR(50) NOT NULL,
  `provider_operator_code` VARCHAR(50) DEFAULT 'any',
  `cost_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `selling_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `is_enabled` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_svc_ctry (`service_id`, `country_id`, `is_enabled`),
  INDEX idx_country_enabled (`country_id`, `is_enabled`, `service_id`),
  INDEX idx_provider_enabled (`provider_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `server_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `country_id` INT NOT NULL,
  `provider_id` INT NOT NULL,
  `phone_number` VARCHAR(30) NOT NULL,
  `provider_order_id` VARCHAR(100) NOT NULL,
  `cost_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'completed', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
  `sms_text` TEXT NULL,
  `otp_code` VARCHAR(50) NULL,
  `expires_at` DATETIME NOT NULL,
  `is_refunded` TINYINT(1) NOT NULL DEFAULT 0,
  `refund_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `refund_reason` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (`status`),
  INDEX idx_expires (`expires_at`, `status`),
  INDEX idx_user_status (`user_id`, `status`),
  INDEX idx_user_created (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `order_id` INT NULL,
  `payment_id` INT NULL,
  `type` ENUM('credit', 'debit', 'refund', 'admin_credit', 'admin_debit') NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `balance_before` DECIMAL(10, 2) NOT NULL,
  `balance_after` DECIMAL(10, 2) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `reference_id` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_tx (`user_id`, `created_at`),
  INDEX idx_order (`order_id`),
  INDEX idx_payment (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `gateway` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `status` ENUM('pending', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  `transaction_ref` VARCHAR(100) NOT NULL UNIQUE,
  `gateway_response` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (`status`),
  INDEX idx_user (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `order_id` INT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium',
  `status` ENUM('open', 'answered', 'resolved', 'closed') DEFAULT 'open',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (`user_id`),
  INDEX idx_status (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `is_admin` TINYINT(1) DEFAULT 0,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ticket (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('order', 'wallet', 'otp', 'system') DEFAULT 'system',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_read (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_country_mappings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider_id` INT NOT NULL,
  `country_id` INT NOT NULL,
  `provider_country_id` VARCHAR(50) NOT NULL,
  `provider_country_name` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_prov_country` (`provider_id`, `provider_country_id`),
  INDEX `idx_pcm_country` (`country_id`),
  INDEX `idx_pcm_prov` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_service_mappings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `provider_service_id` VARCHAR(50) NOT NULL,
  `provider_service_name` VARCHAR(100) NULL,
  `cost_price` DECIMAL(10, 2) NULL,
  `available_count` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_prov_service` (`provider_id`, `provider_service_id`),
  INDEX `idx_psm_service` (`service_id`),
  INDEX `idx_psm_prov` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
