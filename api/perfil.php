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
    $stmt_perfil = $pdo->prepare("SELECT id, nome, email, username, foto_perfil, generos FROM usuarios WHERE id = ?");
    $stmt_perfil->execute([$perfil_id]);
    $perfil = $stmt_perfil->fetch();

    if (!$perfil) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Perfil não encontrado.']);
        exit;
    }
    
    
    if (empty($perfil['foto_perfil'])) {
        // Se a foto de perfil estiver vazia, envia um avatar padrão
        $perfil['avatar'] = 'https://i.pravatar.cc/150?u=' . $perfil['id'];
    } else {
        // Se houver foto, envia-a com o nome 'avatar'
        $perfil['avatar'] = $perfil['foto_perfil'];
    }

    // 2. Verificar o estado "Seguir"
    $is_self = ($utilizador_logado_id == $perfil_id);
    $is_following = false;
    if (!$is_self) {
        $stmt_follow = $pdo->prepare("SELECT * FROM seguidores WHERE seguidor_id = ? AND seguido_id = ?");
        $stmt_follow->execute([$utilizador_logado_id, $perfil_id]);
        $is_following = (bool) $stmt_follow->fetch();
    }

    // 3. Contar Seguidores e Seguindo
    $stmt_following = $pdo->prepare("SELECT COUNT(*) FROM seguidores WHERE seguidor_id = ?");
    $stmt_following->execute([$perfil_id]);
    $perfil['following_count'] = $stmt_following->fetchColumn();

    $stmt_followers = $pdo->prepare("SELECT COUNT(*) FROM seguidores WHERE seguido_id = ?");
    $stmt_followers->execute([$perfil_id]);
    $perfil['followers_count'] = $stmt_followers->fetchColumn();


    // 4. Contar o total de avaliações
    $stmt_reviews_count = $pdo->prepare("SELECT COUNT(*) FROM avaliacoes WHERE usuario_id = ?");
    $stmt_reviews_count->execute([$perfil_id]);
    $perfil['total_avaliacoes'] = $stmt_reviews_count->fetchColumn();
 

    
    echo json_encode([
        'sucesso' => true,
        'perfil' => $perfil, 
        'is_self' => $is_self,
        'is_following' => $is_following
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor.', 'error' => $e->getMessage()]);
}
?>