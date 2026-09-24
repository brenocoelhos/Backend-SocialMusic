<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/auth_token.php';

$token = getBearerToken() ?: ($_COOKIE['auth_token'] ?? null);
$user = findUserByAuthToken($pdo, $token);

if ($user) {
    echo json_encode([
        'sucesso' => true,
        'logado' => true,
        'usuario' => [
            'id' => (int) $user['id'],
            'nome' => $user['nome'],
            'username' => $user['username'],
            'email' => $user['email'],
            'perfil' => $user['perfil'],
            'foto' => $user['foto_perfil'] ?? null,
            'spotify_conectado' => (int) $user['spotify_conectado']
        ]
    ]);
    exit;
}

http_response_code(401);
echo json_encode([
    'sucesso' => true,
    'logado' => false,
    'mensagem' => 'Token inválido ou expirado.'
]);
?>
