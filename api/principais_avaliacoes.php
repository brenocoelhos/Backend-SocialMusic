<?php
require_once 'header.php'; // Session_start()
require_once 'conexao.php'; // conexão banco de dados

$usuario_id = $_SESSION['usuario_id'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3; // Número de avaliações principais a retornar

try{
    $sql = "
        SELECT 
            a.id, a.nota, a.titulo, a.comentario,
            u.id AS usuario_id, 
            u.nome AS usuario_nome, 
            u.foto_perfil AS usuario_avatar,
            m.titulo AS musica_titulo,
            m.artista AS musica_artista,
            m.capa_url AS musica_capa,
            (SELECT COUNT(*) FROM curtidas_avaliacoes ca WHERE ca.avaliacao_id = a.id) AS total_curtidas,
            (EXISTS(SELECT 1 FROM curtidas_avaliacoes cl WHERE cl.avaliacao_id = a.id AND cl.usuario_id = :usuario_logado_id_curtidas)) AS usuario_curtiu,
            (EXISTS(SELECT 1 FROM seguidores s WHERE s.seguido_id = u.id AND s.seguidor_id = :usuario_logado_id_seguidores)) AS is_following
        FROM avaliacoes a
        JOIN usuarios u ON a.usuario_id = u.id
        JOIN musicas m ON a.musica_id = m.id
        ORDER BY total_curtidas DESC, a.data_criacao DESC
        LIMIT :limit";

        $stmt = $pdo->prepare($sql);

        if ($usuario_id === null) {
            $stmt->bindValue(':usuario_logado_id_curtidas', null, PDO::PARAM_NULL);
            $stmt->bindValue(':usuario_logado_id_seguidores', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':usuario_logado_id_curtidas', $usuario_id, PDO::PARAM_INT);
            $stmt->bindValue(':usuario_logado_id_seguidores', $usuario_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    $stmt->execute();
    $avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar as avaliações para o JSON de resposta
    $avaliacoes_formatadas = [];
    foreach ($avaliacoes as $row) {
        $avaliacoes_formatadas[] = [
            'id' => $row['id'],
            'nota' => (float)$row['nota'],
            'titulo' => $row['titulo'],
            'comentario' => $row['comentario'],
            'likes' => (int)$row['total_curtidas'],
            'usuario_curtiu' => (bool)$row['usuario_curtiu'],
            'is_following' => (bool)$row['is_following'],
            'usuario' => [
                'id' => $row['usuario_id'],
                'nome' => $row['usuario_nome'],
                'avatar' => $row['usuario_avatar']
            ],
            'musica' => [
                'titulo' => $row['musica_titulo'],
                'artista' => $row['musica_artista'],
                'capa' => $row['musica_capa']
            ]
        ];
    }
    echo json_encode(['sucesso' => true, 'avaliacoes' => $avaliacoes_formatadas]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro ao buscar principais avaliações: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>