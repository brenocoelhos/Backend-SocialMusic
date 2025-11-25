<?php
require_once __DIR__ . '/../core/header.php';

if (isset($_SESSION['usuario_id'])) {
    // Usuário está logado
    echo json_encode([
        'sucesso' => true,
        'logado' => true,
        'usuario' => [
            'id' => $_SESSION['usuario_id'],
            'email' => $_SESSION['usuario_email'],
            'perfil' => $_SESSION['perfil']
        ]
    ]);
} else {
    // Usuário não está logado
    echo json_encode(['sucesso' => true, 'logado' => false]);
}
?>