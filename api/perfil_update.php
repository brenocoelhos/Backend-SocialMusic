<?php
// Inclui o header.php (para session_start() e CORS)
require_once 'header.php';
// Inclui o conexao.php (para a variável $pdo)
require_once 'conexao.php';

// Endpoint protegido! Verifica se o utilizador está logado.
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['sucesso' => false, 'mensagem' => 'Utilizador não autenticado.']);
    exit;
}

// Se está aqui, o utilizador está logado.
$id_utilizador = $_SESSION['usuario_id'];

// Pega os dados enviados pelo Vue (apenas o nome)
$dados = json_decode(file_get_contents("php://input"));

$novo_nome = $dados->nome ?? null;

// Validação
if (empty($novo_nome)) {
    http_response_code(400); // Bad Request
    echo json_encode(['sucesso' => false, 'mensagem' => 'O nome não pode estar vazio.']);
    exit;
}

// Atualiza o nome na base de dados
try {
    $stmt = $pdo->prepare("UPDATE usuarios SET nome = ? WHERE id = ?");
    $stmt->execute([$novo_nome, $id_utilizador]);

    // Atualiza o nome também na sessão, para que a barra de topo (App.vue)
    // possa ser atualizada se necessário (embora o localStorage seja o principal)
    $_SESSION['usuario_nome'] = $novo_nome; 

    // Retorna o nome atualizado para o Vue
    echo json_encode([
        'sucesso' => true, 
        'mensagem' => 'Nome atualizado com sucesso!',
        'nome_atualizado' => $novo_nome
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor ao atualizar o perfil.']);
}
?>