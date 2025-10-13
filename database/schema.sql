-- Create database with proper character set
CREATE DATABASE IF NOT EXISTS sistema_auth
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE sistema_auth;

-- Create users table with proper constraints and indexes
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255),
    email VARCHAR(255) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('user', 'admin') DEFAULT 'user',
    usuario VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Add indexes for better performance
    INDEX idx_email (email),
    INDEX idx_usuario (usuario),
    -- Add unique constraints
    UNIQUE KEY uk_email (email),
    UNIQUE KEY uk_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add a test admin user (optional, password hash for 'admin123')
-- INSERT INTO usuarios (nome, email, senha_hash, perfil, usuario) VALUES
-- ('Admin', 'admin@example.com', '$2y$10$YOUR_HASH_HERE', 'admin', 'admin');