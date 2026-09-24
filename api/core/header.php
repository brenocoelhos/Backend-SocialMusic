<?php
/**
 * Header para todas as requisições da API.
 *
 * Mantém a sessão PHP existente e adiciona Bearer Token como fallback para
 * navegadores que bloqueiam o cookie cross-site entre Vercel e Render.
 */

require_once __DIR__ . '/../../config/cors.php';

// Mantém o mesmo tempo de vida da sessão já usado pelo projeto.
ini_set('session.gc_maxlifetime', 8 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 28800,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth_token.php';

// Só abre conexão aqui quando realmente existe um Bearer e a sessão não veio
// pelo cookie. Os endpoints que já incluem conexao.php continuam iguais.
if (!isset($_SESSION['usuario_id']) && getBearerToken()) {
    require_once __DIR__ . '/conexao.php';
    hydrateSessionFromBearer($pdo);
}

header('Content-Type: application/json; charset=utf-8');
?>
