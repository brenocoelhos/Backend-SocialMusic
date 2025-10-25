<?php
require_once __DIR__ . '/../config/cors.php';

// Inicia sessão com configurações adequadas para produção
if (session_status() === PHP_SESSION_NONE) {
    // Detecta se está em HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || $_SERVER['SERVER_PORT'] == 443
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    
    // Em produção (HTTPS), use SameSite=None; Secure
    if ($isHttps) {
        ini_set('session.cookie_samesite', 'None');
        ini_set('session.cookie_secure', 1);
    } else {
        // Em desenvolvimento (HTTP), use Lax
        ini_set('session.cookie_samesite', 'Lax');
    }
    
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