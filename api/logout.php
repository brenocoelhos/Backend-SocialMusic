<?php
require_once 'header.php';

// RF4: Encerra a sessão
session_unset();
session_destroy();

echo json_encode(['sucesso' => true, 'mensagem' => 'Logout realizado com sucesso.']);
?>