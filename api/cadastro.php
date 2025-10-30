<?php
require_once 'header.php';
require_once 'conexao.php';

// Decodifica o JSON vindo do App.vue
$dados = json_decode(file_get_contents("php://input"), true);

// Validação dos campos obrigatórios
if (!$dados || empty($dados['nome']) || empty($dados['email']) || empty($dados['senha'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nome, email e senha são obrigatórios.']);
    exit;
}

// Pega os dados
$nome = $dados['nome'];
$email = $dados['email'];
$username = $dados['username'] ?? $dados['email']; // Usa email como username se não fornecido
$senha_hash = password_hash($dados['senha'], PASSWORD_DEFAULT);

// Define perfil automaticamente baseado no domínio do email
// Emails com @socialmusic.com são automaticamente admin
if (str_ends_with($email, '@socialmusic.com')) {
    $perfil = 'admin';
} else {
    $perfil = 'user';
}

$ativo = 1;       // Padrão (já que adicionamos essa coluna para o Admin.vue)

// 1. Verifica se o email já existe
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409); // Conflict
    echo json_encode(['sucesso' => false, 'mensagem' => 'Este email já está cadastrado.']);
    exit;
}

// 2. Verifica se o username já existe
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetch()) {
    http_response_code(409); // Conflict
    echo json_encode(['sucesso' => false, 'mensagem' => 'Este username já está em uso.']);
    exit;
}

// 3. Insere o novo usuário
$stmt = $pdo->prepare("INSERT INTO usuarios (nome, username, email, senha_hash, perfil, ativo) VALUES (?, ?, ?, ?, ?, ?)");
try {
    $stmt->execute([$nome, $username, $email, $senha_hash, $perfil, $ativo]);
    
    http_response_code(201); // Created
    echo json_encode(['sucesso' => true, 'mensagem' => 'Usuário cadastrado com sucesso!']);

} catch (PDOException $e) {
    // Se algo der errado no INSERT (ex: coluna faltando)
    http_response_code(500);
    echo json_encode([
        'sucesso' => false, 
        'mensagem' => 'Erro interno do servidor ao cadastrar usuário.',
        'error_debug' => $e->getMessage() // Mensagem para depuração
    ]);
}
?>