-- Plataforma de Escrow - Script Completo de Base de Datos
-- Versión: 1.0
-- Este script crea todas las tablas necesarias para el proyecto desde cero.

-- Paso 1: Crear la base de datos si no existe y seleccionarla.
CREATE DATABASE IF NOT EXISTS escrow_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE escrow_db;

-- --------------------------------------------------------

--
-- Estructura de la tabla `users`
-- Almacena los datos de los usuarios registrados, incluyendo su saldo de billetera.
--
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de la tabla `admins`
-- Almacena las credenciales de los administradores de la plataforma.
--
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar el usuario administrador inicial.
-- Usuario: admin / Contraseña: password123
INSERT INTO `admins` (`username`, `password_hash`) VALUES
('admin', '$2y$10$t3gqY5P.m9W2k/aH5F.pYOxdYV2cbzS9VOMfJbTr2J5tWHMEk37/2');

-- --------------------------------------------------------

--
-- Estructura de la tabla `transactions`
-- El corazón de la aplicación. Almacena todos los detalles de cada operación.
--
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `transaction_uuid` VARCHAR(36) NOT NULL UNIQUE,
  `buyer_id` INT NULL,
  `seller_id` INT NULL,
  `buyer_name` VARCHAR(255) NOT NULL,
  `seller_name` VARCHAR(255) NOT NULL,
  `product_description` TEXT NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `commission` DECIMAL(10, 2) NOT NULL,
  `net_amount` DECIMAL(10, 2) NOT NULL,
  `buyer_uuid` VARCHAR(36) NOT NULL,
  `seller_uuid` VARCHAR(36) NOT NULL,
  `payment_reference` VARCHAR(255) NULL DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'initiated',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de la tabla `messages`
-- Almacena los mensajes y las imágenes del chat para cada transacción.
--
DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` INT NOT NULL,
  `sender_role` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `image_path` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
