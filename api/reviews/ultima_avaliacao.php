<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

// Verifica se o usuário está logado
$usuario_id = $_SESSION['usuario_id'] ?? null;
if (!$usuario_id) {
    http_response_code(401); // Não autorizado
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado.']);
    exit;
}

// Busca a última avaliação do usuário logado
//Irei enviar a capa, mas talvez não irei usar no front 
try{
    $sql= "
        SELECT
	        a.comentario,
            a.nota,
            m.titulo AS musica_titulo,
            m.artista AS musica_artista,
            m.capa_url AS musica_capa 
    FROM avaliacoes a
    JOIN musicas m 
	    ON a.musica_id = m.id
    WHERE a.usuario_id = :usuario_id
    ORDER BY a.data_criacao DESC
    LIMIT 1";

    
    $stmt = $pdo->prepare($sql);

    $stmt->execute([':usuario_id' => $usuario_id]);

    $avaliacao = $stmt->fetch(PDO::FETCH_ASSOC);

    // Retorna a última avaliação ou null se não existir
    if ($avaliacao) {
        $avaliacao['nota'] = (float)$avaliacao['nota'];
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