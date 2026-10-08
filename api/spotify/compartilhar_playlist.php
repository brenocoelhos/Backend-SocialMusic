<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/spotify_user_helper.php';

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

if (!isset($_SESSION['usuario_id'])) {
    responder(401, ['sucesso' => false, 'mensagem' => 'Faça login para compartilhar uma playlist.']);
}

$usuarioId = (int)$_SESSION['usuario_id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$playlistId = trim((string)($input['playlist_id'] ?? ''));

if ($playlistId === '' || !preg_match('/^[A-Za-z0-9]{10,64}$/', $playlistId)) {
    responder(400, ['sucesso' => false, 'mensagem' => 'Playlist inválida.']);
}

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM perfil_playlists_spotify WHERE usuario_id = ?');
    $stmt->execute([$usuarioId]);
    $totalCompartilhadas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT 1 FROM perfil_playlists_spotify WHERE usuario_id = ? AND spotify_playlist_id = ? LIMIT 1');
    $stmt->execute([$usuarioId, $playlistId]);
    $jaExiste = (bool)$stmt->fetchColumn();

    if (!$jaExiste && $totalCompartilhadas >= 12) {
        responder(409, [
            'sucesso' => false,
            'limite_atingido' => true,
            'mensagem' => 'Você pode compartilhar até 12 playlists no perfil. Remova uma para adicionar outra.',
        ]);
    }

    $usuario = smSpotifyUsuario($pdo, $usuarioId);
    if (!$usuario || !(int)$usuario['spotify_conectado']) {
        responder(409, [
            'sucesso' => false,
            'spotify_nao_conectado' => true,
            'mensagem' => 'Conecte sua conta Spotify para compartilhar playlists.',
        ]);
    }

    $resp = smSpotifyRequestComRefresh(
        $pdo,
        $usuarioId,
        $usuario,
        'https://api.spotify.com/v1/playlists/' . rawurlencode($playlistId)
    );

    if ($resp['status'] === 401 || $resp['status'] === 403) {
        responder(403, [
            'sucesso' => false,
            'necessita_autorizacao' => true,
            'mensagem' => 'Autorize o SocialMusic a ler esta playlist do Spotify.',
        ]);
    }

    if ($resp['status'] === 429) {
        $retry = (int)($resp['headers']['retry-after'] ?? 10);
        responder(429, [
            'sucesso' => false,
            'retry_after' => max(1, $retry),
            'mensagem' => 'O Spotify pediu uma pausa antes de novas consultas. Tente novamente em instantes.',
        ]);
    }

    if ($resp['status'] !== 200 || empty($resp['data']['id'])) {
        error_log('compartilhar_playlist.php Spotify HTTP ' . $resp['status'] . ': ' . $resp['raw']);
        responder(502, ['sucesso' => false, 'mensagem' => 'O Spotify não conseguiu fornecer os dados desta playlist agora.']);
    }

    $p = $resp['data'];
    $imagem = (string)($p['images'][0]['url'] ?? '');
    $urlSpotify = (string)($p['external_urls']['spotify'] ?? ('https://open.spotify.com/playlist/' . $playlistId));
    $ownerName = trim((string)($p['owner']['display_name'] ?? ''));
    if ($ownerName === '') $ownerName = 'Spotify';
    $ownerId = (string)($p['owner']['id'] ?? '');
    $totalItems = $p['items']['total'] ?? $p['tracks']['total'] ?? null;
    $public = array_key_exists('public', $p) && $p['public'] !== null ? (bool)$p['public'] : null;
    $collaborative = !empty($p['collaborative']);

    $stmt = $pdo->prepare(
        'INSERT INTO perfil_playlists_spotify
            (usuario_id, spotify_playlist_id, nome, descricao, imagem_url, spotify_url,
             owner_name, owner_spotify_id, spotify_public, collaborative, total_items, snapshot_id,
             compartilhada_em, atualizada_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            nome = VALUES(nome), descricao = VALUES(descricao), imagem_url = VALUES(imagem_url),
            spotify_url = VALUES(spotify_url), owner_name = VALUES(owner_name), owner_spotify_id = VALUES(owner_spotify_id),
            spotify_public = VALUES(spotify_public), collaborative = VALUES(collaborative),
            total_items = VALUES(total_items), snapshot_id = VALUES(snapshot_id), atualizada_em = NOW()'
    );
    $stmt->execute([
        $usuarioId,
        $playlistId,
        (string)($p['name'] ?? 'Playlist'),
        (string)($p['description'] ?? ''),
        $imagem,
        $urlSpotify,
        $ownerName,
        $ownerId,
        $public === null ? null : ($public ? 1 : 0),
        $collaborative ? 1 : 0,
        $totalItems !== null ? (int)$totalItems : null,
        (string)($p['snapshot_id'] ?? ''),
    ]);

    responder($jaExiste ? 200 : 201, [
        'sucesso' => true,
        'playlist' => [
            'spotify_playlist_id' => $playlistId,
            'nome' => (string)($p['name'] ?? 'Playlist'),
            'descricao' => (string)($p['description'] ?? ''),
            'imagem_url' => $imagem,
            'spotify_url' => $urlSpotify,
            'owner_name' => $ownerName,
            'owner_spotify_id' => $ownerId,
            'public' => $public,
            'collaborative' => $collaborative,
            'total_items' => $totalItems !== null ? (int)$totalItems : null,
        ],
        'mensagem' => $jaExiste ? 'Playlist atualizada no perfil.' : 'Playlist compartilhada no seu perfil!',
    ]);
} catch (Throwable $e) {
    error_log('compartilhar_playlist.php: ' . $e->getMessage());
    responder(500, ['sucesso' => false, 'mensagem' => 'Não foi possível compartilhar a playlist agora.']);
}
