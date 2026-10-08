<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

$perfilId = (int)($_GET['perfil_id'] ?? 0);
if ($perfilId <= 0) responder(400, ['sucesso' => false, 'mensagem' => 'perfil_id é obrigatório.']);

try {
    $stmt = $pdo->prepare(
        'SELECT spotify_playlist_id, nome, descricao, imagem_url, spotify_url,
                owner_name, owner_spotify_id, spotify_public, collaborative, total_items,
                compartilhada_em, atualizada_em
         FROM perfil_playlists_spotify
         WHERE usuario_id = ?
         ORDER BY compartilhada_em DESC, id DESC
         LIMIT 12'
    );
    $stmt->execute([$perfilId]);

    $playlists = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $playlists[] = [
            'spotify_playlist_id' => (string)$row['spotify_playlist_id'],
            'nome' => (string)$row['nome'],
            'descricao' => (string)($row['descricao'] ?? ''),
            'imagem_url' => (string)($row['imagem_url'] ?? ''),
            'spotify_url' => (string)$row['spotify_url'],
            'owner_name' => (string)($row['owner_name'] ?? 'Spotify'),
            'owner_spotify_id' => (string)($row['owner_spotify_id'] ?? ''),
            'public' => $row['spotify_public'] === null ? null : (bool)$row['spotify_public'],
            'collaborative' => (bool)$row['collaborative'],
            'total_items' => $row['total_items'] !== null ? (int)$row['total_items'] : null,
            'compartilhada_em' => $row['compartilhada_em'],
        ];
    }

    responder(200, ['sucesso' => true, 'playlists' => $playlists]);
} catch (Throwable $e) {
    error_log('perfil_playlists.php: ' . $e->getMessage());
    responder(500, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar as playlists do perfil.']);
}
