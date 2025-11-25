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

// Pega o ID da avaliação a ser curtida/descurtida
$dados = json_decode(file_get_contents("php://input"));
$avaliacao_id = $dados->avaliacao_id ?? null;

if (!$avaliacao_id) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID da avaliação é obrigatório.']);
    exit;
}

// Lógica Principal: Tentar curtir a avaliação
try {
    $stmt_insert = $pdo->prepare("INSERT INTO curtidas_avaliacoes (usuario_id, avaliacao_id) VALUES (?, ?)");
    $stmt_insert->execute([$usuario_id, $avaliacao_id]);
    
    $stmt_count = $pdo->prepare("SELECT COUNT(*) AS total_curtidas FROM curtidas_avaliacoes WHERE avaliacao_id = ?");
    $stmt_count->execute([$avaliacao_id]);
    $total = $stmt_count->fetchColumn();

    echo json_encode(['sucesso' => true, 'curtido' => true, 'total_curtidas' => $total]);

} catch (PDOException $e) {
    // Se o erro for de chave duplicada, significa que o usuário já curtiu, então descurtir
    if ($e->getCode() == 23000) {
        $stmt_delete = $pdo->prepare("DELETE FROM curtidas_avaliacoes WHERE usuario_id = ? AND avaliacao_id = ?");
        $stmt_delete->execute([$usuario_id, $avaliacao_id]);
        
        // Contar o total de curtidas após descurtir
        $stmt_count = $pdo->prepare("SELECT COUNT(*) AS total_curtidas FROM curtidas_avaliacoes WHERE avaliacao_id = ?");
        $stmt_count->execute([$avaliacao_id]);
        $total = $stmt_count->fetchColumn();

        echo json_encode(['sucesso' => true, 'curtido' => false, 'total_curtidas' => $total]);
    } else {
        // Outro erro de banco de dados
        http_response_code(500);
        error_log("Erro ao curtir_avaliacao.php: " . $e->getMessage());
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao processar a solicitação.']);
    }
}
?>