<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Carregar .env
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
    }
    
    $tipo = $_GET['tipo'] ?? 'populares';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6;
    
    // Verificar se temos autenticação OAuth do dono
    require_once __DIR__ . '/../classes/SpotifyOwnerAuth.php';
    $ownerAuth = new SpotifyOwnerAuth();
    
    // Determinar credenciais e tipo de acesso
    $clientId = $_ENV['SPOTIFY_CLIENT_ID'] ?? '';
    $clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'] ?? '';
    $accessToken = null;
    $fonte = 'Spotify (Suas Credenciais)';
    
    if ($ownerAuth->isAuthenticated()) {
        $accessToken = $ownerAuth->getAccessToken();
        $fonte = 'Spotify (Sua Conta OAuth)';
    }
    
    // Usar Spotify com credenciais apropriadas
    require_once __DIR__ . '/../classes/SpotifyAPI.php';
    
    $spotify = new SpotifyAPI($clientId, $clientSecret, $accessToken);
    
    switch ($tipo) {
        case 'populares':
            $musicas = $spotify->getMusicasPopulares($limit);
            break;
        case 'top':
            $musicas = $spotify->getTopMusicas($limit);
            break;
        case 'brasil':
            if (method_exists($spotify, 'getTopBrasil')) {
                $musicas = $spotify->getTopBrasil($limit);
            } else {
                $musicas = $spotify->getMusicasPopulares($limit);
            }
            break;
        default:
            throw new Exception('Tipo de requisição inválido');
    }
    
    echo json_encode([
        'sucesso' => true,
        'tipo' => $tipo,
        'fonte' => $fonte,
        'oauth_ativo' => $ownerAuth->isAuthenticated(),
        'musicas' => $musicas
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>