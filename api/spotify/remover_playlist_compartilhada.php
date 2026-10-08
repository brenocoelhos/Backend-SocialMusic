<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

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
    responder(401, ['sucesso' => false, 'mensagem' => 'Faça login para alterar suas playlists compartilhadas.']);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$playlistId = trim((string)($input['playlist_id'] ?? ''));
if ($playlistId === '') responder(400, ['sucesso' => false, 'mensagem' => 'Playlist inválida.']);

try {
    $stmt = $pdo->prepare('DELETE FROM perfil_playlists_spotify WHERE usuario_id = ? AND spotify_playlist_id = ?');
    $stmt->execute([(int)$_SESSION['usuario_id'], $playlistId]);

    responder(200, [
        'sucesso' => true,
        'removida' => $stmt->rowCount() > 0,
        'mensagem' => 'Playlist removida do seu perfil.',
    ]);
} catch (Throwable $e) {
    error_log('remover_playlist_compartilhada.php: ' . $e->getMessage());
    responder(500, ['sucesso' => false, 'mensagem' => 'Não foi possível remover a playlist agora.']);
}
