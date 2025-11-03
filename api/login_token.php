<?php
// api/login_token.php
require_once __DIR__ . '/../config/cors.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AuthManager.php';

try {
    // Receber dados
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    $usuario = $input['usuario'] ?? '';
    $senha = $input['senha'] ?? '';
    
    if (!$usuario || !$senha) {
        throw new Exception('Usuário e senha são obrigatórios');
    }
    
    // Conectar ao banco
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $authManager = new AuthManager($pdo);
    $result = $authManager->login($usuario, $senha);
    
    if ($result) {

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Salva os dados na SESSÃO (igual o autentica.php faz)
        $_SESSION['usuario_id'] = $result['user']['id'];
        $_SESSION['usuario_email'] = $result['user']['email'];
        $_SESSION['perfil'] = $result['user']['perfil'];

        // Definir cookie com o token
        setcookie('auth_token', $result['token'], [
            'expires' => time() + 3600,
            'path' => '/',
            'domain' => '',
            'secure' => true, // HTTPS
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Login realizado com sucesso',
            'token' => $result['token'],
            'usuario' => $result['user']
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Credenciais inválidas'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);
}
?>