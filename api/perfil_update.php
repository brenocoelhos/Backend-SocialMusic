<?php
require_once 'header.php';
require_once 'conexao.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Utilizador não autenticado.']);
    exit;
}
$id_utilizador = $_SESSION['usuario_id'];
$dados = json_decode(file_get_contents("php://input"));


$novo_nome = $dados->nome ?? null;
$novos_generos = $dados->generos ?? ''; 


if (empty($novo_nome)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'O nome não pode estar vazio.']);
    exit;
}

try {
    
    $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, generos = ? WHERE id = ?");
    $stmt->execute([$novo_nome, $novos_generos, $id_utilizador]);

    
    $_SESSION['usuario_nome'] = $novo_nome; 

    
    echo json_encode([
        'sucesso' => true, 
        'mensagem' => 'Perfil atualizado com sucesso!',
        'dados_atualizados' => [
            'nome' => $novo_nome,
            'generos' => $novos_generos
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor ao atualizar o perfil.']);
}
?>