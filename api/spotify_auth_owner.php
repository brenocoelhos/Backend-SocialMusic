<?php
// api/spotify_auth_owner.php - Autenticação OAuth para o dono do sistema

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
$redirectUri = $_ENV['SPOTIFY_REDIRECT_URI'] ?? 'http://localhost/socialmusic_backend/api/spotify_callback_owner.php';

if (empty($clientId) || empty($clientSecret)) {
    echo json_encode(['erro' => 'Credenciais do Spotify não configuradas no .env']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'authorize_url') {
    // Gerar URL de autorização do Spotify para o dono
    
    $scopes = 'playlist-read-private playlist-read-collaborative user-read-private user-read-email user-top-read user-read-recently-played';
    $state = bin2hex(random_bytes(16)); // Para segurança
    
    // Salvar o state em arquivo temporário (mais seguro que sessão para este caso)
    file_put_contents(__DIR__ . '/../temp/spotify_owner_state.txt', $state);

    $authUrl = 'https://accounts.spotify.com/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'scope' => $scopes,
        'redirect_uri' => $redirectUri,
        'state' => $state
    ]);

    echo json_encode([
        'sucesso' => true,
        'auth_url' => $authUrl,
        'state' => $state,
        'scopes' => $scopes
    ]);

} elseif ($method === 'POST' && isset($_POST['code'])) {
    // Processar callback de autorização
    
    $code = $_POST['code'];
    $state = $_POST['state'];

    // Verificar state por segurança
    $savedState = file_exists(__DIR__ . '/../temp/spotify_owner_state.txt') ? 
                  file_get_contents(__DIR__ . '/../temp/spotify_owner_state.txt') : '';
    
    if ($state !== $savedState) {
        echo json_encode(['erro' => 'State inválido']);
        exit;
    }

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

    if (isset($tokenData['access_token'])) {
        // Salvar tokens em arquivo seguro
        $ownerTokens = [
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expires_at' => time() + ($tokenData['expires_in'] ?? 3600),
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'scope' => $tokenData['scope'] ?? '',
            'authenticated_at' => date('Y-m-d H:i:s')
        ];
        
        // Criar diretório temp se não existir
        if (!is_dir(__DIR__ . '/../temp')) {
            mkdir(__DIR__ . '/../temp', 0755, true);
        }
        
        file_put_contents(
            __DIR__ . '/../temp/spotify_owner_tokens.json', 
            json_encode($ownerTokens, JSON_PRETTY_PRINT)
        );
        
        // Buscar informações do usuário
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $tokenData['access_token']
        ]);
        
        $userResponse = curl_exec($ch);
        curl_close($ch);
        
        $userData = json_decode($userResponse, true);

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Sua conta do Spotify foi autenticada com sucesso!',
            'usuario_info' => [
                'nome' => $userData['display_name'] ?? 'N/A',
                'email' => $userData['email'] ?? 'N/A',
                'id' => $userData['id'] ?? 'N/A',
                'seguidores' => $userData['followers']['total'] ?? 0
            ],
            'scopes' => explode(' ', $tokenData['scope'] ?? '')
        ]);
    } else {
        echo json_encode(['erro' => 'Erro ao obter token: ' . ($tokenData['error_description'] ?? 'Erro desconhecido')]);
    }

} elseif ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'status') {
    // Verificar status da autenticação usando a classe que tem renovação automática
    
    require_once __DIR__ . '/../classes/SpotifyOwnerAuth.php';
    
    try {
        $ownerAuth = new SpotifyOwnerAuth();
        
        // Verificar se está autenticado (isso já tenta renovar automaticamente)
        $isAuthenticated = $ownerAuth->isAuthenticated();
        
        if ($isAuthenticated) {
            $tokenInfo = $ownerAuth->getTokenInfo();
            
            echo json_encode([
                'autenticado' => true,
                'expira_em' => $tokenInfo['expires_at'] - time(),
                'expira_em_texto' => gmdate('H:i:s', $tokenInfo['expires_at'] - time()),
                'autenticado_em' => $tokenInfo['authenticated_at'] ?? 'N/A',
                'scopes' => isset($tokenInfo['scope']) ? explode(' ', $tokenInfo['scope']) : [],
                'renovacao_automatica' => 'Ativa'
            ]);
        } else {
            $tokenFile = __DIR__ . '/../temp/spotify_owner_tokens.json';
            
            if (file_exists($tokenFile)) {
                $tokens = json_decode(file_get_contents($tokenFile), true);
                
                echo json_encode([
                    'autenticado' => false,
                    'expira_em' => 0,
                    'expira_em_texto' => 'Expirado',
                    'autenticado_em' => $tokens['authenticated_at'] ?? 'N/A',
                    'scopes' => isset($tokens['scope']) ? explode(' ', $tokens['scope']) : [],
                    'mensagem' => 'Token expirado e renovação falhou. Nova autorização necessária.',
                    'tem_refresh_token' => isset($tokens['refresh_token']) ? 'Sim' : 'Não'
                ]);
            } else {
                echo json_encode([
                    'autenticado' => false,
                    'mensagem' => 'Nenhuma autenticação encontrada'
                ]);
            }
        }
    } catch (Exception $e) {
        echo json_encode([
            'autenticado' => false,
            'erro' => 'Erro ao verificar autenticação: ' . $e->getMessage()
        ]);
    }
    
} else {
    echo json_encode(['erro' => 'Método ou parâmetros inválidos']);
}
?>