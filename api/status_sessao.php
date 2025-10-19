<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('Content-Type: application/json; charset=utf-8');

// Responde OPTIONS para CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Inicia sessão com as mesmas configurações
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', 3600);
    ini_set('session.gc_maxlifetime', 3600);
    session_name('SOCIALMUSIC_SESSION');
    session_start();
}

// Verifica se está logado
$logado = isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);

if ($logado) {
    // Verifica tempo de inatividade (30 minutos)
    if (isset($_SESSION['ultima_atividade'])) {
        $inatividade = time() - $_SESSION['ultima_atividade'];
        if ($inatividade > 1800) { // 30 minutos
            session_unset();
            session_destroy();
            $logado = false;
        } else {
            // Atualiza timestamp de última atividade
            $_SESSION['ultima_atividade'] = time();
        }
    }
}

// Resposta JSON
if ($logado) {
    echo json_encode([
        'logado' => true,
        'usuario' => $_SESSION['username'] ?? $_SESSION['email'] ?? 'Usuário',
        'perfil' => $_SESSION['perfil'] ?? 'user',
        'dados' => [
            'id' => $_SESSION['usuario_id'],
            'email' => $_SESSION['email'],
            'username' => $_SESSION['username'] ?? null,
            'nome' => $_SESSION['nome'] ?? null
        ],
        'debug' => [
            'session_id' => session_id(),
            'session_data' => $_SESSION
        ]
    ]);
} else {
    echo json_encode([
        'logado' => false,
        'mensagem' => 'Usuário não autenticado',
        'debug' => [
            'session_id' => session_id(),
            'session_data' => $_SESSION ?? []
        ]
    ]);
}
?>