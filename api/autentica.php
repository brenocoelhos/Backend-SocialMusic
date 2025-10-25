<?php
//API de Autenticação

// Habilitar exibição de erros para debug (remover em produção final)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Não mostrar na tela
ini_set('log_errors', 1);

// Configuração CORS
try {
    require_once __DIR__ . '/../config/cors.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao carregar CORS', 'erro' => $e->getMessage()]);
    exit;
}

// Apenas POST permitido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao conectar ao banco', 'erro' => $e->getMessage()]);
    exit;
}

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
    
    ini_set('session.cookie_lifetime', 3600); // 1 hora
    ini_set('session.gc_maxlifetime', 3600);
    session_name('SOCIALMUSIC_SESSION');
    session_start();
}

// Debug - Log do início da sessão



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

        return false;
    }
}

try {
    // Recebe dados JSON ou form data
    $json = file_get_contents('php://input');
    $dados = json_decode($json, true);
    
    // Se não conseguiu decodificar JSON, tenta $_POST
    if (!$dados && !empty($_POST)) {
        $dados = $_POST;
    }
    
    if (!$dados) {
        throw new Exception('Dados inválidos');
    }
    
    // Aceita 'email', 'usuario' ou 'username'
    $login = $dados['email'] ?? $dados['usuario'] ?? $dados['username'] ?? '';
    $senha = $dados['senha'] ?? '';
    
    if (empty($login)) {
        throw new Exception('Email ou usuário é obrigatório');
    }
    
    if (empty($senha)) {
        throw new Exception('Senha é obrigatória');
    }
    
    // Determina se é email ou username
    $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL);
    
    // Verifica bloqueio (usa login como identificador)
    if (verificaBloqueio($login)) {
        http_response_code(429);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Muitas tentativas falhas. Tente novamente em 15 minutos.'
        ]);
        exit;
    }
    
    // Busca usuário
    $db = Database::getInstance()->getConnection();
    if ($isEmail) {
        $stmt = $db->prepare("SELECT id, email, username, senha_hash, perfil, nome, ativo FROM usuarios WHERE email = ?");
    } else {
        $stmt = $db->prepare("SELECT id, email, username, senha_hash, perfil, nome, ativo FROM usuarios WHERE username = ?");
    }
    $stmt->execute([$login]);
    $usuario = $stmt->fetch();
    
    // Valida credenciais
    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        registraTentativaLogin($login, false);
        
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
    $_SESSION['username'] = $usuario['username'];
    $_SESSION['nome'] = $usuario['nome'];
    $_SESSION['perfil'] = $usuario['perfil'];
    $_SESSION['ultima_atividade'] = time();
    $_SESSION['ip_login'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Registra sucesso
    registraTentativaLogin($login, true);
    
    // Resposta de sucesso
    $resposta = [
        'sucesso' => true,
        'mensagem' => 'Login realizado com sucesso',
        'usuario' => [
            'id' => $usuario['id'],
            'email' => $usuario['email'],
            'username' => $usuario['username'],
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
