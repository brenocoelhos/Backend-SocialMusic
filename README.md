# 🎵 Social Music Backend

Backend completo da aplicação Social Music, desenvolvida em PHP com integração a APIs de música.

## ✨ Funcionalidades

- � **Sistema completo de autenticação** (cadastro, login, logout)
- 🎵 **API de músicas populares** (Last.fm Global Charts com imagens do iTunes)
- 🔍 **API de busca de músicas** (Spotify API)
- 📊 **Dados em tempo real** com imagens de alta qualidade
- 🔒 **Configuração segura** com arquivos .env
- 🌐 **CORS configurado** para frontend Vue.js

## �📋 Requisitos

- PHP 7.4 ou superior
- MySQL/MariaDB 
- XAMPP (ou servidor Apache configurado)
- Extensão cURL do PHP habilitada

## 🔧 Configuração Rápida

### 1. Clone e Configure
```bash
# Clone na pasta htdocs do XAMPP
git clone [url-do-repositorio] socialmusic_backend
cd socialmusic_backend
```

### 2. Configure o Arquivo .env
```bash
# Copie o exemplo
cp .env.example .env

# Edite o .env com suas credenciais
```

**Exemplo de .env:**
```env
# APIs de Música
LASTFM_API_KEY=sua_chave_lastfm
SPOTIFY_CLIENT_ID=seu_client_id_spotify
SPOTIFY_CLIENT_SECRET=seu_client_secret_spotify

# Banco de Dados
DB_HOST=localhost
DB_NAME=sistema_auth
DB_USER=root
DB_PASS=
```

### 3. Configure o Banco de Dados

