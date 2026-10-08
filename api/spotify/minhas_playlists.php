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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

if (!isset($_SESSION['usuario_id'])) {
    responder(401, ['sucesso' => false, 'mensagem' => 'Faça login para acessar suas playlists.']);
}

$usuarioId = (int)$_SESSION['usuario_id'];
$limit = max(1, min(50, (int)($_GET['limit'] ?? 50)));
$offset = max(0, min(100000, (int)($_GET['offset'] ?? 0)));

try {
    $usuario = smSpotifyUsuario($pdo, $usuarioId);
    if (!$usuario) responder(404, ['sucesso' => false, 'mensagem' => 'Usuário não encontrado.']);
    if (!(int)$usuario['spotify_conectado']) {
        responder(409, [
            'sucesso' => false,
            'spotify_nao_conectado' => true,
            'mensagem' => 'Conecte sua conta Spotify para importar playlists.',
        ]);
    }

    $url = 'https://api.spotify.com/v1/me/playlists?' . http_build_query([
        'limit' => $limit,
        'offset' => $offset,
    ], '', '&', PHP_QUERY_RFC3986);

    $resp = smSpotifyRequestComRefresh($pdo, $usuarioId, $usuario, $url);

    if ($resp['status'] === 401 || $resp['status'] === 403) {
        responder(403, [
            'sucesso' => false,
            'necessita_autorizacao' => true,
            'mensagem' => 'Autorize o SocialMusic a ler suas playlists do Spotify.',
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

    if ($resp['status'] !== 200) {
        error_log('minhas_playlists.php Spotify HTTP ' . $resp['status'] . ': ' . $resp['raw']);
        responder(502, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar suas playlists do Spotify agora.']);
    }

    $stmt = $pdo->prepare('SELECT spotify_playlist_id FROM perfil_playlists_spotify WHERE usuario_id = ?');
    $stmt->execute([$usuarioId]);
    $compartilhadas = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

    $items = $resp['data']['items'] ?? [];
    $playlists = [];

    $updateCompartilhada = $pdo->prepare(
        'UPDATE perfil_playlists_spotify
         SET nome = ?, descricao = ?, imagem_url = ?, spotify_url = ?, owner_name = ?, owner_spotify_id = ?,
             spotify_public = ?, collaborative = ?, total_items = ?, snapshot_id = ?, atualizada_em = NOW()
         WHERE usuario_id = ? AND spotify_playlist_id = ?'
    );

    foreach ($items as $p) {
        if (!is_array($p) || empty($p['id'])) continue;

        $id = (string)$p['id'];
        $imagem = (string)($p['images'][0]['url'] ?? '');
        $urlSpotify = (string)($p['external_urls']['spotify'] ?? ('https://open.spotify.com/playlist/' . $id));
        $ownerName = trim((string)($p['owner']['display_name'] ?? ''));
        if ($ownerName === '') $ownerName = 'Spotify';
        $ownerId = (string)($p['owner']['id'] ?? '');
        $totalItems = $p['items']['total'] ?? $p['tracks']['total'] ?? null;
        $public = array_key_exists('public', $p) && $p['public'] !== null ? (bool)$p['public'] : null;
        $collaborative = !empty($p['collaborative']);
        $compartilhada = isset($compartilhadas[$id]);

        $item = [
            'spotify_playlist_id' => $id,
            'nome' => (string)($p['name'] ?? 'Playlist'),
            'descricao' => (string)($p['description'] ?? ''),
            'imagem_url' => $imagem,
            'spotify_url' => $urlSpotify,
            'owner_name' => $ownerName,
            'owner_spotify_id' => $ownerId,
            'public' => $public,
            'collaborative' => $collaborative,
            'total_items' => $totalItems !== null ? (int)$totalItems : null,
            'snapshot_id' => (string)($p['snapshot_id'] ?? ''),
            'compartilhada' => $compartilhada,
            'is_owner' => $ownerId !== '' && $ownerId === (string)($usuario['spotify_id'] ?? ''),
        ];
        $playlists[] = $item;

        // Mantém os metadados das playlists já compartilhadas atualizados sem
        // fazer nenhuma chamada adicional ao Spotify.
        if ($compartilhada) {
            $updateCompartilhada->execute([
                $item['nome'],
                $item['descricao'],
                $item['imagem_url'],
                $item['spotify_url'],
                $item['owner_name'],
                $item['owner_spotify_id'],
                $item['public'] === null ? null : ($item['public'] ? 1 : 0),
                $item['collaborative'] ? 1 : 0,
                $item['total_items'],
                $item['snapshot_id'],
                $usuarioId,
                $id,
            ]);
        }
    }

    $total = (int)($resp['data']['total'] ?? count($playlists));
    $proximoOffset = ($offset + count($items)) < $total ? ($offset + count($items)) : null;

    responder(200, [
        'sucesso' => true,
        'playlists' => $playlists,
        'total' => $total,
        'offset' => $offset,
        'proximo_offset' => $proximoOffset,
    ]);
} catch (Throwable $e) {
    error_log('minhas_playlists.php: ' . $e->getMessage());
    responder(500, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar suas playlists agora.']);
}
