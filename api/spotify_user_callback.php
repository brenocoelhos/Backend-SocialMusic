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

if (empty($clientId) || empty($clientSecret)) {
    die('Erro: Credenciais do Spotify não configuradas');
}

// Verificar se recebeu o código de autorização
if (!isset($_GET['code']) || !isset($_GET['state'])) {
    $error = $_GET['error'] ?? 'Autorização negada';
    header('Location: ' . $frontendUrl . '/spotify-register?error=' . urlencode($error));
    exit;
}

$code = $_GET['code'];
$state = $_GET['state'];

// Verificar state por segurança
$stateFile = __DIR__ . '/../temp/spotify_user_state_' . $state . '.txt';
if (!file_exists($stateFile)) {
    header('Location: ' . $frontendUrl . '/spotify-register?error=' . urlencode('State inválido'));
    exit;
}

// Remover arquivo de state (uso único)
unlink($stateFile);

$redirectUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$redirectUri = strtok($redirectUri, '?'); // Remove query parameters

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
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    header('Location: ' . $frontendUrl . '/spotify-register?error=' . urlencode('Erro ao obter token do Spotify'));
    exit;
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    $error = $tokenData['error_description'] ?? 'Erro desconhecido ao obter token';
    header('Location: ' . $frontendUrl . '/spotify-register?error=' . urlencode($error));
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
$userHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($userHttpCode !== 200) {
    header('Location: ' . $frontendUrl . '/spotify-register?error=' . urlencode('Erro ao obter dados do usuário'));
    exit;
}

$userData = json_decode($userResponse, true);

if (!$userData || !isset($userData['email'])) {
    header('Location: ' . $frontendUrl . '/spotify-register?error=' . urlencode('Email não disponível na conta Spotify'));
    exit;
}

// Extrair dados do usuário
$spotifyData = [
    'email' => $userData['email'],
    'nome' => $userData['display_name'] ?? 'Usuário Spotify',
    'spotify_id' => $userData['id'],
    'imagem' => isset($userData['images'][0]['url']) ? $userData['images'][0]['url'] : null,
    'pais' => $userData['country'] ?? null,
    'seguidores' => $userData['followers']['total'] ?? 0
];

// Redirecionar para o frontend com os dados
$queryParams = http_build_query([
    'email' => $spotifyData['email'],
    'nome' => $spotifyData['nome'],
    'spotify_id' => $spotifyData['spotify_id'],
    'imagem' => $spotifyData['imagem'] ?? '',
    'success' => '1'
]);

header('Location: ' . $frontendUrl . '/spotify-register?' . $queryParams);
exit;
?>