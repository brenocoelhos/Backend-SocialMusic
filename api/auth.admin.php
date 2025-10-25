<?php
// Configuração CORS
require_once __DIR__ . '/../config/cors.php';

// Configuração da sessão adequada para produção
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
    
    session_start();
}

// Debug da sessão
error_log('SESSION em auth.admin.php: ' . print_r($_SESSION, true));
error_log('HTTPS detected: ' . ($isHttps ? 'yes' : 'no'));

// Verifica se o usuário está logado e se é admin
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['perfil']) || $_SESSION['perfil'] !== 'admin') {
    $erro = [];
    if (!isset($_SESSION['usuario_id'])) $erro[] = 'usuario_id não definido';
    if (!isset($_SESSION['perfil'])) $erro[] = 'perfil não definido';
    if (isset($_SESSION['perfil']) && $_SESSION['perfil'] !== 'admin') $erro[] = 'perfil não é admin';
    
    http_response_code(403); // Forbidden
    echo json_encode([
        "error" => "Acesso negado. Requer perfil de administrador.",
        "detalhes" => $erro,
        "session" => $_SESSION
    ]);
    exit;
}

// Se chegou até aqui, o usuário é um admin autenticado
http_response_code(200); // OK
echo json_encode(["success" => true, "perfil" => $_SESSION['perfil']]);
?>