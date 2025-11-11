<?php
// api/login_token.php

require_once 'header.php';
require_once 'conexao.php';
require_once __DIR__ . '/../classes/AuthManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

try {
    // Receber dados
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    $usuario = $input['usuario'] ?? '';
    $senha = $input['senha'] ?? '';
    
    if (!$usuario || !$senha) {
        throw new Exception('Usuário e senha são obrigatórios');
    }
        
    $authManager = new AuthManager($pdo);
    $result = $authManager->login($usuario, $senha);
    
    if ($result) {

        // Salva os dados na SESSÃO (igual o autentica.php faz)
        $_SESSION['usuario_id'] = $result['user']['id'];
        $_SESSION['usuario_email'] = $result['user']['email'];
        $_SESSION['perfil'] = $result['user']['perfil'];

        // Definir cookie com o token
        setcookie('auth_token', $result['token'], [
            'expires' => time() + 3600,
            'path' => '/',
            'domain' => '',
            'secure' => true, // HTTPS
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Login realizado com sucesso',
            'token' => $result['token'],
            'usuario' => $result['user']
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Credenciais inválidas'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);
}
?>