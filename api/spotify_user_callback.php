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

if (!$userData || !isset($userData['email'])) {
    header('Location: ' . $frontendUrl . '?error=' . urlencode('Email não disponível na conta Spotify'));
    exit;
}

// Redirecionar para o frontend com os dados
$queryParams = http_build_query([
    'email' => $userData['email'],
    'nome' => $userData['display_name'] ?? 'Usuário Spotify',
    'spotify_id' => $userData['id'],
    'imagem' => isset($userData['images'][0]['url']) ? $userData['images'][0]['url'] : '',
    'success' => '1'
]);

header('Location: ' . $frontendUrl . '?' . $queryParams);
exit;
?>