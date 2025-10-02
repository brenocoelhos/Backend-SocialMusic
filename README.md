# Social Music Backend

Backend da aplicação Social Music, desenvolvida em PHP.

## 📋 Requisitos

- PHP 7.4 ou superior
- MySQL/MariaDB
- XAMPP (ou servidor Apache configurado)

## 🔧 Configuração

1. Clone o repositório na pasta `htdocs` do XAMPP:
```bash
git clone [url-do-repositorio] socialmusic_backend
```

2. Configure o banco de dados:
   - Crie um banco de dados MySQL
   - Configure as credenciais no arquivo `config/database.php`

3. Estrutura do banco de dados:
```sql
CREATE DATABASE sistema_auth

USE sistema_auth

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255),
    email VARCHAR(255) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('user', 'admin') DEFAULT 'user',
    usuario VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 📁 Estrutura do Projeto

```
socialmusic_backend/
├── api/
│   ├── autentica.php      # Autenticação de usuários
│   ├── cadastro.php       # Cadastro de novos usuários
│   ├── logout.php         # Logout de usuários
│   ├── sem_permissao.php  # Página de acesso negado
│   └── verifica_sessao.php # Verificação de sessão
├── config/
│   └── database.php       # Configurações do banco de dados
└── README.md
```

## 🔒 Endpoints da API

### POST /api/cadastro.php
Endpoint para cadastro de novos usuários.

**Payload:**
```json
{
    "nome": "Nome do Usuário",
    "email": "usuario@exemplo.com",
    "senha": "senha123",
    "usuario": "nome_usuario"
}
```

### POST /api/autentica.php
Endpoint para autenticação de usuários.

**Payload:**
```json
{
    "email": "usuario@exemplo.com",
    "senha": "senha123"
}
```

## 🔐 Níveis de Acesso

- **user**: Usuário padrão
- **admin**: Administrador (emails com domínio @socialmusic.br)

## ⚙️ Configuração do XAMPP

1. Inicie o Apache e MySQL no painel de controle do XAMPP
2. A API estará disponível em: `http://localhost/socialmusic_backend/api/`

## 🛡️ Segurança

- Senhas são armazenadas com hash usando `password_hash()`
- Validação de dados de entrada
- Proteção contra SQL Injection usando prepared statements
- Headers CORS configurados para desenvolvimento
