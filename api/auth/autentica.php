<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

$dados = json_decode(file_get_contents("php://input"), true);

if (!$dados || empty($dados['email']) || empty($dados['senha'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['sucesso' => false, 'mensagem' => 'Email e senha são obrigatórios.']);
    exit;
}

$email = $dados['email'];
$senha = $dados['senha'];

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
$stmt->execute([$email]);
$usuario = $stmt->fetch();
error_log('DEBUG LOGIN - Usuario encontrado: ' . print_r($usuario, true));

// RF2 e RF5: Valida credenciais e senha com hash
if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
    
    // RF2: Regenera o ID da sessão para segurança
    session_regenerate_id(true);

    // RF2: Armazena dados na sessão
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['perfil'] = $usuario['perfil'];

    // Retorna os dados do usuário para o Vue (para o localStorage)
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Login bem-sucedido!',
        'usuario' => [
            'id' => $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'username' => $usuario['username'],
            'perfil' => $usuario['perfil'],
            'foto' => $usuario['foto_perfil'] ?? null,
            'spotify_conectado' => (int)$usuario['spotify_conectado']
        ]
    ]);
} else {
    // RF5: Mensagem de erro clara
    http_response_code(401); // Unauthorized
    echo json_encode(['sucesso' => false, 'mensagem' => 'Credenciais inválidas.']);
}
?>