<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$inicio = microtime(true);
$usuarioLogadoId = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
$parametro = $_GET['username'] ?? $_GET['id'] ?? null;

if ($parametro === null || $parametro === '') {
    if (!$usuarioLogadoId) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Faça login ou informe um perfil válido.'
        ]);
        exit;
    }
    $parametro = $usuarioLogadoId;
}

try {
    // Uma única consulta retorna os dados principais, contagens e estado de follow.
    // Não há nenhuma chamada ao Spotify no caminho crítico do perfil.
    if (is_numeric($parametro)) {
        $where = 'u.id = :perfil';
        $perfilParam = (int)$parametro;
    } else {
        $where = 'u.username = :perfil';
        $perfilParam = (string)$parametro;
    }

    $sql = "
        SELECT
            u.id,
            u.nome,
            u.username,
            u.foto_perfil,
            u.generos,
            u.spotify_conectado,
            (SELECT COUNT(*) FROM seguidores s1 WHERE s1.seguidor_id = u.id) AS following_count,
            (SELECT COUNT(*) FROM seguidores s2 WHERE s2.seguido_id = u.id) AS followers_count,
            (SELECT COUNT(*) FROM avaliacoes a1 WHERE a1.usuario_id = u.id) AS avaliacoes_count,
            CASE
                WHEN :logado_id_follow IS NULL THEN 0
                WHEN :logado_id_self = u.id THEN 0
                ELSE EXISTS(
                    SELECT 1
                    FROM seguidores sf
                    WHERE sf.seguidor_id = :logado_id_exists
                      AND sf.seguido_id = u.id
                )
            END AS is_following
        FROM usuarios u
        WHERE {$where}
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':perfil', $perfilParam, is_int($perfilParam) ? PDO::PARAM_INT : PDO::PARAM_STR);
    if ($usuarioLogadoId === null) {
        $stmt->bindValue(':logado_id_follow', null, PDO::PARAM_NULL);
        $stmt->bindValue(':logado_id_self', null, PDO::PARAM_NULL);
        $stmt->bindValue(':logado_id_exists', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':logado_id_follow', $usuarioLogadoId, PDO::PARAM_INT);
        $stmt->bindValue(':logado_id_self', $usuarioLogadoId, PDO::PARAM_INT);
        $stmt->bindValue(':logado_id_exists', $usuarioLogadoId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $perfil = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$perfil) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Perfil não encontrado.']);
        exit;
    }

    $perfilId = (int)$perfil['id'];
    $isSelf = $usuarioLogadoId !== null && $usuarioLogadoId === $perfilId;
    $isFollowing = !$isSelf && (bool)$perfil['is_following'];

    unset($perfil['is_following']);
    $perfil['id'] = $perfilId;
    $perfil['spotify_conectado'] = (int)$perfil['spotify_conectado'];
    $perfil['following_count'] = (int)$perfil['following_count'];
    $perfil['followers_count'] = (int)$perfil['followers_count'];
    $perfil['avaliacoes_count'] = (int)$perfil['avaliacoes_count'];

    $duracaoMs = round((microtime(true) - $inicio) * 1000, 1);
    header('Server-Timing: perfil;dur=' . $duracaoMs);

    echo json_encode([
        'sucesso' => true,
        'perfil' => $perfil,
        'is_self' => $isSelf,
        'is_following' => $isFollowing,
        'meta' => [
            'duracao_ms' => $duracaoMs,
            'avaliacoes_separadas' => true,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Erro em perfil.php otimizado: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro de servidor ao carregar o perfil.'
    ], JSON_UNESCAPED_UNICODE);
}
