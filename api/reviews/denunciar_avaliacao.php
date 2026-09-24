<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

$usuario_id = $_SESSION['usuario_id'] ?? null;

if (!$usuario_id) {
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Usuário não autenticado.'
    ]);
    exit;
}

$dados = json_decode(file_get_contents('php://input'));

$avaliacao_id = isset($dados->avaliacao_id) ? (int)$dados->avaliacao_id : 0;
$motivo = isset($dados->motivo) ? trim((string)$dados->motivo) : '';
$descricao = isset($dados->descricao) ? trim((string)$dados->descricao) : '';

$motivosPermitidos = [
    'spam',
    'ofensa',
    'odio',
    'ameaca',
    'conteudo_ilegal',
    'outro'
];

if ($avaliacao_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'ID da avaliação é obrigatório.'
    ]);
    exit;
}

if (!in_array($motivo, $motivosPermitidos, true)) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Selecione um motivo válido para a denúncia.'
    ]);
    exit;
}

$descricaoLength = function_exists('mb_strlen') ? mb_strlen($descricao) : strlen($descricao);

if ($descricaoLength > 500) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'A descrição da denúncia deve ter no máximo 500 caracteres.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, usuario_id FROM avaliacoes WHERE id = :avaliacao_id LIMIT 1'
    );
    $stmt->execute([':avaliacao_id' => $avaliacao_id]);
    $avaliacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$avaliacao) {
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Avaliação não encontrada.'
        ]);
        exit;
    }

    if ((int)$avaliacao['usuario_id'] === (int)$usuario_id) {
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Você não pode denunciar a própria avaliação.'
        ]);
        exit;
    }

    $stmtExistente = $pdo->prepare(
        'SELECT id FROM denuncias_avaliacoes
         WHERE usuario_id = :usuario_id AND avaliacao_id = :avaliacao_id
         LIMIT 1'
    );
    $stmtExistente->execute([
        ':usuario_id' => $usuario_id,
        ':avaliacao_id' => $avaliacao_id
    ]);

    if ($stmtExistente->fetch()) {
        http_response_code(409);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Você já denunciou esta avaliação.'
        ]);
        exit;
    }

    $stmtInsert = $pdo->prepare(
        'INSERT INTO denuncias_avaliacoes
            (avaliacao_id, usuario_id, motivo, descricao, status)
         VALUES
            (:avaliacao_id, :usuario_id, :motivo, :descricao, \'pendente\')'
    );

    $stmtInsert->execute([
        ':avaliacao_id' => $avaliacao_id,
        ':usuario_id' => $usuario_id,
        ':motivo' => $motivo,
        ':descricao' => $descricao !== '' ? $descricao : null
    ]);

    http_response_code(201);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Denúncia enviada. O administrador poderá analisá-la.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erro em denunciar_avaliacao.php: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao registrar a denúncia.'
    ]);
}
?>
