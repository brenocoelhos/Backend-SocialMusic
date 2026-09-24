<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/auth_token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }

    // Aceita tanto o formato antigo (usuario) quanto email.
    $identificador = trim((string) ($input['usuario'] ?? $input['email'] ?? ''));
    $senha = (string) ($input['senha'] ?? '');

    if ($identificador === '' || $senha === '') {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário e senha são obrigatórios']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id, nome, username, email, senha_hash, perfil, ativo, foto_perfil, spotify_conectado
         FROM usuarios
         WHERE email = ? OR username = ?
         LIMIT 1"
    );
    $stmt->execute([$identificador, $identificador]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || !$usuario['senha_hash'] || !password_verify($senha, $usuario['senha_hash'])) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Credenciais inválidas']);
        exit;
    }

    if (!(bool) $usuario['ativo']) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Esta conta está inativa.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['perfil'] = $usuario['perfil'];

    // Token curto usado quando o navegador não aceitar o cookie de sessão.
    $token = createAuthToken($pdo, (int) $usuario['id'], 3600);

    // Mantém também o cookie do token para navegadores que aceitam cookies
    // cross-site. O frontend não depende dele para funcionar.
    setcookie('auth_token', $token, [
        'expires' => time() + 3600,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Login realizado com sucesso',
        'token' => $token,
        'usuario' => [
            'id' => (int) $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'username' => $usuario['username'],
            'perfil' => $usuario['perfil'],
            'foto' => $usuario['foto_perfil'] ?? null,
            'spotify_conectado' => (int) $usuario['spotify_conectado']
        ]
    ]);
} catch (Throwable $e) {
    error_log('Erro login_token.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno ao realizar login.'
    ]);
}
?>
