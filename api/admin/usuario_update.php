<?php
require_once 'guard.php';

$dados = json_decode(file_get_contents("php://input"));

if (!$dados || empty($dados->id)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, ativo = ? WHERE id = ?");
    $stmt->execute([
        $dados->nome,
        $dados->email,
        $dados->ativo,
        $dados->id
    ]);

    echo json_encode(['sucesso' => true, 'mensagem' => 'Usuário atualizado com sucesso.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao atualizar usuário.', 'error' => $e->getMessage()]);
}
?>