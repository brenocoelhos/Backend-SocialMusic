<?php
// api/spotify_user_auth.php - Autenticação OAuth para usuários finais

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
$frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000'; // URL do seu frontend

if (empty($clientId) || empty($clientSecret)) {
    echo json_encode(['erro' => 'Credenciais do Spotify não configuradas no .env']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'authorize') {
    // Iniciar processo de autorização do Spotify para usuário
    
    $scopes = 'user-read-private user-read-email';
    $state = bin2hex(random_bytes(16)); // Para segurança
    
    // Criar diretório temp se não existir
    if (!is_dir(__DIR__ . '/../temp')) {
        mkdir(__DIR__ . '/../temp', 0755, true);
    }
    
    // Salvar o state em arquivo temporário
    file_put_contents(__DIR__ . '/../temp/spotify_user_state_' . $state . '.txt', time());

    $redirectUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/spotify_user_callback.php';

    $authUrl = 'https://accounts.spotify.com/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'scope' => $scopes,
        'redirect_uri' => $redirectUri,
        'state' => $state
    ]);

    // Redirecionar para o Spotify
    header('Location: ' . $authUrl);
    exit;

} else {
    echo json_encode(['erro' => 'Ação inválida. Use ?action=authorize para iniciar a autenticação']);
}
?>
