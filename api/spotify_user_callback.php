<?php
// api/spotify_user_callback.php - Callback OAuth para usuários finais

// Carregar .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value, '"'));
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$clientId = $_ENV['SPOTIFY_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'] ?? '';
$frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
$redirectUri = $_ENV['SPOTIFY_USER_REDIRECT_URI'] ?? 'https://backend-socialmusic.onrender.com/api/spotify_user_callback.php';

if (empty($clientId) || empty($clientSecret)) {
    header('Location: ' . $frontendUrl . '?error=' . urlencode('Credenciais do Spotify não configuradas'));
    exit;
}

// Verificar se recebeu o código de autorização
if (!isset($_GET['code']) || !isset($_GET['state'])) {
    $error = $_GET['error'] ?? 'Autorização negada';
    header('Location: ' . $frontendUrl . '?error=' . urlencode($error));
    exit;
}

$code = $_GET['code'];
$state = $_GET['state'];

// Verificar state por segurança
$savedState = file_exists(__DIR__ . '/../temp/spotify_user_state.txt') ? 
              file_get_contents(__DIR__ . '/../temp/spotify_user_state.txt') : '';

if ($state !== $savedState) {
    header('Location: ' . $frontendUrl . '?error=' . urlencode('State inválido'));
    exit;
}

// Recuperar o mode (login ou register)
$mode = file_exists(__DIR__ . '/../temp/spotify_user_mode.txt') ? 
        file_get_contents(__DIR__ . '/../temp/spotify_user_mode.txt') : 'register';

// Trocar code por token
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirectUri,
    'client_id' => $clientId,
    'client_secret' => $clientSecret
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);

$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    $error = $tokenData['error_description'] ?? 'Erro ao obter token';
    header('Location: ' . $frontendUrl . '?error=' . urlencode($error));
    exit;
}

// Buscar informações do usuário no Spotify
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/me');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $tokenData['access_token']
]);

$userResponse = curl_exec($ch);
curl_close($ch);

$userData = json_decode($userResponse, true);

// DEBUG: Log dos dados retornados pelo Spotify
error_log('=== SPOTIFY USER DATA DEBUG ===');
error_log('HTTP Code: ' . curl_getinfo(curl_init(), CURLINFO_HTTP_CODE));
error_log('Response: ' . $userResponse);
error_log('Parsed Data: ' . json_encode($userData, JSON_PRETTY_PRINT));
error_log('Has email: ' . (isset($userData['email']) ? 'YES - ' . $userData['email'] : 'NO'));
error_log('================================');

if (!$userData || !isset($userData['email'])) {
    // Tentar alternativas para o email
    $email = null;
    
    if (isset($userData['email'])) {
        $email = $userData['email'];
    } elseif (isset($userData['id'])) {
        // Usar o ID do Spotify + domínio fake como fallback
        $email = $userData['id'] . '@spotify.local';
        error_log('Email não disponível, usando ID como fallback: ' . $email);
    }
    
    if (!$email) {
        $errorMessage = 'Dados incompletos do Spotify. Debug: ' . json_encode($userData);
        error_log($errorMessage);
        header('Location: ' . $frontendUrl . '?error=' . urlencode('Email não disponível na conta Spotify'));
        exit;
    }
    
    // Usar o email alternativo
    $userData['email'] = $email;
}

// Conectar ao banco para verificar se o usuário já existe
require_once __DIR__ . '/conexao.php';

$email = $userData['email'];
$spotifyId = $userData['id'];

try {
    // Verificar se o usuário já existe (PRIORIDADE: spotify_id, depois email)
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE spotify_id = ? OR email = ?");
    $stmt->execute([$spotifyId, $email]);
    $usuarioExistente = $stmt->fetch();

    if ($mode === 'login') {
        // MODO LOGIN
        if ($usuarioExistente) {
            // Usuário existe - fazer login automático
            
            // Atualizar spotify_conectado se necessário
            if (!$usuarioExistente['spotify_conectado']) {
                $updateStmt = $pdo->prepare("UPDATE usuarios SET spotify_conectado = 1, spotify_id = ? WHERE id = ?");
                $updateStmt->execute([$spotifyId, $usuarioExistente['id']]);
            }

            // Criar sessão igual ao login normal
            session_start();
            session_regenerate_id(true);
            
            $_SESSION['usuario_logado'] = true;
            $_SESSION['usuario_id'] = $usuarioExistente['id'];
            $_SESSION['usuario_perfil'] = $usuarioExistente['perfil'];

            // Redirecionar com dados de login (SEM success=1 para não confundir com cadastro)
            $queryParams = http_build_query([
                'spotify_login' => 'success',
                'user_name' => $usuarioExistente['nome']
            ]);

            header('Location: ' . $frontendUrl . '?' . $queryParams);
            
        } else {
            // Usuário não existe - erro no login
            header('Location: ' . $frontendUrl . '?error=' . urlencode('Conta não encontrada. Faça o cadastro primeiro.'));
        }
        
    } else {
        // MODO REGISTER
        if ($usuarioExistente) {
            // Usuário já existe - redirecionar para login
            header('Location: ' . $frontendUrl . '?error=' . urlencode('Esta conta já existe. Faça login.'));
            
        } else {
            // Usuário não existe - prosseguir com cadastro
            $queryParams = http_build_query([
                'email' => $userData['email'],
                'nome' => $userData['display_name'] ?? 'Usuário Spotify',
                'spotify_id' => $userData['id'],
                'imagem' => isset($userData['images'][0]['url']) ? $userData['images'][0]['url'] : '',
                'success' => '1',
                'action' => 'register',
                'email_fallback' => strpos($userData['email'], '@spotify.local') !== false ? '1' : '0'
            ]);

            header('Location: ' . $frontendUrl . '?' . $queryParams);
        }
    }
    
} catch (Exception $e) {
    header('Location: ' . $frontendUrl . '?error=' . urlencode('Erro interno: ' . $e->getMessage()));
    exit;
}
exit;
?>