-- Migration: Adicionar suporte ao Spotify na tabela usuarios

-- 1. Adicionar colunas para integração com Spotify
ALTER TABLE usuarios 
ADD COLUMN spotify_id VARCHAR(100) DEFAULT NULL,
ADD COLUMN spotify_conectado TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Permitir senha_hash ser NULL (para contas criadas via Spotify)
ALTER TABLE usuarios 
MODIFY COLUMN senha_hash VARCHAR(255) DEFAULT NULL;

-- 3. Adicionar índice único para spotify_id
ALTER TABLE usuarios 
ADD UNIQUE KEY spotify_id (spotify_id);

-- 4. Comentários para documentação
ALTER TABLE usuarios 
COMMENT = 'Tabela de usuários com suporte a autenticação via Spotify';