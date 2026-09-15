-- Inventory System Database Schema & Initial Data

CREATE DATABASE IF NOT EXISTS `inventory_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `inventory_system`;

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin account: admin / admin12345
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `is_active`) 
VALUES (1, 'admin', 'admin@example.com', '$2y$10$DGUPjKhTiT22g3XJi674o.oUB7zhy3UXdbJ0kwqAhnudNvI5596x6', 'admin', 1)
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- --------------------------------------------------------
-- Table structure for `page_permissions`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `page_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `page_key` VARCHAR(50) NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `unique_role_page` (`page_key`, `role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default permissions
INSERT INTO `page_permissions` (`page_key`, `role`, `is_enabled`) VALUES
('dashboard', 'manager', 1),
('products', 'manager', 1),
('inventory', 'manager', 1),
('sales', 'manager', 1),
('cash_reconciliation', 'manager', 1),
('reports', 'manager', 1),
('settings', 'manager', 0),
('dashboard', 'staff', 1),
('products', 'staff', 0),
('inventory', 'staff', 1),
('sales', 'staff', 1),
('cash_reconciliation', 'staff', 1),
('reports', 'staff', 0),
('settings', 'staff', 0)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- --------------------------------------------------------
-- Table structure for `products`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sku` VARCHAR(100) NOT NULL UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `category` VARCHAR(100) NULL,
  `unit_type` VARCHAR(20) NOT NULL DEFAULT 'piece',
  `weight_per_piece` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `min_weight` DECIMAL(10,3) NOT NULL DEFAULT 0.100,
  `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `last_purchase_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock_qty` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  `location` VARCHAR(100) NULL,
  `reorder_level` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5 Sample Products for testing all functions
INSERT INTO `products` (`sku`, `name`, `description`, `category`, `unit_type`, `weight_per_piece`, `min_weight`, `cost_price`, `sale_price`, `wholesale_price`, `last_purchase_cost`, `stock_qty`, `location`, `reorder_level`) VALUES
('SKU-RICE001', 'Jasmine Rice 25kg Sack', 'Premium quality jasmine white rice 25kg', 'Grains', 'piece', 1.000, 0.100, 1000.00, 1250.00, 1150.00, 1000.00, 20.000, 'Warehouse A', 5.00),
('SKU-PORK002', 'Fresh Pork Belly', 'Fresh cut pork belly sold per kg', 'Meat', 'kg', 1.000, 0.250, 280.00, 360.00, 320.00, 280.00, 50.500, 'Cold Storage 1', 10.00),
('SKU-OIL003', 'Cooking Oil 1L Bottle', 'Pure vegetable cooking oil 1 Liter', 'Groceries', 'piece', 1.000, 0.100, 65.00, 85.00, 75.00, 65.00, 40.000, 'Shelf B-2', 10.00),
('SKU-CHIK004', 'Fresh Whole Chicken', 'Dressed fresh whole chicken sold per kg', 'Poultry', 'kg', 1.200, 0.500, 160.00, 210.00, 190.00, 160.00, 35.000, 'Cold Storage 2', 8.00),
('SKU-SUG005', 'Refined White Sugar 1kg', 'Pure refined cane white sugar 1kg pack', 'Groceries', 'piece', 1.000, 0.100, 70.00, 90.00, 82.00, 70.00, 4.000, 'Shelf A-1', 10.00)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- --------------------------------------------------------
-- Table structure for `purchases`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `purchase_date` DATETIME NOT NULL,
  `qty` DECIMAL(10,3) NOT NULL,
  `cost` DECIMAL(10,2) NOT NULL,
  `supplier` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_purchase_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `sales`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `receipt_no` VARCHAR(100) NOT NULL,
  `product_id` INT NOT NULL,
  `sale_date` DATETIME NOT NULL,
  `qty` DECIMAL(10,3) NOT NULL,
  `weight_kg` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  `price` DECIMAL(10,2) NOT NULL,
  `total` DECIMAL(10,2) NOT NULL,
  `customer_name` VARCHAR(255) NULL,
  `customer_email` VARCHAR(255) NULL,
  `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `change_due` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_void` TINYINT(1) NOT NULL DEFAULT 0,
  `void_reason` VARCHAR(255) NULL,
  `void_by` VARCHAR(100) NULL,
  `void_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sale_product` (`product_id`),
  INDEX `idx_receipt_no` (`receipt_no`),
  INDEX `idx_sale_date` (`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `cash_reconciliations`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cash_reconciliations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `recon_date` DATE NOT NULL UNIQUE,
  `expected_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `counted_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `difference` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL,
  `updated_by` VARCHAR(100) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `cash_reconciliation_logs`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cash_reconciliation_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `recon_date` DATE NOT NULL,
  `expected_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `counted_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `difference` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL,
  `action_type` VARCHAR(50) NOT NULL DEFAULT 'save',
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_recon_date` (`recon_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `audit_logs`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `action` VARCHAR(100) NOT NULL,
  `receipt_no` VARCHAR(100) NULL,
  `product_id` INT NULL,
  `details` TEXT NULL,
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
