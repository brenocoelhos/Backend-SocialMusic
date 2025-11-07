<?php
require_once 'header.php';
require_once 'conexao.php';

// Parâmetros de paginação
$perfil_id = $_GET['id'] ?? null;
$page = $_GET['page'] ?? 1;
$limit = 3; // O seu pedido: carregar apenas 3 de cada vez
$offset = ($page - 1) * $limit;

if (!$perfil_id) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID do perfil é obrigatório.']);
    exit;
}

try {
   
    $stmt_avaliacoes = $pdo->prepare("
        SELECT 
            a.id, a.nota, a.titulo, a.comentario,
            m.titulo as musica_titulo, 
            m.artista as musica_artista, 
            m.capa_url as musica_capa
        FROM avaliacoes a
        LEFT JOIN musicas m ON a.musica_id = m.id
        WHERE a.usuario_id = ?
        ORDER BY a.data_criacao DESC
        LIMIT :limit OFFSET :offset
    ");

    
    $stmt_avaliacoes->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt_avaliacoes->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt_avaliacoes->bindParam(1, $perfil_id, PDO::PARAM_INT);
    
    $stmt_avaliacoes->execute();
    $avaliacoes_raw = $stmt_avaliacoes->fetchAll();
    
    // Formatar os dados
    $avaliacoes_formatadas = [];
    foreach ($avaliacoes_raw as $row) {
         $avaliacoes_formatadas[] = [
            'musica' => [
                // --- MUDANÇA AQUI: Lidar com NULLs (caso o JOIN falhe) ---
                'titulo' => $row['musica_titulo'] ?? 'Música desconhecida',
                'artista' => $row['musica_artista'] ?? 'Artista desconhecido',
                'capa' => $row['musica_capa'] ?? 'https://via.placeholder.com/150' // URL de capa padrão
            ],
            'nota' => (float)$row['nota'],
            'titulo' => $row['titulo'],
            'comentario' => $row['comentario'],
            'likes' => 0
        ];
    }
    // --- FIM DA MUDANÇA ---

    echo json_encode(['sucesso' => true, 'avaliacoes' => $avaliacoes_formatadas]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor.', 'error' => $e->getMessage()]);
}
?>