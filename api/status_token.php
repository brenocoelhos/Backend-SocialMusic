<?php
// api/status_token.php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AuthManager.php';

try {
    // Obter token do cookie, header Authorization ou GET/POST
    $token = $_COOKIE['auth_token'] ?? null;
    
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Bearer ') === 0) {
            $token = substr($auth, 7);
        }
    }
    
    // Fallback: tentar GET ou POST
    if (!$token) {
        $token = $_GET['token'] ?? $_POST['token'] ?? null;
    }
    
    // Conectar ao banco
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $authManager = new AuthManager($pdo);
    $user = $authManager->verifyToken($token);
    
    if ($user) {
        echo json_encode([
            'logado' => true,
            'usuario' => $user['username'] ?? $user['email'],
            'perfil' => $user['perfil'],
            'dados' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'username' => $user['username'] ?? null,
                'nome' => $user['nome'] ?? null
            ]
        ]);
    } else {
        echo json_encode([
            'logado' => false,
            'mensagem' => 'Token inválido ou expirado'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'logado' => false,
        'mensagem' => 'Erro interno: ' . $e->getMessage()
    ]);
}
?>