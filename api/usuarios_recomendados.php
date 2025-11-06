<?php
require_once 'header.php'; // Session_start()
require_once 'conexao.php'; // conexão banco de dados

 $usuario_logado_id = $_SESSION['usuario_id'] ?? null;
 $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5; // Número de usuários recomendados a retornar


// Para evitar erros semelhantes aos que aconteceram em outros arquivos, coloquei os placeholders com nomes únicos 
// Isa

try {
    $isFollowingAnyone = false;

    // Verifica se o usuário logado segue alguém
    if ($usuario_logado_id) {
        $stmt_check = $pdo->prepare("SELECT 1 FROM seguidores WHERE seguidor_id = ? LIMIT 1");
        $stmt_check->execute([$usuario_logado_id]);
        $isFollowingAnyone = $stmt_check->fetchColumn();
    }
    
    if($isFollowingAnyone) {
        // Buscar usuários recomendados com base em seguidores em comum
        $sql = "
            SELECT 
                u.id, u.nome, 
                u.username, 
                u.foto_perfil AS avatar,
                COUNT(DISTINCT s1.seguidor_id) AS mutual_followers
            
            FROM seguidores s1
            JOIN seguidores s2 ON s1.seguido_id = s2.seguidor_id 
            JOIN usuarios u ON s2.seguido_id = u.id 
            
            LEFT JOIN seguidores s_out ON u.id = s_out.seguido_id AND s_out.seguidor_id = :usuario_logado_id_s_out
                
            WHERE 
                s1.seguidor_id = :usuario_logado_id_s1 -- 'Eu'
                AND u.id != :usuario_logado_id_where      -- Não me recomendar
                AND s_out.seguidor_id IS NULL       -- Não recomendar quem eu já sigo
            GROUP BY u.id, u.nome, u.username, u.foto_perfil
            ORDER BY 
                mutual_followers DESC, -- Ordena por mais amigos em comum
                u.id -- Desempate
            LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    // Bind dos placeholders (usamos nomes únicos para evitar problemas com drivers PDO)
    $stmt->bindValue(':usuario_logado_id_s1', $usuario_logado_id, PDO::PARAM_INT);
    $stmt->bindValue(':usuario_logado_id_s_out', $usuario_logado_id, PDO::PARAM_INT);
    $stmt->bindValue(':usuario_logado_id_where', $usuario_logado_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    } else {
        $sql = "
            SELECT 
                u.id, 
                u.nome, 
                u.username,
                u.foto_perfil AS avatar,
                COUNT(s_in.seguidor_id) as total_seguidores
            FROM usuarios u
            LEFT JOIN seguidores s_in ON u.id = s_in.seguido_id
            LEFT JOIN seguidores s_out ON u.id = s_out.seguido_id AND s_out.seguidor_id = :usuario_logado_id
            WHERE 
                u.id != :usuario_logado_id_where
                AND s_out.seguidor_id IS NULL 
            GROUP BY u.id
            ORDER BY total_seguidores DESC
            LIMIT :limit
        ";

        $stmt = $pdo->prepare($sql);
        if ($usuario_logado_id === null) {
            $stmt->bindValue(':usuario_logado_id', null, PDO::PARAM_NULL);
            $stmt->bindValue(':usuario_logado_id_where', -1, PDO::PARAM_INT); 
        } else {
            $stmt->bindValue(':usuario_logado_id', $usuario_logado_id, PDO::PARAM_INT);
            $stmt->bindValue(':usuario_logado_id_where', $usuario_logado_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    }

    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['sucesso' => true, 'usuarios' => $usuarios]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro em usuarios_recomendados.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}