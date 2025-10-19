<?php
/**
 * API de Logout - Suporte a sessões e tokens
 * Endpoint: POST /api/logout.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Responde OPTIONS para CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AuthManager.php';

try {
    // Logout via token
    $token = $_COOKIE['auth_token'] ?? null;
    
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Bearer ') === 0) {
            $token = substr($auth, 7);
        }
    }
    
    if (!$token) {
        $token = $_GET['token'] ?? $_POST['token'] ?? null;
    }
    
    $tokenLogout = false;
    
    if ($token) {
        // Conectar ao banco e fazer logout via token
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        $authManager = new AuthManager($pdo);
        $tokenLogout = $authManager->logout($token);
        
        // Limpar cookie
        setcookie('auth_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    
    // Logout via sessão (fallback/compatibilidade)
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