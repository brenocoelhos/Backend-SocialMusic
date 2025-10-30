<?php
// Este arquivo será incluído no topo de todos os outros
// Ele já inicia o header.php e verifica a sessão de admin

require_once '../header.php'; // Sobe um nível para achar o header
require_once '../conexao.php'; // Sobe um nível para achar a conexão

if (!isset($_SESSION['perfil']) || $_SESSION['perfil'] !== 'admin') {
    http_response_code(403); // Forbidden
    echo json_encode(['sucesso' => false, 'mensagem' => 'Acesso negado. Requer privilégios de administrador.']);
    exit;
}
?>