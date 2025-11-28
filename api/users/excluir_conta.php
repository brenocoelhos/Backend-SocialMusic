<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

// Verifica se está logado
$usuario_id = $_SESSION['usuario_id'] ?? null;

if (!$usuario_id) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Você precisa estar logado para realizar essa ação.']);
    exit;
}

try {
    // Deleta o usuário baseado EXCLUSIVAMENTE no ID da sessão
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);

    // Se deletou, destrói a sessão e faz logout
    session_destroy();

    echo json_encode(['sucesso' => true, 'mensagem' => 'Sua conta foi excluída com sucesso.']);

} catch (PDOException $e) {
    http_response_code(500);
    // Log do erro real no servidor, mas mensagem genérica para o usuário
    error_log("Erro ao excluir conta: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao processar a exclusão. Tente novamente.']);
}
?>