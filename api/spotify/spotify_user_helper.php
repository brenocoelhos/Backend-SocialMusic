<?php
/**
 * Utilitários compartilhados para chamadas autenticadas do usuário ao Spotify.
 * Não produz saída por conta própria.
 */

function smSpotifyRequest(string $url, string $token, string $method = 'GET', ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL não disponível no servidor.');
    }

    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    $rawWithHeaders = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawWithHeaders === false) {
        throw new RuntimeException('Falha de conexão com o Spotify: ' . $curlError);
    }

    $headerRaw = substr($rawWithHeaders, 0, $headerSize);
    $raw = substr($rawWithHeaders, $headerSize);
    $headersMap = [];
    foreach (preg_split('/\r\n|\n|\r/', $headerRaw) as $line) {
        if (strpos($line, ':') === false) continue;
        [$name, $value] = explode(':', $line, 2);
        $headersMap[strtolower(trim($name))] = trim($value);
    }

    $data = json_decode($raw, true);
    return [
        'status' => $status,
        'data' => is_array($data) ? $data : [],
        'raw' => $raw,
        'headers' => $headersMap,
    ];
}

function smSpotifyUsuario(PDO $pdo, int $usuarioId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, spotify_id, spotify_conectado, spotify_access_token, spotify_refresh_token, spotify_token_expires
         FROM usuarios
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    return $usuario ?: null;
}

function smSpotifyAccessToken(PDO $pdo, int $usuarioId, array $usuario, bool $forcar = false): ?string
{
    $accessToken = trim((string)($usuario['spotify_access_token'] ?? ''));
    $expiresAt = !empty($usuario['spotify_token_expires']) ? strtotime((string)$usuario['spotify_token_expires']) : 0;

    if (!$forcar && $accessToken !== '' && $expiresAt > (time() + 60)) {
        return $accessToken;
    }

    $refreshToken = trim((string)($usuario['spotify_refresh_token'] ?? ''));
    if ($refreshToken === '') {
        return $accessToken !== '' && !$forcar ? $accessToken : null;
    }

    $clientId = trim((string)getenv('SPOTIFY_CLIENT_ID'));
    $clientSecret = trim((string)getenv('SPOTIFY_CLIENT_SECRET'));
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Credenciais do Spotify não configuradas no servidor.');
    }

    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Falha ao renovar token do Spotify: ' . $curlError);
    }

    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        error_log('spotify_user_helper refresh: HTTP ' . $status . ' ' . $raw);
        return null;
    }

    $newAccessToken = (string)$data['access_token'];
    $expiresIn = max(60, (int)($data['expires_in'] ?? 3600));
    $newExpiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
    $newRefreshToken = !empty($data['refresh_token']) ? (string)$data['refresh_token'] : $refreshToken;

    $stmt = $pdo->prepare(
        'UPDATE usuarios
         SET spotify_access_token = ?, spotify_refresh_token = ?, spotify_token_expires = ?
         WHERE id = ?'
    );
    $stmt->execute([$newAccessToken, $newRefreshToken, $newExpiresAt, $usuarioId]);

    return $newAccessToken;
}

function smSpotifyRequestComRefresh(PDO $pdo, int $usuarioId, array $usuario, string $url, string $method = 'GET', ?array $body = null): array
{
    $token = smSpotifyAccessToken($pdo, $usuarioId, $usuario, false);
    if (!$token) {
        return ['status' => 401, 'data' => [], 'raw' => '', 'headers' => []];
    }

    $resp = smSpotifyRequest($url, $token, $method, $body);
    if ($resp['status'] === 401) {
        $token = smSpotifyAccessToken($pdo, $usuarioId, $usuario, true);
        if ($token) {
            $resp = smSpotifyRequest($url, $token, $method, $body);
        }
    }

    return $resp;
}
