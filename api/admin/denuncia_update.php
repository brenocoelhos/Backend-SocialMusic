<?php
require_once __DIR__ . '/guard.php';

$dados = json_decode(file_get_contents('php://input'));
$denuncia_id = isset($dados->denuncia_id) ? (int)$dados->denuncia_id : 0;
$status = isset($dados->status) ? trim((string)$dados->status) : '';

$statusPermitidos = ['analisada', 'rejeitada'];

if ($denuncia_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'ID da denúncia é obrigatório.'
    ]);
    exit;
}

if (!in_array($status, $statusPermitidos, true)) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Status de denúncia inválido.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'UPDATE denuncias_avaliacoes
         SET status = :status
         WHERE id = :denuncia_id'
    );
    $stmt->execute([
        ':status' => $status,
        ':denuncia_id' => $denuncia_id
    ]);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT id FROM denuncias_avaliacoes WHERE id = :id');
        $check->execute([':id' => $denuncia_id]);

        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Denúncia não encontrada.'
            ]);
            exit;
        }
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Denúncia atualizada com sucesso.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erro em denuncia_update.php: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao atualizar a denúncia.'
    ]);
}
?>
