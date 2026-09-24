<?php
require_once __DIR__ . '/guard.php';

$dados = json_decode(file_get_contents('php://input'));
$avaliacao_id = isset($dados->avaliacao_id) ? (int)$dados->avaliacao_id : 0;

if ($avaliacao_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'ID da avaliação é obrigatório.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM avaliacoes WHERE id = :avaliacao_id');
    $stmt->execute([':avaliacao_id' => $avaliacao_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Avaliação não encontrada.'
        ]);
        exit;
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Avaliação excluída pelo administrador.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erro em avaliacao_delete.php: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao excluir a avaliação.'
    ]);
}
?>