3. Estrutura do banco de dados:
```sql
CREATE DATABASE sistema_auth;

USE sistema_auth;

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255),
    email VARCHAR(255) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('user', 'admin') DEFAULT 'user',
    username VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 📁 Estrutura do Projeto

```
socialmusic_backend/
├── 📁 api/                           # Endpoints da API
│   ├── autentica.php                 # Autenticação de usuários
│   ├── auth.admin.php                # Autenticação admin
│   ├── cadastro.php                  # Cadastro de novos usuários
│   ├── buscar_musicas.php           # 🔍 Busca de músicas (Spotify)
│   ├── spotify_musicas.php          # 🎵 Músicas populares (Last.fm + iTunes)
│   ├── logout.php                   # Logout de usuários
│   ├── sem_permissao.php           # Página de acesso negado
│   └── verifica_sessao.php         # Verificação de sessão
├── 📁 classes/                       # Classes principais
│   ├── LastFmAPI.php                # 🎵 Last.fm + iTunes integration
│   └── SpotifyAPI.php               # Spotify API wrapper
├── 📁 config/                        # Configurações
│   ├── database.php                 # Config do banco
│   ├── lastfm.php                   # Config Last.fm
│   └── spotify.php                  # Config Spotify  
├── 📁 database/                      # Scripts de banco
│   ├── migrate_table.php            # Migração de tabelas
│   ├── schema.sql                   # Schema do banco
│   └── setup.php                    # Setup inicial
├── 📄 .env                          # Variáveis de ambiente
├── 📄 .env.example                  # Exemplo de .env
└── 📄 README.md                     # Este arquivo
```

## � Endpoints da API

### 👤 Autenticação

#### POST /api/cadastro.php
Cadastro de novos usuários com validação completa.

**Payload:**
```json
{
    "nome": "Nome do Usuário",
    "email": "usuario@exemplo.com", 
    "senha": "senha123",
    "username": "nome_usuario"
}
```

**Resposta de Sucesso:**
```json
{
    "sucesso": true,
    "mensagem": "Conta criada com sucesso! Você já pode fazer o login."
}
```

#### POST /api/autentica.php
Autenticação de usuários registrados.

**Payload:**
```json
{
    "email": "usuario@exemplo.com",
    "senha": "senha123"
}
```

### 🎵 APIs de Música

#### GET /api/spotify_musicas.php
Busca músicas populares globais usando apenas Last.fm com imagens do iTunes.

**Parâmetros:**
- `limit=6` (opcional, 1-50)

**Exemplo:** `GET /api/spotify_musicas.php?limit=10`

**Resposta:**
```json
{
    "sucesso": true,
    "tipo": "populares", 
    "fonte": "Last.fm Global Charts",
    "musicas": [
        {
            "titulo": "Anti-Hero",
            "artista": "Taylor Swift",
            "rank": 1,
            "popularidade": 2500000,
            "capa": "https://is1-ssl.mzstatic.com/image/thumb/Music/.../600x600bb.jpg"
        }
    ]
}
```

#### GET /api/buscar_musicas.php
Busca personalizada de músicas usando Spotify API.

**Parâmetros:**
- `q` (obrigatório): termo de busca
- `limit` (opcional): resultados (1-50, padrão: 20)

**Exemplo:** `GET /api/buscar_musicas.php?q=ed+sheeran&limit=5`

**Resposta:**
```json
{
    "sucesso": true,
    "termo_busca": "ed sheeran",
    "total_resultados": 5,
    "musicas": [
        {
            "titulo": "Shape of You",
            "artista": "Ed Sheeran",
            "album": "÷ (Deluxe)",
            "capa": "https://i.scdn.co/.../640x640bb.jpg",
            "duracao": "3:53",
            "popularidade": 84,
            "preview_url": "https://p.scdn.co/.../preview.mp3",
            "spotify_url": "https://open.spotify.com/track/...",
            "lancamento": "2017-03-03"
        }
    ]
}
```

## 🎯 Integrações de APIs

### Last.fm API
- **Dados**: Top tracks globais em tempo real
- **Funcionalidade**: Ranking mundial de músicas mais ouvidas
- **Atualização**: Dados sempre atuais

### iTunes/Apple Music API
- **Imagens**: Capas de álbuns em alta qualidade (600x600)
- **Vantagem**: Gratuita, sem limites, imagens oficiais
- **Fallback**: Last.fm como backup para imagens

### Spotify API  
- **Busca**: Catálogo completo de músicas
- **Dados extras**: Duração, popularidade, preview de 30s
- **Mercado**: Configurado para Brasil (BR)

## 🔐 Níveis de Acesso

- **user**: Usuário padrão do sistema
- **admin**: Administrador (emails com domínio @socialmusic.br)

## ⚙️ Como Executar

### 1. Inicie o XAMPP
```bash
# Inicie Apache e MySQL no painel de controle
```

### 2. Acesse as APIs
```bash
# Base URL
http://localhost/socialmusic_backend/api/

# Exemplos de teste
http://localhost/socialmusic_backend/api/spotify_musicas.php
http://localhost/socialmusic_backend/api/buscar_musicas.php?q=taylor+swift&limit=5
```

### 3. Teste no Frontend
```javascript
// Exemplo Vue.js/JavaScript - Carrega junto com a página
const response = await fetch('http://localhost/socialmusic_backend/api/spotify_musicas.php?limit=6');
const data = await response.json();

if (data.sucesso) {
    data.musicas.forEach(musica => {
        console.log(`${musica.titulo} - ${musica.artista}`);
    });
}
```

## � Configuração de Ambiente

### Obter API Keys

#### Last.fm
1. Acesse: https://www.last.fm/api/account/create
2. Crie uma aplicação
3. Copie a **API Key** para o `.env`

#### Spotify
1. Acesse: https://developer.spotify.com/dashboard
2. Crie uma aplicação
3. Copie **Client ID** e **Client Secret** para o `.env`

### Exemplo Completo .env
```env
# APIs de Música
LASTFM_API_KEY=sua_chave_lastfm_aqui
SPOTIFY_CLIENT_ID=seu_client_id_aqui
SPOTIFY_CLIENT_SECRET=seu_client_secret_aqui

# Banco de Dados  
DB_HOST=localhost
DB_NAME=sistema_auth
DB_USER=root
DB_PASS=

