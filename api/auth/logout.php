<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/auth_token.php';

$bearerToken = getBearerToken();
$userId = isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;

try {
    // Revoga o token que chegou no Authorization. Se a requisição estiver
    // autenticada apenas pela sessão/cookie, revoga o token daquele usuário.
    if ($bearerToken) {
        revokeAuthToken($pdo, $bearerToken);
    } elseif ($userId) {
        revokeAuthTokensForUser($pdo, $userId);
    }
} catch (Throwable $e) {
    // O logout local deve continuar mesmo se a revogação no banco falhar.
    error_log('Erro ao revogar token no logout: ' . $e->getMessage());
}

session_unset();
session_destroy();

setcookie('auth_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

// Expira também o cookie da sessão PHP usando os parâmetros atuais.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?: '',
        'secure' => (bool) $params['secure'],
        'httponly' => (bool) $params['httponly'],
        'samesite' => 'None'
    ]);
}

echo json_encode(['sucesso' => true, 'mensagem' => 'Logout realizado com sucesso.']);
?>
