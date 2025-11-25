<?php
// Este arquivo será incluído no topo de todos os outros
// Ele já inicia o header.php e verifica a sessão de admin

require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

if (!isset($_SESSION['perfil']) || $_SESSION['perfil'] !== 'admin') {
    http_response_code(403); // Forbidden
    echo json_encode(['sucesso' => false, 'mensagem' => 'Acesso negado. Requer privilégios de administrador.']);
    exit;
}
?>