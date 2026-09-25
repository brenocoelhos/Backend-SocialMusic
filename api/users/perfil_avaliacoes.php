<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$inicio = microtime(true);
$usuarioLogadoId = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
$perfilId = isset($_GET['perfil_id']) ? (int)$_GET['perfil_id'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3;
$limit = max(1, min(12, $limit));
$cursorRaw = trim((string)($_GET['cursor'] ?? ''));

if ($perfilId <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'perfil_id é obrigatório.']);
    exit;
}

$cursorData = null;
if ($cursorRaw !== '') {
    $normalizado = strtr($cursorRaw, '-_', '+/');
    $normalizado .= str_repeat('=', (4 - strlen($normalizado) % 4) % 4);
    $decoded = base64_decode($normalizado, true);
    if ($decoded !== false) {
        $parsed = json_decode($decoded, true);
        if (is_array($parsed) && !empty($parsed['data']) && !empty($parsed['id'])) {
            $cursorData = [
                'data' => (string)$parsed['data'],
                'id' => (int)$parsed['id'],
            ];
        }
    }
}

try {
    // Busca limit + 1 para descobrir se existe outra página sem COUNT adicional.
    $fetchLimit = $limit + 1;
    $whereCursor = '';
    if ($cursorData) {
        $whereCursor = ' AND (a.data_criacao < :cursor_data OR (a.data_criacao = :cursor_data AND a.id < :cursor_id)) ';
    }

    $sql = "
        SELECT
            a.id,
            a.nota,
            a.titulo,
            a.comentario,
            a.data_criacao,
            m.spotify_id AS musica_spotify_id,
            m.titulo AS musica_titulo,
            m.artista AS musica_artista,
            m.capa_url AS musica_capa,
            COUNT(ca.id) AS total_curtidas,
            MAX(CASE WHEN ca.usuario_id = :usuario_logado_id_case THEN 1 ELSE 0 END) AS usuario_curtiu
        FROM avaliacoes a
        INNER JOIN musicas m ON m.id = a.musica_id
        LEFT JOIN curtidas_avaliacoes ca ON ca.avaliacao_id = a.id
        WHERE a.usuario_id = :perfil_id
        {$whereCursor}
        GROUP BY
            a.id, a.nota, a.titulo, a.comentario, a.data_criacao,
            m.spotify_id, m.titulo, m.artista, m.capa_url
        ORDER BY a.data_criacao DESC, a.id DESC
        LIMIT {$fetchLimit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':perfil_id', $perfilId, PDO::PARAM_INT);
    if ($usuarioLogadoId === null) {
        $stmt->bindValue(':usuario_logado_id_case', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':usuario_logado_id_case', $usuarioLogadoId, PDO::PARAM_INT);
    }
    if ($cursorData) {
        $stmt->bindValue(':cursor_data', $cursorData['data'], PDO::PARAM_STR);
        $stmt->bindValue(':cursor_id', $cursorData['id'], PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $temMais = count($rows) > $limit;
    if ($temMais) {
        $rows = array_slice($rows, 0, $limit);
    }

    $avaliacoes = [];
    foreach ($rows as $row) {
        $spotifyId = trim((string)$row['musica_spotify_id']);
        $avaliacoes[] = [
            'id' => (int)$row['id'],
            'musica' => [
                'id' => $spotifyId,
                'titulo' => (string)$row['musica_titulo'],
                'artista' => (string)$row['musica_artista'],
                'capa' => $row['musica_capa'] ?: null,
                // Dados ricos do Spotify são buscados somente se o usuário abrir a música.
                'spotify_url' => $spotifyId !== '' ? 'https://open.spotify.com/track/' . $spotifyId : null,
                'duration_ms' => null,
                'release_date' => null,
                'popularity' => null,
                'explicit' => false,
                'album_name' => null,
                'album_type' => null,
            ],
            'nota' => (float)$row['nota'],
            'titulo' => $row['titulo'],
            'comentario' => $row['comentario'],
            'likes' => (int)$row['total_curtidas'],
            'usuario_curtiu' => (bool)$row['usuario_curtiu'],
            'data_criacao' => $row['data_criacao'],
        ];
    }

    $proximoCursor = null;
    if ($temMais && $rows) {
        $ultimo = end($rows);
        $payload = json_encode([
            'data' => $ultimo['data_criacao'],
            'id' => (int)$ultimo['id'],
        ]);
        $proximoCursor = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    $duracaoMs = round((microtime(true) - $inicio) * 1000, 1);
    header('Server-Timing: avaliacoes;dur=' . $duracaoMs);

    echo json_encode([
        'sucesso' => true,
        'avaliacoes' => $avaliacoes,
        'tem_mais' => $temMais,
        'proximo_cursor' => $proximoCursor,
        'meta' => [
            'duracao_ms' => $duracaoMs,
            'limit' => $limit,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Erro em perfil_avaliacoes.php: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao carregar as avaliações do perfil.'
    ], JSON_UNESCAPED_UNICODE);
}
