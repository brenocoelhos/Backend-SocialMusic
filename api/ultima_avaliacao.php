<?php
require_once 'header.php'; // Session_start()
require_once 'conexao.php'; // conexão banco de dados

// Verifica se o usuário está logado
$usuario_id = $_SESSION['usuario_id'] ?? null;
if (!$usuario_id) {
    http_response_code(401); // Não autorizado
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado.']);
    exit;
}

// Busca a última avaliação do usuário logado
try{
    $sql= "
        SELECT
	        a.comentario,
            u.nome as usuario_nome,
            u.username as usuario_username,
            u.foto_perfil as usuario_avatar
    FROM usuarios u
    JOIN avaliacoes a 
	    ON u.id = a.usuario_id
    WHERE u.id = :usuario_id
    ORDER BY a.data_criacao DESC
    LIMIT 1";

    
    $stmt = $pdo->prepare($sql);

    $stmt->execute([':usuario_id' => $usuario_id]);

    $avaliacao = $stmt->fetch(PDO::FETCH_ASSOC);

    // Retorna a última avaliação ou null se não existir
    if ($avaliacao) {
        echo json_encode(['sucesso' => true, 'avaliacao' => $avaliacao]);
    } else {
        echo json_encode(['sucesso' => true, 'avaliacao' => null]);
    }

}catch (Exception $e) {
    http_response_code(500);
    error_log("Erro em ultima_avaliacao.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>