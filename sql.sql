-- Plataforma Interpago - Script Completo de Base de Datos
-- Versión Final
-- Este script crea todas las tablas necesarias para el proyecto desde cero.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Base de datos: `escrow_db`
--
CREATE DATABASE IF NOT EXISTS `escrow_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `escrow_db`;

-- --------------------------------------------------------

--
-- Estructura de la tabla `admins`
--
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `admins`
--
INSERT INTO `admins` (`id`, `username`, `password_hash`, `created_at`) VALUES
(1, 'admin', '$2y$10$t3gqY5P.m9W2k/aH5F.pYOxdYV2cbzS9VOMfJbTr2J5tWHMEk37/2', '2025-06-15 19:00:00');

-- --------------------------------------------------------

--
-- Estructura de la tabla `users`
--
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `document_type` varchar(20) DEFAULT NULL,
  `document_number` varchar(50) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  `verification_status` varchar(20) NOT NULL DEFAULT 'unverified',
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `document_number` (`document_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de la tabla `transactions`
--
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_uuid` varchar(36) NOT NULL,
  `buyer_id` int(11) DEFAULT NULL,
  `seller_id` int(11) DEFAULT NULL,
  `buyer_name` varchar(255) NOT NULL,
  `seller_name` varchar(255) NOT NULL,
  `product_description` text NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `commission` decimal(10,2) NOT NULL,
  `net_amount` decimal(10,2) NOT NULL,
  `commission_payer` varchar(20) NOT NULL DEFAULT 'seller',
  `buyer_uuid` varchar(36) NOT NULL,
  `seller_uuid` varchar(36) NOT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `qr_code_token` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'initiated',
  `seller_rating` int(11) DEFAULT NULL,
  `seller_comment` text DEFAULT NULL,
  `buyer_rating` int(11) DEFAULT NULL,
  `buyer_comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `shipping_cost` decimal(10,2) DEFAULT NULL,
  `tracking_number` varchar(255) DEFAULT NULL,
  `shipping_provider` varchar(100) DEFAULT NULL,
  `package_details` text DEFAULT NULL,
  `shipping_label_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_uuid` (`transaction_uuid`),
  UNIQUE KEY `qr_code_token` (`qr_code_token`),
  KEY `buyer_id` (`buyer_id`),
  KEY `seller_id` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de la tabla `messages`
--
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `sender_role` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de la tabla `password_resets`
--
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de la tabla `withdrawals`
--
CREATE TABLE `withdrawals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `bank_details` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Restricciones para tablas volcadas
--

ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE;

ALTER TABLE `withdrawals`
  ADD CONSTRAINT `withdrawals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;
