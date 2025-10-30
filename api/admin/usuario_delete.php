<?php
require_once 'guard.php';

$dados = json_decode(file_get_contents("php://input"));

if (!$dados || empty($dados->id)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID do usuário não fornecido.']);
    exit;
}

// Proteção extra: Não permitir que o admin se auto-delete por esta rota
if ($dados->id == $_SESSION['usuario_id']) {
     http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Você não pode excluir sua própria conta por aqui.']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$dados->id]);

    echo json_encode(['sucesso' => true, 'mensagem' => 'Usuário excluído com sucesso.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao excluir usuário.', 'error' => $e->getMessage()]);
}
?>