<?php
require_once __DIR__ . '/../core/header.php';

// Encerra a sessão PHP
session_unset();
session_destroy();

// Expira o cookie do token de autenticação, se existir
setcookie('auth_token', '', [
    'expires' => time() - 3600, 
    'path' => '/',
    'domain' => '',
    'secure' => true, 
    'httponly' => true,
    'samesite' => 'Lax'
]);

echo json_encode(['sucesso' => true, 'mensagem' => 'Logout realizado com sucesso.']);
?>