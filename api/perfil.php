<?php
// Inclui o header.php (para session_start() e CORS)
require_once 'header.php';
require_once 'conexao.php'; 


// Endpoint protegido! Verifica se o utilizador está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['sucesso' => false, 'mensagem' => 'Utilizador não autenticado.']);
    exit;
}

// Se está aqui, o utilizador está logado. Vamos buscar o ID da sessão.
$id_utilizador = $_SESSION['usuario_id'];

try {
    // 1. Buscar os dados do perfil do utilizador (da tabela usuarios)
    $stmt_perfil = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ?");
    $stmt_perfil->execute([$id_utilizador]);
    $perfil = $stmt_perfil->fetch();

    if (!$perfil) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Perfil não encontrado.']);
        exit;
    }
    
    // Adiciona um avatar mockado (já que não o temos na BD)
    $perfil['avatar'] = 'https://randomuser.me/api/portraits/women/44.jpg'; // Pode mudar isto

    // 2. Buscar as avaliações feitas por este utilizador
    // (Junta com a tabela 'musicas' para obter os detalhes da música)
    $stmt_avaliacoes = $pdo->prepare("
        SELECT 
            a.id, a.nota, a.titulo, a.comentario,
            m.titulo as musica_titulo, 
            m.artista as musica_artista, 
            m.capa_url as musica_capa
        FROM avaliacoes a
        JOIN musicas m ON a.musica_id = m.id
        WHERE a.usuario_id = ?
        ORDER BY a.data_criacao DESC
        LIMIT 10
    ");
    $stmt_avaliacoes->execute([$id_utilizador]);
    $avaliacoes_raw = $stmt_avaliacoes->fetchAll();

    // 3. Formatar as avaliações para o formato que o Vue espera
    $avaliacoes_formatadas = [];
    foreach ($avaliacoes_raw as $row) {
        $avaliacoes_formatadas[] = [
            'musica' => [
                'titulo' => $row['musica_titulo'],
                'artista' => $row['musica_artista'],
                'capa' => $row['musica_capa']
            ],
            'titulo' => $row['titulo'],
            'comentario' => $row['comentario'],
            'likes' => 0 // A sua BD ainda não tem 'likes', deixamos 0
        ];
    }

    // 4. Enviar a resposta completa
    echo json_encode([
        'sucesso' => true,
        'perfil' => $perfil,
        'avaliacoes' => $avaliacoes_formatadas
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor ao buscar perfil.', 'error' => $e->getMessage()]);
}
?>