<?php
/**
 * API de Cadastro de Usuário
 * Endpoint: POST /api/cadastro.php
 */

// Define o tipo de conteúdo da resposta como JSON
header('Content-Type: application/json; charset=utf-8');
// Permite requisições de qualquer origem (essencial para o Vue.js em desenvolvimento)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Responde com sucesso a requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Permite apenas o método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

// Inclui a configuração do banco de dados
require_once __DIR__ . '/../config/database.php'; 

try {
    // 1. Recebe e decodifica os dados JSON enviados pelo Vue.js
    $json = file_get_contents('php://input');
    $dados = json_decode($json, true);

    if (!$dados) {
        throw new Exception('Dados de entrada inválidos ou mal formatados.');
    }

    // 2. Validação dos campos
    $nome = trim($dados['nome'] ?? '');
    $email = filter_var($dados['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $senha = $dados['senha'] ?? '';
    $username = trim($dados['username'] ?? '');

    if (empty($nome)) {
        throw new Exception('O nome é obrigatório.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('O e-mail fornecido não é válido.');
    }
    if (empty($senha) || strlen($senha) < 6) {
        throw new Exception('A senha é obrigatória e deve ter no mínimo 6 caracteres.');
    }
    // Adiciona validação para o nome de usuário também
    if (empty($username)) {
        throw new Exception('O nome de usuário não pôde ser gerado.');
    }

    // Inicializa a variável de perfil
    if (strpos($email, '@socialmusic.br') == true) {
        $perfil = 'admin';
    }
    else{
        $perfil = 'user';
    }

    // 3. Conecta ao banco de dados
    $db = Database::getInstance()->getConnection();

    // 4. Verifica se o e-mail OU o usuário já estão em uso
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    
    if ($stmt->fetch()) {
        http_response_code(409); // Conflict
        throw new Exception('Este e-mail ou nome de usuário já está cadastrado.');
    }

    // 5. Criptografa a senha
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

    // 6. Insere o novo usuário no banco de dados
    $stmt = $db->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil, username) VALUES (?, ?, ?, ?, ?)");
    $sucesso_insert = $stmt->execute([$nome, $email, $senha_hash, $perfil, $username]);

    if (!$sucesso_insert) {
        throw new Exception('Ocorreu um erro ao tentar criar a conta. Tente novamente.');
    }
    
    // 7. Responde com sucesso
    http_response_code(201); // 201 Created
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Conta criada com sucesso! Você já pode fazer o login.'
    ]);

} catch (Exception $e) {
    // Captura qualquer erro (validação, banco de dados, etc.) e retorna uma resposta JSON
    if (http_response_code() === 200) {
        http_response_code(400); // Bad Request
    }
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);
}
?>