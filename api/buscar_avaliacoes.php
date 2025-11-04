<?php
require_once 'header.php'; // Session_start()
require_once 'conexao.php'; // conexão banco de dados

// Pega o ID do usuário logado (pode ser null se não estiver logado)
$usuario_logado_id = $_SESSION['usuario_id'] ?? null;
// Pegar o spotify_id da URL
$spotify_id = $_GET['spotify_id'] ?? null;

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3; // Número de avaliações por página
$offset = ($page - 1) * $limit;


if (!$spotify_id) {
    http_response_code(400);
    echo json_encode(['erro' => 'spotify_id é obrigatório.']);
    exit;
}

try{
    // Encontrar o ID local da música
    $stmt_musica = $pdo->prepare("SELECT id FROM musicas WHERE spotify_id = :spotify_id");
    $stmt_musica->execute([':spotify_id' => $spotify_id]);
    $musica = $stmt_musica->fetch();

    if (!$musica) {
        // Música não existe, logo não há avaliações
        echo json_encode(['stats' => ['total' => 0, 'media' => 0.0],'avaliacoes' => []]);
        exit;
    }
    $musica_id_local = $musica['id'];

    //Buscar total e média das avaliações
    $stmt_stats = $pdo->prepare("SELECT COUNT(*) AS total_avaliacoes, AVG(nota) AS media_notas FROM avaliacoes WHERE musica_id = :musica_id");
    $stmt_stats->execute([':musica_id' => $musica_id_local]);
    $stats_raw = $stmt_stats->fetch(PDO::FETCH_ASSOC);

    $stats = [
        'total' => (int) $stats_raw['total_avaliacoes'],
        'media' => $stats_raw['media_notas'] ? round((float) $stats_raw['media_notas'], 1) : 0.0
    ];

    //Buscar a lista de avaliações com informações do usuário e curtidas 
    $sql_avaliacoes = "
        SELECT 
            a.id, a.nota, a.titulo, a.comentario, a.data_criacao,
            u.id as usuario_id, 
            u.nome as usuario_nome,
            u.username as usuario_username,
            u.foto_perfil as usuario_avatar,
            CASE WHEN s.seguidor_id IS NOT NULL THEN TRUE ELSE FALSE END AS is_following,
            (SELECT COUNT(*) FROM curtidas_avaliacoes ca WHERE ca.avaliacao_id = a.id) AS total_curtidas,
            (EXISTS(SELECT 1 FROM curtidas_avaliacoes cl WHERE cl.avaliacao_id = a.id AND cl.usuario_id = :usuario_logado_id)) AS usuario_curtiu
        FROM avaliacoes a
        JOIN usuarios u ON a.usuario_id = u.id
        LEFT JOIN seguidores s ON s.seguido_id = u.id AND s.seguidor_id = :usuario_logado_id 
        WHERE a.musica_id = :musica_id
        ORDER BY a.data_criacao DESC
        LIMIT :limit OFFSET :offset";
    
    $stmt_avaliacoes = $pdo->prepare($sql_avaliacoes);
    
    // Adiciona uma verificação para $usuario_logado_id
    if ($usuario_logado_id === null) {
        $stmt_avaliacoes->bindValue(':usuario_logado_id', null, PDO::PARAM_NULL);
    } else {
        $stmt_avaliacoes->bindValue(':usuario_logado_id', $usuario_logado_id, PDO::PARAM_INT);
    }
    
    // Bind dos outros valores NOMEADOS
    $stmt_avaliacoes->bindValue(':musica_id', $musica_id_local, PDO::PARAM_INT); 
    $stmt_avaliacoes->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt_avaliacoes->bindValue(':offset', $offset, PDO::PARAM_INT); 
    
    $stmt_avaliacoes->execute();
    $avaliacoes = $stmt_avaliacoes->fetchAll(PDO::FETCH_ASSOC);

    // Mapeia os valores booleanos para true/false
    foreach ($avaliacoes as $key => $review){
        $avaliacoes[$key]['is_following'] = (bool)$review['is_following'];
        $avaliacoes[$key]['usuario_curtiu'] = (bool)$review['usuario_curtiu'];
    }

    // Retornar os dados
    echo json_encode(['stats' => $stats, 'avaliacoes' => $avaliacoes]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro ao buscar_avaliacoes.php: " . $e->getMessage());
    echo json_encode(['erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>