<?php
/**
 * Autenticação por Bearer Token usada como fallback da sessão PHP.
 *
 * A aplicação continua usando a sessão/cookie normalmente. Quando o navegador
 * não envia o cookie (ex.: bloqueio de cookies de terceiros), o frontend envia
 * Authorization: Bearer <token> e este arquivo recupera o usuário pela tabela
 * auth_tokens.
 */

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function getBearerToken(): ?string
{
    $header = getAuthorizationHeader();
    if ($header === '') {
        return null;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return null;
    }

    $token = trim($matches[1]);
    return $token !== '' ? $token : null;
}

function createAuthToken(PDO $pdo, int $userId, int $ttlSeconds = 3600): string
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    // Há uma chave UNIQUE por user_id no banco. Um novo login substitui o
    // token Bearer anterior daquele usuário, mantendo o modelo atual simples.
    $stmt = $pdo->prepare(
        "INSERT INTO auth_tokens (user_id, token, expires_at)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
             token = VALUES(token),
             expires_at = VALUES(expires_at),
             created_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$userId, $token, $expiresAt]);

    return $token;
}

function findUserByAuthToken(PDO $pdo, ?string $token): ?array
{
    if (!$token) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT
            u.id,
            u.nome,
            u.username,
            u.email,
            u.perfil,
            u.ativo,
            u.foto_perfil,
            u.spotify_conectado,
            t.expires_at
         FROM auth_tokens t
         INNER JOIN usuarios u ON u.id = t.user_id
         WHERE t.token = ?
           AND t.expires_at > NOW()
           AND u.ativo = 1
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function hydrateSessionFromBearer(PDO $pdo): ?array
{
    // Sessão tradicional tem prioridade. Isso preserva o funcionamento atual.
    if (isset($_SESSION['usuario_id'])) {
        return null;
    }

    $token = getBearerToken();
    if (!$token) {
        return null;
    }

    $user = findUserByAuthToken($pdo, $token);
    if (!$user) {
        return null;
    }

    // Preenche a sessão somente para a requisição atual. Se o cookie PHP estiver
    // bloqueado, a próxima requisição será hidratada novamente pelo Bearer.
    $_SESSION['usuario_id'] = (int) $user['id'];
    $_SESSION['usuario_email'] = $user['email'];
    $_SESSION['perfil'] = $user['perfil'];
    $_SESSION['usuario_nome'] = $user['nome'];
    $_SESSION['usuario_username'] = $user['username'];

    return $user;
}

function revokeAuthToken(PDO $pdo, ?string $token): void
{
    if (!$token) {
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE token = ?");
    $stmt->execute([$token]);
}

function revokeAuthTokensForUser(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
    $stmt->execute([$userId]);
}
