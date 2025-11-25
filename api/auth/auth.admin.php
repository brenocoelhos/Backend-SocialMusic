<?php
require_once __DIR__ . '/../core/header.php';

// RF3: Verifica se a sessão existe E se o perfil é 'admin'
if (isset($_SESSION['usuario_id']) && isset($_SESSION['perfil']) && $_SESSION['perfil'] === 'admin') {
    // O usuário está autenticado E é um admin
    echo json_encode([
        'success' => true,
        'perfil' => $_SESSION['perfil'],
        'email' => $_SESSION['usuario_email']
    ]);
} else if (isset($_SESSION['usuario_id'])) {
    // RF3 e RF5: Está logado, mas não tem permissão
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'mensagem' => 'Acesso negado. Requer privilégios de administrador.']);
} else {
    // RF3 e RF5: Não está logado (sessão expirada ou inexistente)
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'mensagem' => 'Sessão expirada. Por favor, faça login novamente.']);
}
?>