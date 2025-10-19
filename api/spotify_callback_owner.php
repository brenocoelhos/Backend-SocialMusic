<?php
// api/spotify_callback_owner.php - Callback OAuth para o dono do sistema

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    echo "<h1>Erro na autorização</h1>";
    echo "<p>Erro: " . htmlspecialchars($error) . "</p>";
    echo "<p><a href='javascript:window.close()'>Fechar janela</a></p>";
    exit;
}

if (!$code || !$state) {
    echo "<h1>Parâmetros inválidos</h1>";
    echo "<p><a href='javascript:window.close()'>Fechar janela</a></p>";
    exit;
}

// Verificar se o state é válido
$savedState = file_exists(__DIR__ . '/../temp/spotify_owner_state.txt') ? 
              file_get_contents(__DIR__ . '/../temp/spotify_owner_state.txt') : '';

if ($state !== $savedState) {
    echo "<h1>State inválido</h1>";
    echo "<p>Por favor, tente novamente.</p>";
    echo "<p><a href='javascript:window.close()'>Fechar janela</a></p>";
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Spotify - Autenticação do Dono</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            padding: 20px; 
            text-align: center;
            background: linear-gradient(135deg, #1db954, #1ed760);
            color: white;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .container {
            background: rgba(255,255,255,0.1);
            padding: 30px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            margin: 0 auto;
            max-width: 500px;
        }
        .loading { color: #fff; font-size: 18px; }
        .success { color: #d4edda; font-size: 18px; }
        .error { color: #f8d7da; font-size: 18px; }
        .spotify-logo {
            font-size: 48px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="spotify-logo">🎵</div>
        <h1>Autenticando sua conta do Spotify...</h1>
        <p class="loading" id="status">Processando autorização...</p>
    </div>
    
    <script>
        // Enviar dados para o parent window se estiver em um popup
        if (window.opener) {
            window.opener.postMessage({
                type: 'spotify_owner_callback',
                code: '<?php echo htmlspecialchars($code); ?>',
                state: '<?php echo htmlspecialchars($state); ?>'
            }, '*');
            
            document.getElementById('status').innerHTML = 'Autorização concluída! Você pode fechar esta janela.';
            document.getElementById('status').className = 'success';
            
            setTimeout(() => {
                window.close();
            }, 3000);
        } else {
            // Redirecionar para a página principal se não for popup
            document.getElementById('status').innerHTML = 'Redirecionando...';
            setTimeout(() => {
                window.location.href = '../teste_auth_owner.html';
            }, 2000);
        }
    </script>
</body>
</html>