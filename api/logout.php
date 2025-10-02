<?php
/**
 * API de Logout
 * Endpoint: POST /api/logout.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Responde OPTIONS para CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Inicia sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Remove todas as variáveis de sessão
    $_SESSION = [];
    
    // Destrói o cookie de sessão
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    
    // Destrói a sessão
    session_unset();
    session_destroy();
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Logout realizado com sucesso'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao realizar logout: ' . $e->getMessage()
    ]);
}
?>