-- Setup para Banco de Dados de Produção (AWS RDS)
-- Execute este script no banco socialmusic-db

-- Criar tabela de usuários
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255),
    email VARCHAR(255) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('user', 'admin') DEFAULT 'user',
    usuario VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Indexes para performance
    INDEX idx_email (email),
    INDEX idx_usuario (usuario),
    -- Constraints únicos
    UNIQUE KEY uk_email (email),
    UNIQUE KEY uk_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificar se foi criado
SHOW TABLES;
DESCRIBE usuarios;
