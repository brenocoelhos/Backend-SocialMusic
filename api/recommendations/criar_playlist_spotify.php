<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function spotifyRequest(string $url, string $token, string $method = 'GET', ?array $body = null): array
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
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Falha de conexão com o Spotify: ' . $curlError);
    }

    $data = json_decode($raw, true);
    return [
        'status' => $status,
        'data' => is_array($data) ? $data : [],
        'raw' => $raw,
    ];
}

function refreshSpotifyToken(PDO $pdo, int $usuarioId, array $usuario, bool $forcar = false): ?string
{
    $accessToken = trim((string)($usuario['spotify_access_token'] ?? ''));
    $expiresAt = !empty($usuario['spotify_token_expires']) ? strtotime($usuario['spotify_token_expires']) : 0;

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
        error_log('criar_playlist_spotify.php refresh: HTTP ' . $status . ' ' . $raw);
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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

if (!isset($_SESSION['usuario_id'])) {
    responder(401, ['sucesso' => false, 'mensagem' => 'Faça login para salvar a playlist.']);
}

$usuarioId = (int)$_SESSION['usuario_id'];

try {
    $stmt = $pdo->prepare(
        'SELECT id, spotify_conectado, spotify_access_token, spotify_refresh_token, spotify_token_expires
         FROM usuarios
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        responder(404, ['sucesso' => false, 'mensagem' => 'Usuário não encontrado.']);
    }

    if (!(int)$usuario['spotify_conectado']) {
        responder(409, [
            'sucesso' => false,
            'spotify_nao_conectado' => true,
            'mensagem' => 'Conecte sua conta Spotify para salvar esta seleção.',
        ]);
    }

    $stmt = $pdo->prepare('SELECT payload, expira_em FROM recomendacoes_cache WHERE usuario_id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $cacheRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cacheRow || strtotime((string)$cacheRow['expira_em']) <= time()) {
        responder(409, [
            'sucesso' => false,
            'mensagem' => 'Gere uma nova seleção antes de criar a playlist no Spotify.',
        ]);
    }

    $payload = json_decode((string)$cacheRow['payload'], true);
    if (!is_array($payload) || empty($payload['playlist']) || !is_array($payload['playlist'])) {
        responder(409, [
            'sucesso' => false,
            'mensagem' => 'Não há recomendações disponíveis para exportar.',
        ]);
    }

    if (!empty($payload['spotify_export']['url'])) {
        responder(200, [
            'sucesso' => true,
            'ja_criada' => true,
            'playlist_url' => $payload['spotify_export']['url'],
            'playlist_id' => $payload['spotify_export']['playlist_id'] ?? null,
            'nome' => $payload['spotify_export']['nome'] ?? 'SocialMusic — Descobertas',
            'mensagem' => 'Esta seleção já foi salva no Spotify.',
        ]);
    }

    $trackIds = [];
    foreach ($payload['playlist'] as $musica) {
        $id = trim((string)($musica['spotify_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9]{22}$/', $id)) {
            $trackIds[$id] = true;
        }
    }

    $trackIds = array_keys($trackIds);
    if (!$trackIds) {
        responder(409, ['sucesso' => false, 'mensagem' => 'Nenhuma faixa válida para exportar.']);
    }

    // O limite atual do endpoint de inclusão é 100 itens por chamada.
    $trackIds = array_slice($trackIds, 0, 100);
    $uris = array_map(fn($id) => 'spotify:track:' . $id, $trackIds);

    $accessToken = refreshSpotifyToken($pdo, $usuarioId, $usuario, false);
    if (!$accessToken) {
        responder(401, [
            'sucesso' => false,
            'necessita_autorizacao' => true,
            'mensagem' => 'Sua conexão com o Spotify precisa ser autorizada novamente.',
        ]);
    }

    $playlistName = 'SocialMusic — Descobertas';
    $playlistDescription = 'Músicas recomendadas para você pelo SocialMusic.';

    $createBody = [
        'name' => $playlistName,
        'description' => $playlistDescription,
        'public' => false,
    ];

    $create = spotifyRequest('https://api.spotify.com/v1/me/playlists', $accessToken, 'POST', $createBody);

    // Se o access token expirou antes do esperado, renova e tenta uma única vez.
    if ($create['status'] === 401) {
        $accessToken = refreshSpotifyToken($pdo, $usuarioId, $usuario, true);
        if ($accessToken) {
            $create = spotifyRequest('https://api.spotify.com/v1/me/playlists', $accessToken, 'POST', $createBody);
        }
    }

    if ($create['status'] === 403) {
        responder(403, [
            'sucesso' => false,
            'necessita_autorizacao' => true,
            'mensagem' => 'Autorize o SocialMusic a criar playlists privadas no seu Spotify.',
        ]);
    }

    if ($create['status'] !== 201 || empty($create['data']['id'])) {
        error_log('criar_playlist_spotify.php create: HTTP ' . $create['status'] . ' ' . $create['raw']);
        responder(502, [
            'sucesso' => false,
            'mensagem' => 'O Spotify não conseguiu criar a playlist agora. Tente novamente em instantes.',
        ]);
    }

    $playlistId = (string)$create['data']['id'];
    $playlistUrl = (string)($create['data']['external_urls']['spotify'] ?? ('https://open.spotify.com/playlist/' . $playlistId));

    $add = spotifyRequest(
        'https://api.spotify.com/v1/playlists/' . rawurlencode($playlistId) . '/items',
        $accessToken,
        'POST',
        ['uris' => $uris]
    );

    if ($add['status'] !== 201) {
        error_log('criar_playlist_spotify.php add: HTTP ' . $add['status'] . ' ' . $add['raw']);
        responder(502, [
            'sucesso' => false,
            'playlist_criada' => true,
            'playlist_url' => $playlistUrl,
            'mensagem' => 'A playlist foi criada, mas não foi possível adicionar as músicas. Abra o Spotify para conferir.',
        ]);
    }

    $payload['spotify_export'] = [
        'playlist_id' => $playlistId,
        'url' => $playlistUrl,
        'nome' => $playlistName,
        'criada_em' => date(DATE_ATOM),
    ];

    $stmt = $pdo->prepare('UPDATE recomendacoes_cache SET payload = ? WHERE usuario_id = ?');
    $stmt->execute([
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $usuarioId,
    ]);

    responder(201, [
        'sucesso' => true,
        'playlist_id' => $playlistId,
        'playlist_url' => $playlistUrl,
        'nome' => $playlistName,
        'total_faixas' => count($uris),
        'mensagem' => 'Playlist criada no Spotify com sucesso!',
    ]);
} catch (Throwable $e) {
    error_log('criar_playlist_spotify.php: ' . $e->getMessage());
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível criar a playlist agora.',
    ]);
}