# App Config (opcional)
APP_ENV=development
DEBUG=true
```

## 🛡️ Segurança

- ✅ **Senhas criptografadas** com `password_hash()`
- ✅ **Validação robusta** de dados de entrada  
- ✅ **Proteção SQL Injection** com prepared statements
- ✅ **CORS configurado** para desenvolvimento
- ✅ **Credenciais seguras** em arquivos .env
- ✅ **Sanitização de inputs** e validação de tipos

## 📚 Documentação Extra

- 📖 **[COMO_USAR_ENV.md](COMO_USAR_ENV.md)**: Guia completo sobre arquivos .env
- 🔧 **database/schema.sql**: Estrutura completa do banco
- ⚙️ **config/**: Arquivos de configuração organizados

## 🚀 Status do Projeto

- ✅ **Sistema de autenticação** completo
- ✅ **APIs de música** funcionais  
- ✅ **Integração com 3 APIs** externas
- ✅ **Documentação** completa
- ✅ **Pronto para produção**

## 🐳 Deploy com Docker

### Opção 1: Deploy no Render (Recomendado)

#### Passo 1: Preparar o Repositório
```bash
# Certifique-se de que os arquivos Docker estão commitados
git add Dockerfile .dockerignore
git commit -m "Add Docker configuration"
git push
```

#### Passo 2: Configurar no Render
1. Acesse [render.com](https://render.com) e faça login
2. Clique em **"New +"** → **"Web Service"**
3. Conecte seu repositório GitHub
4. Configure:
   - **Name**: `socialmusic-backend`
   - **Environment**: `Docker`
   - **Region**: Escolha a mais próxima
   - **Branch**: `main`
   - **Dockerfile Path**: `Dockerfile` (padrão)
   - **Docker Build Context Directory**: `.` (raiz do projeto)

#### Passo 3: Configurar Variáveis de Ambiente
No Render, vá em **Environment** e adicione:
```env
LASTFM_API_KEY=sua_chave_lastfm
SPOTIFY_CLIENT_ID=seu_client_id
SPOTIFY_CLIENT_SECRET=seu_client_secret
SPOTIFY_REDIRECT_URI=https://seu-app.onrender.com/api/spotify_callback_owner.php
DB_HOST=seu_mysql_host
DB_NAME=sistema_auth
DB_USER=seu_usuario
DB_PASS=sua_senha
DB_CHARSET=utf8mb4
```

#### Passo 4: Deploy
- Clique em **"Create Web Service"**
- O Render vai buildar e deployar automaticamente
- Acesse sua aplicação em: `https://seu-app.onrender.com`

### Opção 2: Desenvolvimento Local com Docker

```bash
# Build e iniciar containers
docker-compose up -d

# Acessar aplicação
# Frontend: http://localhost:8080
# MySQL: localhost:3306

# Ver logs
docker-compose logs -f

# Parar containers
docker-compose down

# Parar e remover volumes (limpar banco)
docker-compose down -v
```

### Opção 3: Build Manual do Docker

```bash
# Build da imagem
docker build -t socialmusic-backend .

# Executar container
docker run -d \
  -p 8080:80 \
  --name socialmusic \
  -e LASTFM_API_KEY=sua_chave \
  -e SPOTIFY_CLIENT_ID=seu_id \
  -e SPOTIFY_CLIENT_SECRET=seu_secret \
  socialmusic-backend

# Ver logs
docker logs -f socialmusic

# Parar container
docker stop socialmusic

# Remover container
docker rm socialmusic
```

### 📦 Banco de Dados para Produção

Para produção, recomendo usar um serviço gerenciado:

- **Render Postgres/MySQL**: Gratuito (750h/mês)
- **PlanetScale**: MySQL serverless gratuito
- **Railway**: MySQL/Postgres com tier gratuito
- **AWS RDS**: Opção profissional

**Conectar ao banco externo:**
```env
DB_HOST=seu-banco.mysql.database.azure.com
DB_NAME=sistema_auth
DB_USER=admin
DB_PASS=senha_segura
```

---

💡 **Dica**: Para dúvidas sobre .env, consulte o arquivo [COMO_USAR_ENV.md](COMO_USAR_ENV.md)
