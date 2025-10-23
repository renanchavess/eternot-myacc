<?php
// Migration 36: Create Mercado Pago tables if missing

// mercadopago_transactions
if (!$db->hasTable('mercadopago_transactions')) {
    $db->exec("CREATE TABLE IF NOT EXISTS `mercadopago_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_reference` varchar(255) NOT NULL,
  `payment_id` varchar(255) DEFAULT NULL,
  `preference_id` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('donate','buybox') NOT NULL,
  `package_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'BRL',
  `status` enum('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
  `mp_status` varchar(50) DEFAULT NULL,
  `mp_status_detail` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_reference` (`external_reference`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
}

// mercadopago_preferences
if (!$db->hasTable('mercadopago_preferences')) {
    $db->exec("CREATE TABLE IF NOT EXISTS `mercadopago_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `preference_id` varchar(255) NOT NULL,
  `external_reference` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('donate','buybox') NOT NULL,
  `package_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'BRL',
  `status` enum('active','expired','used') DEFAULT 'active',
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `preference_id` (`preference_id`),
  UNIQUE KEY `external_reference` (`external_reference`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
}

// mercadopago_webhook_logs (optional)
if (!$db->hasTable('mercadopago_webhook_logs')) {
    $db->exec("CREATE TABLE IF NOT EXISTS `mercadopago_webhook_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL,
  `resource_id` varchar(255) NOT NULL,
  `payload` text NOT NULL,
  `headers` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `processed` tinyint(1) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `resource_id` (`resource_id`),
  KEY `processed` (`processed`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
}

// mercadopago_settings (optional)
if (!$db->hasTable('mercadopago_settings')) {
    $db->exec("CREATE TABLE IF NOT EXISTS `mercadopago_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    // default settings
    $db->exec("INSERT IGNORE INTO `mercadopago_settings` (`setting_key`, `setting_value`, `description`) VALUES
('environment', 'sandbox', 'Ambiente do Mercado Pago (sandbox ou production)'),
('webhook_enabled', '1', 'Habilitar processamento de webhooks'),
('double_coins_enabled', '0', 'Habilitar dobro de moedas em promoções'),
('double_coins_minimum', '500', 'Valor mínimo para ativar dobro de moedas'),
('logs_enabled', '1', 'Habilitar logs de transações'),
('logs_retention_days', '30', 'Dias para manter logs antigos)');");
}