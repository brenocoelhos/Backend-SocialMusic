<?php
require_once 'header.php';
require_once 'conexao.php';

$utilizador_logado_id = $_SESSION['usuario_id'] ?? null;
if (!$utilizador_logado_id) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Utilizador não autenticado.']);
    exit;
}

$perfil_id = $_GET['id'] ?? $utilizador_logado_id;

try {
    
    $stmt_perfil = $pdo->prepare("SELECT id, nome, email, username, avatar_url, generos FROM usuarios WHERE id = ?");
    $stmt_perfil->execute([$perfil_id]);
    $perfil = $stmt_perfil->fetch();

    if (!$perfil) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Perfil não encontrado.']);
        exit;
    }
    
    
    if (empty($perfil['avatar_url'])) {
        $perfil['avatar'] = 'https://i.pravatar.cc/150?u=' . $perfil['id'];
    } else {
        $perfil['avatar'] = $perfil['avatar_url'];
    }

    
    $is_self = ($utilizador_logado_id == $perfil_id);
    $is_following = false;
    if (!$is_self) {
        $stmt_follow = $pdo->prepare("SELECT * FROM seguidores WHERE seguidor_id = ? AND seguido_id = ?");
        $stmt_follow->execute([$utilizador_logado_id, $perfil_id]);
        $is_following = (bool) $stmt_follow->fetch();
    }

    
    $stmt_following = $pdo->prepare("SELECT COUNT(*) FROM seguidores WHERE seguidor_id = ?");
    $stmt_following->execute([$perfil_id]);
    $perfil['following_count'] = $stmt_following->fetchColumn();

    $stmt_followers = $pdo->prepare("SELECT COUNT(*) FROM seguidores WHERE seguido_id = ?");
    $stmt_followers->execute([$perfil_id]);
    $perfil['followers_count'] = $stmt_followers->fetchColumn();


    
    $stmt_avaliacoes = $pdo->prepare("
        SELECT a.id, a.nota, a.titulo, a.comentario,
               m.titulo as musica_titulo, m.artista as musica_artista, m.capa_url as musica_capa
        FROM avaliacoes a
        JOIN musicas m ON a.musica_id = m.id
        WHERE a.usuario_id = ?
        ORDER BY a.data_criacao DESC LIMIT 10
    ");
    $stmt_avaliacoes->execute([$perfil_id]);
    $avaliacoes_raw = $stmt_avaliacoes->fetchAll();
    
    $avaliacoes_formatadas = [];
    foreach ($avaliacoes_raw as $row) {
         $avaliacoes_formatadas[] = [
            'musica' => [
                'titulo' => $row['musica_titulo'],
                'artista' => $row['musica_artista'],
                'capa' => $row['musica_capa']
            ],
            'nota' => (float)$row['nota'],
            'titulo' => $row['titulo'],
            'comentario' => $row['comentario'],
            'likes' => 0
        ];
    }

    
    echo json_encode([
        'sucesso' => true,
        'perfil' => $perfil, 
        'avaliacoes' => $avaliacoes_formatadas,
        'is_self' => $is_self,
        'is_following' => $is_following
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor.', 'error' => $e->getMessage()]);
}
?>