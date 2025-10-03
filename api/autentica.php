<?php
//API de Autenticação

$origin = 'http://localhost:3000'; 

header("Access-Control-Allow-Origin: " . $origin); 
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header('Content-Type: application/json; charset=utf-8');


// Responde OPTIONS para CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas POST permitido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Inicia sessão
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax'); // Mudando para Lax para teste
    session_start();
}

// Debug - Log do início da sessão
error_log('Nova sessão iniciada em autentica.php');
error_log('SESSION ID: ' . session_id());

/**
 * Registra tentativa de login
 */
function registraTentativaLogin($email, $sucesso) {
    try {
        $db = Database::getInstance()->getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt = $db->prepare("INSERT INTO tentativas_login (email, ip_address, sucesso) VALUES (?, ?, ?)");
        $stmt->execute([$email, $ip, $sucesso ? 1 : 0]);
    } catch (Exception $e) {
        error_log("Erro ao registrar tentativa: " . $e->getMessage());
    }
}

/**
 * Verifica bloqueio por excesso de tentativas
 */
function verificaBloqueio($email) {
    try {
        $db = Database::getInstance()->getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Verifica tentativas nos últimos 15 minutos
        $stmt = $db->prepare("
            SELECT COUNT(*) as tentativas 
            FROM tentativas_login 
            WHERE email = ? 
            AND ip_address = ? 
            AND sucesso = 0 
            AND tentativa_em > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$email, $ip]);
        $resultado = $stmt->fetch();
        
        return $resultado['tentativas'] >= 5;
    } catch (Exception $e) {
        error_log("Erro ao verificar bloqueio: " . $e->getMessage());
        return false;
    }
}

try {
    // Recebe dados JSON
    $json = file_get_contents('php://input');
    $dados = json_decode($json, true);
    
    if (!$dados) {
        throw new Exception('Dados inválidos');
    }
    
    // Validações
    $email = filter_var($dados['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $senha = $dados['senha'] ?? '';
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('E-mail inválido');
    }
    
    if (empty($senha)) {
        throw new Exception('Senha é obrigatória');
    }
    
    // Verifica bloqueio
    if (verificaBloqueio($email)) {
        http_response_code(429);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Muitas tentativas falhas. Tente novamente em 15 minutos.'
        ]);
        exit;
    }
    
    // Busca usuário
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, email, usuario, senha_hash, perfil, nome, ativo FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();
    
    // Valida credenciais
    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        registraTentativaLogin($email, false);
        
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'E-mail ou senha incorretos'
        ]);
        exit;
    }
    
    // Verifica se está ativo
    if (!$usuario['ativo']) {
        registraTentativaLogin($email, false);
        
        http_response_code(403);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário inativo. Entre em contato com o administrador.'
        ]);
        exit;
    }
    
    // Atualiza hash se necessário (caso o algoritmo tenha mudado)
    if (password_needs_rehash($usuario['senha_hash'], PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $stmtUpdate = $db->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
        $stmtUpdate->execute([$novoHash, $usuario['id']]);
    }
    
    // Regenera ID da sessão (segurança)
    session_regenerate_id(true);
    
    // Cria sessão
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['email'] = $usuario['email'];
    $_SESSION['usuario'] = $usuario['usuario'];
    $_SESSION['nome'] = $usuario['nome'];
    $_SESSION['perfil'] = $usuario['perfil'];
    $_SESSION['ultima_atividade'] = time();
    $_SESSION['ip_login'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Registra sucesso
    registraTentativaLogin($email, true);
    
    // Resposta de sucesso
    $resposta = [
        'sucesso' => true,
        'mensagem' => 'Login realizado com sucesso',
        'usuario' => [
            'id' => $usuario['id'],
            'email' => $usuario['email'],
            'usuario' => $usuario['usuario'],
            'nome' => $usuario['nome'],
            'perfil' => $usuario['perfil']
        ],
        'debug' => [
            'session_id' => session_id(),
            'session_data' => $_SESSION,
            'usuario_db' => $usuario
        ]
    ];
    echo json_encode($resposta);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);
}
?>