# Integração com Spotify - Guia de Uso

## Como funciona o fluxo de autenticação:

### 1. Iniciar autenticação no frontend
```javascript
// No seu frontend, crie um botão que redireciona para:
const iniciarAutenticacaoSpotify = () => {
    window.location.href = 'http://localhost/socialmusic_backend/api/spotify_user_auth.php?action=authorize';
};
```

### 2. Processar retorno na página spotify-register
Após a autenticação, o usuário será redirecionado para `http://localhost:3000/spotify-register` com os seguintes parâmetros:

**Em caso de sucesso:**
```
http://localhost:3000/spotify-register?email=usuario@exemplo.com&nome=João Silva&spotify_id=abc123&imagem=https://...&success=1
```

**Em caso de erro:**
```
http://localhost:3000/spotify-register?error=Descrição do erro
```

### 3. Capturar dados no frontend (React exemplo)
```javascript
// Na página spotify-register
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

const SpotifyRegister = () => {
    const [searchParams] = useSearchParams();
    const [dadosSpotify, setDadosSpotify] = useState(null);
    const [erro, setErro] = useState(null);

    useEffect(() => {
        const success = searchParams.get('success');
        const error = searchParams.get('error');

        if (success === '1') {
            // Sucesso - capturar dados
            setDadosSpotify({
                email: searchParams.get('email'),
                nome: searchParams.get('nome'),
                spotifyId: searchParams.get('spotify_id'),
                imagem: searchParams.get('imagem')
            });
        } else if (error) {
            setErro(error);
        }
    }, [searchParams]);

    const finalizarCadastro = async (dadosAdicionais) => {
        try {
            const response = await fetch('http://localhost/socialmusic_backend/api/cadastro.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ...dadosSpotify,
                    ...dadosAdicionais,
                    origem: 'spotify'
                })
            });

            const resultado = await response.json();
            
            if (resultado.sucesso) {
                // Redirecionar para login ou dashboard
                console.log('Cadastro realizado com sucesso!');
            }
        } catch (error) {
            console.error('Erro ao finalizar cadastro:', error);
        }
    };

    if (erro) {
        return <div>Erro na autenticação: {erro}</div>;
    }

    if (!dadosSpotify) {
        return <div>Carregando...</div>;
    }

    return (
        <div>
            <h2>Finalizar Cadastro</h2>
            <p>Olá, {dadosSpotify.nome}!</p>
            <p>Email: {dadosSpotify.email}</p>
            {dadosSpotify.imagem && <img src={dadosSpotify.imagem} alt="Avatar" />}
            
            {/* Formulário para dados adicionais como username, senha, etc. */}
            <form onSubmit={(e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                finalizarCadastro({
                    username: formData.get('username'),
                    senha: formData.get('senha')
                });
            }}>
                <input name="username" placeholder="Nome de usuário" required />
                <input name="senha" type="password" placeholder="Senha" required />
                <button type="submit">Finalizar Cadastro</button>
            </form>
        </div>
    );
};
```

## Configuração necessária:

### 1. No arquivo .env do backend:
```
FRONTEND_URL=http://localhost:3000
SPOTIFY_CLIENT_ID=seu_client_id
SPOTIFY_CLIENT_SECRET=seu_client_secret
```

### 2. No Spotify Developer Dashboard:
Adicione a URL de callback:
```
http://localhost/socialmusic_backend/api/spotify_user_callback.php
```

## Dados retornados:
- **email**: Email da conta Spotify
- **nome**: Nome de exibição no Spotify
- **spotify_id**: ID único do usuário no Spotify
- **imagem**: URL da foto do perfil (pode ser null)
- **success**: '1' se deu certo

## Próximos passos:
1. Modificar o endpoint `/api/cadastro.php` para aceitar dados do Spotify
2. Adicionar campo `spotify_id` na tabela de usuários
3. Implementar a verificação de contas duplicadas (por email ou spotify_id)