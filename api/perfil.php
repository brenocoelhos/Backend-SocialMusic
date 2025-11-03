<?php
require_once 'header.php';
require_once 'conexao.php';

// Endpoint protegido! Verifica se o utilizador está logado.
$utilizador_logado_id = $_SESSION['usuario_id'] ?? null;
if (!$utilizador_logado_id) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Utilizador não autenticado.']);
    exit;
}

// 1. Descobrir qual perfil estamos a ver
// (Se o ?id= não for enviado, assume que é o perfil do próprio utilizador)
$perfil_id = $_GET['id'] ?? $utilizador_logado_id;

try {
    // 2. Buscar os dados do perfil
    $stmt_perfil = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE id = ?");
    $stmt_perfil->execute([$perfil_id]);
    $perfil = $stmt_perfil->fetch();

    if (!$perfil) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Perfil não encontrado.']);
        exit;
    }
    
    $perfil['avatar'] = 'https://randomuser.me/api/portraits/women/44.jpg'; // Mock

    // 3. Verificar o estado "Seguir"
    $is_self = ($utilizador_logado_id == $perfil_id);
    $is_following = false;

    if (!$is_self) {
        $stmt_follow = $pdo->prepare(
            "SELECT * FROM seguidores WHERE seguidor_id = ? AND seguido_id = ?"
        );
        $stmt_follow->execute([$utilizador_logado_id, $perfil_id]);
        if ($stmt_follow->fetch()) {
            $is_following = true;
        }
    }

    // 4. Buscar as avaliações do perfil (do utilizador que estamos a VER)
    $stmt_avaliacoes = $pdo->prepare("
        SELECT a.id, a.titulo, a.comentario, m.titulo as musica_titulo, 
               m.artista as musica_artista, m.capa_url as musica_capa
        FROM avaliacoes a
        JOIN musicas m ON a.musica_id = m.id
        WHERE a.usuario_id = ?
        ORDER BY a.data_criacao DESC LIMIT 10
    ");
    $stmt_avaliacoes->execute([$perfil_id]);
    $avaliacoes_raw = $stmt_avaliacoes->fetchAll();
    
    // (Formatar avaliações...)
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
            'likes' => 0
        ];
    }

    // 5. Enviar a resposta completa
    echo json_encode([
        'sucesso' => true,
        'perfil' => $perfil,
        'avaliacoes' => $avaliacoes_formatadas,
        'is_self' => $is_self, // É o meu próprio perfil?
        'is_following' => $is_following // Eu estou a seguir esta pessoa?
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor.', 'error' => $e->getMessage()]);
}
?>