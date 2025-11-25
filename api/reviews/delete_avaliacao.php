<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

// Verificar se o usuário está logado
$usuario_id = $_SESSION['usuario_id'] ?? null;
if (!$usuario_id) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado.']);
    exit;
}

// Pegar o ID da avaliação do POST
$dados = json_decode(file_get_contents("php://input"));
$avaliacao_id = $dados->avaliacao_id ?? null;

if (!$avaliacao_id) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID da avaliação é obrigatório.']);
    exit;
}


try {
    // Apenas o próprio usuário pode apagar a sua avaliação
    $sql = "DELETE FROM avaliacoes WHERE id = :avaliacao_id AND usuario_id = :usuario_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':avaliacao_id' => $avaliacao_id,
        ':usuario_id' => $usuario_id
    ]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['sucesso' => true, 'mensagem' => 'Avaliação excluída com sucesso.']);
    } else {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Você não tem permissão para excluir esta avaliação ou ela não existe.']);
    }

} catch (Exception $e) { 
    http_response_code(500);
    error_log("Erro em delete_avaliacao.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'error' => 'Ocorreu um erro no servidor: ' . $e->getMessage()]);
}
?>