<?php
require_once 'header.php';
require_once 'conexao.php';

$utilizador_logado_id = $_SESSION['usuario_id'] ?? null;
if (!$utilizador_logado_id) {
    http_response_code(401);
    exit;
}

$dados = json_decode(file_get_contents("php://input"));
$seguido_id = $dados->id ?? null; // O ID de quem queremos seguir

if (!$seguido_id || $seguido_id == $utilizador_logado_id) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID inválido ou tentativa de seguir-se a si próprio.']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO seguidores (seguidor_id, seguido_id) VALUES (?, ?)");
    $stmt->execute([$utilizador_logado_id, $seguido_id]);
    
    echo json_encode(['sucesso' => true, 'mensagem' => 'Seguido com sucesso.']);

} catch (PDOException $e) {
    // Código '23000' é violação de chave (ex: já está a seguir)
    if ($e->getCode() == 23000) {
        echo json_encode(['sucesso' => true, 'mensagem' => 'Já está a seguir este utilizador.']);
    } else {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no servidor.']);
    }
}
?>