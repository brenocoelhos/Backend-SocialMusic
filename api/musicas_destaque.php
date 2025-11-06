<?php
require_once 'header.php'; // Session_start()
require_once 'conexao.php'; // conexão banco de dados

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6; // Número de músicas com maiores notas a retornar

// Buscar as músicas com as maiores médias de notas
try {
    $sql = "
        SELECT
            m.id,
            m.titulo,
            m.artista,
            m.capa_url,
            AVG(a.nota) AS media_nota,
            COUNT(a.id) AS total_avaliacoes
        FROM musicas m
        JOIN avaliacoes a ON m.id = a.musica_id
        GROUP BY m.id, m.titulo, m.artista, m.capa_url
        ORDER BY media_nota DESC
        LIMIT :limit";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();

    $musicas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Arredondar a média das notas
    foreach ($musicas as $key => $musica) {
        $musicas[$key]['media_nota'] = round((float)$musica['media_nota'], 1);
    }

    echo json_encode(['sucesso' => true, 'musicas' => $musicas]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro em musicas_destaque.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}

