<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

$utilizador_logado_id = $_SESSION['usuario_id'] ?? null;
if (!$utilizador_logado_id) {
    http_response_code(401);
    exit;
}

$dados = json_decode(file_get_contents("php://input"));
$seguido_id = $dados->id ?? null; // O ID de quem queremos deixar de seguir

if (!$seguido_id) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID inválido.']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM seguidores WHERE seguidor_id = ? AND seguido_id = ?");
    $stmt->execute([$utilizador_logado_id, $seguido_id]);
    
    echo json_encode(['sucesso' => true, 'mensagem' => 'Deixou de seguir com sucesso.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no servidor.']);
}
?>